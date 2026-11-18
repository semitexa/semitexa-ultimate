<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tk-registry-writer-refusal-stops-registration. These are CodeRabbit's
 * outside-diff comments on installer#111, update#113 and ultimate#165, the
 * follow-ups of tk-registry-entry-with-a-space-runs-a-word.
 *
 * - The app writer refuses a root with a line break. A registration went on
 *   past the refusal: under set -e it stopped with the lock held, and a
 *   reconcile saved a port the registry never recorded.
 * - Two places still read an entry raw, router_attach_project and the domain
 *   writer's preserved container route. For a CRLF entry they matched nothing
 *   or handed on a carriage return.
 *
 * The functions run as bin/semitexa has them. Docker, compose and the
 * allocation are stubbed.
 */
final class ARefusedRegistryWriteStopsTheRegistrationTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/registry-refusal-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/apps', 0o777, true);
        mkdir($this->tmp . '/domains');
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    #[Test]
    public function a_root_the_registry_refuses_gets_no_port_and_leaves_the_lock_free(): void
    {
        $stubs = 'app_validate_app_id() { return 0; }; router_app_entry_exists() { return 1; }; router_ensure_app_dirs() { mkdir -p "$ROUTER_APP_REGISTRY_DIR" "$ROUTER_LOCK_DIR"; }' . "\n"
            . 'port_range_start() { printf 9501; }; port_range_end() { printf 9599; }; port_in_range() { return 0; }; port_owned_by_other_app() { return 1; }' . "\n"
            . 'port_in_use_on_host_excluding_self() { return 1; }; allocate_free_port() { printf 9501; }' . "\n"
            . 'router_apply_app_port_to_domains() { printf "ROUTED %s\n" "$2"; }; warn() { printf "WARN %s\n" "$*"; }; error() { printf "ERROR %s\n" "$*"; }' . "\n"
            . 'ROUTER_LOCK_DIR=locks' . "\n";
        // The writer's own check, recording first whether the lock is held: "free" afterwards means
        // released only if it was taken (review, ultimate#166).
        $stubs .= 'registry_value_is_one_line() { [ -d locks/registry.lock ] && echo "held at the write"; case "$1" in *"' . "\n" . '"*) return 1 ;; esac; return 0; }' . "\n";
        $functions = ['registry_entry_load', 'router_app_entry_path', 'router_app_write_entry', 'router_lock_acquire', 'router_lock_release', 'local_app_register_logic'];

        // As local-app:register runs it: under set -e, the result not checked.
        $out = $this->shell($functions, $stubs
            . '( set -e; local_app_register_logic app-123456 "" ' . escapeshellarg("/home/dev/app\nSWOOLE_PORT=80") . '; echo "went on" ); printf "exit=%s\n" "$?"' . "\n"
            . '[ -d locks/registry.lock ] && echo held || echo free');

        self::assertSame([
            'held at the write',
            "ERROR The project's directory has a line break in its name, which the local app registry cannot record: rename the directory.",
            'exit=1',
            'free',
        ], $out, 'no port printed, no route made, and the lock back');
        self::assertSame([], glob($this->tmp . '/apps/*.env'));
    }

    #[Test]
    public function attaching_reads_an_entry_with_crlf_line_ends_as_the_router_does(): void
    {
        file_put_contents($this->tmp . '/domains/shop.test.env', "DOMAIN=shop.test\r\nPROJECT_ROOT=/home/dev/shop\r\nUPSTREAM_HOST=host.docker.internal\r\nUPSTREAM_PORT=09555\r\n");
        file_put_contents($this->tmp . '/domains/other.test.env', "DOMAIN=other.test\nPROJECT_ROOT=/home/dev/other\nUPSTREAM_PORT=9600\n");

        $out = $this->shell(['registry_entry_load', 'router_validate_domain', 'router_port_normalize', 'router_attach_project'],
            'project_has_shared_domains() { return 0; }; compose_bin() { echo compose_stub; }; compose_args() { :; }; compose_stub() { echo cid-1; }' . "\n"
            . 'docker() { case "$*" in *semitexa-router-proxy*) return 0 ;; *".Name"*) echo /shop-app-1 ;; *com.docker.compose.project*) echo shop ;; *Networks*) echo shop_default ;; esac; }' . "\n"
            . 'router_connect_proxy() { :; }; router_proxy_networks() { echo shop_default; }; env_val() { printf 8080; }; router_mode_get() { printf shared; }' . "\n"
            . 'router_registry_write_entry() { printf "WRITE %s\n" "$*"; }; router_generate_nginx_config() { :; }; router_reload_proxy() { :; }' . "\n"
            . 'PROJECT_ROOT=/home/dev/shop; router_attach_project; printf "root=%s\n" "$PROJECT_ROOT"');

        self::assertSame(['WRITE shop.test 9555 shared shop-app-1 shop_default 8080', 'root=/home/dev/shop'], $out, "this project's route moves to its new container; the other project's is left");
    }

    #[Test]
    public function the_domain_writer_keeps_a_crlf_entrys_container_route_without_the_carriage_returns(): void
    {
        file_put_contents($this->tmp . '/domains/shop.test.env', "DOMAIN=shop.test\r\nPROJECT_ROOT=/home/dev/shop\r\nUPSTREAM_CONTAINER=shop-app-1\r\nUPSTREAM_NETWORK=shop_default\r\nUPSTREAM_CONTAINER_PORT=8080\r\n");

        $this->shell(['registry_entry_load', 'registry_value_is_one_line', 'router_entry_key', 'router_entry_path', 'router_registry_write_entry'],
            'router_ensure_dirs() { :; }; error() { printf "ERROR %s\n" "$*"; }' . "\n"
            . 'PROJECT_ROOT=/home/dev/shop; router_registry_write_entry shop.test 9555 shared');

        $entry = (string) file_get_contents($this->tmp . '/domains/shop.test.env');
        self::assertStringContainsString("UPSTREAM_CONTAINER=shop-app-1\nUPSTREAM_NETWORK=shop_default\nUPSTREAM_CONTAINER_PORT=8080\n", $entry, 'the route is kept');
        self::assertStringNotContainsString("\r", $entry, 'and handed on without a carriage return');
    }

    /**
     * Review, ultimate#165: the loader fails on an entry it cannot read, and
     * under set -e that failure stopped the render, so the routes after it,
     * and DNS, were never written. It is left out with a word now.
     */
    #[Test]
    public function an_entry_it_cannot_read_is_left_out_and_the_render_goes_on(): void
    {
        file_put_contents($this->tmp . '/domains/a.test.env', "DOMAIN=a.test\nUPSTREAM_HOST=host.docker.internal\nUPSTREAM_PORT=9555\n");
        chmod($this->tmp . '/domains/a.test.env', 0o000);
        file_put_contents($this->tmp . '/domains/shop.test.env', "DOMAIN=shop.test\nUPSTREAM_HOST=host.docker.internal\nUPSTREAM_PORT=9556\n");
        // Root reads a file whatever its mode: there both routes are written.
        $root = function_exists('posix_geteuid') && posix_geteuid() === 0;

        $out = $this->shell(['registry_entry_load', 'router_validate_domain', 'router_port_normalize', 'router_route_admit'],
            $this->region('router_generate_nginx_config() {', 'router_generate_compose() {') . "\n"
            . 'router_ensure_dirs() { :; }; router_proxy_networks() { :; }; warn() { printf "WARN %s\n" "$*"; }' . "\n"
            . 'ROUTER_NGINX_DEFAULT=default.conf; ROUTER_NGINX_DOMAINS=domains.conf; ROUTER_DNSMASQ_CONF=dnsmasq.conf; ROUTER_DNS_DOMAINS=dns.conf' . "\n"
            . '( set -e; router_generate_nginx_config; router_generate_dns_config; echo rendered )');

        self::assertSame($root ? ['rendered'] : ['WARN Left out of the local router: a.test.env cannot be read.', 'rendered'], $out);
        // The exact set each renderer wrote (review, ultimate#166).
        $domains = $root ? ['a.test', 'shop.test'] : ['shop.test'];
        preg_match_all('/server_name (\S+);/', (string) file_get_contents($this->tmp . '/domains.conf'), $nginx);
        self::assertSame($domains, $nginx[1]);
        self::assertSame(implode('', array_map(static fn (string $d): string => "address=/{$d}/127.0.0.1\n", $domains)), file_get_contents($this->tmp . '/dns.conf'));
    }

    /** Review, ultimate#165: a quoted root with a comment after it kept the quotes and the comment, and the app was never found by its path. */
    #[Test]
    public function a_quoted_root_with_a_comment_is_found_by_its_path(): void
    {
        file_put_contents($this->tmp . '/apps/app-123456.env', "APP_ID=app-123456\nPROJECT_ROOT='/home/dev/My Projects/shop' # workstation\nSWOOLE_PORT=9555\n");

        self::assertSame(['app-123456'], $this->shell(['registry_entry_load', 'router_app_find_by_project'],
            'router_app_find_by_project "/home/dev/My Projects/shop"; echo'));
    }

    /**
     * Review, ultimate#166: a name the writers took from a directory or from
     * Docker is written back unquoted, so it has to read as written. Quotes and
     * " #" are kept.
     */
    #[Test]
    public function a_name_a_writer_took_from_docker_or_a_directory_reads_as_written(): void
    {
        // A container name cannot hold a blank or a "#": a hand-edited comment after one goes (Fable's review).
        file_put_contents($this->tmp . '/domains/shop.test.env', "DOMAIN=shop.test\nPROJECT_NAME=\"weird\"\nCOMPOSE_PROJECT_NAME=my #app\nUPSTREAM_CONTAINER=shop-app-1 # moved\nUPSTREAM_NETWORK=shop #blue\n");

        self::assertSame(['"weird"', 'my #app', 'shop-app-1', 'shop #blue'], $this->shell(['registry_entry_load'],
            'registry_entry_load ' . escapeshellarg($this->tmp . '/domains/shop.test.env') . '; printf "%s\n" "$PROJECT_NAME" "$COMPOSE_PROJECT_NAME" "$UPSTREAM_CONTAINER" "$UPSTREAM_NETWORK"'));
    }

    /**
     * Review, installer#112: an entry was written in place, so a write cut
     * short left half of it and lost the one before. Each writer now writes
     * beside the entry and moves the whole into place. In round 2 the domain
     * writer's optional route set the status, and a cut-short base write was
     * moved in all the same. The cut is a cat that writes part and fails, so
     * it is the same with or without root.
     */
    #[Test]
    public function a_write_cut_short_keeps_the_entry_before_it_byte_for_byte(): void
    {
        $app = "APP_ID=app-123456\nPROJECT_ROOT=/home/dev/app\nPROJECT_NAME=app\nSWOOLE_PORT=9555\n";
        $domain = "DOMAIN=shop.test\nPROJECT_ROOT=/home/dev/shop\nUPSTREAM_HOST=host.docker.internal\nUPSTREAM_PORT=9556\n";
        file_put_contents($this->tmp . '/apps/app-123456.env', $app);
        file_put_contents($this->tmp . '/domains/shop.test.env', $domain);

        $out = $this->shell(['registry_entry_load', 'registry_value_is_one_line', 'router_app_entry_path', 'router_app_write_entry', 'router_entry_key', 'router_entry_path', 'router_registry_write_entry'],
            'router_ensure_app_dirs() { :; }; router_ensure_dirs() { :; }; error() { printf "ERROR %s\n" "$*"; }' . "\n"
            // A full disk: part of the entry is written, then cat fails.
            . 'cat() { printf "APP_ID=part"; return 1; }' . "\n"
            . 'router_app_write_entry app-123456 9600 /home/dev/app; printf "app=%s\n" "$?"' . "\n"
            // A host-only entry: no container route whose test could hide the failure.
            . 'PROJECT_ROOT=/home/dev/shop; router_registry_write_entry shop.test 9601 shared; printf "domain=%s\n" "$?"');

        self::assertSame([
            "ERROR Could not write the local app registry's entry for app-123456; the one before is kept.",
            'app=1',
            "ERROR Could not write the local router's entry for shop.test; the one before is kept.",
            'domain=1',
        ], $out);
        self::assertSame($app, file_get_contents($this->tmp . '/apps/app-123456.env'), 'the app entry, byte for byte');
        self::assertSame($domain, file_get_contents($this->tmp . '/domains/shop.test.env'), 'the domain entry, byte for byte');
        self::assertSame([], array_merge(glob($this->tmp . '/apps/*.new.*') ?: [], glob($this->tmp . '/domains/*.new.*') ?: []), 'nothing is left beside them');
    }

    /** Review, installer#112, round 2: mv -f moves a file into a directory at its target and says it worked. */
    #[Test]
    public function a_directory_at_an_entry_path_is_refused(): void
    {
        mkdir($this->tmp . '/apps/app-777777.env');
        mkdir($this->tmp . '/domains/shop.test.env');

        $out = $this->shell(['registry_entry_load', 'registry_value_is_one_line', 'router_app_entry_path', 'router_app_write_entry', 'router_entry_key', 'router_entry_path', 'router_registry_write_entry'],
            'router_ensure_app_dirs() { :; }; router_ensure_dirs() { :; }; error() { printf "ERROR %s\n" "$*"; }' . "\n"
            . 'router_app_write_entry app-777777 9600 /home/dev/app; printf "app=%s\n" "$?"' . "\n"
            . 'PROJECT_ROOT=/home/dev/shop; router_registry_write_entry shop.test 9601 shared; printf "domain=%s\n" "$?"');

        self::assertSame([
            "ERROR Could not write the local app registry's entry for app-777777; the one before is kept.",
            'app=1',
            "ERROR Could not write the local router's entry for shop.test; the one before is kept.",
            'domain=1',
        ], $out);
        self::assertSame([], array_merge(glob($this->tmp . '/apps/app-777777.env/*') ?: [], glob($this->tmp . '/domains/shop.test.env/*') ?: []), 'nothing went inside the directories');
    }

    /**
     * Review, update#114 and ultimate#166, round 2: a hand-edited quoted
     * container name kept its quotes, the renderer cleared it, and the route
     * fell back to the host port. No writer starts a Docker name with a
     * quote, so a quoted one is the text between the quotes.
     */
    #[Test]
    public function a_quoted_container_or_network_name_reads_without_its_quotes(): void
    {
        file_put_contents($this->tmp . '/domains/shop.test.env', "DOMAIN=shop.test\nUPSTREAM_CONTAINER=\"demo-app\" # local route\nUPSTREAM_NETWORK='demo_default'\n");

        self::assertSame(['demo-app', 'demo_default'], $this->shell(['registry_entry_load'],
            'registry_entry_load ' . escapeshellarg($this->tmp . '/domains/shop.test.env') . '; printf "%s\n" "$UPSTREAM_CONTAINER" "$UPSTREAM_NETWORK"'));
    }

    /**
     * Review, update#114 and ultimate#166: the networks the proxy is on were
     * split at a blank before the Compose label was asked, so detach left a
     * network named with one connected.
     */
    #[Test]
    public function detach_asks_about_each_network_the_proxy_is_on_whole(): void
    {
        $this->shell(['registry_entry_load', 'router_detach_project'],
            'PROJECT_ROOT=/home/dev/shop' . "\n"
            . 'compose_bin() { echo compose_stub; }; compose_args() { :; }; compose_stub() { echo cid-1; }' . "\n"
            . 'router_proxy_networks() { printf "router_default\napp dev\nother_net\n"; }' . "\n"
            // network inspect names the network as its fifth word; the container's own label is "shop".
            . 'docker() { case "$1 $2" in "network disconnect") printf "%s\n" "$4" >> disconnected; return 0 ;; "inspect semitexa-router-proxy") return 0 ;; "network inspect") case "$5" in "app dev") echo shop ;; *) echo other ;; esac; return 0 ;; esac; case "$*" in *com.docker.compose.project*) echo shop ;; esac; }' . "\n"
            . 'router_detach_project');

        self::assertSame("app dev\n", @file_get_contents($this->tmp . '/disconnected'), "this project's network, whole; router_default and another project's left");
    }

    /** The script from one line up to, not including, another. */
    private function region(string $from, string $to): string
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
        $start = strpos($script, "\n" . $from);
        $end = strpos($script, "\n" . $to);
        self::assertIsInt($start, $from . ' must still be in bin/semitexa');
        self::assertIsInt($end, $to . ' must still be in bin/semitexa');

        return substr($script, $start + 1, $end - $start);
    }

    /**
     * @param list<string> $functions the bin/semitexa functions the snippet needs
     * @return list<string> the lines it printed
     */
    private function shell(array $functions, string $snippet): array
    {
        $sh = 'ROUTER_APP_REGISTRY_DIR=' . escapeshellarg($this->tmp . '/apps') . "\n"
            . 'ROUTER_REGISTRY_DIR=' . escapeshellarg($this->tmp . '/domains') . "\n"
            . implode("\n", array_map($this->function(...), $functions)) . "\n" . $snippet;
        $raw = (string) shell_exec(sprintf('cd %s && env -i PATH=/usr/bin:/bin sh -c %s 2>&1', escapeshellarg($this->tmp), escapeshellarg($sh)));

        return explode("\n", str_ends_with($raw, "\n") ? substr($raw, 0, -1) : $raw);
    }

    private function function(string $name): string
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
        self::assertSame(1, preg_match('/^' . $name . '\(\) \{\n.*?^\}$/ms', $script, $match), $name . ' must still exist in bin/semitexa');

        return $match[0] ?? '';
    }
}
