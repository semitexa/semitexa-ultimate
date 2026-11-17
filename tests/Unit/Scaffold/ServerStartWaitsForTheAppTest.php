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
 * The probe (app_answers_health) is run for real against local servers; the wait
 * (server_start_wait_for_app) is run with the probe stubbed.
 */
final class ServerStartWaitsForTheAppTest extends TestCase
{
    private const WAIT_STUBS = <<<'SH'
        env_val() { case "$1" in SWOOLE_PORT) printf '9517' ;; *) printf '%s' "$2" ;; esac; }
        app_answers_health() { n=$(cat "$T_COUNT" 2>/dev/null || echo 0); n=$((n + 1)); echo "$n" > "$T_COUNT"; [ "$n" -ge "$T_ANSWER_AT" ]; }
        run_compose() { [ "$1" = logs ] && printf 'PHP Fatal error:  Uncaught Error: Class "App\\Missing" not found\nWorker exited with code 255\n'; }
        sleep() { printf '%s\n' "$1" >> "$T_SLEPT"; }
        info() { printf 'INFO %s\n' "$*"; }
        error() { printf 'ERROR %s\n' "$*"; }
        SH;

    /** @var resource|null */
    private $server = null;
    private string $dir = '';

    protected function tearDown(): void
    {
        $this->stopServer();
        foreach (['router.php', 'status'] as $file) {
            if (is_file($this->dir . '/' . $file)) {
                unlink($this->dir . '/' . $file);
            }
        }
        if ($this->dir !== '' && is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    /**
     * Review, ultimate#158: an open port is not an answer. Core accepts connections
     * while a worker is still booting and holds /health at its boot gate (a 503
     * after 30 s), so only a 200 from /health says a worker booted.
     */
    #[Test]
    public function the_probe_takes_only_a_200_from_health_as_an_answer(): void
    {
        $port = $this->serve();
        $this->answer(200);
        self::assertSame(0, $this->probe($port), 'a booted app answers 200');
        $this->answer(503);
        self::assertSame(1, $this->probe($port), 'an app held at its boot gate answers 503: not up');

        $this->stopServer();
        self::assertSame(1, $this->probe($port), 'nothing listening');
    }

    /** Review, update#106: a port that takes the connection and never answers held a probe for minutes. */
    #[Test]
    public function a_probe_of_a_port_that_never_answers_gives_up_in_seconds(): void
    {
        // Listens and never accepts: the kernel completes the connection, nobody answers.
        $silent = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($silent);
        $name = (string) stream_socket_get_name($silent, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $started = microtime(true);
        $exit = $this->probe($port);
        $took = microtime(true) - $started;
        fclose($silent);

        self::assertSame(1, $exit);
        self::assertLessThan(5.0, $took, 'one probe gives up after its 2 s timeout');
    }

    /**
     * Review, installer#103: a socket timeout bounds each read, so an answer that
     * keeps coming and never ends (a header line every half second here) held a
     * probe for as long as it went on. The whole probe ends within 3 s.
     */
    #[Test]
    public function a_probe_of_an_answer_that_never_ends_gives_up_in_seconds(): void
    {
        $drip = <<<'PHP'
            $server = stream_socket_server('tcp://127.0.0.1:0');
            $name = (string) stream_socket_get_name($server, false);
            echo substr($name, strrpos($name, ':') + 1), "\n";
            $client = stream_socket_accept($server, 30);
            fwrite($client, "HTTP/1.1 200 OK\r\n");
            for ($i = 0; $i < 60; $i++) {
                fwrite($client, "X-Drip: $i\r\n");
                usleep(500000);
            }
            PHP;
        $server = proc_open([PHP_BINARY, '-r', $drip], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($server);
        $this->server = $server;
        $port = (int) fgets($pipes[1]);
        self::assertGreaterThan(0, $port, 'the dripping server says its port');

        $started = microtime(true);
        $exit = $this->probe($port);
        $took = microtime(true) - $started;

        self::assertSame(1, $exit, 'an answer whose headers never end is no answer');
        self::assertLessThan(6.0, $took, 'the whole probe is bounded, not each read');
    }

    /** Independent review: compose exec forwards the caller's input, and a `while read` loop lost its lines. */
    #[Test]
    public function the_probe_leaves_the_callers_input_alone(): void
    {
        // A compose that reads all it is given, as `docker compose exec` does.
        $sh = 'run_compose() { cat >/dev/null; }' . "\n" . $this->function('app_answers_health')
            . "\nprintf 'first\\nsecond\\n' | { app_answers_health 1; read -r line; printf '%s' \"\$line\"; }";

        self::assertSame('first', shell_exec(sprintf('env -i PATH=/usr/bin:/bin sh -c %s', escapeshellarg($sh))));
    }

    #[Test]
    public function an_app_that_answers_lets_the_start_go_on(): void
    {
        [$out, $exit, $tries, $slept] = $this->wait(answerAt: 3);

        self::assertSame(['INFO Waiting for the app to answer on port 9517...'], $out);
        self::assertSame([0, 3], [$exit, $tries]);
        self::assertSame(['2', '2'], $slept, '2 s between tries, none after the answer');
    }

    // verify:accept-test-change the wait overrides that a_wait_that_is_not_a_positive_whole_number_is_thirty_tries_two_seconds_apart checked are removed from bin/semitexa (two-round cap, installer#103); the fixed 30 tries, 2 s apart, are pinned here
    #[Test]
    public function an_app_that_never_answers_fails_the_start_with_what_it_last_logged(): void
    {
        [$out, $exit, $tries, $slept] = $this->wait(answerAt: 999);

        self::assertSame([
            'INFO Waiting for the app to answer on port 9517...',
            'ERROR The app did not answer on port 9517 after 30 tries, 2s apart: it is not running, still booting, or restarting.',
            'INFO Its last log lines (all of them: docker compose logs app):',
            '    PHP Fatal error:  Uncaught Error: Class "App\Missing" not found',
            '    Worker exited with code 255',
        ], $out);
        self::assertSame([1, 30], [$exit, $tries], 'it gives up after 30 tries');
        self::assertSame(array_fill(0, 29, '2'), $slept, '2 s between tries, none after the last');
    }

    #[Test]
    public function start_and_restart_wait_before_they_say_so_and_a_named_service_restart_does_not_wait(): void
    {
        $start = $this->function('cmd_server_start');
        $wait = strpos($start, 'server_start_wait_for_app || exit 1');
        $said = strpos($start, 'success "Semitexa environment started!"');
        self::assertIsInt($wait, 'server:start waits for the app');
        self::assertIsInt($said, 'server:start still says it started');
        self::assertLessThan($said, $wait, 'it waits before it says so');

        // Review, installer#103: restarting redis alone does not depend on whether the app is up.
        $restart = $this->function('cmd_server_restart');
        $guarded = "    if [ -z \"\$_service\" ] || [ \"\$_service\" = \"app\" ]; then\n        server_start_wait_for_app || exit 1\n    fi\n    success \"Semitexa environment restarted!\"";
        self::assertStringContainsString($guarded, $restart);
    }

    /** Starts a local server whose every path answers with the status answer() wrote; returns its port. */
    private function serve(): int
    {
        $this->dir = sys_get_temp_dir() . '/health-probe-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/router.php', "<?php\nhttp_response_code((int) file_get_contents(__DIR__ . '/status'));\necho 'x';\n");
        $this->answer(200);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $this->dir . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($server);
        $this->server = $server;
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(100_000);
        }

        return $port;
    }

    private function answer(int $status): void
    {
        file_put_contents($this->dir . '/status', (string) $status);
    }

    private function stopServer(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->server = null;
    }

    /** app_answers_health as bin/semitexa has it, its `run_compose exec -T app` run here. */
    private function probe(int $port): int
    {
        $sh = 'run_compose() { shift 3; "$@"; }' . "\n" . $this->function('app_answers_health')
            . "\napp_answers_health " . $port . "; printf '%s' \"\$?\"";

        return (int) shell_exec(sprintf('env -i PATH=%s sh -c %s', escapeshellarg(dirname(PHP_BINARY) . ':/usr/bin:/bin'), escapeshellarg($sh)));
    }

    /** @return array{list<string>, int, int, list<string>} the lines printed, the exit code, the probes made, the sleeps asked for */
    private function wait(int $answerAt): array
    {
        $count = sys_get_temp_dir() . '/start-wait-' . bin2hex(random_bytes(6));
        $sleeps = $count . '.slept';
        try {
            $sh = self::WAIT_STUBS . "\n" . $this->function('server_start_wait_for_app') . "\nserver_start_wait_for_app; printf 'EXIT %s\\n' \"\$?\"";
            $raw = (string) shell_exec(sprintf(
                'env -i PATH=/usr/bin:/bin T_COUNT=%s T_SLEPT=%s T_ANSWER_AT=%d sh -c %s',
                escapeshellarg($count),
                escapeshellarg($sleeps),
                $answerAt,
                escapeshellarg($sh),
            ));
            $tries = (int) @file_get_contents($count);
            $slept = is_file($sleeps) ? (file($sleeps, FILE_IGNORE_NEW_LINES) ?: []) : [];
        } finally {
            @unlink($count);
            @unlink($sleeps);
        }
        $lines = array_values(array_filter(explode("\n", $raw), static fn (string $line): bool => $line !== ''));
        $exitLine = array_pop($lines) ?? '';
        self::assertMatchesRegularExpression('/^EXIT \d+$/', $exitLine);

        return [$lines, (int) substr($exitLine, 5), $tries, array_values($slept)];
    }

    private function function(string $name): string
    {
        self::assertSame(1, preg_match('/^' . $name . '\(\) \{\n.*?^\}$/ms', $this->script(), $match), $name . ' must still exist in bin/semitexa');

        return $match[0] ?? '';
    }

    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
    }
}
