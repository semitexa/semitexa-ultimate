<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Hello;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../src/modules/Hello/Application/Handler/SlotHandler/GuideSlotTemplateDataHandler.php';
require_once __DIR__ . '/../../../src/modules/Hello/Application/Resource/Slot/GuideOneSlot.php';
require_once __DIR__ . '/../../../src/modules/Hello/Application/Resource/Slot/GuideTwoSlot.php';
require_once __DIR__ . '/../../../src/modules/Hello/Application/Resource/Slot/GuideThreeSlot.php';

final class HelloDeferredAttributesTest extends TestCase
{
    public function testHelloDeferredSlotsUseDiscoverableAttributes(): void
    {
        $slotClasses = [
            \App\Modules\Hello\Application\Resource\Slot\GuideOneSlot::class,
            \App\Modules\Hello\Application\Resource\Slot\GuideTwoSlot::class,
            \App\Modules\Hello\Application\Resource\Slot\GuideThreeSlot::class,
        ];

        foreach ($slotClasses as $slotClass) {
            $reflection = new \ReflectionClass($slotClass);
            $attributes = $reflection->getAttributes(\Semitexa\Ssr\Attribute\AsSlotResource::class);

            self::assertNotEmpty($attributes, $slotClass . ' must expose a discoverable AsSlotResource attribute');
        }

        $handlerReflection = new \ReflectionClass(\App\Modules\Hello\Application\Handler\SlotHandler\GuideSlotTemplateDataHandler::class);
        $handlerAttributes = $handlerReflection->getAttributes(\Semitexa\Ssr\Attribute\AsSlotHandler::class);

        self::assertNotEmpty($handlerAttributes, 'Hello slot handler must expose a discoverable AsSlotHandler attribute');
    }
}
