<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Ultimate\Console\Command\InitCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class InitCommandWrongCaseRegistryTest extends TestCase
{
    public function testInitFailsWhenWrongCaseRegistryDirectoryExists(): void
    {
        $tempDir = $this->createTempDirectory();

        try {
            mkdir($tempDir . '/src/Registry', 0777, true);

            $tester = new CommandTester(new InitCommand());
            $exitCode = $tester->execute(['--dir' => $tempDir]);

            self::assertSame(Command::FAILURE, $exitCode);
            self::assertStringContainsString('Non-canonical registry path detected: src/Registry', $tester->getDisplay());
            self::assertDirectoryDoesNotExist($tempDir . '/src/modules', 'Installer must abort before scaffolding when drift is detected.');
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    public function testInitProceedsPastDetectorWhenCanonicalLowercaseRegistryExists(): void
    {
        $tempDir = $this->createTempDirectory();

        try {
            mkdir($tempDir . '/src/registry', 0777, true);

            $reflection = new \ReflectionClass(InitCommand::class);
            $command = $reflection->newInstanceWithoutConstructor();
            $method = $reflection->getMethod('detectWrongCaseRegistryPath');
            $method->setAccessible(true);

            self::assertNull($method->invoke($command, $tempDir));
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    public function testDetectorReturnsOffendingNameForAnyNonCanonicalCasing(): void
    {
        foreach (['Registry', 'REGISTRY', 'RegiStry'] as $variant) {
            $tempDir = $this->createTempDirectory();

            try {
                mkdir($tempDir . '/src/' . $variant, 0777, true);

                $reflection = new \ReflectionClass(InitCommand::class);
                $command = $reflection->newInstanceWithoutConstructor();
                $method = $reflection->getMethod('detectWrongCaseRegistryPath');
                $method->setAccessible(true);

                self::assertSame($variant, $method->invoke($command, $tempDir));
            } finally {
                $this->removeDirectory($tempDir);
            }
        }
    }

    private function createTempDirectory(): string
    {
        $tempDir = sys_get_temp_dir() . '/ultimate-wrongcase-' . bin2hex(random_bytes(8));
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
