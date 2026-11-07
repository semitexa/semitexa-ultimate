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

    public function test_the_installer_copies_everything_the_runner_needs(): void
    {
        $installer = dirname(__DIR__, 4) . '/semitexa-installer';
        self::assertDirectoryExists($installer, 'semitexa-installer must be checked out next to this repository.');
        $script = (string) file_get_contents($installer . '/commands/install.sh');

        foreach (self::FILES as $file) {
            self::assertFileExists($installer . '/scaffold/' . $file);
            self::assertMatchesRegularExpression(
                '#^cp /installer/scaffold/' . preg_quote($file, '#') . '\s+/app/' . preg_quote($file, '#') . '$#m',
                $script,
                "{$file}: a fresh project needs it to run test:run",
            );
        }
        self::assertStringContainsString('mkdir -p /app/docker/e2e-runner', $script);
    }

    /**
     * REGRESSION (same run). docker-compose.test.yml was copied as root and left root's:
     * the user who owns the project could not edit it without sudo.
     */
    public function test_every_file_it_copies_is_handed_to_the_user(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 4) . '/semitexa-installer/commands/install.sh');
        preg_match_all('#^cp /installer/scaffold/\S+\s+/app/(\S+)$#m', $script, $copied);
        self::assertNotEmpty($copied[1]);
        $owners = [];
        $owned = [];
        $inList = false;
        foreach (explode("\n", $script) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'chown -R "${_uid}:${_gid}" ')) {
                array_push($owners, ...array_slice(explode(' ', $line), 3));
            } elseif ($line === 'chown "${_uid}:${_gid}" \\') {
                $inList = true;
            } elseif ($inList) {
                $owned[] = rtrim($line, ' \\');
                $inList = str_ends_with($line, '\\');
            }
        }
        self::assertNotEmpty($owned, 'the chown list');

        foreach ($copied[1] as $file) {
            $path = '/app/' . $file;
            $underTree = array_filter($owners, static fn (string $tree): bool => str_starts_with($path, rtrim($tree, '/') . '/'));
            self::assertTrue(in_array($path, $owned, true) || $underTree !== [], "{$path} is copied as root and never handed to the user");
        }
    }

    public function test_the_runner_builds_its_image_from_the_copied_dockerfile(): void
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 4) . '/semitexa-installer/scaffold/docker-compose.test.yml');

        self::assertStringContainsString('dockerfile: docker/e2e-runner/Dockerfile', $compose);
        self::assertStringContainsString('--config=playwright.config.ts', $compose);
    }

    public function test_the_config_finds_the_specs_where_test_run_looks(): void
    {
        $root = dirname(__DIR__, 4) . '/semitexa-installer/scaffold';
        $config = (string) file_get_contents($root . '/playwright.config.ts');
        $bin = (string) file_get_contents($root . '/bin/semitexa');

        // bin/semitexa discovers src/modules/*/tests/E2E and hands the dirs over as filters:
        // a testMatch that misses them would run nothing and pass.
        self::assertStringContainsString("'src/modules/*/tests/E2E/**/*.spec.ts'", $config);
        self::assertStringContainsString('src/modules/*/tests/E2E', $bin);
        self::assertStringContainsString("outputDir: 'var/test-results/e2e'", $config);
        self::assertMatchesRegularExpression('#^var/test-results/$#m', (string) file_get_contents($root . '/.gitignore'));
    }
}
