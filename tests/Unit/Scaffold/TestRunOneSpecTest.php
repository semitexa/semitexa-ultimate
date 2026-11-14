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
        // Review, installer#91: Playwright matches the collected path, which has no ./ or ../ in it.
        foreach (['tests/E2E/./a.spec.ts', 'tests/../tests/E2E/a.spec.ts', 'tests//E2E/a.spec.ts', '../x/tests/E2E/a.spec.ts'] as $odd) {
            self::assertStringContainsString('Name the spec by its plain project-relative path', $this->run_($odd), $odd);
        }
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
        $override = strpos($phase, 'for _e2e_named in $_e2e_only_specs; do');
        self::assertNotFalse($override, 'the named specs replace the discovered dirs');
        $runner = strpos($phase, 'playwright test --config=playwright.config.ts $_e2e_spec_args');
        self::assertIsInt($runner, 'the runner is called with the filters');
        self::assertLessThan($runner, $override, 'before the runner is called');
    }

    /** Review, installer#91 and update#95. */
    #[Test]
    public function a_named_spec_is_a_literal_anchored_filter_and_needs_no_phase_one(): void
    {
        $phase = (string) strstr($this->script, 'test_run_e2e_phase() {');
        // Discovery finding nothing does not end a run that names its spec.
        self::assertStringContainsString('if [ -z "$_e2e_dirs" ] && [ -z "${_e2e_only_specs:-}" ]; then', $phase);
        // MySQL is started by the reset itself, before the drop: Phase 1 is not there to do it.
        $reset = (string) strstr($this->script, 'e2e_database_reset() {');
        // A position that is not there is false, which compares below any number (review, ultimate#147).
        $drop = strpos($reset, 'DROP DATABASE');
        $mysql = strpos($reset, 'ensure_test_database "$1" "$2" "$_e2e_name"');
        $redis = strpos($reset, '$1 $2 up -d redis');
        self::assertIsInt($drop);
        self::assertIsInt($mysql, 'MySQL is started by the reset');
        self::assertIsInt($redis, 'Redis is started by the reset when the app uses it: the E2E app starts with --no-deps');
        self::assertLessThan($drop, $mysql);
        self::assertLessThan($drop, $redis);
        self::assertStringContainsString("redis-cli ping 2>&1 < /dev/null ) | grep -qE 'PONG|NOAUTH'", $reset);

        // The filter Playwright gets is a regular expression over the absolute path.
        self::assertSame(1, preg_match('/^        _e2e_spec_args=\'\'\n.*?^        set \+f\n/ms', $phase, $loop));
        $filters = (string) shell_exec('_e2e_only_specs=' . escapeshellarg('src/m/tests/E2E/user[1].spec.ts src/m/tests/E2E/b.spec.ts') . "\n" . $loop[0] . 'printf %s "$_e2e_spec_args"');
        $parts = explode(' ', $filters);
        self::assertCount(2, $parts, $filters);
        self::assertSame(1, preg_match('~' . $parts[0] . '~i', '/var/www/html/src/m/tests/E2E/user[1].spec.ts'), $parts[0]);
        self::assertSame(0, preg_match('~' . $parts[0] . '~i', '/var/www/html/src/m/tests/E2E/user1.spec.ts'), 'brackets are literal');
        self::assertSame(0, preg_match('~' . $parts[1] . '~i', '/var/www/html/src/m/tests/E2E/b.spec.ts.bak'), 'anchored at the end');
        self::assertSame(0, preg_match('~' . $parts[1] . '~i', '/var/www/html/src/m/tests/E2E/xb.spec.ts'), 'anchored at a path segment');
        self::assertSame(0, preg_match('~' . $parts[1] . '~i', '/var/www/html/packages/x/src/m/tests/E2E/b.spec.ts'), 'anchored at the project root: the same path deeper is another spec');
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
