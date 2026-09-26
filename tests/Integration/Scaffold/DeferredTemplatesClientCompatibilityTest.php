<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Integration\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every deferred slot template the scaffold ships must be renderable by the
 * client-side Twig subset.
 *
 * In dev, DeferredTemplateRegistry refuses an incompatible template when it
 * publishes it at worker boot, so a single `{{ trans(...) }}` in a deferred
 * slot kills every worker of a fresh install. That check needs a booted
 * server; this runs the same validator through `lint:deferred-twig` so the
 * test suite catches it first.
 */
final class DeferredTemplatesClientCompatibilityTest extends TestCase
{
    #[Test]
    public function scaffold_deferred_templates_pass_the_client_renderer_lint(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $console = $projectRoot . '/vendor/bin/semitexa';
        if (!is_file($console)) {
            self::fail('Needs an installed project (vendor/bin/semitexa): this gate must not pass unchecked.');
        }

        $command = sprintf(
            'cd %s && %s %s lint:deferred-twig --json 2>&1',
            escapeshellarg($projectRoot),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($console),
        );
        exec($command, $lines, $exitCode);
        $output = implode("\n", $lines);

        $start = strpos($output, '{');
        $report = $start === false ? null : json_decode(substr($output, $start), true);

        self::assertIsArray($report, "lint:deferred-twig did not produce a JSON report:\n" . $output);
        self::assertSame([], $report['errors'] ?? null, "Deferred templates the client cannot render:\n" . $output);
        self::assertTrue($report['clean'] ?? false);
        self::assertSame(0, $exitCode, $output);
    }
}
