<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A project's dev server answers this machine only (tk-app-port-binds-every-interface).
 * Measured 2026-11-12 on the framework's own dev stack: the app port answered 200 on
 * the machine's LAN address. The shared router reaches the app over the project's
 * network (router_attach_project), so the host port no longer has to be open to the
 * network; SWOOLE_BIND=0.0.0.0 opens it on purpose.
 */
final class AppPortStaysOnThisMachineTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    #[Test]
    public function every_published_port_is_bound_to_swoole_bind_on_loopback_by_default(): void
    {
        $published = [];
        foreach (glob(self::ROOT . '/docker-compose*.yml') ?: [] as $file) {
            preg_match_all('/^\s+-\s+"([^"]*:[^"]*)"\s*$/m', (string) preg_replace('/^.*#.*$/m', '', (string) file_get_contents($file)), $m);
            foreach ($m[1] as $mapping) {
                if (preg_match('/^\$\{SWOOLE_PORT|^\$\{SWOOLE_BIND|^[0-9.]+:|^\d+:\d+$/', $mapping) === 1) {
                    $published[basename($file)][] = $mapping;
                }
            }
        }

        self::assertSame(['docker-compose.yml' => ['${SWOOLE_BIND:-127.0.0.1}:${SWOOLE_PORT:-8080}:${SWOOLE_PORT:-8080}']], $published, 'a port published on every interface opens the dev server to the network');
    }

    #[Test]
    public function the_env_default_says_how_to_open_it_on_purpose(): void
    {
        $env = (string) file_get_contents(self::ROOT . '/.env.default');

        self::assertStringContainsString('# SWOOLE_BIND=0.0.0.0', $env);
        self::assertDoesNotMatchRegularExpression('/^SWOOLE_BIND=/m', $env, 'the default lives in the compose file, so an unset .env keeps it on loopback');
    }
}
