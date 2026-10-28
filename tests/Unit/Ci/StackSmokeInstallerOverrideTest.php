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

    /**
     * One expression served both cases: an owner/semitexa-installer.git override
     * was checked out from upstream, and an empty override_ref fell back to a
     * branch the fork need not have. Each case now has a checkout of its own.
     */
    #[Test]
    public function the_installer_under_test_is_checked_out_as_given_and_the_default_by_branch(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/stack-smoke.yml');
        $isInstaller = "(endsWith(inputs.override_repo, 'semitexa-installer'))";

        self::assertSame([
            'Check out the installer scaffold' => [
                'if' => '${{ !' . $isInstaller . ' }}',
                'repository' => 'semitexa/semitexa-installer',
                'ref' => "\${{ github.event.repository.name == 'semitexa-ultimate' && (github.base_ref || github.ref_name) || 'develop' }}",
                'path' => 'semitexa-installer',
            ],
            'Check out the installer under test' => [
                'if' => '${{ ' . substr($isInstaller, 1, -1) . ' }}',
                'repository' => "\${{ contains(inputs.override_repo, '/') && inputs.override_repo || format('semitexa/{0}', inputs.override_repo) }}",
                'ref' => '${{ inputs.override_ref }}',
                'path' => 'semitexa-installer',
            ],
        ], [
            'Check out the installer scaffold' => $this->step($workflow, 'Check out the installer scaffold'),
            'Check out the installer under test' => $this->step($workflow, 'Check out the installer under test'),
        ]);
    }

    /**
     * actions/checkout takes a repository name: 'owner/name.git' failed in
     * the default-branch lookup, after half the steps had run. It is refused
     * first, with what to pass instead.
     */
    #[Test]
    public function an_override_spelled_with_git_is_refused_before_anything_runs(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/stack-smoke.yml');
        preg_match_all('/^      - name: (.+)$/m', $workflow, $names);

        self::assertSame('Refuse an override_repo spelled with .git', $names[1][0] ?? null);
        self::assertSame(['if' => '${{ endsWith(inputs.override_repo, \'.git\') }}'], $this->step($workflow, 'Refuse an override_repo spelled with .git'));
        self::assertStringContainsString("override_repo is a repository name (owner/name or name), not a clone URL: drop the .git", $workflow);
    }

    /** @return array<string, string> the step's if, repository, ref and path */
    private function step(string $workflow, string $name): array
    {
        preg_match('/^      - name: ' . preg_quote($name, '/') . '\n(.*?)(?=^      - |\z)/ms', $workflow, $m);
        self::assertNotEmpty($m, "step '{$name}' must exist");
        $found = [];
        foreach (['if', 'repository', 'ref', 'path'] as $key) {
            if (preg_match('/^\s+' . $key . ': (.+)$/m', $m[1], $v) === 1) {
                $found[$key] = $v[1];
            }
        }

        return $found;
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
