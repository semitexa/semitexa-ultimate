<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How bin/semitexa writes var/run/worker.env, the service addresses the
 * worker container reads last, run as the shell runs it: the function is
 * extracted from the shipped bin/semitexa and given a project of the test's own.
 *
 * A file left from an earlier start may name services this one does not load.
 * When it could not be replaced, the start went on with it, in silence.
 */
final class WorkerEnvShellTest extends TestCase
{
    private const CANNOT = 'WARN: Cannot write %s/var/run/worker.env: the worker would start with the service addresses of an earlier start.';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-worker-env-' . uniqid();
        mkdir($this->root, 0o755, true);
        touch($this->root . '/docker-compose.worker.yml');
        touch($this->root . '/docker-compose.mysql.yml');
        file_put_contents($this->root . '/.env', "DB_DRIVER=mysql\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function it_writes_what_the_overlays_give_the_app(): void
    {
        self::assertSame([0, []], $this->write());
        self::assertSame(
            "# Written by bin/semitexa: the services the overlays give the app.\nDB_HOST=mysql\nDB_PORT=3306\n",
            file_get_contents($this->root . '/var/run/worker.env'),
        );
    }

    #[Test]
    public function a_file_it_cannot_replace_is_said_and_fails(): void
    {
        // A directory where the file goes: mv would move the new file into it and report success.
        mkdir($this->root . '/var/run/worker.env/kept', 0o755, true);

        self::assertSame([1, [sprintf(self::CANNOT, $this->root)]], $this->write());
        self::assertSame([], glob($this->root . '/var/run/worker.env/*.tmp.*') ?: [], 'nothing moved into it');
        self::assertSame([], glob($this->root . '/var/run/worker.env.tmp.*') ?: [], 'no temporary file left');
    }

    #[Test]
    public function a_project_without_the_worker_writes_nothing(): void
    {
        unlink($this->root . '/docker-compose.worker.yml');

        self::assertSame([0, []], $this->write());
        self::assertFileDoesNotExist($this->root . '/var/run/worker.env');
    }

    /** @return array{int, list<string>} the exit status and what it said, one line each */
    private function write(): array
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
        preg_match_all('/^(env_val|compose_write_worker_env)\(\) \{\n.*?^\}$/ms', $script, $m);
        self::assertCount(2, $m[0], 'both functions must still exist in bin/semitexa');

        $sh = 'warn(){ printf "WARN: %s\n" "$*"; }' . "\n"
            . 'env_merged_lines(){ cat "$PROJECT_ROOT/.env"; }' . "\n"
            . implode("\n", $m[0]) . "\ncompose_write_worker_env; echo \"status=\$?\"";
        $out = (string) shell_exec(sprintf(
            'env -i PATH=/usr/bin:/bin PROJECT_ROOT=%s sh -c %s 2>&1',
            escapeshellarg($this->root),
            escapeshellarg($sh),
        ));
        $lines = array_values(array_filter(explode("\n", $out), static fn (string $line): bool => $line !== ''));
        $status = (int) substr((string) array_pop($lines), strlen('status='));

        return [$status, $lines];
    }
}
