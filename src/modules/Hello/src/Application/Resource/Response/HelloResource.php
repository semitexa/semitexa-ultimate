<?php

declare(strict_types=1);

namespace App\Modules\Hello\Application\Resource\Response;

use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;

#[AsResource(handle: 'hello', template: '@project-layouts-Hello/hello.html.twig')]
class HelloResource extends HtmlResponse implements ResourceInterface
{
    public function withPhpVersion(string $version): self
    {
        return $this->with('phpVersion', $version);
    }

    public function withSwooleVersion(string $version): self
    {
        return $this->with('swooleVersion', $version);
    }

    public function withDemoUrl(string $url): self
    {
        return $this->with('demoUrl', $url);
    }

    public function withDemoUrlIsExternal(bool $isExternal): self
    {
        return $this->with('demoUrlIsExternal', $isExternal);
    }

    public function withDemoInstalledLocally(bool $isInstalledLocally): self
    {
        return $this->with('demoInstalledLocally', $isInstalledLocally);
    }
}
