<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Ci;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * stack-smoke runs on setup-php's PHP, not on the image users run. Suites green
 * in the image were red there (CurlHttpClientTlsTest, SqliteAdapterUnhexTest,
 * 27-30.10) and the log never said which Swoole, curl, SQLite or OpenSSL they
 * ran on; the script now prints that before its first stage.
 */
final class StackSmokeRuntimeTest extends TestCase
{
    #[Test]
    public function the_runtime_is_printed_before_the_first_stage(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/tools/stack-smoke.sh');

        self::assertMatchesRegularExpression('/\nprint_runtime\nwant install && /', $script);
    }

    #[Test]
    public function it_names_the_versions_this_php_runs_with(): void
    {
        $script = dirname(__DIR__, 3) . '/tools/stack-smoke.sh';
        $run = 'log() { printf "\n==> %s\n" "$*"; }; note() { printf "    %s\n" "$*"; }; PHP=' . escapeshellarg(PHP_BINARY) . '; '
            . 'eval "$(sed -n \'/^print_runtime()/,/^}/p\' ' . escapeshellarg($script) . ')"; print_runtime';
        exec('bash -c ' . escapeshellarg($run) . ' 2>&1', $out, $status);

        self::assertSame(0, $status, implode("\n", $out));
        self::assertSame('==> runtime', $out[1] ?? null);
        self::assertStringStartsWith('    PHP ' . PHP_VERSION . '; swoole ' . (extension_loaded('swoole') ? 'Version => ' . SWOOLE_VERSION : 'missing'), $out[2] ?? '');
        $curl = function_exists('curl_version') ? curl_version() : false;
        self::assertSame('    curl ' . (is_array($curl) && is_string($curl['version'] ?? null) ? $curl['version'] : 'missing') . '; OpenSSL ' . OPENSSL_VERSION_TEXT, $out[3] ?? null);
        self::assertMatchesRegularExpression('/^    SQLite (3\.\d+\.\d+; PDO::connect\(sqlite\) gives [\w\\\\]+|: .+)$/', $out[4] ?? '');
    }
}
