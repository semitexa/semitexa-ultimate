<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\TestCase;

final class InitCommandRegistryAutoloadTest extends TestCase
{
    public function testUltimateComposerDeclaresRegistryAutoloadMapping(): void
    {
        $composer = file_get_contents(dirname(__DIR__, 3) . '/composer.json');

        self::assertIsString($composer);
        self::assertStringContainsString('"App\\\\Registry\\\\": "src/registry/"', $composer);
    }

    public function testInitCommandAddsRegistryMappingWhenAppAutoloadAlreadyExists(): void
    {
        $source = $this->readInitCommandSource();

        self::assertStringContainsString("if (!isset(\$psr4['App\\\\Registry\\\\'])) {", $source);
        self::assertStringContainsString("\$psr4['App\\\\Registry\\\\'] = 'src/registry/';", $source);
    }

    public function testInitCommandIncludesRegistryMappingInFreshAutoloadSetup(): void
    {
        $source = $this->readInitCommandSource();

        self::assertStringContainsString("\$psr4['App\\\\'] = 'src/';", $source);
        self::assertStringContainsString("\$psr4['App\\\\Tests\\\\'] = 'tests/';", $source);
        self::assertStringContainsString("\$psr4['App\\\\Modules\\\\'] = 'src/modules/';", $source);
        self::assertStringContainsString("\$psr4['App\\\\Registry\\\\'] = 'src/registry/';", $source);
    }

    private function readInitCommandSource(): string
    {
        $path = dirname(__DIR__, 3) . '/src/Console/Command/InitCommand.php';
        $source = file_get_contents($path);
        self::assertIsString($source, sprintf('Failed to read %s', $path));

        return $source;
    }
}
