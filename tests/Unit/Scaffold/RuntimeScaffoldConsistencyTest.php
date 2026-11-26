<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\TestCase;

final class RuntimeScaffoldConsistencyTest extends TestCase
{
    // verify:accept-test-change step 4c of tk-one-source-for-bin-semitexa: the runtime-file tests moved to semitexa-installer tests/Unit/Scaffold (installer#134, #135), and ultimate's copies of those files are deleted with them

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

    public function testInstallScriptMakesRegistrationFailureNonFatalUnderErrexit(): void
    {
        $root = dirname(__DIR__, 3);
        $installScript = file_get_contents($root . '/install.sh');

        self::assertIsString($installScript);
        self::assertMatchesRegularExpression(
            '/_rc=0\s*\n\s*set \+e\s*\n\s*if \[ -n "\$_existing_id" \]; then.*\n\s*_rc=\$\?\s*\n\s*set -e/s',
            $installScript,
            'install.sh must temporarily disable errexit around local-app:register, capture its exit code, then restore errexit so broker failures stay non-fatal and fall through to the warn-and-continue branch.',
        );
    }

    /**
     * Regression guard: ensure the public installer endpoint advertised in docs
     * is byte-for-byte aligned with the local file in this package.
     */
    public function testPublicInstallerEndpointStaysAlignedWithPackageScript(): void
    {
        $root = dirname(__DIR__, 3);
        $local = (string) file_get_contents($root . '/install.sh');

        // Allow fetching via curl in tests — help text or CI runner should
        // have network access to semitexa.com.
        $remote = (string) @file_get_contents('https://semitexa.com/install.sh');

        if ($remote === '') {
            self::markTestSkipped('Cannot reach https://semitexa.com/install.sh to verify alignment.');
        }

        self::assertSame(
            $local,
            $remote,
            'Public installer at https://semitexa.com/install.sh has drifted from packages/semitexa-ultimate/install.sh. '
            . 'Sync the site repository with the framework version.',
        );
    }
}
