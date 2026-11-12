<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AGENTS.md is read by every agent run, whole, before the first command
 * (bench-agent-context-growth). On the delivery benchmark a Semitexa run grew
 * the agent's context to 51K tokens against 28-33K on Laravel, and the manual
 * was 23 KB then and 27.7 KB on 2026-11-12. The reference tables moved to
 * AGENTS_DOCTRINE.md §12, read when needed; this budget only goes down.
 */
final class AgentsManualStaysShortTest extends TestCase
{
    /** Bytes. Lower it when the manual shrinks; raising it needs a reason in the commit. */
    private const BUDGET = 21_000;

    private const ROOT = __DIR__ . '/../../..';

    #[Test]
    public function the_manual_every_run_reads_stays_within_its_budget(): void
    {
        $size = filesize(self::ROOT . '/AGENTS.md');

        self::assertIsInt($size);
        self::assertLessThanOrEqual(self::BUDGET, $size, 'AGENTS.md grew: move reference material to AGENTS_DOCTRINE.md, keep the rules here');
    }

    /**
     * One tool for checks (tk-verify-one-door): the manual listed lint:handlers and
     * lint:di as steps "after modifying", while ai:verify already ran both for those
     * files, so an agent repeated the work and learned two of sixteen layers.
     */
    #[Test]
    public function the_manual_names_one_check_and_no_lint_is_a_step_of_its_own(): void
    {
        $manual = (string) file_get_contents(self::ROOT . '/AGENTS.md');
        $doctrine = (string) file_get_contents(self::ROOT . '/AGENTS_DOCTRINE.md');

        self::assertStringContainsString('**`ai:verify` is the one check.**', $manual);
        self::assertDoesNotMatchRegularExpression('/^\| `lint:[a-z-]+` \|[^\n]*\| After /m', $doctrine, 'a lint row sends the agent to run it beside ai:verify');
    }

    #[Test]
    public function what_left_the_manual_is_still_one_pointer_away(): void
    {
        $manual = (string) file_get_contents(self::ROOT . '/AGENTS.md');
        $doctrine = (string) file_get_contents(self::ROOT . '/AGENTS_DOCTRINE.md');

        self::assertStringContainsString('`AGENTS_DOCTRINE.md` §12', $manual);
        self::assertStringContainsString('## 12. Tool reference', $doctrine);
        foreach (['ai:agent', 'ai:quality', 'ai:report', 'ai:verify:receipt', 'ai:invoke', 'ai:backlog stats', 'lint:di'] as $command) {
            self::assertStringContainsString('`' . $command, $doctrine, $command . ' left the manual and is described nowhere');
        }
    }
}
