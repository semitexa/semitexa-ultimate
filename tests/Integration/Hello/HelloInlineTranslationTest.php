<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Integration\Hello;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Locale\Application\Service\I18n\JsonFileLoader;
use Semitexa\Locale\Application\Service\I18n\TranslationCatalog;
use Semitexa\Locale\Application\Service\I18n\TranslationService;
use Semitexa\Locale\Context\LocaleManager;

/**
 * Pins the regression where inline `trans('hello.*')` calls in the Hello
 * module template returned the literal key because JsonFileLoader looked
 * for locales at `{module}/Application/View/locales` while the canonical
 * Semitexa local-module layout puts them under `{module}/src/Application/View/locales`.
 *
 * The fixture is the real Hello module shipped under
 * `packages/semitexa-ultimate/src/modules/Hello/`. We mount that as the
 * modules root and assert the catalog resolves headline keys to their
 * translated values.
 */
final class HelloInlineTranslationTest extends TestCase
{
    private const SOURCE_LOCALES_PATH = __DIR__ . '/../../../src/modules/Hello/src/Application/View/locales/en.json';

    #[Test]
    public function loader_resolves_hello_locales_from_canonical_module_layout(): void
    {
        $modulesRoot = dirname(__DIR__, 3) . '/src/modules';

        self::assertFileExists($modulesRoot . '/Hello/src/Application/View/locales/en.json');

        $catalog = new TranslationCatalog();
        $loader = new JsonFileLoader($modulesRoot);
        $loader->load($catalog);

        self::assertContains('en', $catalog->getLocales(), 'JsonFileLoader must discover Hello en.json under the canonical src/Application/View/locales layout');
        self::assertSame('Build the part that matters.', $catalog->get('hello.headline', 'en'));
        self::assertSame('Build the part that matters.', $catalog->get('Hello.hello.headline', 'en'));
    }

    #[Test]
    public function translation_service_returns_translated_headline_for_hello_keys(): void
    {
        $modulesRoot = dirname(__DIR__, 3) . '/src/modules';

        $catalog = new TranslationCatalog();
        $loader = new JsonFileLoader($modulesRoot);
        $loader->load($catalog);

        $service = new TranslationService($catalog, new LocaleManager());

        self::assertSame('Build the part that matters.', $service->trans('hello.headline'));
        self::assertSame('Documentation', $service->trans('hello.docs'));
        self::assertSame('Toggle color theme', $service->trans('hello.toggle_theme'));
    }

    #[Test]
    public function locale_fixture_carries_inline_translation_keys(): void
    {
        self::assertFileExists(self::SOURCE_LOCALES_PATH, 'Hello en.json must ship populated; empty locale file is the regression symptom.');

        $messages = json_decode((string) file_get_contents(self::SOURCE_LOCALES_PATH), true);
        self::assertIsArray($messages);
        self::assertArrayHasKey('hello.headline', $messages);
        self::assertNotSame('', $messages['hello.headline']);
    }
}
