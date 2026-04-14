<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Hello;

use PHPUnit\Framework\TestCase;

final class GuideSlotTemplateDataHandlerTest extends TestCase
{
    public function testSanitizeGuideBodyEscapesUnsafeMarkupButKeepsCodeBlocks(): void
    {
        $handler = new \App\Modules\Hello\Application\Handler\SlotHandler\GuideSlotTemplateDataHandler();
        $method = new \ReflectionMethod($handler, 'sanitizeGuideBody');

        $sanitized = $method->invoke(
            $handler,
            'Before <script>alert(1)</script> <a href="javascript:alert(1)" onclick="alert(1)">bad</a> <code><img src=x onerror=alert(1)></code> after'
        );

        self::assertIsString($sanitized);
        self::assertStringNotContainsString('<script>', $sanitized);
        self::assertStringNotContainsString('<a href=', $sanitized);
        self::assertStringContainsString('&lt;a href=&quot;javascript:alert(1)&quot; onclick=&quot;alert(1)&quot;&gt;bad&lt;/a&gt;', $sanitized);
        self::assertStringContainsString('<code>&lt;img src=x onerror=alert(1)&gt;</code>', $sanitized);
    }
}
