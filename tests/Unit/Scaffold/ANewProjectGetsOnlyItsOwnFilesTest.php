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
        $tests = array_values(array_filter(
            $this->archivedPaths(),
            static fn (string $path): bool => str_starts_with($path, 'tests/') && str_ends_with($path, '.php'),
        ));
        self::assertNotSame([], $tests, 'the archive carries the project\'s tests: an empty list would pass the check below');

        $reading = [];
        foreach ($tests as $path) {
            $source = $this->archivedSource($path);
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
        $php = array_values(array_filter(
            $this->archivedPaths(),
            static fn (string $path): bool => str_starts_with($path, 'src/') && str_ends_with($path, '.php'),
        ));
        self::assertContains('src/modules/Hello/src/Application/Handler/PayloadHandler/HelloHandler.php', $php, 'the archive carries the sample module: an empty list would pass the check below');

        $declaring = array_values(array_filter(
            $php,
            fn (string $path): bool => self::declaresTheSkeletonsNamespace($this->archivedSource($path)),
        ));
        self::assertSame([], $declaring, 'these declare Semitexa\Ultimate inside a new project\'s src/');
    }

    /** Review, ultimate#159: a declaration need not start its line, and a comment or a longer name is not one. */
    public function test_a_namespace_declaration_is_found_wherever_it_stands(): void
    {
        self::assertTrue(self::declaresTheSkeletonsNamespace('<?php namespace Semitexa\Ultimate; final class Example {}'));
        self::assertTrue(self::declaresTheSkeletonsNamespace("<?php\n\ndeclare(strict_types=1);\n\nnamespace Semitexa\\Ultimate\\Application\\Console\\Command;\n"));
        self::assertTrue(self::declaresTheSkeletonsNamespace('<?php namespace Semitexa\Ultimate { final class Example {} }'));
        self::assertTrue(self::declaresTheSkeletonsNamespace('<?php namespace /* the skeleton */ Semitexa\Ultimate;'));
        self::assertTrue(self::declaresTheSkeletonsNamespace("<?php\n\nnamespace semitexa\\ultimate\\Tools;\n"));
        self::assertFalse(self::declaresTheSkeletonsNamespace("<?php\n\nnamespace App\\Modules\\Hello;\n"));
        self::assertFalse(self::declaresTheSkeletonsNamespace("<?php\n// namespace Semitexa\\Ultimate;\nnamespace App;\n"));
        self::assertFalse(self::declaresTheSkeletonsNamespace("<?php\n\nnamespace Semitexa\\UltimateTools;\n"));
    }

    /** True when the PHP source declares Semitexa\Ultimate or a namespace inside it, read by its tokens. */
    private static function declaresTheSkeletonsNamespace(string $source): bool
    {
        $tokens = token_get_all($source);
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }
            for ($j = $i + 1; isset($tokens[$j]); $j++) {
                $next = $tokens[$j];
                if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                // Namespaces are case-insensitive in PHP.
                $name = is_array($next) ? strtolower($next[1]) : '';
                if ($name === 'semitexa\ultimate' || str_starts_with($name, 'semitexa\ultimate\\')) {
                    return true;
                }
                break;
            }
        }

        return false;
    }

    /**
     * A file as the archive has it: read from the tree the archive is made from,
     * not from the checkout, which may carry edits (review, ultimate#159).
     */
    private function archivedSource(string $path): string
    {
        $root = dirname(__DIR__, 3);
        exec(sprintf('git -C %s show %s 2>&1', escapeshellarg($root), escapeshellarg('HEAD:' . $path)), $lines, $exit);
        // A file that cannot be read was not checked: it fails, never passes.
        self::assertSame(0, $exit, "cannot read {$path}, which the archive carries:\n" . implode("\n", $lines));
        $source = implode("\n", $lines);
        self::assertNotSame('', $source, "{$path}, which the archive carries, is empty");

        return $source;
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
