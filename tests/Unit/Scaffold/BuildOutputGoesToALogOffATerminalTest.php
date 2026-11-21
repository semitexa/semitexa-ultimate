<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tk-install-prints-build-logs-to-a-non-terminal. An agent that deployed a
 * fresh clone read some 25,000 characters of BuildKit steps, Composer
 * "Extracting archive" lines and container state changes from install and
 * server:start, none of it needed once the step worked.
 *
 * With stdout not a terminal a step writes its output to a log under var/log
 * and prints one line; a failure prints the log's last 40 lines, its path, and
 * keeps the command's exit code. On a terminal, or with SEMITEXA_VERBOSE=1,
 * the output comes as before.
 *
 * The functions run as bin/semitexa has them; compose is a stub. The terminal
 * is a real one (a pty). The project's path has a blank in it.
 */
final class BuildOutputGoesToALogOffATerminalTest extends TestCase
{
    private const QUIET = ['stdout_is_quiet', 'composer_quiet_flag', 'quiet_step_log', 'run_quiet_step'];
    private const SAY = 'info() { printf "INFO %s\n" "$*"; }; success() { printf "OK %s\n" "$*"; }; warn() { printf "WARN %s\n" "$*"; }; error() { printf "ERROR %s\n" "$*"; }' . "\n";
    /** A build's noise on both streams, then the exit code in $RC. */
    private const COMPOSE = 'run_compose() { echo "#1 [internal] load build definition"; echo "#2 extracting sha256:abc" >&2; echo "ran: $*"; return "${RC:-0}"; }' . "\n";
    private const NOISE = "#1 [internal] load build definition\n#2 extracting sha256:abc\nran: build app\n";

    /**
     * install and demo:install say how long the first build takes, unless the app image is known to
     * exist (tk-first-run-image-build-minutes). The compose stub names no image, so here it is not known.
     */
    private const INSTALL = [...self::QUIET, 'app_image_state', 'app_image_build_notice'];
    private const FIRST_BUILD = 'INFO No app image yet: the first build compiles the PHP extensions and takes several minutes, about 6 on a typical machine. Later runs reuse the image.';

    private const START = [...self::QUIET, 'server_start_log_signals_port_conflict', 'server_start_compose_up_shown', 'server_start_compose_up', 'server_start_run_with_port_conflict_fallback'];
    /** The port is taken on the first start, free once the app is reconciled to another. */
    private const CONFLICT = 'PORT=9555; env_val() { printf "%s" "$PORT"; }; local_app_reconcile() { PORT=9600; }' . "\n"
        . 'run_compose() { echo " Container demo-app-1 Starting"; if [ "$PORT" = 9555 ]; then echo "Error response from daemon: Bind for 0.0.0.0:9555 failed: port is already allocated" >&2; return 1; fi; echo " Container demo-app-1 Started"; }' . "\n";

    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/quiet step-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            if (is_dir($this->tmp . '/var/log')) {
                chmod($this->tmp . '/var/log', 0o755);
            }
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    #[Test]
    public function off_a_terminal_a_build_that_works_prints_one_line_and_the_log_has_the_rest(): void
    {
        $out = $this->shell(self::QUIET, self::SAY . self::COMPOSE
            . 'run_quiet_step install-build "Building the app image" run_compose build app; printf "exit=%s\n" "$?"');

        $log = $this->tmp . '/var/log/install-build.log';
        self::assertSame(['OK Building the app image: done (full output: ' . $log . ')', 'exit=0'], $out);
        self::assertSame(self::NOISE, file_get_contents($log));
    }

    #[Test]
    public function off_a_terminal_a_build_that_fails_prints_the_last_forty_lines_the_log_and_its_exit_code(): void
    {
        // As install runs it: under set -e, the result not checked.
        $out = $this->shell(self::QUIET, self::SAY
            . 'run_compose() { i=1; while [ "$i" -le 60 ]; do echo "#$i step"; i=$((i + 1)); done; echo "failed to solve: no such image" >&2; return 17; }' . "\n"
            . '( set -e; run_quiet_step install-build "Building the app image" run_compose build app; echo "went on" ); printf "exit=%s\n" "$?"');

        $log = $this->tmp . '/var/log/install-build.log';
        $tail = array_map(static fn (int $i): string => "    #{$i} step", range(22, 60));
        self::assertSame([
            'ERROR Building the app image: failed (exit 17). The last lines of its output:',
            ...$tail,
            '    failed to solve: no such image',
            'ERROR Full output: ' . $log,
            'exit=17',
        ], $out);
        self::assertSame(61, substr_count((string) file_get_contents($log), "\n"), 'the log has all of it');
    }

    #[Test]
    public function on_a_terminal_the_output_comes_as_it_did_and_no_log_is_written(): void
    {
        $out = $this->shell(self::QUIET, self::SAY . self::COMPOSE
            . '[ -t 1 ] && echo "on a terminal"' . "\n"
            . 'run_quiet_step install-build "Building the app image" run_compose build app; printf "exit=%s\n" "$?"' . "\n"
            . 'RC=17; ( set -e; run_quiet_step install-build "Building the app image" run_compose build app; echo "went on" ); printf "exit=%s\n" "$?"' . "\n"
            . 'composer_quiet_flag; printf "flag=[%s]\n" "$COMPOSER_QUIET_FLAG"', terminal: true);

        $noise = explode("\n", rtrim(self::NOISE));
        self::assertSame(['on a terminal', ...$noise, 'exit=0', ...$noise, 'exit=17', 'flag=[]'], $out);
        self::assertDirectoryDoesNotExist($this->tmp . '/var');
    }

    #[Test]
    public function semitexa_verbose_gives_the_full_output_off_a_terminal(): void
    {
        $out = $this->shell(self::QUIET, self::SAY . self::COMPOSE
            . 'SEMITEXA_VERBOSE=1' . "\n"
            . 'run_quiet_step install-build "Building the app image" run_compose build app; printf "exit=%s\n" "$?"' . "\n"
            . 'RC=17; ( set -e; run_quiet_step install-build "Building the app image" run_compose build app; echo "went on" ); printf "exit=%s\n" "$?"' . "\n"
            . 'composer_quiet_flag; printf "flag=[%s]\n" "$COMPOSER_QUIET_FLAG"');

        $noise = explode("\n", rtrim(self::NOISE));
        self::assertSame([...$noise, 'exit=0', ...$noise, 'exit=17', 'flag=[]'], $out);
        self::assertDirectoryDoesNotExist($this->tmp . '/var');
    }

    /** var/log may be another user's (a root container made it): the step stays quiet and names the log it did write. */
    #[Test]
    public function a_log_directory_it_cannot_write_moves_the_log_and_keeps_the_step_quiet(): void
    {
        file_put_contents($this->tmp . '/var', 'a file where the directory would be');

        $out = $this->shell(self::QUIET, self::SAY . self::COMPOSE
            . 'run_quiet_step install-build "Building the app image" run_compose build app; printf "exit=%s\n" "$?"');

        self::assertCount(2, $out);
        self::assertSame(1, preg_match('~^OK Building the app image: done \(full output: (/.+)\)$~', $out[0], $match), $out[0]);
        self::assertSame('exit=0', $out[1]);
        $log = $match[1] ?? '';
        self::assertFalse(str_starts_with($log, $this->tmp . "/"), "not under the project: " . $log);
        self::assertSame(self::NOISE, file_get_contents($log));
        unlink($log);
    }

    #[Test]
    public function install_off_a_terminal_says_each_step_once_and_runs_composer_without_progress(): void
    {
        $out = $this->shell([...self::INSTALL, 'refresh_local_semitexa_packages', 'cmd_install'], $this->installStubs()
            . '( set -e; cmd_install ); printf "exit=%s\n" "$?"');

        $logs = $this->tmp . '/var/log/';
        self::assertSame([
            'INFO Building Docker image and running composer install inside container...',
            self::FIRST_BUILD,
            'OK Building the app image: done (full output: ' . $logs . 'install-build.log)',
            'OK Installing the Composer packages: done (full output: ' . $logs . 'install-composer.log)',
            'INFO Refreshing local Semitexa path packages in composer.lock...',
            'INFO (This keeps workspace clones ahead of stale published tags)',
            'OK Refreshing the local path packages: done (full output: ' . $logs . 'install-path-packages.log)',
            'OK Dependencies installed successfully!',
            'INFO Next: bin/semitexa server:start',
            'exit=0',
        ], array_values(array_filter($out, static fn (string $line): bool => $line !== '' && !str_contains($line, 'Install Dependencies'))));
        self::assertSame("Extracting archive\nran: build app\n", file_get_contents($logs . 'install-build.log'));
        self::assertSame("Extracting archive\nran: run --rm --no-deps --user 7:7 app composer install --no-interaction --prefer-dist --no-progress\n", file_get_contents($logs . 'install-composer.log'));
        self::assertSame("Extracting archive\nran: run --rm --no-deps --user 7:7 app composer update --no-interaction --with-all-dependencies --no-progress semitexa/core semitexa/orm:dev-develop\n", file_get_contents($logs . 'install-path-packages.log'));
    }

    #[Test]
    public function install_on_a_terminal_runs_the_commands_it_ran_before(): void
    {
        $out = $this->shell([...self::INSTALL, 'refresh_local_semitexa_packages', 'cmd_install'], $this->installStubs()
            . '( set -e; cmd_install ); printf "exit=%s\n" "$?"', terminal: true);

        self::assertSame([
            'ran: build app',
            'ran: run --rm --no-deps --user 7:7 app composer install --no-interaction --prefer-dist',
            'ran: run --rm --no-deps --user 7:7 app composer update --no-interaction --with-all-dependencies semitexa/core semitexa/orm:dev-develop',
        ], array_values(array_filter($out, static fn (string $line): bool => str_starts_with($line, 'ran: '))));
        self::assertSame(3, count(array_keys($out, 'Extracting archive', true)), 'the output of each step is on screen');
        self::assertSame('exit=0', $out[count($out) - 1]);
        self::assertDirectoryDoesNotExist($this->tmp . '/var');
    }

    #[Test]
    public function install_off_a_terminal_stops_at_a_failed_build_with_its_exit_code(): void
    {
        $out = $this->shell([...self::INSTALL, 'refresh_local_semitexa_packages', 'cmd_install'], $this->installStubs()
            . 'run_compose() { echo "failed to solve: $*"; return 17; }' . "\n"
            . '( set -e; cmd_install ); printf "exit=%s\n" "$?"');

        $log = $this->tmp . '/var/log/install-build.log';
        self::assertSame([
            'ERROR Building the app image: failed (exit 17). The last lines of its output:',
            '    failed to solve: build app',
            'ERROR Full output: ' . $log,
            'exit=17',
        ], array_slice($out, -4), 'composer never ran');
        self::assertContains(self::FIRST_BUILD, $out, 'the wait is said before the build that fails');
        self::assertFileDoesNotExist($this->tmp . '/var/log/install-composer.log');
    }

    #[Test]
    public function server_start_off_a_terminal_prints_one_line_for_the_containers(): void
    {
        $out = $this->shell([...self::QUIET, 'server_start_log_signals_port_conflict', 'server_start_compose_up_shown', 'server_start_compose_up', 'server_start_run_with_port_conflict_fallback'], self::SAY
            . 'run_compose() { echo " Image demo-app Building"; echo " Container demo-app-1 Started" >&2; echo "ran: $*"; }' . "\n"
            . 'server_start_run_with_port_conflict_fallback; printf "exit=%s\n" "$?"');

        $log = $this->tmp . '/var/log/server-start.log';
        self::assertSame(['OK Building and starting the containers: done (full output: ' . $log . ')', 'exit=0'], $out);
        self::assertSame(" Image demo-app Building\n Container demo-app-1 Started\nran: up -d --build\n", file_get_contents($log));
    }

    /** The port-conflict recovery reads the output it no longer shows. */
    #[Test]
    public function server_start_off_a_terminal_still_recovers_from_a_port_conflict(): void
    {
        $out = $this->shell([...self::QUIET, 'server_start_log_signals_port_conflict', 'server_start_compose_up_shown', 'server_start_compose_up', 'server_start_run_with_port_conflict_fallback'], self::SAY
            . 'PORT=9555; env_val() { printf "%s" "$PORT"; }; local_app_reconcile() { PORT=9600; }' . "\n"
            . 'run_compose() { echo " Container demo-app-1 Starting"; if [ "$PORT" = 9555 ]; then echo "Error response from daemon: Bind for 0.0.0.0:9555 failed: port is already allocated" >&2; return 1; fi; echo " Container demo-app-1 Started"; }' . "\n"
            . 'server_start_run_with_port_conflict_fallback; printf "exit=%s\n" "$?"');

        $logs = $this->tmp . '/var/log/';
        self::assertSame([
            'ERROR Building and starting the containers: failed (exit 1). The last lines of its output:',
            '     Container demo-app-1 Starting',
            '    Error response from daemon: Bind for 0.0.0.0:9555 failed: port is already allocated',
            'ERROR Full output: ' . $logs . 'server-start.log',
            '',
            'WARN Swoole port conflict detected for SWOOLE_PORT=9555.',
            'INFO Non-interactive shell; auto-reconciling without prompt.',
            'INFO Reconciled to SWOOLE_PORT=9600. Retrying server:start once...',
            'OK Building and starting the containers: done (full output: ' . $logs . 'server-start-retry.log)',
            'OK Recovered: server bound to SWOOLE_PORT=9600.',
            'exit=0',
        ], $out);
        self::assertSame(" Container demo-app-1 Starting\n Container demo-app-1 Started\n", file_get_contents($logs . 'server-start-retry.log'));
    }

    #[Test]
    public function server_start_off_a_terminal_keeps_the_exit_code_of_a_start_that_fails(): void
    {
        $out = $this->shell([...self::QUIET, 'server_start_log_signals_port_conflict', 'server_start_compose_up_shown', 'server_start_compose_up', 'server_start_run_with_port_conflict_fallback'], self::SAY
            . 'env_val() { printf 9555; }; run_compose() { echo "service app: pull access denied"; return 18; }' . "\n"
            // As server:start runs it.
            . '( set -e; server_start_run_with_port_conflict_fallback || exit $?; echo "went on" ); printf "exit=%s\n" "$?"');

        self::assertSame([
            'ERROR Building and starting the containers: failed (exit 18). The last lines of its output:',
            '    service app: pull access denied',
            'ERROR Full output: ' . $this->tmp . '/var/log/server-start.log',
            'exit=18',
        ], $out);
    }

    #[Test]
    public function server_start_on_a_terminal_shows_the_containers_as_they_come(): void
    {
        $out = $this->shell([...self::QUIET, 'server_start_log_signals_port_conflict', 'server_start_compose_up_shown', 'server_start_compose_up', 'server_start_run_with_port_conflict_fallback'], self::SAY
            . 'env_val() { printf 9555; }; run_compose() { echo " Container demo-app-1 Started"; echo "ran: $*"; return "${RC:-0}"; }' . "\n"
            . 'server_start_run_with_port_conflict_fallback; printf "exit=%s\n" "$?"' . "\n"
            . 'RC=18; ( set -e; server_start_run_with_port_conflict_fallback || exit $?; echo "went on" ); printf "exit=%s\n" "$?"', terminal: true);

        self::assertSame([
            ' Container demo-app-1 Started', 'ran: up -d --build', 'exit=0',
            ' Container demo-app-1 Started', 'ran: up -d --build', 'exit=18',
        ], $out);
        self::assertDirectoryDoesNotExist($this->tmp . '/var');
    }

    /**
     * Review: the log was emptied with `: > file`, and a redirection that
     * fails on a special builtin ends the shell. With var/log there but not
     * writable the step printed a raw "cannot create" and ran loud.
     */
    #[Test]
    public function a_log_directory_that_is_read_only_moves_the_log_and_keeps_the_step_quiet(): void
    {
        $this->readOnlyLogDirectory();

        $out = $this->shell(self::QUIET, self::SAY . self::COMPOSE
            . 'run_quiet_step install-build "Building the app image" run_compose build app; printf "exit=%s\n" "$?"');

        self::assertCount(2, $out, implode("\n", $out));
        self::assertSame(1, preg_match('~^OK Building the app image: done \(full output: (/.+)\)$~', $out[0], $match), $out[0]);
        self::assertSame('exit=0', $out[1]);
        $log = $match[1] ?? '';
        self::assertFalse(str_starts_with($log, $this->tmp . '/'), 'not under the project: ' . $log);
        self::assertSame(self::NOISE, file_get_contents($log));
        unlink($log);
        self::assertSame([], glob($this->tmp . '/var/log/*'));
    }

    /** Review: with no log of its own the quiet start had nothing for the port-conflict check to read. */
    #[Test]
    public function server_start_off_a_terminal_recovers_from_a_port_conflict_with_a_read_only_log_directory(): void
    {
        $this->readOnlyLogDirectory();

        $out = $this->shell(self::START, self::SAY . self::CONFLICT
            . 'server_start_run_with_port_conflict_fallback; printf "exit=%s\n" "$?"');

        self::assertCount(11, $out, implode("\n", $out));
        self::assertSame(1, preg_match('~^ERROR Full output: (/.+)$~', $out[3], $first), $out[3]);
        self::assertSame(1, preg_match('~^OK Building and starting the containers: done \(full output: (/.+)\)$~', $out[8], $retry), $out[8]);
        self::assertSame([
            'ERROR Building and starting the containers: failed (exit 1). The last lines of its output:',
            '     Container demo-app-1 Starting',
            '    Error response from daemon: Bind for 0.0.0.0:9555 failed: port is already allocated',
            $out[3],
            '',
            'WARN Swoole port conflict detected for SWOOLE_PORT=9555.',
            'INFO Non-interactive shell; auto-reconciling without prompt.',
            'INFO Reconciled to SWOOLE_PORT=9600. Retrying server:start once...',
            $out[8],
            'OK Recovered: server bound to SWOOLE_PORT=9600.',
            'exit=0',
        ], $out);
        foreach ([$first[1] ?? '', $retry[1] ?? ''] as $log) {
            self::assertFalse(str_starts_with($log, $this->tmp . '/'), 'not under the project: ' . $log);
            self::assertStringContainsString(' Container demo-app-1 Starting', (string) file_get_contents($log));
            unlink($log);
        }
    }

    /** Review: a quiet step with no log at all runs loud, and the port-conflict check still has the output. */
    #[Test]
    public function server_start_with_no_log_to_write_runs_loud_and_still_recovers_from_a_port_conflict(): void
    {
        $out = $this->shell(self::START, self::SAY . self::CONFLICT
            . 'quiet_step_log() { return 1; }' . "\n"
            . 'server_start_run_with_port_conflict_fallback; printf "exit=%s\n" "$?"');

        self::assertSame([
            ' Container demo-app-1 Starting',
            'Error response from daemon: Bind for 0.0.0.0:9555 failed: port is already allocated',
            '',
            'WARN Swoole port conflict detected for SWOOLE_PORT=9555.',
            'INFO Non-interactive shell; auto-reconciling without prompt.',
            'INFO Reconciled to SWOOLE_PORT=9600. Retrying server:start once...',
            ' Container demo-app-1 Starting',
            ' Container demo-app-1 Started',
            'OK Recovered: server bound to SWOOLE_PORT=9600.',
            'exit=0',
        ], $out);
    }

    /** Review: a prompt in a step nobody sees would wait; `server:start | cat` at a keyboard has an stdin to wait on. */
    #[Test]
    public function a_quiet_step_reads_no_input_and_a_loud_one_keeps_it(): void
    {
        $out = $this->shell(self::QUIET, self::SAY
            . 'run_compose() { _a=none; read -r _a || :; echo "answer=[$_a]"; }' . "\n"
            . 'echo y | run_quiet_step install-build "Building the app image" run_compose build app' . "\n"
            . 'cat "$PROJECT_ROOT/var/log/install-build.log"' . "\n"
            . 'SEMITEXA_VERBOSE=1; echo y | run_quiet_step install-build "Building the app image" run_compose build app');

        self::assertSame([
            'OK Building the app image: done (full output: ' . $this->tmp . '/var/log/install-build.log)',
            'answer=[]',
            'answer=[y]',
        ], $out);
    }

    #[Test]
    public function demo_install_off_a_terminal_says_each_step_once_and_runs_composer_without_progress(): void
    {
        $out = $this->shell([...self::INSTALL, 'cmd_demo_install'], $this->installStubs()
            . '( set -e; cmd_demo_install ); printf "exit=%s\n" "$?"');

        $logs = $this->tmp . '/var/log/';
        self::assertSame([
            'INFO Installing semitexa/demo via Composer inside Docker...',
            self::FIRST_BUILD,
            'OK Building the app image: done (full output: ' . $logs . 'demo-install-build.log)',
            // One short command, left as it was.
            'Extracting archive',
            'ran: run --rm --no-deps --user 7:7 app composer config repositories.semitexa-demo vcs https://github.com/semitexa/semitexa-demo.git',
            'OK Installing semitexa/demo: done (full output: ' . $logs . 'demo-install-composer.log)',
            'OK Semitexa Demo installed (semitexa/demo).',
            'INFO Start or restart the server to load the new routes.',
            'INFO Remove when done: composer remove semitexa/demo',
            'exit=0',
        ], array_values(array_filter($out, static fn (string $line): bool => $line !== '' && !str_contains($line, 'Install Demo'))));
        self::assertSame("Extracting archive\nran: build app\n", file_get_contents($logs . 'demo-install-build.log'));
        self::assertSame("Extracting archive\nran: run --rm --no-deps --user 7:7 app composer require semitexa/demo:* --no-interaction --prefer-dist --no-progress\n", file_get_contents($logs . 'demo-install-composer.log'));
    }

    #[Test]
    public function demo_install_on_a_terminal_runs_the_commands_it_ran_before(): void
    {
        $out = $this->shell([...self::INSTALL, 'cmd_demo_install'], $this->installStubs()
            . '( set -e; cmd_demo_install ); printf "exit=%s\n" "$?"', terminal: true);

        self::assertSame([
            'ran: build app',
            'ran: run --rm --no-deps --user 7:7 app composer config repositories.semitexa-demo vcs https://github.com/semitexa/semitexa-demo.git',
            'ran: run --rm --no-deps --user 7:7 app composer require semitexa/demo:* --no-interaction --prefer-dist',
        ], array_values(array_filter($out, static fn (string $line): bool => str_starts_with($line, 'ran: '))));
        self::assertSame('exit=0', $out[count($out) - 1]);
        self::assertDirectoryDoesNotExist($this->tmp . '/var');
    }

    /** var/log is there and cannot be written. Root writes it all the same, and the case would prove nothing. */
    private function readOnlyLogDirectory(): void
    {
        mkdir($this->tmp . '/var/log', 0o777, true);
        chmod($this->tmp . '/var/log', 0o555);
        self::assertFalse(@file_put_contents($this->tmp . '/var/log/probe', ''), 'var/log must refuse a write: these tests run as the app user, not root');
    }

    private function installStubs(): string
    {
        foreach (['Dockerfile', 'composer.json', '.env.default'] as $file) {
            file_put_contents($this->tmp . '/' . $file, '');
        }

        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
        self::assertSame(1, preg_match('/^APP_IMAGE_BUILD_WAIT=.*$/m', $script, $wait), 'APP_IMAGE_BUILD_WAIT must still exist in bin/semitexa');

        return self::SAY . ($wait[0] ?? '') . "\n" . 'has_compose() { return 0; }; id() { echo 7; }' . "\n"
            . 'local_semitexa_package_update_specs() { printf "semitexa/core\nsemitexa/orm:dev-develop\n"; }' . "\n"
            . 'run_compose() { echo "Extracting archive"; echo "ran: $*"; }' . "\n";
    }

    /**
     * @param list<string> $functions the bin/semitexa functions the snippet needs
     * @return list<string> the lines it printed, stderr among them
     */
    private function shell(array $functions, string $snippet, bool $terminal = false): array
    {
        $sh = 'exec 2>&1' . "\n" . 'PROJECT_ROOT=' . escapeshellarg($this->tmp) . "\n"
            . implode("\n", array_map($this->function(...), $functions)) . "\n" . $snippet;
        $process = proc_open(
            ['sh', '-c', $sh],
            [0 => ['file', '/dev/null', 'r'], 1 => $terminal ? ['pty'] : ['pipe', 'w']],
            $pipes,
            $this->tmp,
            ['PATH' => '/usr/bin:/bin', 'TMPDIR' => sys_get_temp_dir()],
        );
        self::assertIsResource($process, 'sh must start');
        $raw = '';
        // A pty gives an error, not an end of file, once the shell is gone.
        while (!feof($pipes[1])) {
            $chunk = @fread($pipes[1], 8192);
            if ($chunk === false || ($chunk === '' && !proc_get_status($process)['running'])) {
                break;
            }
            $raw .= $chunk;
        }
        fclose($pipes[1]);
        proc_close($process);
        $raw = str_replace("\r\n", "\n", $raw);

        return explode("\n", str_ends_with($raw, "\n") ? substr($raw, 0, -1) : $raw);
    }

    private function function(string $name): string
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
        self::assertSame(1, preg_match('/^' . $name . '\(\) \{\n.*?^\}$/ms', $script, $match), $name . ' must still exist in bin/semitexa');

        return $match[0] ?? '';
    }
}
