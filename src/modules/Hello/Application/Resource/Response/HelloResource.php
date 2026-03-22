<?php

declare(strict_types=1);

namespace App\Modules\Hello\Application\Resource\Response;

use Semitexa\Core\Attributes\AsResource;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Ssr\Http\Response\HtmlResponse;

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

    public function withAccentColor(string $color): self
    {
        return $this->with('accentColor', $color);
    }

    public function withLang(string $lang): self
    {
        return $this->with('lang', $lang);
    }
}
