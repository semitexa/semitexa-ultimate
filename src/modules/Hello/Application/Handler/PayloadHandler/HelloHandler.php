<?php

declare(strict_types=1);

namespace App\Modules\Hello\Application\Handler\PayloadHandler;

use App\Modules\Hello\Application\Payload\Request\HelloPayload;
use App\Modules\Hello\Application\Resource\Response\HelloResource;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Tenancy\Support\TenantUrlResolver;

#[AsPayloadHandler(payload: HelloPayload::class, resource: HelloResource::class)]
final class HelloHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

    public function handle(HelloPayload $_payload, HelloResource $resource): HelloResource
    {
        $demoLink = $this->resolveDemoLink();

        return $resource
            ->withPhpVersion(PHP_VERSION)
            ->withSwooleVersion(defined('SWOOLE_VERSION') ? SWOOLE_VERSION : 'n/a')
            ->withDemoUrl($demoLink['url'])
            ->withDemoUrlIsExternal($demoLink['external'])
            ->withDemoInstalledLocally(! $demoLink['external']);
    }

    /**
     * @return array{url: string, external: bool}
     */
    private function resolveDemoLink(): array
    {
        if ($this->moduleRegistry->isActive('demo')) {
            $demoUrl = TenantUrlResolver::resolveUrl('demo', '/demo') ?? '/demo';

            return [
                'url' => $demoUrl,
                'external' => false,
            ];
        }

        return [
            'url' => TenantUrlResolver::resolveUrl('demo', '/demo', true) ?? 'https://framework.semitexa.com/demo',
            'external' => true,
        ];
    }
}
