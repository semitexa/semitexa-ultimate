<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Swoole is built at a version the HTTP client is known to work on. Unpinned,
 * a rebuild on 2026-10-29 picked up 6.2.4, whose curl hook inflates a gzip
 * answer in memory before the client's write callback sees a byte: the
 * decompression-bomb cap stopped nothing, and a truncated compressed body
 * failed as "does not inflate" (release preflight, CurlHttpClientCoroutineTest).
 * Raise the pin only with that test green on the new version.
 */
final class SwoolePinnedTest extends TestCase
{
    #[Test]
    public function the_image_builds_the_swoole_version_it_was_tested_on(): void
    {
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 3) . '/Dockerfile');

        self::assertSame(1, preg_match('/^ARG SWOOLE_VERSION=(\S+)$/m', $dockerfile, $m), 'Dockerfile must declare ARG SWOOLE_VERSION');
        self::assertSame('6.2.3', $m[1]);
        self::assertStringContainsString('pecl install --nobuild swoole-"${SWOOLE_VERSION}"', $dockerfile);
        self::assertStringNotContainsString('pecl install --nobuild swoole \\', $dockerfile, 'an unpinned install takes whatever PECL serves that day');
    }
}
