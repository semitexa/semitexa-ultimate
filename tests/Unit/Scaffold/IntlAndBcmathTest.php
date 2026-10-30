<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The image carries bcmath and intl (operator decision 2026-10-30): the
 * release's backward-compatibility gate, roave/backward-compatibility-check,
 * will not install without them, and applications reach for both. With
 * Alpine's default ICU data, intl knows English only and formats every other
 * locale as English, so the full data comes with it.
 */
final class IntlAndBcmathTest extends TestCase
{
    #[Test]
    public function the_image_builds_bcmath_and_intl_with_every_locale(): void
    {
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 3) . '/Dockerfile');

        self::assertSame(1, preg_match('/^RUN apk add --no-cache icu-libs icu-data-full \\\\\n\s+&& apk add --no-cache --virtual \.intl-build icu-dev \\\\\n\s+&& docker-php-ext-install bcmath intl \\\\\n\s+&& apk del \.intl-build$/m', $dockerfile), 'one layer: runtime ICU with full data, headers only for the build');
        self::assertGreaterThan(strpos($dockerfile, 'RUN docker-php-ext-install pcntl'), strpos($dockerfile, 'docker-php-ext-install bcmath intl'), 'after the Swoole build, so adding it re-uses that cached layer');
    }
}
