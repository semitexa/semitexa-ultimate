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
    private const STUBS = <<<'SH'
        info() { printf 'INFO %s\n' "$*"; }
        error() { printf 'ERROR %s\n' "$*"; }
        warn() { printf 'WARN %s\n' "$*"; }
        run_compose() { printf 'COMPOSE %s\n' "$*"; }
        has_compose() { return 0; }
        SH;

    #[Test]
    public function init_says_where_a_project_comes_from_and_runs_nothing(): void
    {
        [$out, $exit] = $this->runCommand('cmd_init', sys_get_temp_dir());

        self::assertSame([
            'INFO Nothing to init: a Semitexa project is made by the installer, and bin/semitexa update keeps its scaffold files current.',
            'INFO New project:      curl -fsSL https://semitexa.com/install.sh | bash -s my-project',
            "INFO This project's:   bin/semitexa update",
        ], $out);
        self::assertSame(0, $exit, 'a script that still calls init after an install asked for what is already there');
    }

    /**
     * The restore command names the root, quoted for the shell: run from another
     * repository, a bare `git checkout -- docker-compose.yml` restored that one's
     * file (review, core#249; tk-restore-hint-names-the-project-root).
     *
     * @return iterable<string, array{string}>
     */
    public static function roots(): iterable
    {
        yield 'a plain root' => ['no-project-'];
        yield 'a root with a quote and a space' => ["it's a project-"];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('roots')]
    public function server_start_outside_a_project_no_longer_sends_you_to_init(string $name): void
    {
        $empty = sys_get_temp_dir() . '/' . $name . bin2hex(random_bytes(6));
        mkdir($empty);
        try {
            [$out, $exit] = $this->runCommand('cmd_server_start', $empty);
        } finally {
            rmdir($empty);
        }

        self::assertSame([
            'ERROR docker-compose.yml not found in ' . $empty . '.',
            "INFO Run bin/semitexa in a Semitexa project. A file removed by mistake comes back with: git -C '" . str_replace("'", "'\\''", $empty) . "' checkout -- docker-compose.yml",
            'INFO A new project is made by the installer: curl -fsSL https://semitexa.com/install.sh | bash -s my-project',
        ], $out);
        self::assertSame(1, $exit);
    }

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

    /** @return array{list<string>, int} the lines printed (the banner left out), the exit code */
    private function runCommand(string $command, string $projectRoot): array
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
        self::assertSame(1, preg_match('/^' . $command . '\(\) \{\n.*?^\}$/ms', $script, $match), $command . ' must still exist in bin/semitexa');

        $sh = self::STUBS . "\n" . ($match[0] ?? '') . "\n(" . $command . "); printf 'EXIT %s\\n' \"\$?\"";
        $raw = (string) shell_exec(sprintf('env -i PATH=/usr/bin:/bin PROJECT_ROOT=%s sh -c %s 2>&1', escapeshellarg($projectRoot), escapeshellarg($sh)));
        $lines = array_values(array_filter(
            explode("\n", $raw),
            static fn (string $line): bool => trim($line) !== '' && !str_contains($line, 'Semitexa — '),
        ));
        $exitLine = (string) array_pop($lines);
        self::assertMatchesRegularExpression('/^EXIT \d+$/', $exitLine, $raw);

        return [$lines, (int) substr($exitLine, 5)];
    }
}
