<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A test:run that picks tests runs no E2E phase after them, like one that names
 * paths. When `--filter X` stopped counting X as a path
 * (bench-test-run-filter-noop), a filtered run went on to the whole E2E suite;
 * raised on semitexa-update#69. Run as the shell runs it, against a docker
 * stub that answers every call with success.
 */
final class TestRunFocusShellTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-test-run-focus-' . bin2hex(random_bytes(6));
        foreach (['bin', 'stubs', 'vendor/bin', 'src/modules/Shop/tests'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o755, true);
        }
        copy(dirname(__DIR__, 3) . '/bin/semitexa', $this->root . '/bin/semitexa');
        file_put_contents($this->root . '/.env', "APP_ENV=dev\nDB_DATABASE=live\nDB_TEST_DATABASE=isolated_test\n");
        file_put_contents($this->root . '/composer.json', '{}');
        foreach (['docker-compose.yml', 'docker-compose.test.yml', 'vendor/bin/semitexa', 'vendor/bin/phpunit'] as $file) {
            file_put_contents($this->root . '/' . $file, 'fixture');
        }
        file_put_contents($this->root . '/src/modules/Shop/tests/OrderTest.php', '<?php');
        file_put_contents($this->root . '/stubs/docker', "#!/bin/sh\nprintf 'docker %s\\n' \"\$*\" >> \"\$(dirname \"\$0\")/../docker.log\"\ncase \" \$* \" in *' ps '*) printf 'running\\n' ;; esac\nexit 0\n");
        chmod($this->root . '/stubs/docker', 0o755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_test_selection_skips_the_e2e_phase_and_a_bare_run_does_not(): void
    {
        foreach ([['--filter', 'OrderTest'], ['--filter=OrderTest'], ['--group', 'slow'], ['--testsuite', 'default']] as $selection) {
            @unlink($this->root . '/docker.log');
            $output = $this->testRun(...$selection);
            $calls = (string) @file_get_contents($this->root . '/docker.log');
            self::assertStringContainsString('E2E phase skipped', $output, implode(' ', $selection));
            self::assertStringNotContainsString('Phase 2/2', $output, implode(' ', $selection));
            // PHPUnit got the selection, and nothing reached for Playwright.
            self::assertStringContainsString('vendor/bin/phpunit ' . implode(' ', $selection), $calls, implode(' ', $selection));
            self::assertStringNotContainsStringIgnoringCase('playwright', $calls, implode(' ', $selection));
        }

        self::assertStringContainsString('Phase 2/2', $this->testRun(), 'a bare run still goes on to E2E');
    }

    private function testRun(string ...$args): string
    {
        return (string) shell_exec(sprintf(
            'cd %s && env -i HOME=%s PATH=%s:/usr/bin:/bin sh bin/semitexa test:run %s 2>&1',
            escapeshellarg($this->root),
            escapeshellarg($this->root),
            escapeshellarg($this->root . '/stubs'),
            implode(' ', array_map('escapeshellarg', $args)),
        ));
    }
}
