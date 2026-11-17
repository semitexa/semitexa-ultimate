<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tk-server-start-says-ok-while-the-app-crash-loops (reported by the Guard
 * project): server:start printed "Semitexa environment started!" right after
 * `compose up -d`, while the app container crash-looped; nothing checked that it
 * stayed up or answered, and an agent took the deploy for done.
 *
 * server_start_wait_for_app() runs as bin/semitexa has it; the app container is
 * a stub that answers from a given attempt on, or never.
 */
final class ServerStartWaitsForTheAppTest extends TestCase
{
    private const STUBS = <<<'SH'
        env_val() { case "$1" in SWOOLE_PORT) printf '9517' ;; *) printf '%s' "$2" ;; esac; }
        run_compose() {
            case "$1" in
                exec) n=$(cat "$T_COUNT" 2>/dev/null || echo 0); n=$((n + 1)); echo "$n" > "$T_COUNT"; [ "$n" -ge "$T_ANSWER_AT" ] ;;
                logs) printf 'PHP Fatal error:  Uncaught Error: Class "App\\Missing" not found\nWorker exited with code 255\n' ;;
            esac
        }
        sleep() { :; }
        info() { printf 'INFO %s\n' "$*"; }
        error() { printf 'ERROR %s\n' "$*"; }
        SH;

    #[Test]
    public function an_app_that_answers_lets_the_start_go_on(): void
    {
        [$out, $exit, $tries] = $this->wait(answerAt: 3, attempts: 30, delay: 2);

        self::assertSame(['INFO Waiting for the app to answer on port 9517...'], $out);
        self::assertSame([0, 3], [$exit, $tries]);
    }

    #[Test]
    public function an_app_that_never_answers_fails_the_start_with_what_it_last_logged(): void
    {
        [$out, $exit, $tries] = $this->wait(answerAt: 999, attempts: 4, delay: 5);

        self::assertSame([
            'INFO Waiting for the app to answer on port 9517...',
            'ERROR The app did not answer on port 9517 within 20s: it is not running, or it is restarting.',
            'INFO Its last log lines (all of them: docker compose logs app):',
            '    PHP Fatal error:  Uncaught Error: Class "App\Missing" not found',
            '    Worker exited with code 255',
        ], $out);
        self::assertSame([1, 4], [$exit, $tries], 'it gives up after the attempts it was given');
    }

    #[Test]
    public function both_start_and_restart_wait_before_they_say_so(): void
    {
        $script = $this->script();
        foreach (['cmd_server_start', 'cmd_server_restart'] as $command) {
            self::assertSame(1, preg_match('/^' . $command . '\(\) \{\n.*?^\}$/ms', $script, $match), $command . ' must still exist');
            $body = $match[0] ?? '';
            $wait = strpos($body, 'server_start_wait_for_app || exit 1');
            $said = strpos($body, 'success "Semitexa environment');
            self::assertIsInt($wait, $command . ' waits for the app');
            self::assertIsInt($said, $command . ' still says it is done');
            self::assertLessThan($said, $wait, $command . ' waits before it says so');
        }
    }

    /** @return array{list<string>, int, int} the lines printed, the exit code, the probes made */
    private function wait(int $answerAt, int $attempts, int $delay): array
    {
        self::assertSame(1, preg_match('/^server_start_wait_for_app\(\) \{\n.*?^\}$/ms', $this->script(), $match), 'server_start_wait_for_app must still exist in bin/semitexa');
        $count = sys_get_temp_dir() . '/start-wait-' . bin2hex(random_bytes(6));
        try {
            $sh = self::STUBS . "\n" . ($match[0] ?? '') . "\nserver_start_wait_for_app; printf 'EXIT %s\\n' \"\$?\"";
            $raw = (string) shell_exec(sprintf(
                'env -i PATH=/usr/bin:/bin T_COUNT=%s T_ANSWER_AT=%d SEMITEXA_START_WAIT_ATTEMPTS=%d SEMITEXA_START_WAIT_DELAY=%d sh -c %s',
                escapeshellarg($count),
                $answerAt,
                $attempts,
                $delay,
                escapeshellarg($sh),
            ));
            $tries = (int) @file_get_contents($count);
        } finally {
            @unlink($count);
        }
        $lines = array_values(array_filter(explode("\n", $raw), static fn (string $line): bool => $line !== ''));
        $exitLine = array_pop($lines) ?? '';
        self::assertMatchesRegularExpression('/^EXIT \d+$/', $exitLine);

        return [$lines, (int) substr($exitLine, 5), $tries];
    }

    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
    }
}
