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
    public function the_caller_wins_over_env_and_env_wins_over_the_host(): void
    {
        $print = 'env_export_compose_context; printf "%s:%s" "$SEMITEXA_UID" "$SEMITEXA_GID"';

        self::assertSame('4242:4343', $this->shell($print, "SEMITEXA_UID=4242\nSEMITEXA_GID=4343"));
        self::assertSame('77:88', $this->shell('SEMITEXA_UID=77 SEMITEXA_GID=88; ' . $print, "SEMITEXA_UID=0\nSEMITEXA_GID=0"));
    }

    #[Test]
    public function rootless_docker_keeps_the_container_root_which_is_the_host_user(): void
    {
        self::assertSame('0:0', $this->shell(
            'env_export_compose_context; printf "%s:%s" "$SEMITEXA_UID" "$SEMITEXA_GID"',
            '',
            rootless: true,
        ));
    }

    #[Test]
    public function root_owned_files_go_to_the_ids_the_services_run_as(): void
    {
        $out = $this->shell(
            'find(){ [ -f "$PROJECT_ROOT/.done" ] || echo "$PROJECT_ROOT/var/cache/x"; }; '
            . 'run_compose(){ echo "COMPOSE $*"; : > "$PROJECT_ROOT/.done"; }; project_ownership_reclaim; echo rc=$?',
            "SEMITEXA_UID=2000\nSEMITEXA_GID=2001",
        );

        self::assertStringContainsString('e.g. var/cache/x', $out);
        self::assertStringContainsString(
            'COMPOSE run --rm --build --no-deps -T --user 0:0 app find /var/www/html -xdev -user 0 -exec chown -h 2000:2001 {} +',
            $out,
        );
        self::assertStringEndsWith('rc=0', $out);
    }

    #[Test]
    public function the_handover_leaves_the_callers_compose_overlays_alone(): void
    {
        // test:run picks the test overlay into _overlays, reclaims, then runs
        // e2e-runner with it; run_compose assigning _overlays inside the
        // reclaim made that "no such service: e2e-runner".
        $out = $this->shell(
            '_overlays="-f docker-compose.test.yml"; '
            . 'find(){ [ -f "$PROJECT_ROOT/.done" ] || echo "$PROJECT_ROOT/x"; }; '
            . 'run_compose(){ _overlays="-f docker-compose.yml"; : > "$PROJECT_ROOT/.done"; }; '
            . 'project_ownership_reclaim; printf "overlays=%s" "$_overlays"',
            "SEMITEXA_UID=1000\nSEMITEXA_GID=1000",
        );

        self::assertStringEndsWith('overlays=-f docker-compose.test.yml', $out);
    }

    #[Test]
    public function a_clean_project_or_a_root_service_user_starts_no_container(): void
    {
        $clean = $this->shell('find(){ :; }; run_compose(){ echo COMPOSE; }; project_ownership_reclaim; echo rc=$?', '');
        $rootService = $this->shell(
            'find(){ echo "$PROJECT_ROOT/x"; }; run_compose(){ echo COMPOSE; }; project_ownership_reclaim; echo rc=$?',
            "SEMITEXA_UID=0\nSEMITEXA_GID=0",
        );

        self::assertSame('rc=0', $clean);
        self::assertSame('rc=0', $rootService);
    }

    #[Test]
    public function files_still_root_owned_after_the_handover_stop_the_start(): void
    {
        $out = $this->shell(
            'find(){ echo "$PROJECT_ROOT/var/install"; }; run_compose(){ return 1; }; project_ownership_reclaim; echo rc=$?',
            "SEMITEXA_UID=1000\nSEMITEXA_GID=1000",
        );

        self::assertStringContainsString('ERROR: Root-owned project files remain (e.g. var/install)', $out);
        self::assertStringContainsString('SEMITEXA_UID=0 SEMITEXA_GID=0 bin/semitexa server:start', $out);
        self::assertStringEndsWith('rc=1', $out);
    }

    #[Test]
    public function start_and_restart_reclaim_and_the_services_run_as_the_host_user(): void
    {
        $script = $this->script();
        foreach (['cmd_server_start', 'cmd_server_restart'] as $fn) {
            self::assertMatchesRegularExpression(
                '/^' . $fn . '\(\) \{\n.*?^\s+project_ownership_reclaim \|\| exit 1$.*?^\}$/ms',
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

    private function shell(string $call, string $merged, bool $rootless = false): string
    {
        preg_match_all('/^(docker_is_rootless|env_export_compose_context|project_ownership_reclaim)\(\) \{\n.*?^\}$/ms', $this->script(), $m);
        self::assertCount(3, $m[0], 'the three helpers must still exist in bin/semitexa');

        $sh = 'warn(){ printf "WARN: %s\n" "$*"; }; error(){ printf "ERROR: %s\n" "$*"; }; info(){ printf "%s\n" "$*"; }; '
            . 'env_merged_lines(){ [ -n "$MERGED" ] && printf "%b\n" "$MERGED"; }' . "\n"
            . implode("\n", $m[0]) . "\n" . $call;

        return trim((string) shell_exec(sprintf(
            'env -u SEMITEXA_UID -u SEMITEXA_GID PROJECT_ROOT=%s MERGED=%s _SEMITEXA_ROOTLESS=%d sh -c %s 2>&1',
            escapeshellarg($this->root),
            escapeshellarg($merged),
            $rootless ? 1 : 0,
            escapeshellarg($sh),
        )));
    }
}
