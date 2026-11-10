<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The E2E phase runs on an app and a database of its own (tk-e2e-own-database).
 *
 * The runner drove the developer's running app, so a run's specs wrote into
 * the database the developer works in (67 guestbook rows on 07.11). The phase
 * now starts the app service once more with its own database, runtime dirs,
 * cache namespace and NATS namespaces, and falls back to the old way, said out
 * loud, only when it cannot. These tests drive the script's own functions.
 */
final class E2eOwnDatabaseTest extends TestCase
{
    private string $script;

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    #[Test]
    public function the_phase_runs_on_its_own_app_where_it_can(): void
    {
        // Proven by the function's own "available" status, not by an empty output a
        // harness that stopped early would give too (review, ultimate#135).
        self::assertSame('AVAILABLE', $this->unavailable([], true), 'mysql overlay, the e2e file and an update with --no-scaffold: isolated');
    }

    #[Test]
    public function it_names_why_when_it_cannot(): void
    {
        self::assertStringContainsString('no bundled MySQL', $this->unavailable(['overlays' => '-f docker-compose.yml -f docker-compose.test.yml']));
        self::assertStringContainsString('docker-compose.e2e.yml is missing', $this->unavailable(['e2eFile' => false]));
        self::assertStringContainsString('has no --no-scaffold yet', $this->unavailable(['updateHelp' => 'Usage: update [--no-composer]']));
        self::assertStringContainsString('neither copy nor clean', $this->unavailable(['env' => ['SEMITEXA_E2E_DB' => 'mirror']]));
    }

    #[Test]
    public function the_e2e_database_is_never_the_live_the_test_or_a_system_one(): void
    {
        foreach (['semitexa', 'semitexa_test', 'mysql', 'sys', 'information_schema', 'performance_schema'] as $name) {
            self::assertStringContainsString("DB_E2E_DATABASE '{$name}' is the live, the test or a MySQL system database", $this->unavailable(['env' => ['DB_E2E_DATABASE' => $name]]), $name);
        }
        self::assertStringContainsString('not a plain identifier', $this->unavailable(['env' => ['DB_E2E_DATABASE' => 'e2e;drop']]));
        // Names are read as Compose reads them: .env wins over the caller.
        self::assertStringContainsString("DB_E2E_DATABASE 'live_db' is the live", $this->unavailable(['env' => ['DB_DATABASE' => 'live_db', 'DB_E2E_DATABASE' => 'live_db'], 'shell' => ['DB_DATABASE' => 'stale']]));
    }

    #[Test]
    public function its_app_gets_its_own_database_namespaces_and_runtime_dirs_by_relative_path(): void
    {
        $args = $this->appArgs(['DB_E2E_DATABASE' => 'my_e2e']);

        self::assertStringContainsString('-e DB_DATABASE=my_e2e', $args);
        foreach (['CACHE_ENV=e2e', 'NATS_QUEUE_STREAM=QUEUE_E2E', 'NATS_QUEUE_SUBJECT_PREFIX=e2e.queue.', 'LEDGER_STREAM=EVENTS_E2E', 'LEDGER_SUBJECT_PREFIX=e2e.events'] as $env) {
            self::assertStringContainsString('-e ' . $env, $args);
        }
        foreach (['run', 'runtime', 'cache', 'lock', 'log', 'tmp'] as $dir) {
            self::assertStringContainsString("-v ./var/e2e/{$dir}:/var/www/html/var/{$dir}", $args);
        }
        self::assertStringNotContainsString('/var/www/html/var/storage', $args, 'project data under var/ stays shared');
        self::assertStringNotContainsString('-v /', $args, 'relative mounts: a project path with a space cannot split them');
    }

    #[Test]
    public function a_failed_copy_fails_the_run_and_the_app_goes_on_every_exit(): void
    {
        $reset = $this->function('e2e_database_reset');
        self::assertStringContainsString('bash -o pipefail -c', $reset);
        self::assertMatchesRegularExpression('/set -e\n\s+mysqldump /', $reset, 'the dump runs under set -e');
        self::assertStringContainsString('|| { grep -v "Using a password" /tmp/e2e-dump.err /tmp/e2e-import.err >&2; exit 1; }', $reset);
        self::assertStringContainsString('TRUNCATE $t failed', $reset, 'an outbox left full would be sent again');
        self::assertStringContainsString('does not exist after creating it', $reset);

        $phase = (string) strstr($this->script, "Phase 2/2 — E2E tests");
        // In the caller's shell, not $(...): a TERM interrupts the wait within a
        // second instead of when a command substitution returns (review, installer#80).
        $start = strpos($phase, 'e2e_app_start "$_compose" "$_overlays" || exit 1');
        self::assertStringNotContainsString('$(e2e_app_start', $phase);
        self::assertStringContainsString('curl -fsS --connect-timeout 1 --max-time 2', $this->function('e2e_app_start'), 'one probe cannot hang the wait');
        $trap = strpos($phase, 'trap "docker rm -f');
        $preflight = strpos($phase, 'e2e_runner_preflight "$_compose" "$_e2e_runner_overlays"');
        self::assertNotFalse($start);
        self::assertNotFalse($trap);
        self::assertNotFalse($preflight, 'the runner is checked in the E2E app namespace too');
        // Before the app starts (review, ultimate#135): Ctrl-C in the 90 s it takes
        // to answer left its container running.
        self::assertLessThan($start, $trap, 'the trap is set before the app starts');
        self::assertLessThan($preflight, $start);
        self::assertStringContainsString('_e2e_cleanup="$(e2e_container_name)"', $phase);
        self::assertStringContainsString('warn "E2E runs against your running app and writes into its database: $_e2e_why."', $phase);
    }

    #[Test]
    public function a_term_while_the_app_has_not_answered_ends_the_wait_and_removes_the_container(): void
    {
        // Review, installer#80: a probe that never returns, and a TERM to the script.
        // The phase's own lines run here, from the trap to the start.
        $phase = (string) strstr($this->script, "Phase 2/2 — E2E tests");
        self::assertSame(1, preg_match('/^(\s+_e2e_cleanup="\$\(e2e_container_name\)".*?^\s+e2e_app_start "\$_compose" "\$_overlays" \|\| exit 1\n)/ms', $phase, $block), 'the phase sets its trap, then starts the app');

        $root = $this->root([], true);
        $bin = $root . '/stub';
        mkdir($bin);
        $log = $root . '/docker.log';
        // docker: logs every call. The readiness probe (exec ... curl) is an app that
        // takes the connection and never answers: curl waits out its --max-time and
        // gives up with 28, as the real one does; the call must carry that bound.
        file_put_contents($bin . '/docker', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($log) . "\ncase \"\$1\" in exec) case \"\$*\" in *'--max-time 2'*) sleep 2; exit 28 ;; *) exec sleep 60 ;; esac ;; esac\nexit 0\n");
        file_put_contents($bin . '/compose', "#!/bin/sh\nexit 0\n");
        chmod($bin . '/docker', 0755);
        chmod($bin . '/compose', 0755);
        preg_match_all('/^[a-z_][a-z0-9_]*\(\) \{\n.*?^\}\n/ms', $this->script, $all);
        self::assertSame(1, preg_match('/^E2E_RUNTIME_DIRS=.*$/m', $this->script, $dirs));
        $script = "PROJECT_ROOT=" . escapeshellarg($root) . "\ncd \"\$PROJECT_ROOT\"\n" . $dirs[0] . "\nC_RED=''; C_RESET=''\n"
            . implode('', $all[0])
            . "_compose=" . escapeshellarg($bin . '/compose') . "; _overlays='-f docker-compose.yml'\n"
            . $block[1] . "echo STARTED\n";
        file_put_contents($root . '/harness.sh', $script);

        $process = proc_open(['sh', $root . '/harness.sh'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, ['PATH' => $bin . ':/usr/bin:/bin', 'HOME' => $root]);
        self::assertIsResource($process);
        // Until the probe is hanging.
        for ($i = 0; $i < 50 && !str_contains((string) @file_get_contents($log), 'exec'); $i++) {
            usleep(100_000);
        }
        self::assertStringContainsString('exec', (string) @file_get_contents($log), 'the probe ran');
        $sentAt = microtime(true);
        proc_terminate($process, 15);
        $status = proc_get_status($process);
        while ($status['running'] && microtime(true) - $sentAt < 10) {
            usleep(100_000);
            $status = proc_get_status($process);
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertFalse($status['running'], 'a TERM ends the wait');
        self::assertLessThan(5.0, microtime(true) - $sentAt, 'within the probe timeout, not the 90 s wait');
        self::assertStringNotContainsString('STARTED', $stdout);
        $calls = (string) file_get_contents($log);
        self::assertMatchesRegularExpression('/^rm -f semitexa-e2e-\S+$/m', substr($calls, (int) strpos($calls, 'exec')), 'the container is removed after the TERM');
    }

    /**
     * Runs e2e_isolation_unavailable against stubs.
     *
     * @param array{overlays?: string, e2eFile?: bool, updateHelp?: string, env?: array<string, string>, shell?: array<string, string>} $o
     */
    private function unavailable(array $o, bool $status = false): string
    {
        $root = $this->root($o['env'] ?? [], $o['e2eFile'] ?? true);
        $bin = $root . '/stub';
        mkdir($bin);
        // The compose "binary": answers the update --help probe.
        file_put_contents($bin . '/compose', "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg($o['updateHelp'] ?? "Options:\n  --no-composer\n  --no-scaffold") . "\n");
        chmod($bin . '/compose', 0755);
        $shell = '';
        foreach ($o['shell'] ?? [] as $k => $v) {
            $shell .= "{$k}=" . escapeshellarg($v) . "; export {$k}\n";
        }

        $call = "e2e_isolation_unavailable " . escapeshellarg($bin . '/compose') . ' ' . escapeshellarg($o['overlays'] ?? '-f docker-compose.yml -f docker-compose.mysql.yml -f docker-compose.test.yml');
        // 1 with nothing said is "available"; a marker says it reached that return.
        $out = $this->sh($root, $shell . ($status ? "if {$call}; then :; else echo AVAILABLE; fi\n" : $call . " || true\n"));

        return trim($out);
    }

    /** @param array<string, string> $env */
    private function appArgs(array $env): string
    {
        return $this->sh($this->root($env, true), "e2e_app_args\n");
    }

    /** @param array<string, string> $env */
    private function root(array $env, bool $e2eFile): string
    {
        $root = sys_get_temp_dir() . '/semitexa-e2e-db-' . bin2hex(random_bytes(4));
        mkdir($root);
        $this->cleanup[] = $root;
        $lines = ['DB_DATABASE=semitexa', 'DB_TEST_DATABASE=semitexa_test'];
        foreach ($env as $k => $v) {
            $lines = array_values(array_filter($lines, static fn (string $l): bool => !str_starts_with($l, $k . '=')));
            $lines[] = "{$k}={$v}";
        }
        file_put_contents($root . '/.env', implode("\n", $lines) . "\n");
        if ($e2eFile) {
            file_put_contents($root . '/docker-compose.e2e.yml', "services: {}\n");
        }

        return $root;
    }

    private function sh(string $root, string $body): string
    {
        // Every function the script defines, so a helper they call is never missing;
        // only the ones the test names are run.
        preg_match_all('/^[a-z_][a-z0-9_]*\(\) \{\n.*?^\}\n/ms', $this->script, $all);
        $functions = implode('', $all[0]);
        self::assertStringContainsString('e2e_isolation_unavailable() {', $functions);
        self::assertSame(1, preg_match('/^E2E_RUNTIME_DIRS=.*$/m', $this->script, $dirs));
        $script = "set -e\nPROJECT_ROOT=" . escapeshellarg($root) . "\ncd \"\$PROJECT_ROOT\"\n" . $dirs[0] . "\n" . $functions . $body;
        // A file, not sh -c: every function together is longer than one argument may be.
        file_put_contents($root . '/harness.sh', $script);
        $process = proc_open(['sh', $root . '/harness.sh'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, ['PATH' => '/usr/bin:/bin', 'HOME' => $root]);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        self::assertStringNotContainsString('not found', $stderr, 'every helper the functions call is defined: ' . $stderr);

        return $stdout;
    }

    private function function(string $name): string
    {
        self::assertSame(1, preg_match('/^' . preg_quote($name, '/') . '\(\) \{\n(.*?)^\}\n/ms', $this->script, $m), "bin/semitexa defines {$name}()");

        return $m[1];
    }
}
