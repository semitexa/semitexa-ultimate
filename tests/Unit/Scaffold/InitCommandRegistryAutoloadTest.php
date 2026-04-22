<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Ultimate\Console\Command\InitCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class InitCommandRegistryAutoloadTest extends TestCase
{
    public function testUltimateComposerDeclaresRegistryAutoloadMapping(): void
    {
        $composerPath = dirname(__DIR__, 3) . '/composer.json';
        $composer = file_get_contents($composerPath);

        self::assertIsString($composer, sprintf('Failed to read %s', $composerPath));

        $decoded = json_decode($composer, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame('src/registry/', $decoded['autoload']['psr-4']['App\\Registry\\'] ?? null);
    }

    public function testPatchComposerAutoloadAddsRegistryMappingWhenAppAutoloadAlreadyExists(): void
    {
        $tempDir = $this->createTempDirectory();

        try {
            $this->writeComposerJson(
                $tempDir,
                [
                    'name' => 'semitexa/ultimate-test',
                    'autoload' => [
                        'psr-4' => [
                            'App\\' => 'src/',
                        ],
                    ],
                ]
            );

            $decoded = $this->invokePatchComposerAutoload($tempDir);

            self::assertSame('src/', $decoded['autoload']['psr-4']['App\\'] ?? null);
            self::assertSame('src/registry/', $decoded['autoload']['psr-4']['App\\Registry\\'] ?? null);
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    public function testPatchComposerAutoloadSkipsRegistryMappingWhenLegacyRegistryDirectoryExists(): void
    {
        $tempDir = $this->createTempDirectory();

        try {
            mkdir($tempDir . '/src/Registry', 0777, true);
            $this->writeComposerJson(
                $tempDir,
                [
                    'name' => 'semitexa/ultimate-test',
                    'autoload' => [
                        'psr-4' => [
                            'App\\' => 'src/',
                        ],
                    ],
                ]
            );

            $decoded = $this->invokePatchComposerAutoload($tempDir);

            self::assertSame('src/', $decoded['autoload']['psr-4']['App\\'] ?? null);
            self::assertSame('src/modules/', $decoded['autoload']['psr-4']['App\\Modules\\'] ?? null);
            self::assertArrayNotHasKey('App\\Registry\\', $decoded['autoload']['psr-4']);
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    public function testPatchComposerAutoloadKeepsCanonicalRegistryDirectoryEligibleForMapping(): void
    {
        $tempDir = $this->createTempDirectory();

        try {
            mkdir($tempDir . '/src/registry', 0777, true);
            $this->writeComposerJson(
                $tempDir,
                [
                    'name' => 'semitexa/ultimate-test',
                    'autoload' => [
                        'psr-4' => [
                            'App\\' => 'src/',
                        ],
                    ],
                ]
            );

            $decoded = $this->invokePatchComposerAutoload($tempDir);

            self::assertSame('src/registry/', $decoded['autoload']['psr-4']['App\\Registry\\'] ?? null);
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    public function testPatchComposerAutoloadIncludesRegistryMappingInFreshAutoloadSetup(): void
    {
        $tempDir = $this->createTempDirectory();

        try {
            $this->writeComposerJson(
                $tempDir,
                [
                    'name' => 'semitexa/ultimate-test',
                ]
            );

            $decoded = $this->invokePatchComposerAutoload($tempDir);

            self::assertSame('src/', $decoded['autoload']['psr-4']['App\\'] ?? null);
            self::assertSame('tests/', $decoded['autoload']['psr-4']['App\\Tests\\'] ?? null);
            self::assertSame('src/modules/', $decoded['autoload']['psr-4']['App\\Modules\\'] ?? null);
            self::assertSame('src/registry/', $decoded['autoload']['psr-4']['App\\Registry\\'] ?? null);
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    private function invokePatchComposerAutoload(string $projectDir): array
    {
        require_once dirname(__DIR__, 3) . '/src/Console/Command/InitCommand.php';

        $reflection = new \ReflectionClass(InitCommand::class);
        $command = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('patchComposerAutoload');
        $method->setAccessible(true);

        $input = new ArrayInput([]);
        $output = new BufferedOutput();
        $io = new SymfonyStyle($input, $output);

        $result = $method->invoke($command, $projectDir, $io, false);

        self::assertTrue($result === true, 'patchComposerAutoload() should succeed');

        return $this->readComposerJson($projectDir . '/composer.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposerJson(string $composerPath): array
    {
        $composer = file_get_contents($composerPath);

        self::assertIsString($composer, sprintf('Failed to read %s', $composerPath));

        $decoded = json_decode($composer, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function writeComposerJson(string $projectDir, array $composer): void
    {
        $written = file_put_contents(
            $projectDir . '/composer.json',
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        self::assertNotFalse($written, sprintf('Failed to write %s/composer.json', $projectDir));
    }

    private function createTempDirectory(): string
    {
        $tempDir = sys_get_temp_dir() . '/ultimate-init-command-' . bin2hex(random_bytes(8));
        $created = mkdir($tempDir, 0777, true);

        self::assertTrue($created, sprintf('Failed to create temp directory %s', $tempDir));

        return $tempDir;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
