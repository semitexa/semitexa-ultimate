<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * bin/semitexa mcp:config (tk-mcp-bin-semitexa): what to give an agent so it
 * starts the project's MCP server, with the project's path on the host, which
 * the app container does not know. Run as the shell runs it.
 */
final class McpConfigShellTest extends TestCase
{
    #[Test]
    public function it_prints_the_claude_code_command_and_the_json_with_this_projects_path(): void
    {
        self::assertSame([
            'Claude Code, in this project:',
            '  claude mcp add semitexa -- "/srv/my app/bin/semitexa" mcp',
            '',
            'Cursor (.cursor/mcp.json) or Claude Desktop (claude_desktop_config.json):',
            '{"mcpServers": {"semitexa": {"command": "/srv/my app/bin/semitexa", "args": ["mcp"]}}}',
        ], $this->config('/srv/my app'));
    }

    /** A quote in the path must not end the JSON string. */
    #[Test]
    public function a_quote_or_backslash_in_the_path_is_escaped_in_the_json(): void
    {
        $json = $this->config('/srv/a"b\\c')[4];

        self::assertSame(['mcpServers' => ['semitexa' => ['command' => '/srv/a"b\\c/bin/semitexa', 'args' => ['mcp']]]], json_decode($json, true));
    }

    /**
     * REGRESSION (review). The relay starts this command again with
     * SEMITEXA_MCP_DIRECT set; with the stack down it says "try again" (75).
     * On a host without Compose there is no stack to wait for, and the
     * server never started: it runs on the host's PHP.
     */
    #[Test]
    public function started_by_the_relay_without_compose_it_runs_the_server_on_local_php(): void
    {
        $ran = $this->mcp(hasCompose: false);

        self::assertSame([0, ['php ' . $this->root . '/vendor/bin/semitexa mcp:serve --x']], $ran);
    }

    /** With Compose and the stack down (a restart in progress), the relay is told to try again. */
    #[Test]
    public function started_by_the_relay_while_the_stack_is_down_it_asks_to_be_retried(): void
    {
        self::assertSame([75, []], $this->mcp(hasCompose: true));
    }

    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /** @return array{int, list<string>} the exit status and what ran, one line each */
    private function mcp(bool $hasCompose): array
    {
        $this->root = sys_get_temp_dir() . '/semitexa-mcp-' . uniqid();
        mkdir($this->root . '/vendor/bin', 0o755, true);
        mkdir($this->root . '/bin');
        touch($this->root . '/vendor/bin/semitexa');
        touch($this->root . '/.env.default');
        file_put_contents($this->root . '/bin/php', "#!/bin/sh\necho \"php \$*\"\n");
        chmod($this->root . '/bin/php', 0o755);

        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
        preg_match_all('/^(env_runtime_file_exists|cmd_mcp)\(\) \{\n.*?^\}$/ms', $script, $m);
        self::assertCount(2, $m[0], 'both functions must still exist in bin/semitexa');

        $sh = 'error(){ printf "ERROR: %s\n" "$*"; }; is_app_running(){ return 1; }; agent_env_args(){ :; }' . "\n"
            . 'has_compose(){ [ "$HAS_COMPOSE" = 1 ]; }' . "\n"
            . 'proxy_run_one_off_app(){ echo "one-off $*"; }; run_compose(){ echo "compose $*"; }' . "\n"
            . implode("\n", $m[0]) . "\n(cmd_mcp --x); echo \"status=\$?\"";
        $out = (string) shell_exec(sprintf(
            'env -i PATH=%s:/usr/bin:/bin PROJECT_ROOT=%s SEMITEXA_MCP_DIRECT=1 HAS_COMPOSE=%s sh -c %s 2>&1',
            escapeshellarg($this->root . '/bin'),
            escapeshellarg($this->root),
            $hasCompose ? '1' : '0',
            escapeshellarg($sh),
        ));
        $lines = array_values(array_filter(explode("\n", $out), static fn (string $line): bool => $line !== ''));
        $last = (string) array_pop($lines);
        self::assertMatchesRegularExpression('/^status=\d+$/', $last, $out);

        return [(int) substr($last, strlen('status=')), $lines];
    }

    /** @return list<string> */
    private function config(string $root): array
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
        preg_match('/^cmd_mcp_config\(\) \{\n.*?^\}$/ms', $script, $m);
        self::assertNotEmpty($m, 'cmd_mcp_config must still exist in bin/semitexa');

        $out = (string) shell_exec(sprintf(
            'env -i PATH=/usr/bin:/bin PROJECT_ROOT=%s sh -c %s 2>&1',
            escapeshellarg($root),
            escapeshellarg($m[0] . "\ncmd_mcp_config"),
        ));

        return explode("\n", rtrim($out, "\n"));
    }
}
