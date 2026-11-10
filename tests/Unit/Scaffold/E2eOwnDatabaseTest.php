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
        self::assertSame('', $this->unavailable([]), 'mysql overlay, the e2e file and an update with --no-scaffold: isolated');
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
        $start = strpos($phase, '_e2e_isolated="$(e2e_app_start');
        $trap = strpos($phase, 'trap "docker rm -f');
        $preflight = strpos($phase, 'e2e_runner_preflight "$_compose" "$_e2e_runner_overlays"');
        self::assertNotFalse($start);
        self::assertNotFalse($trap);
        self::assertNotFalse($preflight, 'the runner is checked in the E2E app namespace too');
        self::assertLessThan($trap, $start);
        self::assertLessThan($preflight, $trap, 'the trap is set before any later step can exit');
        self::assertStringContainsString('warn "E2E runs against your running app and writes into its database: $_e2e_why."', $phase);
    }

    /**
     * Runs e2e_isolation_unavailable against stubs.
     *
     * @param array{overlays?: string, e2eFile?: bool, updateHelp?: string, env?: array<string, string>, shell?: array<string, string>} $o
     */
    private function unavailable(array $o): string
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

        $out = $this->sh($root, $shell
            . "e2e_isolation_unavailable " . escapeshellarg($bin . '/compose') . ' ' . escapeshellarg($o['overlays'] ?? '-f docker-compose.yml -f docker-compose.mysql.yml -f docker-compose.test.yml') . "\n");

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
