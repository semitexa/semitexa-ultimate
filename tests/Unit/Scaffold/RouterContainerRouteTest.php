<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared router reaches a project's app over the project's own Docker
 * network (tk-app-port-binds-every-interface, first step).
 *
 * The router proxied every domain to host.docker.internal:<port>, which only
 * works while the app's port is published on every interface, so each dev
 * server was open to the whole network. The router now joins the project's
 * network and proxies into the app container, which lets the next step bind
 * the port to 127.0.0.1. Driven here against a stub docker: the functions are
 * the script's own.
 */
final class RouterContainerRouteTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../bin/semitexa';

    private string $root;
    private string $log;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-router-route-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/home/.semitexa/router/registry/domains', 0755, true);
        mkdir($this->root . '/home/.semitexa/router/nginx/generated', 0755, true);
        mkdir($this->root . '/project', 0755, true);
        mkdir($this->root . '/stub', 0755, true);
        file_put_contents($this->root . '/project/.env', "SWOOLE_PORT=9507\n");
        $this->log = $this->root . '/docker.log';
        // docker: the router is on its own network and on shop_default; the
        // project's app is shop-app-1 on shop_default. Every call is logged.
        file_put_contents($this->root . '/stub/docker', <<<SH
            #!/bin/sh
            printf '%s\\n' "\$*" >> '{$this->log}'
            case "\$*" in
              'compose version') exit 0 ;;
              *' ps -q app') echo cid-shop ;;
              *' ps -a -q') echo cid-shop ;;
              'network inspect -f '*' shop_default') echo shop ;;
              *'.Name'*' cid-shop') echo /shop-app-1 ;;
              *'com.docker.compose.project'*' cid-shop') echo shop ;;
              *'.NetworkSettings.Networks'*' cid-shop') printf 'shop_default\\n\\n' ;;
              *'.NetworkSettings.Networks'*' semitexa-router-proxy') printf 'router_default\\nshop_default\\n\\n' ;;
              'exec semitexa-router-proxy nginx -t') exit 0 ;;
            esac
            exit 0
            SH);
        chmod($this->root . '/stub/docker', 0755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_domain_whose_router_is_on_the_app_network_goes_into_the_container(): void
    {
        $this->entry('shop.test', "UPSTREAM_CONTAINER=shop-app-1\nUPSTREAM_NETWORK=shop_default\nUPSTREAM_CONTAINER_PORT=9507\n");
        // Registered by a project the router is not attached to yet: the host port, as before.
        $this->entry('blog.test', "UPSTREAM_CONTAINER=blog-app-1\nUPSTREAM_NETWORK=blog_default\nUPSTREAM_CONTAINER_PORT=9600\n", '/elsewhere/blog', 9600);
        // An entry an older bin/semitexa wrote.
        $this->entry('old.test', '', '/elsewhere/old', 9700);

        $this->runScript('router_generate_nginx_config');
        $conf = $this->domains();

        self::assertStringContainsString("server_name shop.test;\n\n    location / {\n        resolver 127.0.0.11 valid=5s ipv6=off;\n        set \$semitexa_upstream shop-app-1;\n        proxy_pass http://\$semitexa_upstream:9507;", $conf);
        self::assertStringContainsString("server_name blog.test;\n\n    location / {\n        proxy_pass http://host.docker.internal:9600;", $conf);
        self::assertStringContainsString("server_name old.test;\n\n    location / {\n        proxy_pass http://host.docker.internal:9700;", $conf);
    }

    #[Test]
    public function server_start_joins_the_app_network_and_records_the_container(): void
    {
        $this->entry('shop.test', '');

        $this->runScript('router_attach_project');

        $calls = (string) file_get_contents($this->log);
        self::assertStringContainsString("network connect shop_default semitexa-router-proxy\n", $calls);
        $entry = (string) file_get_contents($this->root . '/home/.semitexa/router/registry/domains/shop.test.env');
        self::assertStringContainsString("UPSTREAM_CONTAINER=shop-app-1\nUPSTREAM_NETWORK=shop_default\nUPSTREAM_CONTAINER_PORT=9507\n", $entry);
        // What an older renderer and the port checks read is left as it was.
        self::assertStringContainsString("UPSTREAM_HOST=host.docker.internal\nUPSTREAM_PORT=9507\n", $entry);
        self::assertStringContainsString('set $semitexa_upstream shop-app-1;', $this->domains());
        // Reloaded only after nginx accepted the config.
        self::assertMatchesRegularExpression('/exec semitexa-router-proxy nginx -t\nexec semitexa-router-proxy nginx -s reload\n/', $calls);
    }

    #[Test]
    public function a_rewrite_before_the_app_is_up_keeps_the_route_of_its_own_project_only(): void
    {
        $this->entry('shop.test', "UPSTREAM_CONTAINER=shop-app-1\nUPSTREAM_NETWORK=shop_default\nUPSTREAM_CONTAINER_PORT=9507\n");
        $this->entry('taken.test', "UPSTREAM_CONTAINER=other-app-1\nUPSTREAM_NETWORK=other_default\nUPSTREAM_CONTAINER_PORT=9800\n", '/elsewhere/other', 9800);

        $this->runScript("router_registry_write_entry shop.test 9507 dns\nrouter_registry_write_entry taken.test 9507 dns");

        self::assertStringContainsString('UPSTREAM_CONTAINER=shop-app-1', (string) file_get_contents($this->root . '/home/.semitexa/router/registry/domains/shop.test.env'));
        // The domain moved to this project: another project's container is not its route.
        // Written, and this project's, first: a missing file would pass the negative (review, ultimate#137).
        $taken = (string) file_get_contents($this->root . '/home/.semitexa/router/registry/domains/taken.test.env');
        self::assertStringContainsString('PROJECT_ROOT=' . $this->root . "/project\n", $taken);
        self::assertStringNotContainsString('UPSTREAM_CONTAINER', $taken);
    }

    #[Test]
    public function server_stop_takes_the_router_off_the_network_before_down(): void
    {
        $this->entry('shop.test', "UPSTREAM_CONTAINER=shop-app-1\nUPSTREAM_NETWORK=shop_default\nUPSTREAM_CONTAINER_PORT=9507\n");

        $this->runScript('router_detach_project');

        self::assertStringContainsString("network disconnect -f shop_default semitexa-router-proxy\n", (string) file_get_contents($this->log));
        $script = (string) file_get_contents(self::SCRIPT);
        foreach (['cmd_server_stop', 'cmd_server_restart'] as $command) {
            self::assertSame(1, preg_match('/^' . $command . '\(\) \{\n(.*?)^\}\n/ms', $script, $body), $command);
            // Both found before they are compared: strpos() false is "less" than any position (review, ultimate#137).
            $detach = strpos($body[1], 'router_detach_project');
            $down = strpos($body[1], 'down --remove-orphans');
            self::assertIsInt($detach, "{$command} detaches");
            self::assertIsInt($down, "{$command} runs down");
            self::assertLessThan($down, $detach, "{$command} detaches before down");
        }
    }

    #[Test]
    public function server_stop_detaches_by_docker_even_when_the_registry_lost_the_entry(): void
    {
        // Fable review, installer#82: local-domain:remove took the entry, the
        // router was still on shop_default, and down could not remove it.
        $this->runScript('router_detach_project');

        self::assertStringContainsString("network disconnect -f shop_default semitexa-router-proxy\n", (string) file_get_contents($this->log));
        self::assertStringNotContainsString('disconnect -f router_default', (string) file_get_contents($this->log), 'the router keeps its own network');
    }

    #[Test]
    public function rendering_leaves_the_callers_compose_project_name_as_it_was(): void
    {
        // Each entry sets COMPOSE_PROJECT_NAME; .env keys are exported, so a later
        // compose call would otherwise run in the last entry's project.
        $this->entry('blog.test', '', '/elsewhere/blog', 9600);
        $this->runScript("export COMPOSE_PROJECT_NAME=shop\nrouter_generate_nginx_config\n[ \"\$COMPOSE_PROJECT_NAME\" = shop ] || { echo \"clobbered: \$COMPOSE_PROJECT_NAME\"; exit 1; }\nunset COMPOSE_PROJECT_NAME\nrouter_generate_nginx_config\n[ -z \"\${COMPOSE_PROJECT_NAME+x}\" ] || { echo 'set where it was unset'; exit 1; }");
        self::assertStringContainsString('server_name blog.test;', $this->domains());
    }

    #[Test]
    public function a_recreated_router_rejoins_every_registered_network(): void
    {
        $this->entry('shop.test', "UPSTREAM_CONTAINER=shop-app-1\nUPSTREAM_NETWORK=shop_default\nUPSTREAM_CONTAINER_PORT=9507\n");
        $this->entry('blog.test', "UPSTREAM_CONTAINER=blog-app-1\nUPSTREAM_NETWORK=blog_default\nUPSTREAM_CONTAINER_PORT=9600\n", '/elsewhere/blog', 9600);

        $this->runScript('router_reconnect_all');

        $calls = (string) file_get_contents($this->log);
        self::assertStringContainsString("network connect shop_default semitexa-router-proxy\n", $calls);
        self::assertStringContainsString("network connect blog_default semitexa-router-proxy\n", $calls);
    }

    /**
     * The projects whose script reopens the router to the network are named on
     * every start (tk-router-refuses-public-bind): one from before 2026-11-09
     * publishes "80:80" whenever it starts the shared router.
     */
    #[Test]
    public function a_router_start_names_the_projects_whose_script_reopens_it(): void
    {
        // "quoted" carries the address only in a grep line, as this very function does:
        // that is no proof its router is rendered on loopback (Fable review, installer#84).
        foreach (['old' => "ports:\n      - \"80:80\"\n", 'new' => "ports:\n      - \"127.0.0.1:80:80\"\n", 'quoted' => "grep -q '\"127.0.0.1:80:80\"' x\nports:\n      - \"80:80\"\n"] as $name => $script) {
            mkdir($this->root . "/{$name}/bin", 0755, true);
            file_put_contents($this->root . "/{$name}/bin/semitexa", $script);
            $this->entry("{$name}.test", '', $this->root . "/{$name}");
        }
        $this->entry('gone.test', '', $this->root . '/gone');

        $this->runScript("router_stale_projects > stale.txt");

        // And the start says so, with each path and how to update an edited script (review, ultimate#141).
        $this->runScript("router_check_before_rebind 2> warn.txt");
        $warning = (string) file_get_contents($this->root . '/project/warn.txt');
        self::assertStringContainsString('may reopen the shared router to the network', $warning);
        foreach (['old', 'quoted'] as $name) {
            self::assertStringContainsString('    ' . $this->root . "/{$name}\n", $warning);
        }
        self::assertStringNotContainsString($this->root . "/new\n", $warning);
        self::assertStringContainsString('--write-scaffold-candidates', $warning);
        self::assertStringContainsString('has no bin/semitexa: restore it, or, if the project is gone, remove its domains', $warning);

        self::assertSame(
            $this->root . "/gone (no bin/semitexa: not checked)\n" . $this->root . "/old\n" . $this->root . "/quoted\n",
            (string) file_get_contents($this->root . '/project/stale.txt'),
            'the old scripts, the address in a grep line not counting; a project without a script said, not skipped; never the new one',
        );
    }

    private function entry(string $domain, string $route, ?string $project = null, int $port = 9507): void
    {
        file_put_contents(
            $this->root . "/home/.semitexa/router/registry/domains/{$domain}.env",
            "DOMAIN={$domain}\nPROJECT_ROOT=" . ($project ?? $this->root . '/project') . "\nCOMPOSE_PROJECT_NAME=x\nUPSTREAM_HOST=host.docker.internal\nUPSTREAM_PORT={$port}\nMODE=dns\nUPDATED_AT=2026-11-10T00:00:00Z\n" . $route,
        );
    }

    private function domains(): string
    {
        return (string) file_get_contents($this->root . '/home/.semitexa/router/nginx/generated/domains.conf');
    }

    private function runScript(string $calls): void
    {
        // The script's own source mode (SEMITEXA_BIN_SOURCE_ONLY): every function
        // and setting, no command run. The project is then this temp one.
        $harness = 'SEMITEXA_BIN_SOURCE_ONLY=1; . ' . escapeshellarg((string) realpath(self::SCRIPT)) . "\n"
            . 'PROJECT_ROOT=' . escapeshellarg($this->root . '/project') . "; cd \"\$PROJECT_ROOT\"\n"
            . $calls . "\n";
        file_put_contents($this->root . '/harness.sh', $harness);
        exec('PATH=' . escapeshellarg($this->root . '/stub') . ':/usr/bin:/bin HOME=' . escapeshellarg($this->root . '/home') . ' SEMITEXA_HOME= sh ' . escapeshellarg($this->root . '/harness.sh') . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
    }
}
