<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Hello;

use PHPUnit\Framework\TestCase;

/**
 * Hello is the first page an agent copies, and it shows deferred blocks only.
 * An agent that copied it took deferred for the whole story and never found
 * how a block stays current (bench-hello-shows-no-live): the page and the slot
 * class say that deferred is delivered once and where live comes from.
 */
final class HelloSaysWhereLiveComesFromTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../src/modules/Hello/src/Application';

    private const MECHANISM = 'bin/semitexa ai:ask mechanisms --id=ssr.transport';

    public function test_the_page_says_deferred_is_not_live_and_where_live_comes_from(): void
    {
        $messages = json_decode((string) file_get_contents(self::MODULE . '/View/locales/en.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($messages);
        $body = $messages['hello.deferred_body'] ?? null;

        self::assertIsString($body);
        self::assertStringContainsString('Deferred means delivered once, not live', $body);
        self::assertStringContainsString('signed-in visitors', $body);
        self::assertStringContainsString('guests', $body);
        self::assertStringContainsString(self::MECHANISM, $body);
    }

    public function test_the_slot_an_agent_copies_says_it_too(): void
    {
        $source = (string) file_get_contents(self::MODULE . '/Resource/Slot/GuideOneSlot.php');

        self::assertStringContainsString('deferred: true', $source, 'the slot this explains is still a deferred one');
        self::assertStringContainsString('It is not' . "\n" . ' * live.', $source);
        self::assertStringContainsString(self::MECHANISM, $source);
        self::assertStringContainsString('SSR_DEFERRED_PERSISTENT_SSE=true', $source);
    }
}
