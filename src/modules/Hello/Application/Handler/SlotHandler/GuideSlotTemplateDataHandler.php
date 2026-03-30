<?php

declare(strict_types=1);

namespace App\Modules\Hello\Application\Handler\SlotHandler;

use App\Modules\Hello\Application\Resource\Slot\GuideOneSlot;
use App\Modules\Hello\Application\Resource\Slot\GuideThreeSlot;
use App\Modules\Hello\Application\Resource\Slot\GuideTwoSlot;
use Semitexa\Core\Environment;
use Semitexa\Ssr\Attributes\AsSlotHandler;
use Semitexa\Ssr\Contract\TypedSlotHandlerInterface;
use Semitexa\Ssr\Http\Response\HtmlSlotResponse;
use Semitexa\Ssr\I18n\Translator;
use Swoole\Coroutine;

#[AsSlotHandler(slot: GuideOneSlot::class)]
#[AsSlotHandler(slot: GuideTwoSlot::class)]
#[AsSlotHandler(slot: GuideThreeSlot::class)]
final class GuideSlotTemplateDataHandler implements TypedSlotHandlerInterface
{
    public function handle(object $slot): object
    {
        if (!$slot instanceof HtmlSlotResponse) {
            return $slot;
        }

        $context = $slot->getStaticContext();
        $titleKey = (string) ($context['title_key'] ?? '');
        $bodyKey = (string) ($context['body_key'] ?? '');

        // Intentional demo delay: these staggered sleeps make the deferred KISS loading
        // visible in the browser so Semitexa's async slot rendering is easy to notice.
        $this->sleepForSlot((string) ($context['index'] ?? ''));

        return $slot->withRenderContext([
            'index' => (string) ($context['index'] ?? ''),
            'title' => $titleKey !== '' ? Translator::trans($titleKey) : '',
            'bodyHtml' => $bodyKey !== '' ? $this->sanitizeGuideBody(Translator::trans($bodyKey)) : '',
        ]);
    }

    private function sleepForSlot(string $index): void
    {
        if (Environment::getEnvValue('HELLO_DEMO_DELAY', '1') !== '1') {
            return;
        }

        $delayMs = match ($index) {
            '01' => random_int(450, 3000),
            '02' => random_int(450, 3000),
            '03' => random_int(450, 3000),
            default => 0,
        };

        if ($delayMs <= 0) {
            return;
        }

        if (class_exists(Coroutine::class, false) && Coroutine::getCid() > 0) {
            Coroutine::sleep($delayMs / 1000);
            return;
        }

        usleep($delayMs * 1000);
    }

    private function sanitizeGuideBody(string $body): string
    {
        $parts = preg_split('/(<code>.*?<\/code>)/s', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $sanitized = '';
        foreach ($parts as $part) {
            if (preg_match('/^<code>(.*?)<\/code>$/s', $part, $matches) === 1) {
                $sanitized .= '<code>' . htmlspecialchars($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
                continue;
            }

            $sanitized .= htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $sanitized;
    }
}
