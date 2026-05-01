<?php

declare(strict_types=1);

namespace App\Modules\Hello\Application\Resource\Slot;

use Semitexa\Ssr\Attribute\AsSlotResource;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlSlotResponse;

#[AsSlotResource(
    handle: 'hello',
    slot: 'guide_01',
    template: '@project-layouts-Hello/slots/guide.html.twig',
    deferred: true,
    skeletonTemplate: '@project-layouts-Hello/slots/guide.skeleton.html.twig',
    mode: 'template',
    context: [
        'index' => '01',
        'title_key' => 'hello.panel1_title',
        'body_key' => 'hello.panel1_body',
    ],
)]
final class GuideOneSlot extends HtmlSlotResponse
{
}
