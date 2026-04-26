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
        $natsOverlay = file_get_contents($root . '/docker-compose.nats.yml');

        self::assertIsString($baseCompose);
        self::assertIsString($mysqlOverlay);
        self::assertIsString($redisOverlay);
        self::assertIsString($natsOverlay);

        self::assertStringContainsString("\n  scheduler:\n", $baseCompose);
        self::assertStringContainsString('build: .', $baseCompose);
        self::assertStringContainsString("\n  scheduler:\n", $mysqlOverlay);
        self::assertStringContainsString("\n  scheduler:\n", $redisOverlay);
        self::assertStringContainsString("\n  scheduler:\n", $natsOverlay);
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

    public function testInstallScriptPreRegistersAppBeforeFirstServerStart(): void
    {
        $root = dirname(__DIR__, 3);
        $installScript = file_get_contents($root . '/install.sh');

        self::assertIsString($installScript);

        self::assertStringContainsString(
            'local-app:register',
            $installScript,
            'install.sh must invoke `bin/semitexa local-app:register` so the broker '
            . 'allocates SEMITEXA_APP_ID + SWOOLE_PORT before any server:start.',
        );
        self::assertStringContainsString(
            '--write-env',
            $installScript,
            'install.sh must pass --write-env so the broker upserts the assigned '
            . 'SEMITEXA_APP_ID and SWOOLE_PORT into the project .env.',
        );

        $registerCallSite = strpos($installScript, "\n    register_local_app\n");
        self::assertNotFalse(
            $registerCallSite,
            'main() must call register_local_app at top level before DNS / start.',
        );

        $askDomainCallSite = strpos($installScript, "\n    ask_local_domain\n");
        self::assertNotFalse(
            $askDomainCallSite,
            'main() must still call ask_local_domain (DNS registration entry point).',
        );

        $startServerCallSite = strpos($installScript, "\n            start_server\n");
        self::assertNotFalse(
            $startServerCallSite,
            'main() must still call start_server in the post-confirm branch.',
        );

        self::assertLessThan(
            $askDomainCallSite,
            $registerCallSite,
            'register_local_app must run BEFORE ask_local_domain so the DNS entry '
            . 'maps a stable, broker-allocated upstream port.',
        );
        self::assertLessThan(
            $startServerCallSite,
            $registerCallSite,
            'register_local_app must run BEFORE start_server so the first start '
            . 'consumes the already-registered port instead of racing the broker.',
        );
    }

    public function testInstallScriptSkipsLegacyPortScanWhenAppIsRegistered(): void
    {
        $root = dirname(__DIR__, 3);
        $installScript = file_get_contents($root . '/install.sh');

        self::assertIsString($installScript);

        self::assertMatchesRegularExpression(
            '/if\s*\[\s*"\$APP_REGISTERED"\s*-eq\s*0\s*\]\s*;\s*then\s*\n\s*check_port_conflicts/s',
            $installScript,
            'main() must skip the legacy linear-scan check_port_conflicts whenever '
            . 'register_local_app succeeded — running both scans would let the '
            . 'fallback pick a port outside the broker range and corrupt cross-app '
            . 'uniqueness.',
        );
    }

    public function testInstallScriptUsesIdempotentBrokerCallShape(): void
    {
        $root = dirname(__DIR__, 3);
        $installScript = file_get_contents($root . '/install.sh');

        self::assertIsString($installScript);

        // The broker registration is idempotent only when re-runs reuse the
        // SEMITEXA_APP_ID already written to .env (otherwise the broker
        // generates a fresh UUID and overwrites the scaffold-provided one,
        // leaving an orphan registry entry per re-run).
        self::assertStringContainsString(
            "--app-id=\${_existing_id}",
            $installScript,
            'install.sh must pass --app-id when .env already carries a '
            . 'SEMITEXA_APP_ID so repeated installs reuse the same id.',
        );
        self::assertStringContainsString(
            "grep -E '^SEMITEXA_APP_ID=' \"\$PROJECT_NAME/.env\"",
            $installScript,
            'install.sh must read the scaffold-seeded SEMITEXA_APP_ID from .env '
            . 'before calling local-app:register so the broker entry and .env '
            . 'stay aligned.',
        );
    }

    public function testPublicInstallRouteServesUltimateInstallScriptVerbatim(): void
    {
        // The published curl|bash command serves the bytes of this very file
        // through Semitexa\Site\Application\Handler\PayloadHandler\InstallScriptHandler.
        // Lock the route's resolution order to the source-of-truth path so a
        // future move of the install script cannot silently leave the public
        // endpoint stale (or, worse, falling back to a vendor-pinned copy).
        $root = dirname(__DIR__, 4);
        $handlerPath = $root . '/semitexa-site/src/Application/Handler/PayloadHandler/InstallScriptHandler.php';

        if (!is_file($handlerPath)) {
            self::markTestSkipped('semitexa-site is not installed alongside semitexa-ultimate.');
        }

        $handler = file_get_contents($handlerPath);
        self::assertIsString($handler);

        self::assertStringContainsString(
            "'/packages/semitexa-ultimate/install.sh'",
            $handler,
            'Public /install.sh route must serve packages/semitexa-ultimate/install.sh '
            . 'as its primary candidate so the canonical curl|bash flow is never '
            . 'stale relative to this source-of-truth file.',
        );
    }
}
