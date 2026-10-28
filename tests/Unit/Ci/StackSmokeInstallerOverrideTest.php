<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Ci;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * stack-smoke with the installer as the package under test: it is checked out
 * beside the app, not installed into vendor/, so the install stage matched no
 * package and failed before the scaffold parity test could run.
 */
final class StackSmokeInstallerOverrideTest extends TestCase
{
    #[Test]
    public function the_installer_is_told_apart_from_the_vendor_packages(): void
    {
        self::assertSame(
            ['semitexa-installer' => 0, 'alice/semitexa-installer' => 0, 'semitexa/semitexa-installer.git' => 0, 'semitexa-core' => 1, 'installer-tools' => 1, '' => 1],
            array_combine(
                ['semitexa-installer', 'alice/semitexa-installer', 'semitexa/semitexa-installer.git', 'semitexa-core', 'installer-tools', ''],
                array_map($this->isInstaller(...), ['semitexa-installer', 'alice/semitexa-installer', 'semitexa/semitexa-installer.git', 'semitexa-core', 'installer-tools', '']),
            ),
        );
    }

    #[Test]
    public function an_installer_override_does_not_fail_the_install_stage(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/tools/stack-smoke.sh');

        self::assertMatchesRegularExpression('/\[ "\$matched" = 0 \] && ! override_is_installer; then\n\s*record install FAIL/', $script);
    }

    /** The exit status of override_is_installer, run as the script runs it. */
    private function isInstaller(string $repo): int
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/tools/stack-smoke.sh');
        preg_match('/^override_is_installer\(\) \{\n.*?^\}$/ms', $script, $m);
        self::assertNotEmpty($m, 'override_is_installer must exist in tools/stack-smoke.sh');

        exec(sprintf('env -i SEMITEXA_OVERRIDE_REPO=%s bash -c %s', escapeshellarg($repo), escapeshellarg($m[0] . "\noverride_is_installer")), $out, $status);

        return $status;
    }
}
