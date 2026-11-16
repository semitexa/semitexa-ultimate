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
    private const FRAMEWORK_OWN = ['.github/', 'tools/', 'install.sh', 'SECURITY.md'];

    public function test_the_archive_a_new_project_unpacks_carries_none_of_the_frameworks_own_files(): void
    {
        $paths = $this->archivedPaths();

        // The archive is real and is the skeleton: an empty listing would pass every check below.
        self::assertContains('composer.json', $paths);
        self::assertContains('bin/semitexa', $paths);
        self::assertContains('docker-compose.yml', $paths);

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
