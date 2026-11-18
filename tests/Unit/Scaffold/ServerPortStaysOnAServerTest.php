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
 * local_app_reconcile() and local_app_register_logic() run as bin/semitexa has
 * them; what they touch (the lock, the registry entry, the routed domains, the
 * host) is stubbed and reported: a free port is always 9501.
 */
final class ServerPortStaysOnAServerTest extends TestCase
{
    private const STUBS = <<<'SH'
        env_val() { case "$1" in APP_ENV) printf '%s' "$T_APP_ENV" ;; SWOOLE_PORT) printf '%s' "$T_PORT" ;; SEMITEXA_APP_ID) printf 'app-123456' ;; *) printf '%s' "$2" ;; esac; }
        env_upsert_kv() { printf 'UPSERT %s=%s\n' "$2" "$3"; }
        app_validate_app_id() { return 0; }
        router_ensure_app_dirs() { :; }
        router_lock_acquire() { :; }
        router_lock_release() { :; }
        router_app_prune_gone_projects() { :; }
        router_app_entry_exists() { return 1; }
        router_app_entry_path() { :; }
        port_range_start() { printf '9501'; }
        port_range_end() { printf '9599'; }
        port_in_range() { case "$1" in ''|*[!0-9]*) return 1 ;; esac; [ "$1" -ge 9501 ] && [ "$1" -le 9599 ]; }
        port_owned_by_other_app() { [ "$1" = "$T_TAKEN" ]; }
        port_owner_name() { [ "$1" = "$T_TAKEN" ] && printf 'guard'; return 0; }
        port_in_use_on_host_excluding_self() { [ "$1" = "${T_BOUND:-}" ]; }
        allocate_free_port() { printf '9501'; }
        router_app_write_entry() { printf 'RECORD %s %s\n' "$1" "$2" >&2; }
        router_apply_app_port_to_domains() { printf 'ROUTE %s\n' "$2" >&2; }
        warn() { printf 'WARN %s\n' "$*" >&2; }
        info() { printf 'INFO %s\n' "$*"; }
        error() { printf 'ERROR %s\n' "$*" >&2; }
        ROUTER_CONFIG_FILE=/home/dev/.semitexa/router/config.env
        SH;

    private const RANGE_HINT = 'The range is SEMITEXA_LOCAL_PORT_RANGE_START/END in /home/dev/.semitexa/router/config.env; under APP_ENV=prod the port is never moved.';

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: list<string>, 4?: string}> */
    public static function starts(): iterable
    {
        // verify:accept-test-change the register logic is no longer a stub that only echoed its port: every case now asserts what the real one records, routes and warns (6 cases, was 4)
        // Review, installer#102 / update#105: kept, and still recorded and routed.
        yield 'a server keeps the port its proxy expects, recorded and routed' => ['prod', '9000', '', [
            'RECORD app-123456 9000',
            'ROUTE 9000',
        ]];
        yield 'a server keeps its port even when another app has it registered, and says so' => ['prod', '9502', '9502', [
            'WARN Port 9502 is also registered to another local Semitexa app; kept, since APP_ENV=prod.',
            'RECORD app-123456 9502',
            'ROUTE 9502',
        ]];
        yield 'a server asking for auto is given a port' => ['prod', 'auto', '', [
            'RECORD app-123456 9501',
            'ROUTE 9501',
            'INFO Swoole port for app app-123456 assigned to 9501.',
            'UPSERT SWOOLE_PORT=9501',
        ]];
        yield 'a server with no port yet is given one' => ['prod', '', '', [
            'RECORD app-123456 9501',
            'ROUTE 9501',
            'INFO Swoole port for app app-123456 assigned to 9501.',
            'UPSERT SWOOLE_PORT=9501',
        ]];
        // verify:accept-test-change a start no longer repeats the move in three warnings (tk-first-start-warns-about-an-ordinary-port-choice): register says nothing, reconcile says it once
        yield 'a workstation port outside the range is moved, and the warning says why and where' => ['dev', '9000', '', [
            'RECORD app-123456 9501',
            'ROUTE 9501',
            'WARN SWOOLE_PORT in .env changed from 9000 to 9501: it is outside the local range 9501-9599. ' . self::RANGE_HINT,
            'UPSERT SWOOLE_PORT=9501',
        ]];
        // The second project on a machine: every project ships 9502, so this is an ordinary move, said once.
        yield 'a workstation port taken by another app is moved, said in one line naming it' => ['dev', '9502', '9502', [
            'RECORD app-123456 9501',
            'ROUTE 9501',
            'INFO Port 9502 is used by guard; using 9501, saved to .env.',
            'UPSERT SWOOLE_PORT=9501',
        ]];
        yield 'a workstation port bound by another process is moved, said in one line' => ['dev', '9502', '', [
            'RECORD app-123456 9501',
            'ROUTE 9501',
            'INFO Port 9502 is used by another process on this machine; using 9501, saved to .env.',
            'UPSERT SWOOLE_PORT=9501',
        ], '9502'];
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('starts')]
    public function the_port_is_moved_on_a_workstation_and_never_on_a_server(string $appEnv, string $port, string $takenByAnother, array $expected, string $boundOnHost = ''): void
    {
        $functions = $this->function('local_app_register_logic') . "\n" . $this->function('local_app_reconcile');
        $project = sys_get_temp_dir() . '/port-reconcile-' . bin2hex(random_bytes(6));
        mkdir($project);
        touch($project . '/.env');
        try {
            $sh = self::STUBS . "\nPROJECT_ROOT=" . escapeshellarg($project) . "\n" . $functions . "\nlocal_app_reconcile 2>&1; printf 'EXIT %s\\n' \"\$?\"";
            $out = (string) shell_exec(sprintf(
                'env -i PATH=/usr/bin:/bin T_APP_ENV=%s T_PORT=%s T_TAKEN=%s T_BOUND=%s sh -c %s',
                escapeshellarg($appEnv),
                escapeshellarg($port),
                escapeshellarg($takenByAnother),
                escapeshellarg($boundOnHost),
                escapeshellarg($sh),
            ));
        } finally {
            unlink($project . '/.env');
            rmdir($project);
        }

        self::assertSame([...$expected, 'EXIT 0'], array_values(array_filter(explode("\n", $out), static fn (string $line): bool => $line !== '')));
    }

    private function function(string $name): string
    {
        self::assertSame(1, preg_match('/^' . $name . '\(\) \{\n.*?^\}$/ms', $this->script(), $match), $name . ' must still exist in bin/semitexa');
        $function = $match[0] ?? '';
        self::assertStringStartsWith($name . '() {', $function);

        return $function;
    }

    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
    }
}
