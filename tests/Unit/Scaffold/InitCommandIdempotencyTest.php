<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Ultimate\Console\Command\InitCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class InitCommandIdempotencyTest extends TestCase
{
    public function testRunningInitTwiceProducesIdenticalTreeAndFileContents(): void
    {
        $tempDir = $this->createTempDirectory();

        try {
            $firstTester = new CommandTester(new InitCommand());
            $firstExit = $firstTester->execute(['--dir' => $tempDir]);
            self::assertSame(Command::SUCCESS, $firstExit, 'First init run must succeed. Output: ' . $firstTester->getDisplay());

            $firstSnapshot = $this->snapshotTree($tempDir);

            $secondTester = new CommandTester(new InitCommand());
            $secondExit = $secondTester->execute(['--dir' => $tempDir]);
            self::assertSame(Command::SUCCESS, $secondExit, 'Second init run must succeed. Output: ' . $secondTester->getDisplay());

            $secondSnapshot = $this->snapshotTree($tempDir);

            self::assertSame(
                array_keys($firstSnapshot),
                array_keys($secondSnapshot),
                'Directory tree drifted between idempotent installer runs.',
            );

            foreach ($firstSnapshot as $relative => $hash) {
                self::assertSame(
                    $hash,
                    $secondSnapshot[$relative] ?? null,
                    sprintf('Content of "%s" drifted between idempotent installer runs.', $relative),
                );
            }
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    /**
     * @return array<string, string> relative path => sha1 of contents (directories => '<dir>')
     */
    private function snapshotTree(string $root): array
    {
        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $relative = substr($item->getPathname(), strlen($root) + 1);
            if ($item->isDir()) {
                $snapshot[$relative] = '<dir>';
                continue;
            }
            $contents = file_get_contents($item->getPathname());
            $snapshot[$relative] = $contents === false ? '<unreadable>' : sha1($contents);
        }

        ksort($snapshot);

        return $snapshot;
    }

    private function createTempDirectory(): string
    {
        $tempDir = sys_get_temp_dir() . '/ultimate-idempotency-' . bin2hex(random_bytes(8));
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
