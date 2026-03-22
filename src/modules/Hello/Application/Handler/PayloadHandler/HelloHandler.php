<?php

declare(strict_types=1);

namespace App\Modules\Hello\Application\Handler\PayloadHandler;

use App\Modules\Hello\Application\Payload\Request\HelloPayload;
use App\Modules\Hello\Application\Resource\Response\HelloResource;
use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Locale\Context\LocaleContextStore;

#[AsPayloadHandler(payload: HelloPayload::class, resource: HelloResource::class)]
final class HelloHandler implements TypedHandlerInterface
{
    public function handle(HelloPayload $payload, HelloResource $resource): HelloResource
    {
        LocaleContextStore::setLocale($payload->lang);

        return $resource
            ->withPhpVersion(PHP_VERSION)
            ->withSwooleVersion(defined('SWOOLE_VERSION') ? SWOOLE_VERSION : 'n/a')
            ->withAccentColor($payload->color)
            ->withLang($payload->lang);
    }
}
