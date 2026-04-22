<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Ultimate\Console\Command\InitCommand;

/**
 * Guardrail: the installer's sync surface is an explicit allow-list. It must
 * never route the installer's own runtime code (e.g. src/Console/) into the
 * consumer project. If this test fails, a new scaffold target is leaking
 * installer implementation into app layer — reject the change.
 */
final class InstallerSyncAllowListTest extends TestCase
{
    public function testScaffoldFileMapNeverTargetsSrcConsole(): void
    {
        $map = $this->readConstant('SCAFFOLD_FILE_MAP');

        foreach ($map as $projectPath => $_sourcePath) {
            self::assertStringStartsNotWith(
                'src/Console/',
                $projectPath,
                sprintf('SCAFFOLD_FILE_MAP entry "%s" routes installer code into consumer src/Console.', $projectPath),
            );
        }
    }

    public function testFullInitDirectorySyncNeverIncludesSrcConsole(): void
    {
        $dirs = $this->readConstant('FULL_INIT_DIRECTORY_SYNC');

        foreach ($dirs as $dir) {
            self::assertStringStartsNotWith(
                'src/Console',
                $dir,
                sprintf('FULL_INIT_DIRECTORY_SYNC entry "%s" mirrors installer code into consumer src/Console.', $dir),
            );
        }
    }

    public function testScaffoldAssetsDoNotDeclareInstallerNamespace(): void
    {
        $scaffoldRoot = dirname(__DIR__, 3);
        $map = $this->readConstant('SCAFFOLD_FILE_MAP');

        foreach ($map as $_projectPath => $sourcePath) {
            $fullPath = $scaffoldRoot . '/' . $sourcePath;

            self::assertTrue(
                is_file($fullPath),
                sprintf('Scaffold asset "%s" referenced by SCAFFOLD_FILE_MAP must exist as a readable file.', $sourcePath),
            );

            $contents = file_get_contents($fullPath);
            self::assertNotFalse(
                $contents,
                sprintf('Scaffold asset "%s" referenced by SCAFFOLD_FILE_MAP must be readable.', $sourcePath),
            );

            self::assertStringNotContainsString(
                'namespace Semitexa\\Ultimate',
                $contents,
                sprintf('Scaffold asset "%s" declares the installer namespace; it would leak into consumer PSR-4 tree.', $sourcePath),
            );
        }
    }

    /**
     * @return array<int|string, string>
     */
    private function readConstant(string $name): array
    {
        $reflection = new \ReflectionClass(InitCommand::class);
        /** @var array<int|string, string> $value */
        $value = $reflection->getConstant($name);
        self::assertIsArray($value, sprintf('InitCommand::%s must be an array.', $name));

        return $value;
    }
}
