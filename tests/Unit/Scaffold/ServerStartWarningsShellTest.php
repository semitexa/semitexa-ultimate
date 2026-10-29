<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What server:start warns about before the containers start, run as the shell
 * runs it: the function is extracted from the shipped bin/semitexa and given
 * an .env of the test's own.
 *
 * Two of them start fine and go wrong later:
 * - SWOOLE_WORKER_NUM=1 in production (tk-single-worker-recycle-warning):
 *   nothing serves while the worker is recycled, and with SWOOLE_MAX_REQUEST=0
 *   too Swoole gives it no manager, so a recycle ends the whole server.
 * - DB_USERNAME=root with the mysql service (tk-pip-db-root-user): the image
 *   refuses root as its user, but only on a new volume, so it surfaces on the
 *   first deploy to a fresh host, not where the .env was written.
 */
final class ServerStartWarningsShellTest extends TestCase
{
    private const ONE_WORKER = 'WARN: APP_ENV=prod with SWOOLE_WORKER_NUM=1: while the worker is recycled (SWOOLE_MAX_REQUEST, '
        . 'the memory watchdog, a fatal error) nothing serves and every open connection is cut. '
        . 'Set SWOOLE_WORKER_NUM=2 or more in .env.';

    private const NO_MANAGER = 'WARN: APP_ENV=prod with SWOOLE_WORKER_NUM=1 and SWOOLE_MAX_REQUEST=0: the worker runs without '
        . 'a manager, so a memory-watchdog recycle or a fatal error ends the whole server until the container is '
        . 'started again. Set SWOOLE_WORKER_NUM=2 or more in .env.';

    private const DB_ROOT = 'WARN: DB_USERNAME=root: the mysql service creates DB_USERNAME as its user and refuses root, '
        . 'so on a new database volume it never starts. Set another DB_USERNAME in .env (the scaffold uses '
        . "semitexa); root's password is DB_ROOT_PASSWORD.";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-start-warnings-' . uniqid();
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function one_worker_in_production_is_warned(): void
    {
        self::assertSame([self::ONE_WORKER], $this->warnings("APP_ENV=prod\nAPP_DEBUG=0\nSWOOLE_WORKER_NUM=1\n"));
    }

    #[Test]
    public function one_worker_without_a_request_limit_is_warned_that_a_recycle_ends_the_server(): void
    {
        self::assertSame([self::NO_MANAGER], $this->warnings("APP_ENV=prod\nAPP_DEBUG=0\nSWOOLE_WORKER_NUM=1\nSWOOLE_MAX_REQUEST=0\n"));
        self::assertSame([self::ONE_WORKER], $this->warnings("APP_ENV=prod\nAPP_DEBUG=0\nSWOOLE_WORKER_NUM=1\nSWOOLE_MAX_REQUEST=0010\n"), 'a count with a leading zero is still a count');
        self::assertSame([self::ONE_WORKER], $this->warnings("APP_ENV=prod\nAPP_DEBUG=0\nSWOOLE_WORKER_NUM=01\nSWOOLE_MAX_REQUEST=+5\n"), 'read as the server reads them: 1 worker, 5 requests');
        self::assertSame([self::ONE_WORKER], $this->warnings("APP_ENV=prod\nAPP_DEBUG=0\nSWOOLE_WORKER_NUM=1\nSWOOLE_MAX_REQUEST=10k\n"));
    }

    /** The server reads an unset APP_ENV as prod; so does server:start. */
    #[Test]
    public function an_unset_app_env_is_production(): void
    {
        self::assertSame([self::ONE_WORKER], $this->warnings("APP_DEBUG=0\nSWOOLE_WORKER_NUM=1\n"));
    }

    #[Test]
    public function one_worker_in_development_or_several_in_production_is_not(): void
    {
        self::assertSame([], $this->warnings("APP_ENV=dev\nSWOOLE_WORKER_NUM=1\n"));
        self::assertSame([], $this->warnings("APP_ENV=prod\nAPP_DEBUG=0\nSWOOLE_WORKER_NUM=2\n"));
        self::assertSame([], $this->warnings("APP_ENV=prod\nAPP_DEBUG=0\n"), 'unset is the default 4');
    }

    #[Test]
    public function the_debug_warning_it_already_gave_is_still_given(): void
    {
        self::assertSame(
            ['WARN: APP_ENV=prod with APP_DEBUG on: error pages show details. Set APP_DEBUG=0 in .env.'],
            $this->warnings("APP_ENV=prod\nAPP_DEBUG=1\n"),
        );
    }

    #[Test]
    public function root_as_the_database_user_of_the_mysql_service_is_warned(): void
    {
        touch($this->root . '/docker-compose.mysql.yml');

        self::assertSame([self::DB_ROOT], $this->warnings("APP_ENV=dev\nDB_DRIVER=mysql\nDB_USERNAME=root\n"));
        self::assertSame([], $this->warnings("APP_ENV=dev\nDB_DRIVER=mysql\nDB_USERNAME=semitexa\n"));
        self::assertSame([], $this->warnings("APP_ENV=dev\nDB_DRIVER=mysql\n"), 'unset is the scaffold\'s semitexa');
    }

    /** A database of the project's own, not the mysql service, may well be reached as root. */
    #[Test]
    public function root_without_the_mysql_service_is_not(): void
    {
        self::assertSame([], $this->warnings("APP_ENV=dev\nDB_DRIVER=mysql\nDB_USERNAME=root\n"));
    }

    /** @return list<string> the warnings, one a line */
    private function warnings(string $env): array
    {
        file_put_contents($this->root . '/.env', $env);
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
        preg_match_all('/^(env_val|server_start_config_warnings)\(\) \{\n.*?^\}$/ms', $script, $m);
        self::assertCount(2, $m[0], 'both functions must still exist in bin/semitexa');

        $sh = 'warn(){ printf "WARN: %s\n" "$*"; }' . "\n"
            . 'env_merged_lines(){ cat "$PROJECT_ROOT/.env"; }' . "\n"
            . implode("\n", $m[0]) . "\nserver_start_config_warnings; echo \"status=\$?\"";
        $out = (string) shell_exec(sprintf(
            'env -i PATH=/usr/bin:/bin PROJECT_ROOT=%s sh -c %s 2>&1',
            escapeshellarg($this->root),
            escapeshellarg($sh),
        ));

        $lines = array_values(array_filter(explode("\n", $out), static fn (string $line): bool => $line !== ''));
        // Asserted first: a check that failed and printed nothing read as "no warnings".
        self::assertSame('status=0', array_pop($lines), $out);

        return $lines;
    }
}
