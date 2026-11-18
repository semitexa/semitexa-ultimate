<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tk-registry-entry-with-a-space-runs-a-word: every reader of the local
 * registries (~/.semitexa/router/registry/apps and /domains) sourced the entry.
 * An entry holds the project's root unquoted. When sourced:
 * - a root with $(...) or a backtick in it ran that command on every start;
 * - a root with a space ran the rest of the path as a command, and the project
 *   was never found by its root.
 *
 * registry_entry_load() and the readers run as bin/semitexa has them, on
 * registries in a temporary directory.
 */
final class ARegistryEntryIsReadAsDataTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/registry-data-' . bin2hex(random_bytes(6));
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
    public function a_root_that_holds_shell_runs_nothing_and_is_read_as_it_is(): void
    {
        $root = '/home/dev/My Projects/app$(touch ' . $this->tmp . '/ran)`touch ' . $this->tmp . '/ran2`';
        $this->appEntry('app-123456', $root, 'app', '9555');

        $out = $this->shell(
            ['registry_entry_load'],
            'registry_entry_load ' . escapeshellarg($this->tmp . '/apps/app-123456.env') . '; printf "%s\n" "$PROJECT_ROOT" "$APP_ID" "$SWOOLE_PORT"',
        );

        self::assertSame([$root, 'app-123456', '9555'], $out);
        self::assertFileDoesNotExist($this->tmp . '/ran', 'the $(...) in the root ran');
        self::assertFileDoesNotExist($this->tmp . '/ran2', 'the backtick in the root ran');
    }

    #[Test]
    public function a_project_whose_root_has_a_space_is_found_by_it(): void
    {
        $this->appEntry('app-123456', '/home/dev/My Projects/app', 'app', '9555');

        self::assertSame(['app-123456'], $this->shell(
            ['registry_entry_load', 'router_app_find_by_project'],
            'router_app_find_by_project "/home/dev/My Projects/app"; echo',
        ));
    }

    #[Test]
    public function the_project_that_holds_a_port_is_named_without_running_its_root(): void
    {
        $this->appEntry('app-other1', '/home/dev/x$(touch ' . $this->tmp . '/ran)', 'other', '9555');

        self::assertSame(['other'], $this->shell(
            ['registry_entry_load', 'port_owner_name'],
            'port_owner_name 9555 app-123456; echo',
        ));
        self::assertFileDoesNotExist($this->tmp . '/ran');
    }

    #[Test]
    public function a_domain_entry_is_read_the_same_way(): void
    {
        file_put_contents($this->tmp . '/domains/shop.test.env', "DOMAIN=shop.test\nPROJECT_ROOT=/home/dev/a b\$(touch {$this->tmp}/ran)\nUPSTREAM_PORT=9555\nMODE=shared\n");

        $out = $this->shell(
            ['registry_entry_load'],
            'registry_entry_load ' . escapeshellarg($this->tmp . '/domains/shop.test.env') . '; printf "%s\n" "$DOMAIN" "$PROJECT_ROOT" "$UPSTREAM_PORT" "$MODE"',
        );

        self::assertSame(['shop.test', '/home/dev/a b$(touch ' . $this->tmp . '/ran)', '9555', 'shared'], $out);
        self::assertFileDoesNotExist($this->tmp . '/ran');
    }

    #[Test]
    public function quotes_a_hand_edit_added_are_dropped_and_other_keys_ignored(): void
    {
        file_put_contents($this->tmp . '/apps/app-123456.env', "APP_ID=app-123456\nPROJECT_NAME=\"my app\"\nPROJECT_ROOT='/home/dev/my app'\nPATH=/nowhere\nSWOOLE_PORT=9555\n");

        self::assertSame(['my app', '/home/dev/my app', '/usr/bin:/bin'], $this->shell(
            ['registry_entry_load'],
            'registry_entry_load ' . escapeshellarg($this->tmp . '/apps/app-123456.env') . '; printf "%s\n" "$PROJECT_NAME" "$PROJECT_ROOT" "$PATH"',
        ));
    }

    /** As sourcing did: a key the entry does not have is left as the reader set it. */
    #[Test]
    public function a_key_the_entry_lacks_is_left_as_it_was(): void
    {
        file_put_contents($this->tmp . '/domains/b.test.env', "DOMAIN=b.test\n");

        self::assertSame(['b.test', 'kept'], $this->shell(
            ['registry_entry_load'],
            'COMPOSE_PROJECT_NAME=kept; registry_entry_load ' . escapeshellarg($this->tmp . '/domains/b.test.env') . '; printf "%s\n" "$DOMAIN" "$COMPOSE_PROJECT_NAME"',
        ));
    }

    #[Test]
    public function an_entry_that_is_gone_sets_nothing_and_says_so(): void
    {
        self::assertSame(['1', 'kept'], $this->shell(
            ['registry_entry_load'],
            'APP_ID=kept; registry_entry_load ' . escapeshellarg($this->tmp . '/apps/none.env') . '; printf "%s\n" "$?" "$APP_ID"',
        ));
    }

    /** What a hand edit or another editor leaves is read as sourcing read it (Fable's review). */
    #[Test]
    public function a_hand_edited_entry_is_read_as_sourcing_read_it(): void
    {
        file_put_contents($this->tmp . '/domains/shop.test.env', "DOMAIN=shop.test\r\n  MODE=shared\nexport UPSTREAM_PORT=9555\n\tUPSTREAM_HOST=host.docker.internal");

        self::assertSame(['shop.test', 'shared', '9555', 'host.docker.internal'], $this->shell(
            ['registry_entry_load'],
            'registry_entry_load ' . escapeshellarg($this->tmp . '/domains/shop.test.env') . '; printf "%s\n" "$DOMAIN" "$MODE" "$UPSTREAM_PORT" "$UPSTREAM_HOST"',
        ));
    }

    #[Test]
    public function an_entry_it_cannot_read_sets_nothing_and_stops_nothing(): void
    {
        file_put_contents($this->tmp . '/apps/app-123456.env', "APP_ID=app-123456\n");
        chmod($this->tmp . '/apps/app-123456.env', 0o000);
        // Root reads a file whatever its mode, so there the entry is read. Both
        // runs go on, which is what the guard is for: dash and bash stopped on
        // a redirect they could not open.
        $root = function_exists('posix_geteuid') && posix_geteuid() === 0;

        self::assertSame($root ? ['0', 'app-123456', 'went on'] : ['1', 'kept', 'went on'], $this->shell(
            ['registry_entry_load'],
            'APP_ID=kept; registry_entry_load ' . escapeshellarg($this->tmp . '/apps/app-123456.env') . '; printf "%s\n" "$?" "$APP_ID" "went on"',
        ));
    }

    /** The one way left to put keys of its own into an entry is closed where entries are written (Fable's review). */
    #[Test]
    public function a_directory_with_a_line_break_in_its_name_is_not_recorded(): void
    {
        $root = "/home/dev/app\nSWOOLE_PORT=80";

        $out = $this->shell(
            ['registry_entry_load', 'registry_value_is_one_line', 'router_app_entry_path', 'router_app_write_entry'],
            'router_ensure_app_dirs() { mkdir -p "$ROUTER_APP_REGISTRY_DIR"; }; error() { printf "ERROR %s\n" "$*"; }' . "\n"
                . 'router_app_write_entry app-123456 9555 ' . escapeshellarg($root) . '; printf "%s\n" "$?"',
        );

        self::assertSame(["ERROR The project's directory has a line break in its name, which the local app registry cannot record: rename the directory.", '1'], $out);
        self::assertSame([], glob($this->tmp . '/apps/*.env'));
    }

    #[Test]
    public function the_router_renders_only_what_the_writers_write(): void
    {
        file_put_contents($this->tmp . '/domains/shop.test.env', "DOMAIN=shop.test\nUPSTREAM_HOST=host.docker.internal\nUPSTREAM_PORT=9555\nUPSTREAM_CONTAINER=shop-app-1;evil\nUPSTREAM_NETWORK=shop_default\nUPSTREAM_CONTAINER_PORT=9555\n");
        file_put_contents($this->tmp . '/domains/a.test.env', "DOMAIN=a.test; } server { listen 80\nUPSTREAM_HOST=host.docker.internal\nUPSTREAM_PORT=9555\n");
        file_put_contents($this->tmp . '/domains/b.test.env', "DOMAIN=b.test\nUPSTREAM_HOST=host.docker.internal\nUPSTREAM_PORT=9555; return 302 http://elsewhere\n");
        // proxy_pass http://:9555 made nginx refuse the whole configuration (Fable's review).
        file_put_contents($this->tmp . '/domains/c.test.env', "DOMAIN=c.test\nUPSTREAM_PORT=9555\n");

        // Both write a heredoc with a closing brace of its own: taken whole, up to the function after them.
        $renderers = $this->region('router_generate_nginx_config() {', 'router_generate_compose() {');
        $out = $this->shell(
            ['registry_entry_load', 'router_validate_domain'],
            $renderers . "\n"
                . 'router_ensure_dirs() { :; }; router_proxy_networks() { printf "shop_default\n"; }; warn() { printf "WARN %s\n" "$*"; }' . "\n"
                . 'ROUTER_NGINX_DEFAULT=' . escapeshellarg($this->tmp . '/default.conf') . '; ROUTER_NGINX_DOMAINS=' . escapeshellarg($this->tmp . '/domains.conf') . "\n"
                . 'ROUTER_DNSMASQ_CONF=' . escapeshellarg($this->tmp . '/dnsmasq.conf') . '; ROUTER_DNS_DOMAINS=' . escapeshellarg($this->tmp . '/dns-domains.conf') . "\n"
                . 'router_generate_nginx_config; router_generate_dns_config',
        );

        self::assertSame([
            'WARN Left out of the local router: a.test.env does not name a .test domain.',
            'WARN Left out of the local router: b.test.env holds an upstream that is not a host and a port.',
            'WARN Left out of the local router: c.test.env holds an upstream that is not a host and a port.',
        ], $out);
        $nginx = (string) file_get_contents($this->tmp . '/domains.conf');
        self::assertSame(1, substr_count($nginx, 'server_name '), $nginx);
        self::assertStringContainsString('server_name shop.test;', $nginx);
        self::assertStringContainsString('proxy_pass http://host.docker.internal:9555;', $nginx, 'a container name that is not one falls back to the host port');
        self::assertStringNotContainsString('evil', $nginx);
        // A .test domain resolves here whatever its upstream; one that is not a domain is not written.
        self::assertSame("address=/b.test/127.0.0.1\naddress=/c.test/127.0.0.1\naddress=/shop.test/127.0.0.1\n", file_get_contents($this->tmp . '/dns-domains.conf'));
    }

    /**
     * Fable's review: the domain check ran for every entry and set the callers'
     * _domain to the last entry's, so local-domain:remove aaa.test took
     * zzz.test's line out of /etc/hosts.
     */
    #[Test]
    public function rendering_the_router_leaves_the_callers_domain_as_it_was(): void
    {
        file_put_contents($this->tmp . '/domains/zzz.test.env', "DOMAIN=zzz.test\nUPSTREAM_HOST=host.docker.internal\nUPSTREAM_PORT=9555\n");

        self::assertSame(['aaa.test'], $this->shell(
            ['registry_entry_load', 'router_validate_domain'],
            $this->region('router_generate_nginx_config() {', 'router_generate_compose() {') . "\n"
                . 'router_ensure_dirs() { :; }; router_proxy_networks() { :; }; warn() { :; }' . "\n"
                . 'ROUTER_NGINX_DEFAULT=default.conf; ROUTER_NGINX_DOMAINS=domains.conf; ROUTER_DNSMASQ_CONF=dnsmasq.conf; ROUTER_DNS_DOMAINS=dns.conf' . "\n"
                . '_domain=aaa.test; router_generate_nginx_config; router_generate_dns_config; printf "%s\n" "$_domain"',
        ));
    }

    #[Test]
    public function the_router_reads_a_projects_entries_under_a_home_with_a_space(): void
    {
        mkdir($this->tmp . '/John Smith/domains', 0o777, true);
        file_put_contents($this->tmp . '/John Smith/domains/shop.test.env', "DOMAIN=shop.test\nPROJECT_ROOT=/home/dev/shop\nUPSTREAM_NETWORK=shop_default\n");

        $this->shell(
            ['router_detach_project'],
            'ROUTER_REGISTRY_DIR=' . escapeshellarg($this->tmp . '/John Smith/domains') . '; PROJECT_ROOT=/home/dev/shop' . "\n"
                // The disconnect's own output goes to /dev/null: the stub writes it down instead.
                . 'docker() { case "$1 $2" in "network disconnect") printf "%s\n" "$4" >> disconnected ;; "inspect semitexa-router-proxy") return 0 ;; esac; return 1; }' . "\n"
                . 'compose_bin() { echo false; }; compose_args() { :; }; router_proxy_networks() { :; }' . "\n"
                . 'router_detach_project',
        );

        self::assertSame("shop_default\n", @file_get_contents($this->tmp . '/disconnected'), 'the network named in the entry is let go');
    }

    #[Test]
    public function no_reader_sources_an_entry_any_more(): void
    {
        $script = $this->script();

        self::assertSame(0, preg_match_all('/^\s*(?:\.|source)\s+["$]/m', $script), 'an entry is read with registry_entry_load, never sourced');
        self::assertGreaterThanOrEqual(17, substr_count($script, 'registry_entry_load "'), 'every reader that sourced an entry reads it with the loader');
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

        // Only the last newline goes: an empty value is a line of its own.
        return explode("\n", str_ends_with($raw, "\n") ? substr($raw, 0, -1) : $raw);
    }

    private function appEntry(string $id, string $root, string $name, string $port): void
    {
        file_put_contents($this->tmp . '/apps/' . $id . '.env', "APP_ID={$id}\nPROJECT_ROOT={$root}\nPROJECT_NAME={$name}\nSWOOLE_PORT={$port}\n");
    }

    private function function(string $name): string
    {
        self::assertSame(1, preg_match('/^' . $name . '\(\) \{\n.*?^\}$/ms', $this->script(), $match), $name . ' must still exist in bin/semitexa');

        return $match[0] ?? '';
    }

    /** The script from one line up to, not including, another. */
    private function region(string $from, string $to): string
    {
        $script = $this->script();
        $start = strpos($script, "\n" . $from);
        $end = strpos($script, "\n" . $to);
        self::assertIsInt($start, $from . ' must still be in bin/semitexa');
        self::assertIsInt($end, $to . ' must still be in bin/semitexa');
        self::assertGreaterThan($start, $end);

        return substr($script, $start + 1, $end - $start);
    }

    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
    }
}
