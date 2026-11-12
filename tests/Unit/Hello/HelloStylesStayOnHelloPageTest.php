<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Hello;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * hello.css styles html, body and h1. As a module asset (the v2 default for
 * css) every page added to Hello wore it, and on 2026-11-10 a page made with
 * `make:page --module=Hello` showed dark text on the dark page
 * (tk-hello-css-leaks-to-module-pages). It is a page asset now: the Hello page
 * links it, and a new page in the module starts from the theme.
 */
final class HelloStylesStayOnHelloPageTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../src/modules/Hello/src/Application';

    #[Test]
    public function hello_css_is_a_page_asset_the_hello_page_links_itself(): void
    {
        $manifest = json_decode((string) file_get_contents(self::MODULE . '/Static/assets.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('page', $manifest['overrides']['css/hello.css']['scope'] ?? null, 'a module-scoped hello.css styles every page in Hello');

        $template = (string) file_get_contents(self::MODULE . '/View/templates/hello.html.twig');
        self::assertStringContainsString("asset('css/hello.css', 'Hello')", $template, 'the Hello page loses its styles');
    }
}
