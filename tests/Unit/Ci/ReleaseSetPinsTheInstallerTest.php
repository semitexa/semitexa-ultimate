<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Ci;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The release set names semitexa/installer, so a release that changes only the
 * scaffold reaches the projects made before it.
 *
 * From step 2 of one source for bin/semitexa, semitexa/update reads the
 * scaffold from vendor/semitexa/installer. A project made before that has no
 * semitexa/installer in its composer.json: it arrives through update, and the
 * update's composer stage sees it as transitive. That stage runs composer only
 * when a pin is behind the release set, which is read from the newest
 * semitexa/ultimate's require. Without the installer in it, a release of
 * the installer alone would leave every older project on the old scaffold,
 * with the scaffold stage reporting it current (Fable's review of the step 2
 * plan, D1). Finalize pins every semitexa/* entry here to the version it
 * releases, so the pin follows each cut by itself.
 */
final class ReleaseSetPinsTheInstallerTest extends TestCase
{
    #[Test]
    public function the_installer_is_pinned_like_every_other_framework_package(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 3) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['require'] ?? null);

        $pin = $manifest['require']['semitexa/installer'] ?? null;
        self::assertIsString($pin, 'semitexa/ultimate does not require semitexa/installer: the release set would not carry it.');
        // The exact form finalize writes and the update's release set reads
        // (ComposerUpdatePlanner::RELEASE_VERSION): a range, or a beta in a
        // stable ultimate, is left out of the set.
        self::assertMatchesRegularExpression('/^\d{4}\.\d{2}\.\d{2}\.\d{4}$/', $pin);
    }
}
