<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * bin/semitexa's stack log and live-agent warning, run as the shell runs them.
 *
 * A restart takes the server and every agent's one-off CLI containers down. It
 * now says who is about to be interrupted, and records who did it — both from
 * POSIX sh, so they are exercised here by extracting the two functions from the
 * shipped script and running them against a throwaway project root.
 */
final class StackEventsShellTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-stack-events-' . uniqid();
        mkdir($this->root . '/var/ai-work/agents', 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function the_log_line_is_valid_json_whatever_the_detail_says(): void
    {
        $this->shell('stack_event restart "app\"; rm -rf / \\\\ $(printf \'\001\')x"', 'claude-aaaaaa');
        $this->shell('stack_event stop', 'not an id!');

        $lines = file($this->root . '/var/ai-work/stack-events.ndjson', FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(2, $lines);
        $first = json_decode($lines[0], true);
        $second = json_decode($lines[1], true);
        self::assertIsArray($first, 'a hostile detail must not break the line');
        self::assertSame(['restart', 'claude-aaaaaa'], [$first['action'], $first['by']]);
        self::assertStringNotContainsString('"', $first['detail']);
        // Sanitised, not emptied: the words around the dropped quote survive.
        self::assertStringStartsWith('app; rm -rf /', $first['detail']);
        self::assertNull($second['by'], 'a session value that is not an id is recorded as nobody');
    }

    #[Test]
    public function the_warning_names_live_agents_but_not_the_caller_or_the_departed(): void
    {
        $this->agent('codex-live01', 'writing the SSR demo article', null);
        $this->agent('claude-left1', 'finished earlier', '2026-09-24T09:00:00+00:00');
        $this->agent('claude-self1', 'me, restarting', null);

        $out = $this->shell('stack_warn_live_agents restart', 'claude-self1');

        self::assertStringContainsString('codex-live01 — writing the SSR demo article', $out);
        self::assertStringContainsString('removes their one-off CLI containers', $out);
        self::assertStringNotContainsString('claude-left1', $out);
        self::assertStringNotContainsString('claude-self1', $out);
    }

    #[Test]
    public function a_project_path_with_a_space_still_names_the_live_agent(): void
    {
        // Word-splitting find's output turned each session file into two names
        // that do not exist, and the live agent got no warning.
        exec('rm -rf ' . escapeshellarg($this->root));
        $this->root = sys_get_temp_dir() . '/semitexa stack ' . uniqid();
        mkdir($this->root . '/var/ai-work/agents', 0o755, true);
        $this->agent('codex-live01', 'writing the SSR demo article', null);

        self::assertStringContainsString('codex-live01', $this->shell('stack_warn_live_agents restart', 'claude-self1'));
    }

    #[Test]
    public function a_single_service_restart_does_not_claim_to_remove_their_containers(): void
    {
        $this->agent('codex-live01', 'writing the SSR demo article', null);

        $out = $this->shell('stack_warn_live_agents restart app', 'claude-self1');

        self::assertStringContainsString("recreates the 'app' container", $out);
        self::assertStringNotContainsString('removes their one-off CLI containers', $out);
    }

    #[Test]
    public function nobody_else_live_means_no_warning(): void
    {
        $this->agent('claude-self1', 'me, restarting', null);

        self::assertSame('', trim($this->shell('stack_warn_live_agents restart', 'claude-self1')));
    }

    private function shell(string $call, string $session): string
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
        preg_match_all('/^(stack_event|stack_warn_live_agents)\(\) \{\n.*?^\}$/ms', $script, $m);
        self::assertCount(2, $m[0], 'both helpers must still exist in bin/semitexa');

        $sh = 'warn(){ printf "WARN: %s\n" "$*" >&2; }' . "\n" . implode("\n", $m[0]) . "\n" . $call;
        $cmd = sprintf(
            'PROJECT_ROOT=%s SEMITEXA_AGENT_SESSION=%s sh -c %s 2>&1',
            escapeshellarg($this->root),
            escapeshellarg($session),
            escapeshellarg($sh),
        );

        return (string) shell_exec($cmd);
    }

    private function agent(string $id, string $intent, ?string $endedAt): void
    {
        // Pretty-printed, as AgentRegistry writes it: the shell reads it by line.
        // A fixture that failed to write would let the exclusion checks pass on nothing.
        $written = file_put_contents($this->root . '/var/ai-work/agents/' . $id . '.json', json_encode([
            'id' => $id, 'agent' => 'x', 'intent' => $intent, 'task' => null, 'repos' => [],
            'started_at' => 'x', 'beat_at' => 'x', 'ended_at' => $endedAt,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        self::assertNotFalse($written, "fixture for {$id} must be written");
    }
}
