<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tk-server-start-rewrites-a-servers-port (reported by the Guard project):
 * server:start reconciles SWOOLE_PORT against the workstation's local router
 * and rewrote any port outside 9501-9599 in .env, whatever APP_ENV. On a server
 * where nginx proxies a fixed port that is an outage nobody is told about, and
 * the warning named neither the range nor where it is set.
 *
 * local_app_reconcile() is run as bin/semitexa has it, its collaborators stubbed:
 * the registry always offers 9501.
 */
final class ServerPortStaysOnAServerTest extends TestCase
{
    private const STUBS = <<<'SH'
        env_val() { case "$1" in APP_ENV) printf '%s' "$T_APP_ENV" ;; SWOOLE_PORT) printf '%s' "$T_PORT" ;; SEMITEXA_APP_ID) printf 'app-123456' ;; *) printf '%s' "$2" ;; esac; }
        env_upsert_kv() { printf 'UPSERT %s=%s\n' "$2" "$3"; }
        local_app_register_logic() { printf 'REGISTER %s\n' "$2" >&2; printf '9501'; }
        port_in_range() { [ "$1" -ge 9501 ] && [ "$1" -le 9599 ]; }
        port_range_start() { printf '9501'; }
        port_range_end() { printf '9599'; }
        warn() { printf 'WARN %s\n' "$*"; }
        info() { printf 'INFO %s\n' "$*"; }
        ROUTER_CONFIG_FILE=/home/dev/.semitexa/router/config.env
        SH;

    private const RANGE_HINT = 'The range is SEMITEXA_LOCAL_PORT_RANGE_START/END in /home/dev/.semitexa/router/config.env; under APP_ENV=prod the port is never moved.';

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function starts(): iterable
    {
        yield 'a server keeps the port its proxy expects' => ['prod', '9000', []];
        yield 'a workstation port outside the range is moved, and the warning says why and where' => ['dev', '9000', [
            'REGISTER 9000',
            'WARN SWOOLE_PORT in .env changed from 9000 to 9501: it is outside the local range 9501-9599. ' . self::RANGE_HINT,
            'UPSERT SWOOLE_PORT=9501',
        ]];
        yield 'a workstation port taken by another app is moved, and the warning says so' => ['dev', '9502', [
            'REGISTER 9502',
            'WARN SWOOLE_PORT in .env changed from 9502 to 9501: it is taken by another app or process. ' . self::RANGE_HINT,
            'UPSERT SWOOLE_PORT=9501',
        ]];
        yield 'a server with no port yet is given one' => ['prod', '', [
            'REGISTER ',
            'INFO Swoole port for app app-123456 assigned to 9501.',
            'UPSERT SWOOLE_PORT=9501',
        ]];
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('starts')]
    public function the_port_is_moved_on_a_workstation_and_never_on_a_server(string $appEnv, string $port, array $expected): void
    {
        self::assertSame(1, preg_match('/^local_app_reconcile\(\) \{\n.*?^\}$/ms', $this->script(), $match), 'local_app_reconcile must still exist in bin/semitexa');
        $function = $match[0] ?? '';
        self::assertStringStartsWith('local_app_reconcile() {', $function);
        $project = sys_get_temp_dir() . '/port-reconcile-' . bin2hex(random_bytes(6));
        mkdir($project);
        touch($project . '/.env');
        try {
            $sh = self::STUBS . "\nPROJECT_ROOT=" . escapeshellarg($project) . "\n" . $function . "\nlocal_app_reconcile 2>&1; printf 'EXIT %s\\n' \"\$?\"";
            $out = (string) shell_exec(sprintf(
                'env -i PATH=/usr/bin:/bin T_APP_ENV=%s T_PORT=%s sh -c %s',
                escapeshellarg($appEnv),
                escapeshellarg($port),
                escapeshellarg($sh),
            ));
        } finally {
            unlink($project . '/.env');
            rmdir($project);
        }

        self::assertSame([...$expected, 'EXIT 0'], array_values(array_filter(explode("\n", $out), static fn (string $line): bool => $line !== '')));
    }

    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
    }
}
