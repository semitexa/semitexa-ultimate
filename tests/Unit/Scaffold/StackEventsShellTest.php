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
        mkdir($this->root . '/shared', 0o755, true);
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
            'PROJECT_ROOT=%s SEMITEXA_AGENTS_HOST_DIR=%s SEMITEXA_AGENT_SESSION=%s sh -c %s 2>&1',
            escapeshellarg($this->root),
            escapeshellarg($this->root . '/shared'),
            escapeshellarg($session),
            escapeshellarg($sh),
        );

        return (string) shell_exec($cmd);
    }

    #[Test]
    public function the_warning_reads_the_machine_wide_store_and_names_only_who_uses_this_stack(): void
    {
        // One store for every project on the machine since 2026-10-17: the
        // project's own directory alone would warn nobody.
        $this->agent('codex-here01', 'working this project', null, ['project' => $this->root], 'shared');
        $this->agent('claude-away1', 'working the portal', null, ['project' => '/elsewhere/portal'], 'shared');
        $this->agent('claude-visit', 'took a task of this backlog', null, ['project' => '/elsewhere/portal', 'task_project' => $this->root], 'shared');
        $this->agent('codex-old001', 'from before sessions named a project', null, [], 'shared');

        $out = $this->shell('stack_warn_live_agents restart', 'claude-self1');

        self::assertStringContainsString('codex-here01', $out);
        self::assertStringContainsString('claude-visit', $out, 'it runs this backlog, so this stack');
        self::assertStringNotContainsString('claude-away1', $out);
        self::assertStringNotContainsString('codex-old001', $out, 'a project-less record in the shared store belongs to no one here');
    }

    #[Test]
    public function an_agent_that_exported_nothing_gets_the_same_derived_session_on_every_command(): void
    {
        $identity = function (string $env): string {
            $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
            self::assertSame(1, preg_match('/^agent_identity\(\) \{\n.*?^\}$/ms', $script, $m), 'agent_identity must still exist in bin/semitexa');
            $sh = $m[0] . "\nagent_identity\nprintf '%s|%s' \"\${SEMITEXA_AGENT_SESSION:-}\" \"\${SEMITEXA_AGENT_IMPLICIT:-}\"";

            return (string) shell_exec('env -i PATH=/usr/bin:/bin ' . $env . ' sh -c ' . escapeshellarg($sh));
        };

        $first = $identity('CLAUDE_CODE_SESSION_ID=dac88658-d35d-4cde-8520-5f4a1db5be9f');
        self::assertMatchesRegularExpression('/^claude-[0-9a-f]{6}\|claude$/', $first);
        self::assertSame($first, $identity('CLAUDE_CODE_SESSION_ID=dac88658-d35d-4cde-8520-5f4a1db5be9f'));
        self::assertNotSame($first, $identity('CLAUDE_CODE_SESSION_ID=another-session'));
        self::assertSame('codex-abc123|', $identity('SEMITEXA_AGENT_SESSION=codex-abc123 CLAUDE_CODE_SESSION_ID=x'), 'an explicit export wins');
        self::assertSame('|0', $identity('SEMITEXA_AGENT_IMPLICIT=0 CLAUDE_CODE_SESSION_ID=x'), 'and the agent can turn derivation off');
    }

    #[Test]
    public function compose_always_gets_an_absolute_store_and_a_rejected_one_falls_back_to_the_project(): void
    {
        // Unset, compose read the rejected value from .env; a bare name is a volume.
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
        self::assertSame(1, preg_match('/^env_export_compose_context\(\) \{\n.*?^\}$/ms', $script, $m));
        $store = function (string $env) use ($m): string {
            $sh = "docker_is_rootless(){ return 1; }\nenv_merged_lines(){ :; }\n" . $m[0] . "\ncd " . escapeshellarg($this->root) . " && env_export_compose_context; printf '%s' \"\$SEMITEXA_AGENTS_HOST_DIR\"";

            return (string) shell_exec(sprintf('env -i PATH=/usr/bin:/bin HOME=%s PROJECT_ROOT=%s %s sh -c %s', escapeshellarg($this->root), escapeshellarg($this->root), $env, escapeshellarg($sh)));
        };

        self::assertSame($this->root . '/.semitexa/agents', $store(''));
        self::assertSame($this->root . '/var/ai-work/agents', $store('SEMITEXA_UID=4242 SEMITEXA_GID=4242'), 'services under another uid cannot write an owner-only store');
        self::assertSame($this->root . '/rel', $store('SEMITEXA_AGENTS_HOST_DIR=rel'));
        file_put_contents($this->root . '/a-file', 'x');
        self::assertSame($this->root . '/var/ai-work/agents', $store('SEMITEXA_AGENTS_HOST_DIR=' . escapeshellarg($this->root . '/a-file')), 'not a usable directory: the project store, exported');
        symlink($this->root . '/shared', $this->root . '/link');
        self::assertSame($this->root . '/var/ai-work/agents', $store('SEMITEXA_AGENTS_HOST_DIR=' . escapeshellarg($this->root . '/link')), 'a symlink is not followed');
    }

    /**
     * @param array<string, string> $extra
     */
    private function agent(string $id, string $intent, ?string $endedAt, array $extra = [], string $store = 'var/ai-work/agents'): void
    {
        // Pretty-printed, as AgentRegistry writes it: the shell reads it by line.
        // A fixture that failed to write would let the exclusion checks pass on nothing.
        $written = file_put_contents($this->root . '/' . $store . '/' . $id . '.json', json_encode([
            'id' => $id, 'agent' => 'x', 'intent' => $intent, 'task' => null, 'repos' => [],
            'started_at' => 'x', 'beat_at' => 'x', 'ended_at' => $endedAt,
        ] + $extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        self::assertNotFalse($written, "fixture for {$id} must be written");
    }
}
