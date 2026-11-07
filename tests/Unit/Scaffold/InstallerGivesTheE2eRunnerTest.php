<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\TestCase;

/**
 * A fresh project can run its E2E tests on any machine.
 *
 * REGRESSION (live run, 2026-11-07). The installer copied docker-compose.test.yml
 * and nothing it needs: no playwright.config.ts, so the agent wrote one, and no
 * docker/e2e-runner/Dockerfile, while the runner's image is built locally and
 * is on no registry. It worked on a machine that had built the image before.
 */
final class InstallerGivesTheE2eRunnerTest extends TestCase
{
    private const FILES = ['docker-compose.test.yml', 'docker/e2e-runner/Dockerfile', 'playwright.config.ts'];

    private function installer(): string
    {
        $installer = dirname(__DIR__, 4) . '/semitexa-installer';
        self::assertDirectoryExists($installer, 'semitexa-installer must be checked out next to this repository.');

        return $installer;
    }

    private function script(): string
    {
        return (string) file_get_contents($this->installer() . '/commands/install.sh');
    }

    public function test_the_installer_copies_everything_the_runner_needs(): void
    {
        $script = $this->script();

        foreach (self::FILES as $file) {
            self::assertFileExists($this->installer() . '/scaffold/' . $file);
            // One line: a newline between the operands would leave cp without its target.
            self::assertMatchesRegularExpression(
                '#^cp /installer/scaffold/' . preg_quote($file, '#') . '[ \t]+/app/' . preg_quote($file, '#') . '[ \t]*$#m',
                $script,
                "{$file}: a fresh project needs it to run test:run",
            );
        }
    }

    public function test_the_runner_directory_exists_before_its_dockerfile_is_copied(): void
    {
        $script = $this->script();
        $mkdir = strpos($script, "\nmkdir -p /app/docker/e2e-runner\n");
        $copy = strpos($script, "\ncp /installer/scaffold/docker/e2e-runner/Dockerfile");

        self::assertNotFalse($mkdir);
        self::assertNotFalse($copy);
        self::assertLessThan($copy, $mkdir);
    }

    /**
     * REGRESSION (same run). docker-compose.test.yml was copied as root and left root's:
     * the user who owns the project could not edit it without sudo.
     */
    public function test_every_file_it_copies_is_handed_to_the_user_after_the_copy(): void
    {
        $script = $this->script();
        preg_match_all('#^cp /installer/scaffold/\S+[ \t]+/app/(\S+)$#m', $script, $copied, PREG_OFFSET_CAPTURE);
        self::assertNotEmpty($copied[1]);
        $lastCopy = max(array_map(static fn (array $match): int => $match[1], $copied[1]));

        $owners = [];
        $owned = [];
        $inList = false;
        $offset = 0;
        $firstChown = null;
        foreach (explode("\n", $script) as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, 'chown -R "${_uid}:${_gid}" ')) {
                $firstChown ??= $offset;
                array_push($owners, ...array_slice(explode(' ', $trimmed), 3));
            } elseif ($trimmed === 'chown "${_uid}:${_gid}" \\') {
                $firstChown ??= $offset;
                $inList = true;
            } elseif ($inList) {
                $owned[] = rtrim($trimmed, ' \\');
                $inList = str_ends_with($trimmed, '\\');
            }
            $offset += strlen($line) + 1;
        }
        self::assertNotEmpty($owned, 'the chown list');
        // A chown before a copy hands over nothing: the copy comes later, as root.
        self::assertGreaterThan($lastCopy, (int) $firstChown, 'ownership is set after every copy');

        foreach ($copied[1] as [$file]) {
            $path = '/app/' . $file;
            $underTree = array_filter($owners, static fn (string $tree): bool => str_starts_with($path, rtrim($tree, '/') . '/'));
            self::assertTrue(in_array($path, $owned, true) || $underTree !== [], "{$path} is copied as root and never handed to the user");
        }
    }

    public function test_the_runner_test_run_starts_is_built_from_the_copied_dockerfile(): void
    {
        // The service's own block, comments dropped: a commented-out build line must not count.
        $compose = (string) file_get_contents($this->installer() . '/scaffold/docker-compose.test.yml');
        self::assertSame(1, preg_match('/^  e2e-runner:\n((?:(?:    .*|\s*)\n)+)/m', $compose, $block), 'bin/semitexa test:run runs the e2e-runner service');
        $live = implode("\n", array_filter(
            array_map(static fn (string $line): string => rtrim((string) preg_replace('/(^|\s)#.*$/', '', $line)), explode("\n", $block[1])),
            static fn (string $line): bool => trim($line) !== '',
        ));

        self::assertMatchesRegularExpression('/^    build:\n      context: \.\n      dockerfile: docker\/e2e-runner\/Dockerfile$/m', $live);
        self::assertMatchesRegularExpression('/^    command: \[.*"--config=playwright\.config\.ts".*\]$/m', $live);
    }

    public function test_test_run_finds_a_module_spec_the_config_matches(): void
    {
        $root = $this->installer() . '/scaffold';
        $config = (string) file_get_contents($root . '/playwright.config.ts');
        self::assertStringContainsString("'src/modules/*/tests/E2E/**/*.spec.ts'", $config);
        self::assertStringContainsString("outputDir: 'var/test-results/e2e'", $config);
        self::assertMatchesRegularExpression('#^var/test-results/$#m', (string) file_get_contents($root . '/.gitignore'));

        // Run as the shell runs it: the dirs it hands Playwright as filters must hold the module's spec.
        $project = sys_get_temp_dir() . '/semitexa-e2e-discovery-' . uniqid();
        mkdir($project . '/src/modules/Probe/tests/E2E', 0o755, true);
        touch($project . '/src/modules/Probe/tests/E2E/probe.spec.ts');
        mkdir($project . '/src/modules/Empty/tests/E2E', 0o755, true);
        try {
            self::assertSame(1, preg_match('/^discover_e2e_dirs\(\) \{\n.*?^\}$/ms', (string) file_get_contents($root . '/bin/semitexa'), $m));
            $out = (string) shell_exec(sprintf('env -i PATH=/usr/bin:/bin PROJECT_ROOT=%s sh -c %s 2>&1', escapeshellarg($project), escapeshellarg($m[0] . "\ndiscover_e2e_dirs")));
            self::assertSame("src/modules/Probe/tests/E2E\n", $out);
        } finally {
            exec('rm -rf ' . escapeshellarg($project));
        }
    }
}
