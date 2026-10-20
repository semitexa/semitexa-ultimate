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
