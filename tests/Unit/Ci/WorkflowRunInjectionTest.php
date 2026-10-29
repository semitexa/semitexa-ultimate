<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Ci;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A ${{ }} expression inside a run: script is pasted into the shell before it
 * runs, so a value someone else controls becomes code: a workflow input, an
 * event field, a branch name. Aikido rated it critical (2026-10-29) for
 * stack-smoke.yml's override_repo, which printed '${{ inputs.override_repo }}'
 * in an echo. Such a value reaches a script only through env:, where it stays data.
 */
final class WorkflowRunInjectionTest extends TestCase
{
    private const UNTRUSTED = '/\$\{\{[^}]*\b(inputs\.|github\.event\.|github\.head_ref|github\.base_ref|github\.ref_name)[^}]*\}\}/';

    #[Test]
    public function no_run_script_pastes_an_untrusted_value(): void
    {
        // No file, or one unread, would scan nothing and pass.
        $files = glob(dirname(__DIR__, 3) . '/.github/workflows/*.y*ml') ?: [];
        self::assertContains('stack-smoke.yml', array_map('basename', $files));
        $found = [];
        foreach ($files as $file) {
            $yaml = file_get_contents($file);
            self::assertIsString($yaml, "cannot read {$file}");
            foreach ($this->runScripts($yaml) as [$line, $script]) {
                if (preg_match_all(self::UNTRUSTED, $script, $m) > 0) {
                    $found[] = basename($file) . ':' . $line . ' ' . implode(', ', $m[0]);
                }
            }
        }

        self::assertSame([], $found, 'pass the value through env: and read it as $VAR');
    }

    #[Test]
    public function the_scan_sees_an_inline_and_a_block_script(): void
    {
        $yaml = "    steps:\n      - run: echo \${{ github.event.issue.title }}\n      - name: b\n        run: |\n          echo ok\n          echo \"\${{ inputs.repo }}\"\n      - name: c\n        env:\n          REPO: \${{ inputs.repo }}\n        run: echo \"\$REPO\"\n";

        $scripts = array_column($this->runScripts($yaml), 1);

        self::assertSame(["echo \${{ github.event.issue.title }}", "echo ok\necho \"\${{ inputs.repo }}\"", 'echo "$REPO"'], $scripts);
    }

    /** A pattern that matched nothing would leave the gate silent while the suite stays green. */
    #[Test]
    public function the_pattern_tells_an_untrusted_value_from_a_safe_one(): void
    {
        $script = 'echo ${{ inputs.repo }} ${{ github.event.pull_request.title }} ${{ secrets.TOKEN }} ${{ steps.x.outputs.y }}';

        preg_match_all(self::UNTRUSTED, $script, $m);

        self::assertSame(['${{ inputs.repo }}', '${{ github.event.pull_request.title }}'], $m[0]);
    }

    /** @return list<array{int, string}> [first line, script] of every run: in a workflow */
    private function runScripts(string $yaml): array
    {
        $lines = explode("\n", $yaml);
        $scripts = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^(\s*)(?:- )?run:\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }
            if (!in_array(trim($m[2]), ['|', '>', '|-', '>-'], true)) {
                $scripts[] = [$i + 1, trim($m[2])];
                continue;
            }
            $indent = strlen($m[1]);
            $body = [];
            for ($j = $i + 1; $j < count($lines); $j++) {
                if (trim($lines[$j]) !== '' && strlen($lines[$j]) - strlen(ltrim($lines[$j])) <= $indent + 2 && !str_starts_with(ltrim($lines[$j]), '#') && preg_match('/^\s*(- |[a-z_-]+:)/', $lines[$j]) === 1) {
                    break;
                }
                $body[] = trim($lines[$j]);
            }
            $scripts[] = [$i + 1, trim(implode("\n", $body))];
        }

        return $scripts;
    }
}
