<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The wrapper's own lines never reach the proxied command's stdout
 * (tk-cli-info-line-on-stdout-with-json).
 *
 * With the app container stopped, bin/semitexa printed "[INFO] App container
 * is not running; using a one-off container..." on stdout before the JSON of
 * `ai:orient --json`, so a caller that parsed stdout got no JSON.
 */
final class ProxyKeepsStdoutForTheCommandTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../bin/semitexa';

    #[Test]
    public function every_line_the_proxy_prints_itself_goes_to_stderr(): void
    {
        $script = (string) file_get_contents(self::SCRIPT);
        self::assertSame(1, preg_match('/^cmd_proxy\(\) \{\n(.*?)^\}\n/ms', $script, $body), 'cmd_proxy is there');

        $printed = 0;
        foreach (explode("\n", $body[1]) as $line) {
            $line = trim($line);
            // The port-conflict diagnosis prints its own [INFO] lines too (review, installer#83).
            if (preg_match('/^(info|success) "|^proxy_diagnose_port_conflict /', $line) === 1 || $line === 'printf "\n"' || str_starts_with($line, 'printf "\n" ')) {
                $printed++;
                self::assertMatchesRegularExpression('/>&2( \|\| true)?$/', $line, "stdout is the command's: {$line}");
            }
        }
        // The one the ticket names is among them.
        self::assertStringContainsString('info "App container is not running; using a one-off container for this CLI command..." >&2', $body[1]);
        self::assertGreaterThanOrEqual(10, $printed, 'the lines checked are the ones the proxy prints');
        self::assertSame(2, substr_count($body[1], 'proxy_diagnose_port_conflict "$_swoole_port" "$_our_compose" "$_our_app_id" >&2 || true'));
    }
}
