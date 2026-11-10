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

    /**
     * A check that cannot read what it needs says so and gives no all-clear
     * (review, ultimate#133): docker port failing for another reason than "no
     * such container" leaves the binding unknown; an engine that will not say
     * its version is unknown, not new enough. Both return 3, and doctor prints
     * no success for 3.
     */
    #[Test]
    public function a_check_docker_would_not_answer_is_unknown_not_clean(): void
    {
        [$code, $first, $all] = $this->runWarn(null, '29.8.1', true);
        self::assertSame('3', $code);
        self::assertStringStartsWith('Could not read which addresses the router proxy publishes port 80 on: permission denied while trying to connect', $first);

        [$code, $first] = $this->runWarn('127.0.0.1:80', null);
        self::assertSame('3', $code);
        self::assertStringStartsWith('Could not read the Docker Engine version', $first);
        self::assertStringNotContainsString('upgrade', $first, 'no upgrade advice for a version nobody read');

        // Public is still said, and a rebind still worth it (1), with the version unknown.
        [$code, $first, $all] = $this->runWarn('0.0.0.0:80', null, true);
        self::assertSame('1', $code);
        self::assertStringStartsWith('The shared local router answers beyond this machine (0.0.0.0)', $first);
        self::assertStringContainsString('Could not read the Docker Engine version', $all);

        // Podman behind the docker CLI reports its own version: not "Docker too old", unknown (review, update#79).
        [$code, $first] = $this->runWarn('127.0.0.1:80', 'Podman Engine|5.4.0');
        self::assertSame('3', $code);
        self::assertSame('The engine is Podman Engine 5.4.0, not Docker Engine: whether 127.0.0.1 keeps the router to this machine is not checked here.', $first);
        self::assertSame('1', $this->runWarn('0.0.0.0:80', 'Podman Engine|5.4.0')[0], 'public stays public: a rebind still helps');
        // An engine that names no platform: its version decides. 27.5.1 is a Docker Engine
        // number (YY.MM), 5.4.0 is not one Docker ever had (review, update#79).
        self::assertSame('2', $this->runWarn('127.0.0.1:80', '|27.5.1')[0]);
        self::assertSame(['3', 'The engine is 5.4.0, not Docker Engine: whether 127.0.0.1 keeps the router to this machine is not checked here.'], $this->runWarn('127.0.0.1:80', '|5.4.0'));
        // A Podman name built from OS, architecture and distribution has no "Docker" in it either.
        self::assertSame('3', $this->runWarn('127.0.0.1:80', 'linux/amd64/fedora-41|5.4.0')[0]);

        // A CLI warning beside a successful answer is not an address (review, installer#76).
        self::assertSame(['0', ''], $this->runWarn('127.0.0.1:80', '29.8.1', false, 0, 'WARNING: Error loading config file: /root/.docker/config.json: unexpected end of JSON input'));

        // No proxy container is "not running", not a failure.
        self::assertSame(['0', ''], $this->runWarn("Error response from daemon: No such container: semitexa-router-proxy\n", '29.8.1', false, 1));

        $doctor = $this->function('cmd_local_router_doctor');
        self::assertStringContainsString('absent:*)', $doctor);
        self::assertMatchesRegularExpression('/\*:0\) success /', $doctor, 'success only for a 0');
        self::assertStringNotContainsString('router_proxy_published', $this->script, 'one reader of the binding: router_proxy_inspect');
    }

    #[Test]
    public function the_warnings_go_to_stderr_and_nothing_to_stdout(): void
    {
        [, $stdout, $stderr] = $this->runScript('0.0.0.0:80', '27.5.1', 0);

        self::assertSame('', $stdout, 'doctor > file must not swallow the warnings');
        self::assertStringContainsString('[WARN]  The shared local router answers beyond this machine (0.0.0.0)', $stderr);
        self::assertStringContainsString('[WARN]  Docker Engine 27.5.1 does not keep', $stderr);
    }

    /**
     * @param string|null $dockerPortOutput null: docker port fails with a daemon error
     * @param string|null $engine null: docker version fails
     * @return array{0: string, 1: string, 2?: string} exit code, the first warning, and with $all everything said
     */
    private function runWarn(?string $dockerPortOutput, ?string $engine = '29.8.1', bool $all = false, int $portExit = 0, string $portStderr = ''): array
    {
        [$code, , $stderr] = $this->runScript($dockerPortOutput, $engine, $portExit, $portStderr);
        $said = (string) preg_replace('/^\[WARN\]  /m', '', rtrim($stderr, "\n"));
        $first = explode("\n", $said, 2)[0];

        return $all ? [$code, $first, $said] : [$code, $first];
    }

    /**
     * router_warn_if_public as the script defines it, with its own warn(), against a stub docker.
     *
     * @return array{string, string, string} exit code, stdout, stderr
     */
    private function runScript(?string $dockerPortOutput, ?string $engine, int $portExit, string $portStderr = ''): array
    {
        $dir = sys_get_temp_dir() . '/semitexa-router-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $version = $engine === null
            ? "echo 'Error response from daemon: authorization denied by plugin' >&2; exit 1"
            // The script asks '{{.Server.Platform.Name}}|{{.Server.Version}}': a bare version is Docker Engine's.
            : "printf '%s\\n' " . escapeshellarg(str_contains($engine, '|') ? $engine : 'Docker Engine - Community|' . $engine) . '; exit 0';
        $port = $dockerPortOutput === null
            ? "echo 'permission denied while trying to connect to the Docker daemon socket' >&2; exit 1"
            : ($portExit === 0
                ? ($portStderr === '' ? '' : "printf '%s\\n' " . escapeshellarg($portStderr) . ' >&2; ')
                    . "printf '%s\\n' " . escapeshellarg($dockerPortOutput) . " | sed '/^\$/d'"
                : "printf '%s' " . escapeshellarg($dockerPortOutput) . " >&2; exit {$portExit}");
        file_put_contents($dir . '/docker', "#!/bin/sh\n[ \"\$1\" = version ] && { {$version}; }\n{$port}\n");
        chmod($dir . '/docker', 0755);
        self::assertSame(1, preg_match('/^warn\(\)\s+\{.*\}$/m', $this->script, $warn), 'bin/semitexa defines warn()');
        $script = "C_YELLOW=''; C_RESET=''\n" . $warn[0] . "\n"
            . "router_proxy_inspect() {\n" . $this->function('router_proxy_inspect') . "}\n"
            . "router_warn_if_public() {\n" . $this->function('router_warn_if_public') . "}\n"
            . "router_warn_if_public; exit \$?\n";
        $process = proc_open(['sh', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => $dir . ':/usr/bin:/bin']);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);

        return [(string) $code, $stdout, $stderr];
    }

    private function function(string $name): string
    {
        self::assertSame(1, preg_match('/^' . preg_quote($name, '/') . '\(\) \{\n(.*?)^\}\n/ms', $this->script, $m), "bin/semitexa defines {$name}()");

        return $m[1];
    }
}
