<?php

declare(strict_types=1);

namespace App\Modules\Hello\Application\Resource\Slot;

use Semitexa\Ssr\Attribute\AsSlotResource;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlSlotResponse;

#[AsSlotResource(
    handle: 'hello',
    slot: 'guide_02',
    template: '@project-layouts-Hello/slots/guide.html.twig',
    deferred: true,
    skeletonTemplate: '@project-layouts-Hello/slots/guide.skeleton.html.twig',
    mode: 'template',
    context: [
        'index' => '02',
        'title_key' => 'hello.panel2_title',
        'body_key' => 'hello.panel2_body',
    ],
)]
final class GuideTwoSlot extends HtmlSlotResponse
{
}
