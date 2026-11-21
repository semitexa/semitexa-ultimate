<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The app image fetches its PECL extensions through pecl_fetch, a helper in
 * the Dockerfile's build layer. On 2026-11-18 the stack smoke failed twice in
 * a row on "unable to unpack /tmp/pear/download/swoole-6.2.5.tgz" while the
 * next run fetched the same archive fine, and a first install builds this
 * image (tk-image-build-retries-pecl-downloads). Only the download is tried
 * again; the install runs once, from the archive that was checked.
 */
final class PeclDownloadsAreRetriedTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pecl-fetch-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/src', 0700, true);
        file_put_contents($this->dir . '/src/package.xml', str_repeat("<package>redis</package>\n", 200));
        shell_exec('tar -czf ' . escapeshellarg($this->dir . '/good.tgz') . ' -C ' . escapeshellarg($this->dir . '/src') . ' package.xml');
        // The first half of a real archive: what a cut-off download leaves.
        file_put_contents($this->dir . '/cut.tgz', substr((string) file_get_contents($this->dir . '/good.tgz'), 0, 40));
    }

    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private static function dockerfile(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/Dockerfile');
    }

    /**
     * Runs the Dockerfile's pecl_fetch for "redis" against a stub pecl; $downloads says what
     * each `pecl download` does, as case arms keyed by the number of the call.
     *
     * @return array{exit: string, calls: list<string>, sleeps: list<string>, archive: ?string, left: list<string>}
     */
    private function fetch(string $downloads): array
    {
        self::assertSame(1, preg_match('/^RUN (pecl_fetch\(\) \{ \\\\\n.*?^    \}) \\\\$/ms', self::dockerfile(), $m), 'the Dockerfile must define pecl_fetch at the head of its build layer');
        // Joined into one line the way the image build does it: a backslash at a line's end continues it.
        $function = str_replace('/tmp/pecl-fetch', $this->dir . '/fetch', str_replace("\\\n", '', $m[1] ?? ''));
        $d = $this->dir;
        $sh = <<<SH
            pecl() {
                _n=\$((\$(cat "$d/count" 2>/dev/null || echo 0) + 1)); echo "\$_n" > "$d/count"
                echo "\$*" >> "$d/calls"
                case "\$_n" in
            {$downloads}
                esac
            }
            sleep() { echo "\$1" >> "$d/sleeps"; }
            {$function}
            pecl_fetch redis 2>/dev/null
            printf '%s' "\$?" > "$d/exit"
            SH;
        shell_exec('env -i PATH=/usr/bin:/bin sh -c ' . escapeshellarg($sh));

        $lines = static fn (string $file): array => is_file($file) ? array_values(array_filter(explode("\n", (string) file_get_contents($file)), static fn (string $l): bool => $l !== '')) : [];
        $left = is_dir($d . '/fetch') ? array_values(array_diff(scandir($d . '/fetch') ?: [], ['.', '..'])) : [];

        return [
            'exit' => is_file($d . '/exit') ? (string) file_get_contents($d . '/exit') : 'never ran',
            'calls' => $lines($d . '/calls'),
            'sleeps' => $lines($d . '/sleeps'),
            'archive' => is_file($d . '/fetch/redis.tgz') ? hash_file('sha256', $d . '/fetch/redis.tgz') ?: null : null,
            'left' => $left,
        ];
    }

    private function good(): string
    {
        return (string) hash_file('sha256', $this->dir . '/good.tgz');
    }

    #[Test]
    public function a_download_that_fails_once_is_tried_again(): void
    {
        $run = $this->fetch(<<<SH
                    1) return 1 ;;
                    *) cp "{$this->dir}/good.tgz" ./redis-6.3.0.tgz ;;
            SH);

        self::assertSame('0', $run['exit']);
        self::assertSame(['download redis', 'download redis'], $run['calls']);
        self::assertSame(['5'], $run['sleeps'], 'one wait, before the second try');
        self::assertSame($this->good(), $run['archive'], 'the archive of the try that worked, under the name the install line reads');
        self::assertSame(['redis.tgz'], $run['left'], 'nothing else stays behind');
    }

    #[Test]
    public function it_gives_up_after_three_failed_downloads(): void
    {
        $run = $this->fetch('        *) return 1 ;;');

        self::assertSame('1', $run['exit'], 'a fault that stays must still fail the build');
        self::assertSame(['download redis', 'download redis', 'download redis'], $run['calls']);
        self::assertSame(['5', '10'], $run['sleeps'], 'a growing wait between tries, none after the last');
        self::assertSame([], $run['left']);
    }

    #[Test]
    public function an_archive_that_does_not_unpack_is_not_accepted(): void
    {
        // pecl reports success and leaves a cut-off file: the fault the smoke hit at install time.
        $run = $this->fetch(<<<SH
                    *) cp "{$this->dir}/cut.tgz" ./redis-6.3.0.tgz ;;
            SH);

        self::assertSame('1', $run['exit']);
        self::assertCount(3, $run['calls']);
        self::assertNull($run['archive'], 'no archive is handed to the install');
        self::assertSame([], $run['left']);
    }

    #[Test]
    public function a_bad_archive_is_cleared_before_the_next_try(): void
    {
        // The good download has another file name: left in place, the cut one would make two archives.
        $run = $this->fetch(<<<SH
                    1) cp "{$this->dir}/cut.tgz" ./redis-6.2.9.tgz ;;
                    *) cp "{$this->dir}/good.tgz" ./redis-6.3.0.tgz ;;
            SH);

        self::assertSame('0', $run['exit']);
        self::assertCount(2, $run['calls']);
        self::assertSame($this->good(), $run['archive']);
        self::assertSame(['redis.tgz'], $run['left']);
    }

    #[Test]
    public function every_pecl_package_is_fetched_through_it_and_installed_from_the_archive(): void
    {
        $dockerfile = self::dockerfile();

        preg_match_all('/^\s+&& pecl install (.+) \\\\$/m', $dockerfile, $installs);
        self::assertSame([
            '--nobuild /tmp/pecl-fetch/swoole-"${SWOOLE_VERSION}".tgz',
            '/tmp/pecl-fetch/redis.tgz',
            '/tmp/pecl-fetch/imagick-"${IMAGICK_VERSION}".tgz',
            '/tmp/pecl-fetch/pcov.tgz',
        ], $installs[1], 'pecl install reads a local archive only: a package name here is a download with no retry');
        self::assertSame(4, preg_match_all('/\bpecl install\b/', (string) preg_replace('/^#.*$/m', '', $dockerfile)), 'and there is no other pecl install outside those lines');

        foreach (['swoole-"${SWOOLE_VERSION}"' => '--nobuild ', 'redis' => '', 'imagick-"${IMAGICK_VERSION}"' => '', 'pcov' => ''] as $spec => $flag) {
            self::assertStringContainsString(
                "    && pecl_fetch {$spec} \\\n    && pecl install {$flag}/tmp/pecl-fetch/{$spec}.tgz \\\n",
                $dockerfile,
                "{$spec} is fetched by pecl_fetch on the line before its install",
            );
        }
    }
}
