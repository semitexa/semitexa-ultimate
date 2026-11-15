<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The router renders "127.0.0.1:80:80", but every project renders the shared
 * router with its own bin/semitexa, and one from before 2026-11-09 writes "80:80"
 * (tk-router-loopback-network). A network made with
 * com.docker.network.bridge.host_binding_ipv4=127.0.0.1 publishes such a port on
 * loopback. Measured 2026-11-15 (Docker 29.8.1, Compose 5.5.1) on a scratch
 * project with a bare port: without that network 0.0.0.0 and [::]; with it, made
 * without Compose labels, 127.0.0.1 after up -d, restart and down+up, and a LAN
 * address refused. A labelled one is deleted by down. A second network joined at
 * the default gateway priority whose name sorts first took the binding back to
 * 0.0.0.0 at the next restart; joined at --gw-priority=-1 it did not.
 *
 * The functions run here as the script defines them, against a stub docker.
 */
final class RouterNetworkBindsLoopbackTest extends TestCase
{
    private const CREATE = 'network create -o com.docker.network.bridge.host_binding_ipv4=127.0.0.1 router_default';

    private string $script;

    protected function setUp(): void
    {
        $this->script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
    }

    #[Test]
    public function a_missing_network_is_made_on_loopback_without_compose_labels(): void
    {
        [$calls] = $this->ensure(inspect: null);

        self::assertSame(['inspect', self::CREATE], $calls);
    }

    #[Test]
    public function a_network_already_on_loopback_and_unlabelled_is_left_alone(): void
    {
        [$calls, $said] = $this->ensure(inspect: '127.0.0.1|');

        self::assertSame(['inspect'], $calls);
        self::assertSame('', $said);
    }

    #[Test]
    public function a_network_compose_made_is_replaced_with_the_router_down(): void
    {
        // Compose's own router_default (labelled, no option) and a loopback one Compose labelled,
        // which an older local-router:stop would delete.
        foreach (['|default', '127.0.0.1|default'] as $inspect) {
            [$calls] = $this->ensure(inspect: $inspect);
            self::assertSame(['inspect', 'compose down', 'network rm router_default', self::CREATE], $calls, $inspect);
        }
    }

    /**
     * One this script made without the option, because the engine refused it, is
     * kept: replacing it would take the router down on every start (Fable review).
     */
    #[Test]
    public function an_unlabelled_network_without_the_option_is_kept(): void
    {
        [$calls, $said] = $this->ensure(inspect: '|');

        self::assertSame(['inspect'], $calls);
        self::assertSame('', $said);
    }

    #[Test]
    public function a_network_that_cannot_be_removed_is_said_and_not_made_again(): void
    {
        [$calls, $said] = $this->ensure(inspect: '|default', rmFails: true);

        self::assertSame(['inspect', 'compose down', 'network rm router_default'], $calls);
        self::assertStringContainsString('Could not remove the router_default network: Error response from daemon: network has active endpoints', $said);
    }

    /**
     * The router compose declares router_default external, so up -d cannot start
     * the router without it: an engine that refuses the option still gets one
     * (Fable review: before this, Compose made the network itself).
     */
    #[Test]
    public function an_engine_that_refuses_the_option_still_gets_a_network(): void
    {
        [$calls, $said] = $this->ensure(inspect: null, optionRefused: true);

        self::assertSame(['inspect', self::CREATE, 'network create router_default'], $calls);
        self::assertStringContainsString('would not make router_default publish on 127.0.0.1', $said);
    }

    #[Test]
    public function the_proxy_joins_a_project_network_below_the_router_gateway(): void
    {
        $inspect = 'inspect -f {{with index .NetworkSettings.Networks "demo_default"}}{{.GwPriority}}{{end}} semitexa-router-proxy';

        [$calls] = $this->connect(priority: '');
        self::assertSame([$inspect, 'network connect --gw-priority=-1 demo_default semitexa-router-proxy'], $calls);

        // Docker before 28 has no --gw-priority (and no GwPriority to read): the proxy still joins.
        [$calls] = $this->connect(priority: '', gwPriorityKnown: false);
        self::assertSame([
            $inspect,
            'network connect --gw-priority=-1 demo_default semitexa-router-proxy',
            'network connect demo_default semitexa-router-proxy',
        ], $calls);

        // Joined at 0 by a script from 2026-11-11..14: made again at -1 (Fable review).
        [$calls] = $this->connect(priority: '0');
        self::assertSame([
            $inspect,
            'network disconnect demo_default semitexa-router-proxy',
            'network connect --gw-priority=-1 demo_default semitexa-router-proxy',
        ], $calls);

        // The router's own network keeps the default priority: at -1 it would tie with the others (review, installer#99).
        [$calls] = $this->connect(priority: '0', network: 'router_default');
        self::assertSame(['network connect router_default semitexa-router-proxy'], $calls);

        // Already below the router's gateway: left as it is.
        [$calls] = $this->connect(priority: '-1');
        self::assertSame([$inspect], $calls);

        self::assertSame(
            substr_count($this->function('router_connect_proxy'), 'docker network connect'),
            substr_count($this->script, 'docker network connect'),
            'every join goes through router_connect_proxy',
        );
        self::assertSame(3, substr_count($this->function('router_connect_proxy'), 'docker network connect'));
        self::assertStringContainsString('router_connect_proxy "$_rc_net"', $this->function('router_reconnect_all'));
        self::assertStringContainsString('router_connect_proxy "$_ra_net"', $this->function('router_attach_project'));
    }

    #[Test]
    public function the_router_compose_uses_that_network_and_start_and_restart_make_it_first(): void
    {
        $compose = $this->function('router_generate_compose');
        self::assertMatchesRegularExpression('/^networks:\n  default:\n    name: router_default\n    external: true$/m', $compose, 'external: a down leaves it in place');

        foreach (['router_start_for_mode', 'cmd_local_router_restart'] as $caller) {
            $body = $this->function($caller);
            $ensure = strpos($body, 'router_ensure_loopback_network');
            self::assertNotFalse($ensure, "{$caller} makes the network");
            $render = strpos($body, 'router_render');
            self::assertNotFalse($render, "{$caller} renders");
            self::assertLessThan($ensure, $render, "{$caller} renders first");
            self::assertLessThan(strpos($body, 'router_compose up -d'), $ensure, "{$caller} makes it before up -d, which needs it");
        }
    }

    /**
     * @param string|null $inspect what docker network inspect prints; null: no such network
     * @return array{list<string>, string} the docker and compose calls, and what was said
     */
    private function ensure(?string $inspect, bool $rmFails = false, bool $optionRefused = false): array
    {
        $docker = 'case "$1 $2" in'
            . "\n  'network inspect') echo inspect >>\"\$LOG\"; "
            . ($inspect === null ? 'exit 1' : "printf '%s\\n' " . escapeshellarg($inspect) . '; exit 0') . ' ;;'
            . "\n  'network rm') echo \"\$*\" >>\"\$LOG\"; " . ($rmFails ? "echo 'Error response from daemon: network has active endpoints' >&2; exit 1" : 'exit 0') . ' ;;'
            . "\n  'network create') echo \"\$*\" >>\"\$LOG\"; " . ($optionRefused ? "case \"\$*\" in *host_binding*) echo 'unsupported driver option' >&2; exit 1 ;; esac; " : '') . 'exit 0 ;;'
            . "\nesac\necho \"\$*\" >>\"\$LOG\"\n";

        return $this->runFunctions($docker, 'router_ensure_loopback_network');
    }

    /**
     * @param string $priority what docker inspect prints for the proxy's GwPriority on demo_default; '': not joined
     * @return array{list<string>, string}
     */
    private function connect(string $priority, bool $gwPriorityKnown = true, string $network = 'demo_default'): array
    {
        $docker = "echo \"\$*\" >>\"\$LOG\"\n"
            . '[ "$1" = inspect ] && { printf ' . escapeshellarg('%s') . ' ' . escapeshellarg($priority) . "; exit 0; }\n"
            . ($gwPriorityKnown ? '' : "case \"\$*\" in *--gw-priority*) echo 'unknown flag: --gw-priority' >&2; exit 125 ;; esac\n")
            . "exit 0\n";

        return $this->runFunctions($docker, 'router_connect_proxy ' . $network);
    }

    /** @return array{list<string>, string} */
    private function runFunctions(string $docker, string $call): array
    {
        $dir = sys_get_temp_dir() . '/semitexa-router-net-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/docker', "#!/bin/sh\n" . $docker);
        chmod($dir . '/docker', 0755);
        $script = "info() { printf '%s\\n' \"\$*\" >&2; }\nwarn() { printf '%s\\n' \"\$*\" >&2; }\n"
            . "router_compose() { echo \"compose \$*\" >>\"\$LOG\"; }\n"
            . "router_ensure_loopback_network() {\n" . $this->function('router_ensure_loopback_network') . "}\n"
            . "router_connect_proxy() {\n" . $this->function('router_connect_proxy') . "}\n"
            . $call . "\n";
        $process = proc_open(['sh', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => $dir . ':/usr/bin:/bin', 'LOG' => $dir . '/log']);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $calls = is_file($dir . '/log') ? array_values(array_filter(explode("\n", (string) file_get_contents($dir . '/log')))) : [];
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
        // Recreating says so; only a missing or failed network is a warning.
        $said = implode("\n", array_filter(explode("\n", $stderr), static fn (string $l): bool => !str_starts_with($l, 'Recreating')));

        return [$calls, $said];
    }

    private function function(string $name): string
    {
        self::assertSame(1, preg_match('/^' . preg_quote($name, '/') . '\(\) \{\n(.*?)^\}\n/ms', $this->script, $m), "bin/semitexa defines {$name}()");

        return $m[1];
    }
}
