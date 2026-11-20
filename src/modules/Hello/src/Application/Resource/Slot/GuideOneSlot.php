<?php

declare(strict_types=1);

namespace App\Modules\Hello\Application\Resource\Slot;

use Semitexa\Ssr\Attribute\AsSlotResource;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlSlotResponse;

/**
 * A deferred slot: rendered after the page and streamed in once. It is not
 * live. A block that keeps itself current is an island
 * (UiIslandInterface::watches) for signed-in visitors, or a public SSE feed
 * for guests: `bin/semitexa ai:ask mechanisms --id=ssr.transport` shows both.
 * refreshInterval alone stays silent on a guest page, because a persistent
 * deferred stream needs SSR_DEFERRED_PERSISTENT_SSE=true and, by default, a
 * signed-in visitor.
 */
#[AsSlotResource(
    handle: 'hello',
    slot: 'guide_01',
    template: '@project-layouts-Hello/deferred/guide.html.twig',
    deferred: true,
    skeletonTemplate: '@project-layouts-Hello/deferred/guide.skeleton.html.twig',
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
