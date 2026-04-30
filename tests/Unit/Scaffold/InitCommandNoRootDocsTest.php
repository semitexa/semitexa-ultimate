<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\TestCase;

/**
 * Guardrail: the installer must never scaffold or sync a root-level `docs/`
 * directory into generated projects. Official framework documentation lives
 * in `vendor/semitexa/docs/` when installed (or `packages/semitexa-docs/` in
 * the Semitexa workspace). Root `./docs/` is not part of the framework
 * contract — see `vendor/semitexa/docs/workspace/DOCUMENTATION_OWNERSHIP.md`.
 *
 * These assertions run against the InitCommand source file text so the guard
 * stays independent of whether the ultimate package is autoloaded into the
 * surrounding workspace.
 */
final class InitCommandNoRootDocsTest extends TestCase
{
    private const DISALLOWED_DOCS_LITERALS = [
        "'docs'",
        "'docs/'",
        "'./docs'",
        "'./docs/'",
        "'docs/AI_CONTEXT.md'",
        "\"docs/AI_CONTEXT.md\"",
        "=> 'docs/",
    ];

    public function testInitCommandSourceDoesNotReferenceRootDocs(): void
    {
        $source = $this->readInitCommandSource();

        foreach (self::DISALLOWED_DOCS_LITERALS as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $source,
                sprintf(
                    'InitCommand source must not contain %s. '
                    . 'The framework does not seed, sync, or depend on a root-level docs/ directory. '
                    . 'Official framework docs live in vendor/semitexa/docs; AI scaffold files live at project root. '
                    . 'See vendor/semitexa/docs/workspace/DOCUMENTATION_OWNERSHIP.md.',
                    $needle,
                ),
            );
        }
    }

    public function testInitCommandScaffoldsAiContextAtRoot(): void
    {
        $source = $this->readInitCommandSource();

        self::assertStringContainsString(
            "'AI_CONTEXT.md' => 'AI_CONTEXT.md'",
            $source,
            'InitCommand should scaffold AI_CONTEXT.md at project root (not under docs/). '
            . 'If the scaffold location changes, update this assertion AND the workspace '
            . 'documentation ownership policy together.',
        );
    }

    private function readInitCommandSource(): string
    {
        $path = dirname(__DIR__, 3) . '/src/Application/Console/Command/InitCommand.php';
        $source = file_get_contents($path);
        self::assertIsString($source, sprintf('Failed to read %s', $path));

        return $source;
    }
}
