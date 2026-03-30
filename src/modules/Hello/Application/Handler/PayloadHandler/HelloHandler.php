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
        $demoLink = $this->resolveDemoLink();

        return $resource
            ->withPhpVersion(PHP_VERSION)
            ->withSwooleVersion(defined('SWOOLE_VERSION') ? SWOOLE_VERSION : 'n/a')
            ->withLang(LocaleContextStore::getLocale())
            ->withDemoUrl($demoLink['url'])
            ->withDemoUrlIsExternal($demoLink['external'])
            ->withDemoInstalledLocally(!$demoLink['external']);
    }

    /**
     * @return array{url: string, external: bool}
     */
    private function resolveDemoLink(): array
    {
        if (class_exists('Semitexa\\Demo\\Application\\Payload\\Request\\DemoHomePayload')) {
            return [
                'url' => '/demo',
                'external' => false,
            ];
        }

        return [
            'url' => 'https://demo.semitexa.com/demo/',
            'external' => true,
        ];
    }
}
