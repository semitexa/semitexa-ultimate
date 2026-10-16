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
        // A host user other than 1000 also gets the files of uid 1000, the
        // image's own user, which every container ran as before.
        self::assertStringContainsString(
            'COMPOSE run --rm --no-deps -T --user 0:0 app find /var/www/html -xdev ( -user 0 -o -user 1000 ) -exec chown -h 2000:2001 {} +',
            $out,
        );
        self::assertStringEndsWith('rc=0', $out);
    }

    #[Test]
    public function the_image_user_itself_only_takes_roots_files(): void
    {
        $out = $this->shell(
            'find(){ [ -f "$PROJECT_ROOT/.done" ] || echo "$PROJECT_ROOT/var/cache/x"; }; '
            . 'run_compose(){ echo "COMPOSE $*"; : > "$PROJECT_ROOT/.done"; }; project_ownership_reclaim; echo rc=$?',
            "SEMITEXA_UID=1000\nSEMITEXA_GID=1000",
        );

        self::assertStringContainsString(
            'COMPOSE run --rm --no-deps -T --user 0:0 app find /var/www/html -xdev -user 0 -exec chown -h 1000:1000 {} +',
            $out,
        );
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
    public function rootless_docker_over_files_a_rootful_one_left_asks_the_host_to_fix_them(): void
    {
        // Switched from rootful to rootless: the service user is 0:0 (the
        // host user), which still cannot write files owned by host root.
        foreach ([''] as $configured) {
            // A non-root host whatever runs PHPUnit, and with or without an
            // explicit service uid: no container can chown host-root files.
            $out = $this->shell(
                'id(){ case "$1" in -u|-g) echo 1000;; *) echo dev;; esac; }; '
                . 'find(){ echo "$PROJECT_ROOT/var/cache"; }; run_compose(){ echo COMPOSE; }; project_ownership_reclaim; echo rc=$?',
                $configured,
                rootless: true,
            );

            self::assertStringNotContainsString('COMPOSE', $out, 'no container can chown as host root here');
            self::assertStringContainsString('left from a rootful Docker', $out);
            self::assertStringContainsString('sudo chown -R 1000:1000', $out);
            self::assertStringEndsWith('rc=1', $out);
        }
    }

    #[Test]
    public function a_nonzero_service_uid_under_rootless_docker_is_refused(): void
    {
        // Rootless: only container root is the host user; 1000 would map to a
        // subordinate id that cannot write the project, clean project or not.
        $out = $this->shell(
            'id(){ case "$1" in -u|-g) echo 1000;; *) echo dev;; esac; }; '
            . 'find(){ :; }; run_compose(){ echo COMPOSE; }; project_ownership_reclaim; echo rc=$?',
            "SEMITEXA_UID=1000\nSEMITEXA_GID=1000",
            rootless: true,
        );

        self::assertStringContainsString('SEMITEXA_UID=1000 under rootless Docker', $out);
        self::assertStringNotContainsString('COMPOSE', $out);
        self::assertStringEndsWith('rc=1', $out);
    }

    #[Test]
    public function files_left_after_the_handover_warn_with_the_cause_and_do_not_stop_the_start(): void
    {
        // A container still running from before a restart can write a file
        // between the handover and its own stop: a warning, not a refusal.
        $out = $this->shell(
            'find(){ echo "$PROJECT_ROOT/var/install"; }; run_compose(){ echo "compose said no" >&2; return 1; }; project_ownership_reclaim; echo rc=$?',
            "SEMITEXA_UID=1000\nSEMITEXA_GID=1000",
        );

        self::assertStringContainsString('compose said no', $out, 'the real cause stays on screen');
        self::assertStringContainsString('WARN: The ownership handover container failed', $out);
        self::assertStringContainsString('WARN: Project files the app (1000:1000) cannot write remain, e.g. var/install', $out);
        self::assertStringEndsWith('rc=0', $out);
    }

    #[Test]
    public function start_and_restart_reclaim_and_the_services_run_as_the_host_user(): void
    {
        $script = $this->script();
        foreach (['cmd_server_start', 'cmd_server_restart', 'cmd_test_run'] as $fn) {
            // The function's own body only: a pattern allowed to run on would
            // find the call in the NEXT function and stay green without it.
            self::assertSame(1, preg_match('/^' . $fn . '\(\) \{\n(.*?)^\}$/ms', $script, $body), "{$fn} must exist");
            self::assertMatchesRegularExpression(
                '/^\s+project_ownership_reclaim \|\| exit 1$/m',
                $body[1],
                "{$fn} must hand root-owned files back before containers write",
            );
        }
        // test:run: before the PHPUnit phase, not only before E2E.
        preg_match('/^cmd_test_run\(\) \{\n(.*?)^\}$/ms', $script, $testRun);
        $reclaimAt = strpos($testRun[1], 'project_ownership_reclaim');
        $phpunitAt = strpos($testRun[1], 'run --rm phpunit');
        self::assertIsInt($reclaimAt);
        self::assertIsInt($phpunitAt, 'test:run must still run the phpunit service');
        self::assertLessThan($phpunitAt, $reclaimAt, 'test:run must reclaim before the PHPUnit phase writes');

        $compose = (string) file_get_contents(dirname(__DIR__, 3) . '/docker-compose.yml');
        foreach (['app', 'scheduler', 'cli'] as $service) {
            self::assertMatchesRegularExpression(
                '/^  ' . $service . ':\n    build: \.\n(?:    #.*\n)*    user: "\$\{SEMITEXA_UID:-1000\}:\$\{SEMITEXA_GID:-1000\}"\n    environment:\n      HOME: \/var\/www\/html$/m',
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
