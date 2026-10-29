<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The image's ImageMagick policy denies every coder but the raster formats
 * media handles. ImageMagick reads the BMP most tools write as BMP3 (and the
 * oldest as BMP2) — coders of their own, so allowing BMP alone refused them:
 * the release preflight on 2026-10-29 failed media's
 * a_bmp_of_any_header_version_is_image_bmp, and an uploaded BMP3 would have
 * been refused in production the same way.
 */
final class ImageMagickPolicyTest extends TestCase
{
    #[Test]
    public function every_raster_format_media_handles_has_its_coders_allowed(): void
    {
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 3) . '/Dockerfile');

        self::assertSame(1, preg_match('/<policy domain="coder" rights="read\|write" pattern="\{([^}]+)\}"\/>/', $dockerfile, $m), 'the coder allow-list must stay one pattern');
        $allowed = explode(',', $m[1]);
        sort($allowed);

        self::assertSame(['AVIF', 'BMP', 'BMP2', 'BMP3', 'GIF', 'HEIC', 'HEIF', 'JPEG', 'JPG', 'PNG', 'WEBP'], $allowed);
        self::assertStringContainsString('<policy domain="coder" rights="none" pattern="*"/>', $dockerfile, 'everything else stays denied');
    }
}
