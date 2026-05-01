<?php

declare(strict_types=1);

namespace App\Modules\Hello\Application\Resource\Slot;

use Semitexa\Ssr\Attribute\AsSlotResource;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlSlotResponse;

#[AsSlotResource(
    handle: 'hello',
    slot: 'guide_03',
    template: '@project-layouts-Hello/slots/guide.html.twig',
    deferred: true,
    skeletonTemplate: '@project-layouts-Hello/slots/guide.skeleton.html.twig',
    mode: 'template',
    context: [
        'index' => '03',
        'title_key' => 'hello.panel3_title',
        'body_key' => 'hello.panel3_body',
    ],
)]
final class GuideThreeSlot extends HtmlSlotResponse
{
}
