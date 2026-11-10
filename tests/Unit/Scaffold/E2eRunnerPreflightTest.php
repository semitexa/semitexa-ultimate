<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The E2E phase asks the runner for the modules its image is built to carry
 * before it runs Playwright (tk-e2e-runner-image-tag-without-axe).
 *
 * A present image tag skips the build: on 2026-11-05 a local
 * semitexa/e2e-runner:1.59.1-axe4.13.0 had no @axe-core/playwright, the six
 * specs importing it stopped at load, no spec ran, and the phase "failed" with
 * the cause buried in Playwright's output.
 */
final class E2eRunnerPreflightTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        $this->script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
    }

    #[Test]
    public function the_modules_are_the_ones_the_runner_dockerfile_installs(): void
    {
        $root = $this->project("RUN npm install --global \\\n    \"@playwright/test@\${PLAYWRIGHT_TEST_VERSION}\" \\\n    \"playwright-core@\${PLAYWRIGHT_TEST_VERSION}\" \\\n    \"@axe-core/playwright@\${AXE_PLAYWRIGHT_VERSION}\"\n");

        self::assertSame(['@axe-core/playwright', '@playwright/test', 'playwright-core'], $this->modules($root));
        self::assertSame(['@playwright/test'], $this->modules($this->project(null)), 'no Dockerfile: the one module every spec needs');
    }

    #[Test]
    public function an_image_without_a_module_stops_the_phase_and_names_the_rebuild(): void
    {
        [$code, $said, $argv] = $this->preflight(3, "@axe-core/playwright\n");

        self::assertSame(1, $code);
        self::assertStringContainsString('The e2e-runner image lacks: @axe-core/playwright', $said);
        self::assertStringContainsString('docker compose -f a.yml -f test.yml build --no-cache e2e-runner', $said);
        // The runner itself is asked, without starting the app (--no-deps), for every module the Dockerfile names.
        self::assertStringContainsString('compose -f a.yml -f test.yml run --rm --no-deps -T e2e-runner node -e', $argv);
        self::assertStringEndsWith('@axe-core/playwright @playwright/test playwright-core', rtrim($argv));
    }

    #[Test]
    public function a_complete_image_or_a_check_that_could_not_run_lets_the_phase_go_on(): void
    {
        [$code, , $argv] = $this->preflight(0, '');
        self::assertSame(0, $code);
        self::assertStringContainsString('run --rm --no-deps -T e2e-runner node -e', $argv, 'the runner was asked');
        // Anything but 3 is the check failing, not a missing module: the run that follows says why.
        [$code, $said, $argv] = $this->preflight(1, '');
        self::assertSame(0, $code);
        self::assertStringContainsString('run --rm --no-deps -T e2e-runner node -e', $argv, 'the runner was asked, and its failure let through');
        self::assertStringNotContainsString('lacks', $said);
    }

    #[Test]
    public function the_e2e_phase_checks_before_it_runs_playwright(): void
    {
        $check = strpos($this->script, 'e2e_runner_preflight "$_compose" "$_overlays" || exit 1');
        $run = strpos($this->script, 'run --rm e2e-runner npx --no-install playwright test --config=playwright.config.ts $_e2e_spec_args');

        self::assertNotFalse($check);
        self::assertNotFalse($run);
        self::assertLessThan($run, $check);
    }

    /** @return list<string> */
    private function modules(string $root): array
    {
        $out = $this->sh("PROJECT_ROOT=" . escapeshellarg($root) . "\n"
            . "e2e_runner_required_modules() {\n" . $this->function('e2e_runner_required_modules') . "}\n"
            . "e2e_runner_required_modules\n", []);

        return array_values(array_filter(explode("\n", $out[1])));
    }

    /** @return array{int, string, string} exit code, what it said (stderr, then stdout), the argv the stub docker was called with */
    private function preflight(int $runnerExit, string $runnerOut): array
    {
        $root = $this->project("RUN npm install -g \"@playwright/test@\${A}\" \"playwright-core@\${A}\" \"@axe-core/playwright@\${B}\"\n");
        $bin = $root . '/bin-stub';
        mkdir($bin);
        file_put_contents($bin . '/docker', "#!/bin/sh\nprintf '%s ' \"\$@\" >> " . escapeshellarg($root . '/argv') . "\nprintf '%s' " . escapeshellarg($runnerOut) . "\nexit {$runnerExit}\n");
        chmod($bin . '/docker', 0755);
        self::assertSame(1, preg_match('/^error\(\)\s+\{.*\}$/m', $this->script, $error));
        self::assertSame(1, preg_match('/^info\(\)\s+\{.*\}$/m', $this->script, $info));

        [$code, $stdout, $stderr] = $this->sh("C_RED=''; C_CYAN=''; C_RESET=''\n" . $error[0] . "\n" . $info[0] . "\n"
            . "env_export_compose_context() { :; }\nPROJECT_ROOT=" . escapeshellarg($root) . "\n"
            . "e2e_runner_required_modules() {\n" . $this->function('e2e_runner_required_modules') . "}\n"
            . "e2e_runner_preflight() {\n" . $this->function('e2e_runner_preflight') . "}\n"
            . "e2e_runner_preflight 'docker compose' '-f a.yml -f test.yml'\n", ['PATH' => $bin . ':/usr/bin:/bin']);

        // error() writes to stderr, info() to stdout, as everywhere in the script.
        return [$code, $stderr . $stdout, (string) @file_get_contents($root . '/argv')];
    }

    private function project(?string $dockerfile): string
    {
        $root = sys_get_temp_dir() . '/semitexa-e2e-pre-' . bin2hex(random_bytes(4));
        mkdir($root);
        if ($dockerfile !== null) {
            mkdir($root . '/docker/e2e-runner', 0777, true);
            file_put_contents($root . '/docker/e2e-runner/Dockerfile', "FROM x\n" . $dockerfile);
        }
        $this->cleanup[] = $root;

        return $root;
    }

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $root) {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * @param array<string, string> $env
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function sh(string $script, array $env): array
    {
        $process = proc_open(['sh', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => '/usr/bin:/bin']);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function function(string $name): string
    {
        self::assertSame(1, preg_match('/^' . preg_quote($name, '/') . '\(\) \{\n(.*?)^\}\n/ms', $this->script, $m), "bin/semitexa defines {$name}()");

        return $m[1];
    }
}
