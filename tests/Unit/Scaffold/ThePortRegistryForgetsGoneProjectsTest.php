<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tk-port-registry-keeps-dead-projects (reported by the Guard project's review
 * of an agent deploying from a fresh clone): ~/.semitexa/router/registry/apps
 * kept an entry, and its port, for every project ever started. On one
 * workstation 9 of 20 entries named a directory that was gone, and the 99-port
 * range filled without a word.
 *
 * router_app_prune_gone_projects() runs as bin/semitexa has it, on a registry
 * in a temporary directory. local_app_reconcile() runs with the allocation
 * stubbed, as in ServerPortStaysOnAServerTest.
 */
final class ThePortRegistryForgetsGoneProjectsTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/port-registry-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/apps', 0o777, true);
        mkdir($this->tmp . '/live');
        mkdir($this->tmp . '/live with a space');
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    #[Test]
    public function an_entry_whose_directory_is_gone_is_dropped_and_named(): void
    {
        $this->entry('a-live', $this->tmp . '/live', 'live');
        $this->entry('b-gone', $this->tmp . '/gone', 'gone-app');
        $this->entry('c-gone-unnamed', $this->tmp . '/scratch/fresh-install', '');
        $this->entry('d-live-space', $this->tmp . '/live with a space', 'live with a space');
        $this->entry('e-gone-space', $this->tmp . '/gone with a space', 'gone with a space');

        [$out] = $this->runShell('router_app_prune_gone_projects');

        self::assertSame(['gone-app', 'fresh-install', 'gone with a space'], $out, 'each dropped project, by name or else by its directory');
        self::assertSame(['a-live.env', 'd-live-space.env'], $this->entries(), 'a project that is there keeps its port');
    }

    #[Test]
    public function an_entry_it_cannot_judge_is_kept(): void
    {
        $this->entry('a-no-root', '', 'no-root');
        $this->entry('b-relative', 'projects/app', 'relative');

        [$out] = $this->runShell('router_app_prune_gone_projects');

        self::assertSame([], $out);
        self::assertSame(['a-no-root.env', 'b-relative.env'], $this->entries());
    }

    #[Test]
    public function a_registry_that_was_never_made_is_left_alone(): void
    {
        rmdir($this->tmp . '/apps');

        [$out, $exit] = $this->runShell('router_app_prune_gone_projects');

        self::assertSame([[], 0], [$out, $exit]);
        self::assertDirectoryDoesNotExist($this->tmp . '/apps');
    }

    #[Test]
    public function a_start_says_in_one_line_what_it_freed(): void
    {
        $this->entry('a-gone', $this->tmp . '/gone', 'gone-app');

        self::assertSame(['INFO Freed the port of a project that is gone: gone-app.'], $this->reconcile());
    }

    #[Test]
    public function many_are_counted_and_five_are_named(): void
    {
        foreach (range(1, 7) as $n) {
            $this->entry('gone-' . $n, $this->tmp . '/gone-' . $n, 'app-' . $n);
        }

        self::assertSame(['INFO Freed the ports of 7 projects that are gone: app-1, app-2, app-3, app-4, app-5 and 2 more.'], $this->reconcile());
        self::assertSame([], $this->entries());
    }

    #[Test]
    public function a_start_with_nothing_to_free_says_nothing_about_it(): void
    {
        $this->entry('a-live', $this->tmp . '/live', 'live');

        self::assertSame([], $this->reconcile());
        self::assertSame(['a-live.env'], $this->entries());
    }

    /**
     * Review, installer#110: the lock gives up after about 5 s and returned as
     * if taken, so a start removed entries while another registration was
     * writing one, and then freed that registration's lock.
     */
    #[Test]
    public function a_start_that_cannot_take_the_lock_removes_nothing_and_leaves_the_lock(): void
    {
        $this->entry('a-gone', $this->tmp . '/gone', 'gone-app');
        mkdir($this->tmp . '/locks/registry.lock', 0o777, true);

        self::assertSame([], $this->reconcile(realLock: true), 'nothing is said to be freed');
        self::assertSame(['a-gone.env'], $this->entries(), 'the pruning waits for the next start');
        self::assertDirectoryExists($this->tmp . '/locks/registry.lock', "the other registration's lock is left to it");
    }

    #[Test]
    public function a_lock_left_by_a_run_that_died_is_taken_over(): void
    {
        $this->entry('a-gone', $this->tmp . '/gone', 'gone-app');
        mkdir($this->tmp . '/locks/registry.lock', 0o777, true);
        touch($this->tmp . '/locks/registry.lock', time() - 300);

        self::assertSame(['INFO Freed the port of a project that is gone: gone-app.'], $this->reconcile(realLock: true));
        self::assertDirectoryDoesNotExist($this->tmp . '/locks/registry.lock', 'taken, used and freed');
    }

    /** @return list<string> what local_app_reconcile printed, the allocation's own lines left out */
    private function reconcile(bool $realLock = false): array
    {
        touch($this->tmp . '/live/.env');
        $lock = $realLock
            ? 'router_ensure_app_dirs() { mkdir -p "$ROUTER_APP_REGISTRY_DIR" "$ROUTER_LOCK_DIR"; }; ROUTER_LOCK_DIR=' . escapeshellarg($this->tmp . '/locks') . "\n"
                . $this->function('router_lock_acquire') . "\n" . $this->function('router_lock_release') . "\n"
            // As if taken: the lock's own cases run it as bin/semitexa has it.
            : 'router_lock_acquire() { ROUTER_LOCK_HELD=1; }; router_lock_release() { ROUTER_LOCK_HELD=; }' . "\n";
        $stubs = 'env_val() { case "$1" in SWOOLE_PORT) printf 9501 ;; SEMITEXA_APP_ID) printf app-123456 ;; *) printf "%s" "$2" ;; esac; }' . "\n"
            . 'local_app_register_logic() { printf 9501; }' . "\n" . $lock
            . 'info() { printf "INFO %s\n" "$*"; }; warn() { printf "WARN %s\n" "$*"; }' . "\n"
            . 'PROJECT_ROOT=' . escapeshellarg($this->tmp . '/live') . "\n"
            . $this->function('router_app_prune_gone_projects') . "\n";
        [$out, $exit] = $this->runShell('local_app_reconcile', $stubs);
        self::assertSame(0, $exit);

        return $out;
    }

    /**
     * @return array{list<string>, int} the lines printed and the exit code
     */
    private function runShell(string $function, string $stubs = ''): array
    {
        $sh = 'ROUTER_APP_REGISTRY_DIR=' . escapeshellarg($this->tmp . '/apps') . "\n" . $stubs
            . $this->function($function) . "\n" . $function . "; printf 'EXIT %s\\n' \"\$?\"";
        $raw = (string) shell_exec(sprintf('env -i PATH=/usr/bin:/bin sh -c %s 2>&1', escapeshellarg($sh)));
        $lines = array_values(array_filter(explode("\n", $raw), static fn (string $line): bool => $line !== ''));
        $exit = (string) array_pop($lines);
        self::assertMatchesRegularExpression('/^EXIT \d+$/', $exit, $raw);

        return [$lines, (int) substr($exit, 5)];
    }

    private function entry(string $id, string $root, string $name): void
    {
        file_put_contents($this->tmp . '/apps/' . $id . '.env', "APP_ID={$id}\nPROJECT_ROOT={$root}\nPROJECT_NAME={$name}\nSWOOLE_PORT=9555\n");
    }

    /** @return list<string> */
    private function entries(): array
    {
        $names = array_map('basename', glob($this->tmp . '/apps/*.env') ?: []);
        sort($names);

        return $names;
    }

    private function function(string $name): string
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
        self::assertSame(1, preg_match('/^' . $name . '\(\) \{\n.*?^\}$/ms', $script, $match), $name . ' must still exist in bin/semitexa');

        return $match[0] ?? '';
    }
}
