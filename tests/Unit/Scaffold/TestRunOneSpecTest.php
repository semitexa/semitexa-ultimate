<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `test:run <path>.spec.ts` runs that spec alone, in the E2E phase, without PHPUnit
 * (tk-test-run-one-e2e-spec). An agent writing one spec ran the whole suite to see it:
 * `test:run --e2e <spec>` went to PHPUnit as an unknown option.
 */
final class TestRunOneSpecTest extends TestCase
{
    private string $script;

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    #[Test]
    public function a_wrong_call_is_refused_before_anything_starts(): void
    {
        self::assertStringContainsString('test:run has no --e2e option: name the spec itself', $this->run_('--e2e', 'tests/E2E/a.spec.ts'));
        self::assertStringContainsString('An E2E spec runs on its own, without PHPUnit: drop --filter X', $this->run_('tests/E2E/a.spec.ts', '--filter', 'X'));
        self::assertStringContainsString('No such E2E spec: tests/E2E/missing.spec.ts', $this->run_('tests/E2E/missing.spec.ts'));
    }

    #[Test]
    public function a_named_spec_skips_phpunit_and_is_the_only_one_playwright_gets(): void
    {
        $run = (string) strstr($this->script, 'cmd_test_run() {');
        $phase1 = strpos($run, 'Phase 1/2 — PHPUnit');
        $alone = strpos($run, "if [ -n \"\$_e2e_only_specs\" ]; then\n        info \"E2E spec(s) named");
        self::assertNotFalse($phase1);
        self::assertNotFalse($alone, 'a named spec goes to the E2E phase');
        self::assertLessThan($phase1, $alone, 'before PHPUnit starts');
        self::assertStringContainsString("test_run_e2e_phase\n    fi\n", substr($run, (int) $alone, 300));

        $phase = (string) strstr($this->script, 'test_run_e2e_phase() {');
        $override = strpos($phase, '_e2e_spec_args="$_e2e_only_specs"');
        self::assertNotFalse($override, 'the named specs replace the discovered dirs');
        self::assertLessThan(strpos($phase, 'playwright test --config=playwright.config.ts $_e2e_spec_args'), $override, 'before the runner is called');
    }

    private function run_(string ...$args): string
    {
        $root = sys_get_temp_dir() . '/semitexa-test-run-spec-' . bin2hex(random_bytes(4));
        mkdir($root . '/bin', 0o755, true);
        mkdir($root . '/tests/E2E', 0o755, true);
        $this->cleanup[] = $root;
        copy(dirname(__DIR__, 3) . '/bin/semitexa', $root . '/bin/semitexa');
        touch($root . '/tests/E2E/a.spec.ts');

        $process = proc_open(['sh', $root . '/bin/semitexa', 'test:run', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, ['PATH' => '/usr/bin:/bin', 'HOME' => $root]);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($process), 'refused with exit 1: ' . $out);

        return $out;
    }
}
