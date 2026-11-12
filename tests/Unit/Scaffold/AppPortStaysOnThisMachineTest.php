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
        self::assertSame(['"a:1:1"', '"b:2:2"'], self::portEntries("x:\n  ports: [\"a:1:1\", \"b:2:2\"]\n"), 'a flow list');
        self::assertSame(['"a:1:1"'], self::portEntries("x:\n  ports:  # one\n  -  \"a:1:1\"\n  volumes: []\n"), 'a comment after the key, a list at its own indent');
    }

    #[Test]
    public function the_env_default_says_how_to_open_it_on_purpose(): void
    {
        $env = (string) file_get_contents(self::ROOT . '/.env.default');

        self::assertStringContainsString('# SWOOLE_BIND=0.0.0.0', $env);
        self::assertDoesNotMatchRegularExpression('/^SWOOLE_BIND=/m', $env, 'the default lives in the compose file, so an unset .env keeps it on loopback');
    }

    /**
     * The entries under every `ports:` key, one string each: block lists (indented or
     * at the key's own indent), flow lists, long syntax joined onto its dash line;
     * comments left out. Review, ultimate#145: a form it cannot read would pass unseen.
     *
     * @return list<string>
     */
    private static function portEntries(string $yaml): array
    {
        $entries = [];
        $indent = null;
        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            $code = rtrim((string) preg_replace('/\s+#.*$|^\s*#.*$/', '', $line));
            if (preg_match('/^(\s*)(?:-\s+)?ports:\s*(.*)$/', $code, $m) === 1) {
                $indent = null;
                $rest = trim($m[2]);
                if ($rest === '') {
                    $indent = strlen($m[1]);
                } elseif (preg_match('/^\[(.*)\]$/', $rest, $flow) === 1) {
                    foreach (explode(',', $flow[1]) as $item) {
                        if (trim($item) !== '') {
                            $entries[] = trim($item);
                        }
                    }
                } else {
                    self::fail('a ports: value this test cannot read: ' . $rest);
                }
                continue;
            }
            if ($indent === null || trim($code) === '') {
                continue;
            }
            $depth = strlen($code) - strlen(ltrim($code));
            $text = trim($code);
            $isItem = str_starts_with($text, '-');
            if ($depth < $indent || ($depth === $indent && !$isItem)) {
                $indent = null;
                continue;
            }
            if ($isItem) {
                $entries[] = trim(substr($text, 1));
            } elseif ($entries !== []) {
                $entries[count($entries) - 1] .= ' ' . $text;
            }
        }

        return $entries;
    }
}
