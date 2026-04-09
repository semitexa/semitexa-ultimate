<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\TestCase;

final class RuntimeScaffoldConsistencyTest extends TestCase
{
    public function testDockerComposeBaseDefinesSchedulerServiceUsedByOverlays(): void
    {
        $root = dirname(__DIR__, 3);
        $baseCompose = file_get_contents($root . '/docker-compose.yml');
        $mysqlOverlay = file_get_contents($root . '/docker-compose.mysql.yml');
        $redisOverlay = file_get_contents($root . '/docker-compose.redis.yml');
        $rabbitMqOverlay = file_get_contents($root . '/docker-compose.rabbitmq.yml');

        self::assertIsString($baseCompose);
        self::assertIsString($mysqlOverlay);
        self::assertIsString($redisOverlay);
        self::assertIsString($rabbitMqOverlay);

        self::assertStringContainsString("\n  scheduler:\n", $baseCompose);
        self::assertStringContainsString('build: .', $baseCompose);
        self::assertStringContainsString("\n  scheduler:\n", $mysqlOverlay);
        self::assertStringContainsString("\n  scheduler:\n", $redisOverlay);
        self::assertStringContainsString("\n  scheduler:\n", $rabbitMqOverlay);
    }

    public function testShellEntryPointKeepsEnvScopeOnCommittedRuntimeFiles(): void
    {
        $root = dirname(__DIR__, 3);
        $bin = file_get_contents($root . '/bin/semitexa');

        self::assertIsString($bin);
        self::assertStringContainsString('.env.default', $bin);
        self::assertStringContainsString('.env', $bin);
        self::assertStringNotContainsString('.env.local', $bin);
    }

    public function testInstallerScaffoldStaysInSyncWithUltimateRuntimeFiles(): void
    {
        $ultimateRoot = dirname(__DIR__, 3);
        $installerRoot = dirname($ultimateRoot) . '/semitexa-installer/scaffold';
        $pathPairs = [
            [
                'ultimate' => $ultimateRoot . '/docker-compose.yml',
                'installer' => $installerRoot . '/docker-compose.yml',
            ],
            [
                'ultimate' => $ultimateRoot . '/docker-compose.ollama.yml',
                'installer' => $installerRoot . '/docker-compose.ollama.yml',
            ],
            [
                'ultimate' => $ultimateRoot . '/bin/semitexa',
                'installer' => $installerRoot . '/bin/semitexa',
            ],
        ];

        foreach ($pathPairs as $pair) {
            self::assertFileExists($pair['ultimate']);
            self::assertFileExists($pair['installer']);
            self::assertIsReadable($pair['ultimate']);
            self::assertIsReadable($pair['installer']);

            $ultimateContents = file_get_contents($pair['ultimate']);
            $installerContents = file_get_contents($pair['installer']);

            self::assertIsString($ultimateContents);
            self::assertIsString($installerContents);
            self::assertSame($ultimateContents, $installerContents);
        }
    }

    public function testInstallerScriptRefreshesInstallerImageBeforeScaffolding(): void
    {
        $root = dirname(__DIR__, 3);
        $installScript = file_get_contents($root . '/install.sh');

        self::assertIsString($installScript);
        $pullPos = strpos($installScript, 'docker pull "$INSTALLER_IMAGE"');
        $inspectPos = strpos($installScript, 'docker image inspect "$INSTALLER_IMAGE"');
        $runPos = strpos($installScript, "\"\$INSTALLER_IMAGE\" \\\n        install");

        self::assertNotFalse($pullPos);
        self::assertNotFalse($inspectPos);
        self::assertNotFalse($runPos);
        self::assertLessThan($inspectPos, $pullPos);
        self::assertLessThan($runPos, $inspectPos);
    }
}
