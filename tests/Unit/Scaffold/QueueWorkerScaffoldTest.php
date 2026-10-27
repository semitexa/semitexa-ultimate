<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * REGRESSION (tk-pip-no-queue-worker). EVENTS_ASYNC=1 is the scaffold default,
 * and nothing in an installed project ran queue:work: a Queued listener was
 * published to NATS and never consumed. The worker now runs in a container of
 * its own (docker-compose.worker.yml), which bin/semitexa loads exactly when
 * EVENTS_ASYNC=1, and which runs every line of worker:processes: queue:work
 * per queue, mail:work, media:work, the scheduler and webhook delivery.
 */
final class QueueWorkerScaffoldTest extends TestCase
{
    private const OVERLAYS = ['docker-compose.nats.yml', 'docker-compose.mysql.yml', 'docker-compose.redis.yml', 'docker-compose.ollama.yml'];

    private string $root;

    private string $tmp = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '') {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    #[Test]
    public function the_worker_runs_from_the_apps_image_and_project_in_a_container_of_its_own(): void
    {
        $worker = $this->service('docker-compose.worker.yml', 'worker');
        $app = $this->service('docker-compose.yml', 'app');

        foreach (['build: .', 'user: "${SEMITEXA_UID:-1000}:${SEMITEXA_GID:-1000}"', '      - .:/var/www/html', 'restart: unless-stopped'] as $line) {
            self::assertStringContainsString($line, $app, 'the app this is compared with');
            self::assertStringContainsString($line, $worker);
        }
        self::assertStringContainsString(self::section($app, 'env_file') . "\n      - path: var/run/worker.env\n        required: false\n", $worker, "the app's env files, then what bin/semitexa writes for the worker");
        self::assertSame("    environment:\n      HOME: /var/www/html", self::section($worker, 'environment'), 'nothing set here: a value missing here would blank what the env files give');
        self::assertStringContainsString("    init: true\n", $worker, 'docker stop reaches the shell, which stops every queue:work');
        self::assertStringContainsString("    depends_on:\n      app:\n        condition: service_started\n", $worker);
        self::assertStringNotContainsString('ports:', $worker, 'it can be scaled: no host port to collide on');
    }

    /**
     * REGRESSION (release review). The first version put a partial `worker:`
     * into each overlay. `bin/semitexa update` replaces files one by one, so a
     * project that keeps an edited docker-compose.yml got the new overlays
     * without the base that defines the service, and every compose command
     * failed: "service worker has neither an image nor a build context".
     */
    #[Test]
    public function no_other_compose_file_names_the_worker(): void
    {
        foreach (['docker-compose.yml', ...self::OVERLAYS] as $file) {
            $contents = (string) file_get_contents($this->root . '/' . $file);
            self::assertStringContainsString("services:\n", $contents, "{$file} is still there to be checked");
            self::assertStringNotContainsString("\n  worker:\n", $contents, $file);
        }
    }

    /**
     * The scheduler and webhook delivery poll the database and need no broker,
     * so the worker runs whatever EVENTS_ASYNC says (review): without async
     * events it runs those, and the queue consumers name nothing.
     */
    #[Test]
    public function bin_semitexa_loads_the_worker_whatever_events_async_says(): void
    {
        $this->project([...self::OVERLAYS, 'docker-compose.worker.yml']);

        self::assertSame('-f docker-compose.yml -f docker-compose.worker.yml -f docker-compose.nats.yml', $this->shell('compose_args', ['EVENTS_ASYNC' => '1']));
        self::assertSame('-f docker-compose.yml -f docker-compose.worker.yml', $this->shell('compose_args', ['EVENTS_ASYNC' => '0']));

        unlink($this->tmp . '/docker-compose.worker.yml');
        self::assertSame('', $this->shell('compose_args', ['EVENTS_ASYNC' => '0']), 'a project without the file starts as before');
    }

    /**
     * REGRESSION (release review). A `--profile queue` flag replaced the
     * profiles a user set in COMPOSE_PROFILES instead of adding to them. A
     * file of its own needs no profile at all.
     */
    #[Test]
    public function no_compose_profile_is_passed_and_stop_includes_the_worker(): void
    {
        $this->project([...self::OVERLAYS, 'docker-compose.worker.yml']);

        self::assertSame('-f docker-compose.yml -f docker-compose.worker.yml -f docker-compose.nats.yml', $this->shell('compose_args', ['EVENTS_ASYNC' => '1']), 'files only, no --profile');
        self::assertSame(
            '-f docker-compose.yml -f docker-compose.worker.yml -f docker-compose.nats.yml -f docker-compose.mysql.yml -f docker-compose.redis.yml -f docker-compose.ollama.yml',
            $this->shell('compose_all_args', ['EVENTS_ASYNC' => '0']),
            'server:stop takes the worker down even after EVENTS_ASYNC was switched off',
        );
    }

    /** A project's docker-compose.override.yml comes after the worker's file, so it can change the worker. */
    #[Test]
    public function the_projects_override_is_loaded_after_the_worker(): void
    {
        $this->project(['docker-compose.override.yml', 'docker-compose.worker.yml']);

        self::assertSame('-f docker-compose.yml -f docker-compose.worker.yml -f docker-compose.override.yml', $this->shell('compose_args', ['EVENTS_ASYNC' => '1']));
        self::assertSame('-f docker-compose.yml -f docker-compose.worker.yml -f docker-compose.override.yml', $this->shell('compose_all_args', []));
    }

    /**
     * The worker's file cannot name an overlay's service, so bin/semitexa
     * writes what each overlay sets on the app to var/run/worker.env. Read
     * from the overlays themselves, so a host or port changed there cannot
     * drift here.
     */
    #[Test]
    public function the_worker_is_given_exactly_what_each_loaded_overlay_gives_the_app(): void
    {
        $this->project([...self::OVERLAYS, 'docker-compose.worker.yml']);
        $expected = [];
        foreach (['docker-compose.mysql.yml', 'docker-compose.redis.yml', 'docker-compose.nats.yml', 'docker-compose.ollama.yml'] as $overlay) {
            preg_match_all('/^      ([A-Z_]+): "?([^"\n]+)"?$/m', self::section($this->service($overlay, 'app'), 'environment'), $m, PREG_SET_ORDER);
            foreach ($m as [, $key, $value]) {
                $expected[] = "{$key}={$value}";
            }
        }
        self::assertCount(6, $expected, 'DB_HOST, DB_PORT, REDIS_HOST, REDIS_PORT, NATS_PRIMARY_URL, LLM_BASE_URL');
        $header = '# Written by bin/semitexa: the services the overlays give the app.';

        $this->shell('compose_write_worker_env', ['EVENTS_ASYNC' => '1', 'DB_DRIVER' => 'mysql', 'REDIS_HOST' => 'redis', 'LLM_PROVIDER' => 'ollama']);
        self::assertSame($header . "\n" . implode("\n", $expected) . "\n", file_get_contents($this->tmp . '/var/run/worker.env'));

        $this->shell('compose_write_worker_env', ['EVENTS_ASYNC' => '0', 'DB_DRIVER' => 'sqlite']);
        self::assertSame($header . "\n", file_get_contents($this->tmp . '/var/run/worker.env'), 'rewritten: no overlay loaded, nothing from the last start kept');
    }

    /**
     * REGRESSION (tk-worker-runs-mail-work). Only queue:work ran here: queued
     * mail, media variants, scheduled jobs and webhook deliveries were never
     * processed in an installed project. Every line is a process now.
     */
    #[Test]
    public function the_worker_starts_every_listed_process_and_reports_anything_else_printed(): void
    {
        [$started, $stderr] = $this->runWorker("printf 'PHP Deprecated: something in /x.php\\n5 queue:work nats async\\n5 mail:work nats mail\\n60 scheduler:plan\\n5 webhook:work\\n5x queue:work\\n'; exit 0", 4);

        self::assertSame(['mail:work nats mail', 'queue:work nats async', 'scheduler:plan', 'webhook:work'], $started);
        self::assertStringContainsString("worker: skipped, not a '<pause> <command>' line: PHP Deprecated: something in /x.php", $stderr);
        self::assertStringContainsString("worker: skipped, not a '<pause> <command>' line: 5x queue:work", $stderr);
    }

    /**
     * A line is split into words before it is checked: without set -f a `*`
     * would become the names of the files around it, and pass the check.
     */
    #[Test]
    public function a_wildcard_in_a_line_is_not_expanded_into_file_names(): void
    {
        [$started, $stderr] = $this->runWorker("printf '5 queue:work *\\n5 webhook:work\\n'; exit 0", 1, before: static function (string $root): void {
            touch($root . '/nats');
            touch($root . '/events');
        });

        self::assertSame(['webhook:work'], $started);
        self::assertStringContainsString("worker: skipped, not a '<pause> <command>' line: 5 queue:work *", $stderr);
    }

    /** A command that finished its work is not news; one that failed is. */
    #[Test]
    public function only_a_failing_exit_is_reported(): void
    {
        [$started, $stderr] = $this->runWorker("printf '0 scheduler:plan\\n1 broken:job\\n'; exit 0", 3);

        self::assertContains('broken:job', $started);
        self::assertGreaterThanOrEqual(2, count(array_keys($started, 'scheduler:plan', true)), 'scheduler:plan exited and was started again');
        self::assertStringContainsString("worker: broken:job exited (3); again in 1s\n", $stderr);
        self::assertStringNotContainsString('scheduler:plan exited', $stderr);
    }

    /** scheduler:plan does its work and exits: it is started again after its pause. */
    #[Test]
    public function a_process_that_exits_is_started_again_after_its_pause(): void
    {
        [$started] = $this->runWorker("printf '0 scheduler:plan\\n'; exit 0", 3);

        self::assertGreaterThanOrEqual(3, count($started));
        self::assertSame(['scheduler:plan'], array_values(array_unique($started)));
    }

    /**
     * A core from before worker:processes (or one that cannot list any)
     * still gets the default queue consumed, instead of a container that
     * exits and restarts forever.
     */
    #[Test]
    public function without_worker_processes_the_worker_consumes_the_default_queue(): void
    {
        [$started, $stderr] = $this->runWorker("echo 'Command \"worker:processes\" is not defined.' >&2; exit 1", 1);

        self::assertSame(['queue:work'], $started);
        self::assertStringContainsString('worker: worker:processes failed; running queue:work on the default queue only', $stderr);
    }

    /**
     * tk-nats-consumer-name-collision: worker:processes refuses two kinds of
     * worker on one queue, and with nothing else installed it lists nothing.
     * The default-queue fallback is for a listing that failed: run here, it
     * put queue:work back on the queue that was refused.
     */
    #[Test]
    public function a_listing_with_nothing_to_run_starts_nothing_and_says_so(): void
    {
        [$started, $stderr, $alive, $aliveBefore] = $this->runWorker("echo 'worker:processes: queue events on nats is read by two kinds of worker' >&2; exit 0", 0, shellOnly: true);

        self::assertSame([], $started);
        self::assertStringContainsString('worker: worker:processes listed nothing to run; idle until server:restart', $stderr);
        // Up, or restart: unless-stopped would run worker:processes again and again.
        self::assertGreaterThan(0, $aliveBefore, 'the worker stays up');
        self::assertSame(0, $alive, 'and goes with the shell on docker stop');
    }

    /**
     * docker stop sends TERM to the container's init, which passes it to the
     * shell alone: the shell has to take every queue:work down with it, or the
     * container waits out the stop timeout and is killed.
     */
    #[Test]
    public function a_term_to_the_shell_alone_stops_every_queue_work(): void
    {
        [$started, , $alive] = $this->runWorker("printf '5 queue:work nats async\\n5 mail:work nats mail\\n'; exit 0", 2, shellOnly: true);

        self::assertCount(2, $started);
        self::assertSame(0, $alive, 'no queue:work outlives the shell');
    }

    /**
     * Runs the worker service's own script with a stand-in php:
     * worker:processes does what $queuesBehaviour says; every other command
     * records its arguments and stays up, as a real worker does, except
     * scheduler:plan, which exits, as the real one does, and broken:job,
     * which fails (exit 3).
     *
     * @return array{list<string>, string, int, int} the queue:work calls (sorted), stderr, processes still alive after the TERM, and before it
     */
    private function runWorker(string $queuesBehaviour, int $expected, bool $shellOnly = false, ?\Closure $before = null): array
    {
        $this->project([]);
        if ($before !== null) {
            $before($this->tmp);
        }
        $bin = $this->tmp . '/bin';
        mkdir($bin);
        file_put_contents($bin . '/php', "#!/bin/sh\nfor a in \"\$@\"; do case \$a in worker:processes) {$queuesBehaviour} ;; esac; done\nwhile [ \"\$1\" != vendor/bin/semitexa ]; do shift; done\nshift\necho \"\$*\" >> \"\$WORK_LOG\"\n[ \"\$1\" = scheduler:plan ] && exit 0\n[ \"\$1\" = broken:job ] && exit 3\nexec sleep 30\n");
        chmod($bin . '/php', 0o755);

        $script = str_replace('$$', '$', self::section($this->service('docker-compose.worker.yml', 'worker'), 'command'));
        self::assertSame(1, preg_match('/      - \|\n(.*)\z/s', $script, $m), 'the worker command is an inline shell script');
        $body = (string) preg_replace('/^        /m', '', $m[1]);

        $log = $this->tmp . '/work.log';
        $pid = (int) shell_exec(sprintf(
            'cd %s && PATH=%s:$PATH WORK_LOG=%s setsid sh -c %s > /dev/null 2> %s & echo $!',
            escapeshellarg($this->tmp),
            escapeshellarg($bin),
            escapeshellarg($log),
            escapeshellarg($body),
            escapeshellarg($this->tmp . '/stderr'),
        ));
        $read = static fn (): array => is_file($log) ? (file($log, FILE_IGNORE_NEW_LINES) ?: []) : [];
        for ($i = 0; $i < 50 && count($read()) < $expected; $i++) {
            usleep(100_000);
        }
        // Long enough for a second, unwanted start to show up too.
        usleep(300_000);
        $aliveBefore = self::processesInGroup($pid);
        exec(($shellOnly ? 'kill -TERM ' : 'kill -TERM -') . $pid . ' 2>/dev/null');
        $alive = -1;
        for ($i = 0; $i < 30; $i++) {
            usleep(100_000);
            $alive = self::processesInGroup($pid);
            if ($alive === 0) {
                break;
            }
        }
        exec('kill -KILL -' . $pid . ' 2>/dev/null');

        $started = $read();
        sort($started);

        return [$started, (string) @file_get_contents($this->tmp . '/stderr'), $alive, $aliveBefore];
    }

    /** Processes whose process group is $group, read from /proc (no ps flags to depend on). */
    private static function processesInGroup(int $group): int
    {
        $count = 0;
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $stat) {
            $line = (string) @file_get_contents($stat);
            $after = substr($line, (int) strrpos($line, ')') + 2);
            $fields = explode(' ', $after);
            // After "pid (comm) ": state, ppid, pgrp.
            if (($fields[2] ?? '') === (string) $group && $fields[0] !== 'Z') {
                $count++;
            }
        }

        return $count;
    }

    /** One top-level service block of a compose file, from its name to the next service. */
    private function service(string $file, string $name): string
    {
        $yaml = (string) file_get_contents($this->root . '/' . $file);
        self::assertSame(1, preg_match('/\n  ' . preg_quote($name, '/') . ":\n(?:(?:    .*|)\n)*/", $yaml, $m), "{$file} defines {$name}");

        return rtrim($m[0] ?? '') . "\n";
    }

    /** One key of a service block with everything indented under it. */
    private static function section(string $service, string $key): string
    {
        self::assertSame(1, preg_match('/^    ' . preg_quote($key, '/') . ":\n(?:      .*\n|\n)*/m", $service, $m), "the service has {$key}");

        return rtrim($m[0] ?? '');
    }

    /** @param list<string> $files A throwaway project root holding these (empty) compose files. */
    private function project(array $files): void
    {
        $this->tmp = sys_get_temp_dir() . '/semitexa-queue-worker-' . uniqid();
        mkdir($this->tmp);
        foreach ($files as $file) {
            touch($this->tmp . '/' . $file);
        }
    }

    /** @param array<string, string> $env */
    private function shell(string $call, array $env): string
    {
        $script = (string) file_get_contents($this->root . '/bin/semitexa');
        $functions = '';
        foreach (['compose_args', 'compose_all_args', 'compose_write_worker_env'] as $function) {
            self::assertSame(1, preg_match('/^' . $function . '\(\) \{\n.*?^\}$/ms', $script, $m), "{$function} must still exist in bin/semitexa");
            $functions .= ($m[0] ?? '') . "\n";
        }
        $values = '';
        foreach ($env as $key => $value) {
            $values .= "    {$key}) printf '%s' " . escapeshellarg($value) . " ;;\n";
        }
        $sh = "env_val() {\n  case \$1 in\n{$values}    *) printf '%s' \"\${2:-}\" ;;\n  esac\n}\n" . $functions . $call;

        return trim((string) shell_exec(sprintf('env -i PATH=/usr/bin:/bin PROJECT_ROOT=%s sh -c %s', escapeshellarg($this->tmp), escapeshellarg($sh))));
    }
}
