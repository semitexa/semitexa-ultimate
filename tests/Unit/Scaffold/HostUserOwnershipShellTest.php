<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The app runs as the host user, and what root left behind is handed back.
 *
 * `compose exec` into a root app container, and the root server itself, left
 * root-owned files all over the bind-mounted project (generated docs, the
 * quality baseline, var/), which the developer could not edit without sudo.
 * The fix lives in three places that must agree: docker-compose.yml runs the
 * services as SEMITEXA_UID:SEMITEXA_GID, bin/semitexa exports those ids, and
 * server:start/restart hand root-owned files back once. The shell helpers are
 * extracted from the shipped script and run here as the shell runs them.
 */
final class HostUserOwnershipShellTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-host-user-' . uniqid();
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function the_compose_context_carries_the_host_ids(): void
    {
        $out = $this->shell('env_export_compose_context; printf "%s:%s" "$SEMITEXA_UID" "$SEMITEXA_GID"', '');

        self::assertSame(trim((string) shell_exec('id -u')) . ':' . trim((string) shell_exec('id -g')), $out);
    }

    #[Test]
    public function an_explicit_id_from_env_or_the_caller_wins(): void
    {
        self::assertSame('4242:4343', $this->shell(
            'env_export_compose_context; printf "%s:%s" "$SEMITEXA_UID" "$SEMITEXA_GID"',
            "SEMITEXA_UID=4242\nSEMITEXA_GID=4343",
        ));
        self::assertSame('77:88', $this->shell(
            'SEMITEXA_UID=77 SEMITEXA_GID=88; env_export_compose_context; printf "%s:%s" "$SEMITEXA_UID" "$SEMITEXA_GID"',
            '',
        ));
    }

    #[Test]
    public function root_owned_files_are_handed_back_through_a_root_one_off(): void
    {
        $out = $this->shell(
            'id(){ case "$1" in -u|-g) echo 1000;; *) echo dev;; esac; }; '
            . 'find(){ echo "$PROJECT_ROOT/var/cache/x"; }; '
            . 'run_compose(){ echo "COMPOSE $*"; }; project_ownership_reclaim',
            '',
        );

        self::assertStringContainsString('e.g. var/cache/x', $out);
        self::assertStringContainsString(
            'COMPOSE run --rm --no-deps -T --user 0:0 app find /var/www/html -xdev -user 0 -exec chown -h 1000:1000 {} +',
            $out,
        );
    }

    #[Test]
    public function a_clean_project_or_a_root_host_starts_no_container(): void
    {
        $clean = $this->shell(
            'id(){ echo 1000; }; find(){ :; }; run_compose(){ echo COMPOSE; }; project_ownership_reclaim; echo rc=$?',
            '',
        );
        $rootHost = $this->shell(
            'id(){ echo 0; }; find(){ echo "$PROJECT_ROOT/x"; }; run_compose(){ echo COMPOSE; }; project_ownership_reclaim; echo rc=$?',
            '',
        );

        self::assertSame('rc=0', $clean);
        self::assertSame('rc=0', $rootHost);
    }

    #[Test]
    public function a_failed_handover_warns_and_does_not_stop_the_start(): void
    {
        $out = $this->shell(
            'id(){ case "$1" in -u|-g) echo 1000;; *) echo dev;; esac; }; find(){ echo "$PROJECT_ROOT/x"; }; '
            . 'run_compose(){ return 1; }; project_ownership_reclaim; echo rc=$?',
            '',
        );

        self::assertStringContainsString('WARN: Could not hand root-owned project files back', $out);
        self::assertStringEndsWith('rc=0', $out);
    }

    #[Test]
    public function start_and_restart_reclaim_and_the_services_run_as_the_host_user(): void
    {
        $script = $this->script();
        foreach (['cmd_server_start', 'cmd_server_restart'] as $fn) {
            self::assertMatchesRegularExpression(
                '/^' . $fn . '\(\) \{\n.*?^\s+project_ownership_reclaim$.*?^\}$/ms',
                $script,
                "{$fn} must hand root-owned files back before the stack comes up",
            );
        }

        $compose = (string) file_get_contents(dirname(__DIR__, 3) . '/docker-compose.yml');
        foreach (['app', 'scheduler', 'cli'] as $service) {
            self::assertMatchesRegularExpression(
                '/^  ' . $service . ':\n    build: \.\n(?:    #.*\n)*    user: "\$\{SEMITEXA_UID:-0\}:\$\{SEMITEXA_GID:-0\}"$/m',
                $compose,
                "service {$service} must run as the host user",
            );
        }
        self::assertMatchesRegularExpression('/^  setup:\n    build: \.\n    user: root$/m', $compose, 'setup chowns the bootstrap and stays root');
    }

    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
    }

    private function shell(string $call, string $merged): string
    {
        preg_match_all('/^(env_export_compose_context|project_ownership_reclaim)\(\) \{\n.*?^\}$/ms', $this->script(), $m);
        self::assertCount(2, $m[0], 'both helpers must still exist in bin/semitexa');

        $sh = 'warn(){ printf "WARN: %s\n" "$*"; }; info(){ printf "%s\n" "$*"; }; '
            . 'env_merged_lines(){ [ -n "$MERGED" ] && printf "%s\n" "$MERGED"; }' . "\n"
            . implode("\n", $m[0]) . "\n" . $call;

        return trim((string) shell_exec(sprintf(
            'env -u SEMITEXA_UID -u SEMITEXA_GID PROJECT_ROOT=%s MERGED=%s sh -c %s 2>&1',
            escapeshellarg($this->root),
            escapeshellarg($merged),
            escapeshellarg($sh),
        )));
    }
}
