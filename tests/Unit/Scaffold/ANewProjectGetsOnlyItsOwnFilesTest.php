<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\TestCase;

/**
 * `composer create-project semitexa/ultimate` unpacks the archive git makes of
 * this repository, so what that archive holds is what every new project starts
 * with. It held the framework's own CI (.github/workflows: our release and
 * stack gates), its maintainer tools, its installer and its security policy: a
 * project on GitLab carried dead GitHub workflows, one on GitHub would have run
 * our gates in its CI, and a product's security reports were sent to us. A
 * project assumes no host (decision dec-projects-are-host-neutral, 2026-11-16).
 *
 * Asked of git itself — the archive as `create-project --prefer-dist` gets it,
 * with this working tree's .gitattributes — not of a list of patterns.
 */
final class ANewProjectGetsOnlyItsOwnFilesTest extends TestCase
{
    // tests/Unit/Scaffold: the skeleton's own tests, which went red in a project
    // whenever bin/semitexa update changed a file they assert (tk-skeleton-tests-go-red-after-update).
    // src/Application/Console/, src/Console/, src/Capabilities.php: the skeleton's
    // own code. Its init command sat in every project's src/
    // (tk-init-command-lands-in-every-projects-src). src/infrastructure/: a folder
    // nothing reads, removed by ep-update-dev-orm-ownership.
    private const FRAMEWORK_OWN = [
        '.github/', 'tools/', 'install.sh', 'SECURITY.md', 'tests/Unit/Scaffold/',
        'src/Application/Console/', 'src/Console/', 'src/Capabilities.php', 'src/infrastructure/',
    ];

    public function test_the_archive_a_new_project_unpacks_carries_none_of_the_frameworks_own_files(): void
    {
        $paths = $this->archivedPaths();

        // The archive is real and is the skeleton: an empty listing would pass every check below.
        self::assertContains('composer.json', $paths);
        self::assertContains('bin/semitexa', $paths);
        self::assertContains('docker-compose.yml', $paths);
        self::assertContains('tests/Unit/Hello/HelloDeferredAttributesTest.php', $paths, "the product keeps its own sample tests");

        $carried = array_values(array_filter(
            $paths,
            static function (string $path): bool {
                foreach (self::FRAMEWORK_OWN as $own) {
                    if ($path === rtrim($own, '/') || str_starts_with($path, $own)) {
                        return true;
                    }
                }

                return false;
            },
        ));
        self::assertSame([], $carried, 'these would ship to every new project; export-ignore them in .gitattributes');
    }

    /**
     * A test a new project gets runs in its first test:run, so it must not need
     * what the project does not get. Four did (review, ultimate#154): the CI and
     * soak tooling's tests read .github/ and tools/.
     */
    public function test_no_test_a_new_project_gets_reads_what_it_does_not_get(): void
    {
        $root = dirname(__DIR__, 3);
        $tests = array_values(array_filter(
            $this->archivedPaths(),
            static fn (string $path): bool => str_starts_with($path, 'tests/') && str_ends_with($path, '.php'),
        ));
        self::assertNotSame([], $tests, 'the archive carries the project\'s tests: an empty list would pass the check below');

        $reading = [];
        foreach ($tests as $path) {
            // A test that cannot be read was not checked: it fails, never passes.
            $source = file_get_contents($root . '/' . $path);
            if ($source === false || $source === '') {
                self::fail("cannot read {$path}, which the archive carries");
            }
            if (preg_match('#\.github/|[\'"/]tools/|install\.sh|SECURITY\.md#', $source) === 1) {
                $reading[] = $path;
            }
        }
        self::assertSame([], $reading, 'these tests read framework files a new project does not get; export-ignore them too');
    }

    // verify:accept-test-change NoInfrastructureScaffoldTest and InstallerSyncAllowListTest read InitCommand::SCAFFOLD_FILE_MAP, which is gone with init; what they guarded (no src/infrastructure/, no src/Console/, no skeleton namespace in a project's src/) is asked of the archive here
    /**
     * A project's src/ is App\: no file a new project gets there may declare the
     * skeleton's own namespace, or the class lands in the project's PSR-4 tree.
     */
    public function test_no_file_in_a_new_projects_src_declares_the_skeletons_namespace(): void
    {
        $root = dirname(__DIR__, 3);
        $php = array_values(array_filter(
            $this->archivedPaths(),
            static fn (string $path): bool => str_starts_with($path, 'src/') && str_ends_with($path, '.php'),
        ));
        self::assertContains('src/modules/Hello/src/Application/Handler/PayloadHandler/HelloHandler.php', $php, 'the archive carries the sample module: an empty list would pass the check below');

        $declaring = [];
        foreach ($php as $path) {
            $source = @file_get_contents($root . '/' . $path);
            if ($source === false) {
                self::fail("cannot read {$path}, which the archive carries");
            }
            if (preg_match('/^namespace\s+Semitexa\\\\Ultimate\b/m', $source) === 1) {
                $declaring[] = $path;
            }
        }
        self::assertSame([], $declaring, 'these declare Semitexa\Ultimate inside a new project\'s src/');
    }

    /** @return list<string> */
    private function archivedPaths(): array
    {
        // Only a git checkout has this test: it is export-ignored itself, so a new
        // project never gets it, and the workspace, the release clone and the
        // stack smoke are all checkouts. No skip: an archive that cannot be made
        // here is a failure worth seeing.
        $root = dirname(__DIR__, 3);

        $command = sprintf(
            'git -C %s archive --worktree-attributes --format=tar HEAD | tar -t',
            escapeshellarg($root),
        );
        exec($command . ' 2>&1', $lines, $exit);
        self::assertSame(0, $exit, "git archive failed:\n" . implode("\n", $lines));

        return array_values(array_filter($lines, static fn (string $l): bool => $l !== ''));
    }
}
