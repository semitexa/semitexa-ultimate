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
            foreach (self::portEntries((string) file_get_contents($file)) as $entry) {
                $published[basename($file)][] = $entry;
            }
        }

        self::assertSame(['docker-compose.yml' => ['"${SWOOLE_BIND:-127.0.0.1}:${SWOOLE_PORT:-8080}:${SWOOLE_PORT:-8080}"']], $published, 'a port published on every interface opens the dev server to the network');
    }

    /** Review, ultimate#145: every entry under a ports: key, quoted, bare or long syntax, is read; none is skipped. */
    #[Test]
    public function an_unquoted_or_long_syntax_port_is_seen_too(): void
    {
        $yaml = "services:\n  app:\n    ports:\n      # a comment\n      - \"\${SWOOLE_BIND:-127.0.0.1}:9502:9502\"\n      - 9503:9503\n      - target: 80\n        published: 8081\n    volumes:\n      - ./a:/a\n";

        self::assertSame(['"${SWOOLE_BIND:-127.0.0.1}:9502:9502"', '9503:9503', 'target: 80 published: 8081'], self::portEntries($yaml));
    }

    /**
     * The entries under every `ports:` key, one string each; comments left out.
     *
     * @return list<string>
     */
    private static function portEntries(string $yaml): array
    {
        $entries = [];
        $indent = null;
        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if (preg_match('/^(\s*)ports:\s*$/', $line, $m) === 1) {
                $indent = strlen($m[1]);
                continue;
            }
            if ($indent === null || trim($line) === '' || preg_match('/^\s*#/', $line) === 1) {
                continue;
            }
            $depth = strlen($line) - strlen(ltrim($line));
            if ($depth <= $indent) {
                $indent = null;
                continue;
            }
            $text = trim((string) preg_replace('/\s+#.*$/', '', $line));
            if (str_starts_with($text, '- ')) {
                $entries[] = substr($text, 2);
            } elseif ($entries !== []) {
                $entries[count($entries) - 1] .= ' ' . $text;
            }
        }

        return $entries;
    }

    #[Test]
    public function the_env_default_says_how_to_open_it_on_purpose(): void
    {
        $env = (string) file_get_contents(self::ROOT . '/.env.default');

        self::assertStringContainsString('# SWOOLE_BIND=0.0.0.0', $env);
        self::assertDoesNotMatchRegularExpression('/^SWOOLE_BIND=/m', $env, 'the default lives in the compose file, so an unset .env keeps it on loopback');
    }
}
