<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared local router is published on loopback only. Its DNS resolves *.test
 * to 127.0.0.1 and nowhere else, yet the proxy published 0.0.0.0:80: anyone on
 * the network could send a registered Host to port 80 and be proxied into that
 * project's dev stack (measured 2026-11-09: `curl -H 'Host: guard.test'
 * http://<lan ip>/` answered 200). And `local-router:restart` ran only
 * `compose restart`, which keeps a container's old ports, so a router already
 * running would never have picked the binding up.
 */
final class LocalRouterBindsLoopbackTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        $this->script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
    }

    #[Test]
    public function the_router_proxy_publishes_on_loopback_only(): void
    {
        $compose = $this->function('router_generate_compose');

        self::assertStringContainsString('- "127.0.0.1:80:80"', $compose);
        self::assertDoesNotMatchRegularExpression('/^\s*- "80:80"$/m', $compose, 'a bare 80:80 publishes on every interface');
        self::assertStringContainsString('- "127.0.0.1:5553:53/udp"', $compose);
    }

    #[Test]
    public function a_router_restart_applies_a_changed_compose_before_it_restarts(): void
    {
        $restart = $this->function('cmd_local_router_restart');

        foreach (['dns proxy', 'proxy'] as $services) {
            $up = strpos($restart, "router_compose up -d {$services}\n");
            $again = strpos($restart, "router_compose restart {$services}\n");
            self::assertNotFalse($up, "restart must run up -d {$services}: restart alone keeps the old ports");
            self::assertNotFalse($again);
            self::assertLessThan($again, $up);
        }
    }

    /**
     * The router workspace is shared by every project on the machine, and a
     * project with an older bin/semitexa renders it with "80:80" again. This
     * script cannot stop that, so it must notice and say what to do.
     */
    #[Test]
    public function a_router_reopened_by_an_older_script_is_reported_and_rebound(): void
    {
        self::assertSame(['1', 'The shared local router answers beyond this machine (0.0.0.0 [::]), not only on 127.0.0.1.'], $this->runWarn("0.0.0.0:80\n[::]:80"));
        // One LAN address is no safer than every interface: only loopback is local.
        self::assertSame(['1', 'The shared local router answers beyond this machine (192.168.1.100), not only on 127.0.0.1.'], $this->runWarn('192.168.1.100:80'));
        self::assertSame(['1', 'The shared local router answers beyond this machine (192.168.1.100), not only on 127.0.0.1.'], $this->runWarn("127.0.0.1:80\n192.168.1.100:80"));
        self::assertSame(['0', ''], $this->runWarn('127.0.0.1:80'));
        self::assertSame(['0', ''], $this->runWarn("127.0.0.1:80\n[::1]:80"));
        self::assertSame(['0', ''], $this->runWarn(''), 'a router that is not running is not a finding');
        // Before Docker Engine 28, 127.0.0.1 is still reachable from the same network segment.
        self::assertSame(['2', 'Docker Engine 27.5.1 does not keep a port published on 127.0.0.1 from hosts on the same network; 28.0 does.'], $this->runWarn('127.0.0.1:80', '27.5.1'));
        // Public on an old engine: both said, and a rebind is still worth it (1).
        [$code, $first, $all] = $this->runWarn('0.0.0.0:80', '27.5.1', true);
        self::assertSame('1', $code);
        self::assertStringStartsWith('The shared local router answers beyond this machine (0.0.0.0)', $first);
        self::assertStringContainsString('Docker Engine 27.5.1 does not keep', $all);
        self::assertStringNotContainsString('Rebinding', $all, 'the check reports; only start and restart rebind and say so');
        self::assertSame(['0', ''], $this->runWarn('127.0.0.1:80', '28.0.0'));

        foreach (['router_start_for_mode', 'cmd_local_router_restart'] as $caller) {
            $body = $this->function($caller);
            $warn = strpos($body, 'router_check_before_rebind');
            self::assertNotFalse($warn, "{$caller} checks the binding");
            self::assertLessThan(strpos($body, 'router_compose up -d'), $warn, "{$caller} checks before up -d rebinds");
        }
        $doctor = $this->function('cmd_local_router_doctor');
        self::assertStringContainsString('router_warn_if_public', $doctor);
        // It reports what it checked, not a boundary (a host routed to the bridge
        // reaches the container itself), and an old engine is not fixed by a restart.
        self::assertStringNotContainsString('answers this machine only', $doctor);
        self::assertStringContainsString('upgrade Docker Engine to 28 or later; a restart does not close this', $doctor);
    }

    /** @return array{0: string, 1: string, 2?: string} exit code, the first warning, and with $all everything said */
    private function runWarn(string $dockerPortOutput, string $engine = '29.8.1', bool $all = false): array
    {
        $dir = sys_get_temp_dir() . '/semitexa-router-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/docker', "#!/bin/sh\n[ \"\$1\" = version ] && { printf '%s\\n' " . escapeshellarg($engine) . "; exit 0; }\nprintf '%s\\n' " . escapeshellarg($dockerPortOutput) . " | sed '/^\$/d'\n");
        chmod($dir . '/docker', 0755);
        $script = "warn() { printf '%s\\n' \"\$*\"; }\n"
            . "router_proxy_published() {\n" . $this->function('router_proxy_published') . "}\n"
            . "router_warn_if_public() {\n" . $this->function('router_warn_if_public') . "}\n"
            . "out=\$(router_warn_if_public); code=\$?; printf '%s\\n%s\\n%s' \"\$code\" \"\$(printf '%s' \"\$out\" | head -n 1)\" \"\$out\"\n";
        $out = (string) shell_exec('PATH=' . escapeshellarg($dir) . ':/usr/bin:/bin sh -c ' . escapeshellarg($script));
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
        $lines = explode("\n", $out, 3);

        return $all ? [$lines[0], $lines[1] ?? '', $lines[2] ?? ''] : [$lines[0], $lines[1] ?? ''];
    }

    private function function(string $name): string
    {
        self::assertSame(1, preg_match('/^' . preg_quote($name, '/') . '\(\) \{\n(.*?)^\}\n/ms', $this->script, $m), "bin/semitexa defines {$name}()");

        return $m[1];
    }
}
