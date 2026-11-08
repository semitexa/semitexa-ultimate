<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\TestCase;

/**
 * A test that reads the installer checkout beside this repository cannot run in
 * a project made with `composer create-project`: it has no such checkout, and
 * its first test:run went red (bench-fresh-testrun-red, 2026-11-04). Each one is
 * kept out of the archive with export-ignore.
 *
 * REGRESSION (review ultimate#131): InstallerGivesTheE2eRunnerTest was added
 * without the line, and every fresh project would have failed it.
 */
final class InstallerCheckoutTestsStayOutOfTheArchiveTest extends TestCase
{
    public function test_every_test_that_needs_the_installer_checkout_is_export_ignored(): void
    {
        $root = dirname(__DIR__, 3);
        $attributes = (string) file_get_contents($root . '/.gitattributes');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/tests', \FilesystemIterator::SKIP_DOTS));

        $needing = 0;
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php' || $file->getPathname() === __FILE__) {
                continue;
            }
            // A path joined onto a directory: a workflow expression naming the repository reads nothing.
            if (preg_match("#\\.\\s*'/semitexa-installer#", (string) file_get_contents($file->getPathname())) !== 1) {
                continue;
            }
            ++$needing;
            $relative = substr($file->getPathname(), strlen($root));
            self::assertMatchesRegularExpression('#^' . preg_quote($relative, '#') . '\s+export-ignore$#m', $attributes, "{$relative} reads the installer checkout and would ship to every new project");
        }
        self::assertGreaterThan(0, $needing, 'the scan found the tests it guards');
    }
}
