<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tk-init-command-lands-in-every-projects-src (with Fable 16.11): init is retired.
 * The PHP command it ran lived in the skeleton's own src/, so it landed in every
 * project; a project is made by the installer and bin/semitexa update keeps its
 * scaffold files current. The shell commands run as bin/semitexa has them.
 */
final class InitIsRetiredTest extends TestCase
{
    // verify:accept-test-change step 4c of tk-one-source-for-bin-semitexa: the runtime-file tests moved to semitexa-installer tests/Unit/Scaffold (installer#134, #135), and ultimate's copies of those files are deleted with them

    private const STUBS = <<<'SH'
        info() { printf 'INFO %s\n' "$*"; }
        error() { printf 'ERROR %s\n' "$*"; }
        warn() { printf 'WARN %s\n' "$*"; }
        run_compose() { printf 'COMPOSE %s\n' "$*"; }
        has_compose() { return 0; }
        SH;

    /**
     * The scaffold docs' headers said a new project gets them from init and that
     * init --only-docs refreshes them. An agent that read those lines ran a
     * command that is retired (tk-scaffold-doc-headers-name-the-retired-init).
     *
     * @return iterable<string, array{string}> the docs scaffold:sync-docs keeps in step
     */
    public static function scaffoldDocs(): iterable
    {
        foreach (['AGENTS.md', 'AGENTS_DOCTRINE.md', 'AI_ENTRY.md', 'AI_REFERENCE.md', 'AI_CONTEXT.md', 'CLAUDE.md', 'README.md'] as $doc) {
            yield $doc => [$doc];
        }
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('scaffoldDocs')]
    public function no_scaffold_doc_sends_you_to_init(string $doc): void
    {
        $path = dirname(__DIR__, 3) . '/' . $doc;
        self::assertFileExists($path);
        $text = (string) file_get_contents($path);
        self::assertStringContainsString('semitexa/ultimate', $text, $doc . ' is read: its header names the scaffold it comes from');

        self::assertStringNotContainsString('bin/semitexa init', $text, $doc . ': init is retired; a new project comes from the installer, and bin/semitexa update keeps the scaffold docs current');
    }
}
