<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tk-reinstalled-project-keeps-its-old-ports: each bin/semitexa install wrote
 * a new SEMITEXA_APP_ID into .env, and nothing dropped the entry of the id
 * before. One directory, installed three times, held three ports in
 * ~/.semitexa/router/registry/apps. A root has one .env, so it has one app id.
 *
 * local_app_drop_earlier_ids() runs as bin/semitexa has it, under set -e, on a
 * registry and a lock in a temporary directory. local_app_reconcile() and
 * cmd_local_app_register() run with the allocation stubbed, as in
 * ThePortRegistryForgetsGoneProjectsTest.
 */
final class AReinstalledProjectKeepsOnePortTest extends TestCase
{
    private const CURRENT = 'cccccccc-0000-4000-8000-000000000003';

    private string $tmp = '';

    private string $root = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/reinstalled-' . bin2hex(random_bytes(6));
        // With a space: an entry is read as data, so the root is one value.
        $this->root = $this->tmp . '/dev/my shop';
        mkdir($this->tmp . '/apps', 0o777, true);
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    #[Test]
    public function the_ids_of_earlier_installs_are_dropped_and_the_one_in_env_is_kept(): void
    {
        $this->entry('aaaaaaaa-0000-4000-8000-000000000001', $this->root, 9504);
        $this->entry('bbbbbbbb-0000-4000-8000-000000000002', $this->root, 9505);
        $this->entry(self::CURRENT, $this->root, 9509);

        self::assertSame([
            'INFO Dropped an earlier registration of this project: app id aaaaaaaa-0000-4000-8000-000000000001, port 9504.',
            'INFO Dropped an earlier registration of this project: app id bbbbbbbb-0000-4000-8000-000000000002, port 9505.',
            'went on',
            'exit=0',
            'free',
        ], $this->drop(), 'one line for each, and the lock back');
        self::assertSame([self::CURRENT . '.env'], $this->entries());
        self::assertStringContainsString('SWOOLE_PORT=9509', (string) file_get_contents($this->tmp . '/apps/' . self::CURRENT . '.env'), 'the entry in .env keeps its port');
    }

    #[Test]
    public function an_earlier_install_is_dropped_before_this_one_registered(): void
    {
        $this->entry('aaaaaaaa-0000-4000-8000-000000000001', $this->root, 9504);

        self::assertSame([
            'INFO Dropped an earlier registration of this project: app id aaaaaaaa-0000-4000-8000-000000000001, port 9504.',
            'went on',
            'exit=0',
            'free',
        ], $this->drop());
        self::assertSame([], $this->entries());
    }

    /** Roots are compared exactly: a root that begins with this one, or that this one begins with, is another project. */
    #[Test]
    public function another_projects_entry_is_kept_whatever_its_root_shares_with_this_one(): void
    {
        $this->root = '/home/dev/shop';
        $this->entry('aaaaaaaa-0000-4000-8000-000000000001', '/home/dev/shop', 9504);
        $this->entry('dddddddd-0000-4000-8000-000000000004', '/home/dev/shop-2', 9510);
        $this->entry('eeeeeeee-0000-4000-8000-000000000005', '/home/dev/shop/', 9511);
        $this->entry('ffffffff-0000-4000-8000-000000000006', '/home/dev/shop/nested', 9512);
        $this->entry('11111111-0000-4000-8000-000000000007', '/home/dev', 9513);
        $this->entry('22222222-0000-4000-8000-000000000008', '/home/other/shop', 9514);
        $this->entry('33333333-0000-4000-8000-000000000009', '', 9515);
        $this->entry(self::CURRENT, '/home/dev/shop', 9509);

        self::assertSame([
            'INFO Dropped an earlier registration of this project: app id aaaaaaaa-0000-4000-8000-000000000001, port 9504.',
            'went on',
            'exit=0',
            'free',
        ], $this->drop(), 'only the entry of this very root');
        self::assertSame([
            '11111111-0000-4000-8000-000000000007.env',
            '22222222-0000-4000-8000-000000000008.env',
            '33333333-0000-4000-8000-000000000009.env',
            self::CURRENT . '.env',
            'dddddddd-0000-4000-8000-000000000004.env',
            'eeeeeeee-0000-4000-8000-000000000005.env',
            'ffffffff-0000-4000-8000-000000000006.env',
        ], $this->entries());
    }

    /** And the other way round: this root is the longer one. */
    #[Test]
    public function a_project_whose_root_this_one_begins_with_is_kept(): void
    {
        $this->root = '/home/dev/shop-2';
        $this->entry('aaaaaaaa-0000-4000-8000-000000000001', '/home/dev/shop', 9504);
        $this->entry('bbbbbbbb-0000-4000-8000-000000000002', '/home/dev/shop-2', 9505);
        $this->entry(self::CURRENT, '/home/dev/shop-2', 9509);

        self::assertSame([
            'INFO Dropped an earlier registration of this project: app id bbbbbbbb-0000-4000-8000-000000000002, port 9505.',
            'went on',
            'exit=0',
            'free',
        ], $this->drop());
        self::assertSame(['aaaaaaaa-0000-4000-8000-000000000001.env', self::CURRENT . '.env'], $this->entries());
    }

    /**
     * An entry that cannot be read says nothing about whose it is. Here it is
     * a link to a file that is gone, which rm would have removed: it needs no
     * permissions, so it holds for root too.
     */
    #[Test]
    public function an_entry_that_cannot_be_read_is_left_alone(): void
    {
        $unreadable = $this->tmp . '/apps/00000000-0000-4000-8000-00000000000a.env';
        symlink($this->tmp . '/apps/no-such-file', $unreadable);
        $this->entry('aaaaaaaa-0000-4000-8000-000000000001', $this->root, 9504);
        $this->entry(self::CURRENT, $this->root, 9509);

        self::assertSame([
            'INFO Dropped an earlier registration of this project: app id aaaaaaaa-0000-4000-8000-000000000001, port 9504.',
            'went on',
            'exit=0',
            'free',
        ], $this->drop());
        self::assertTrue(is_link($unreadable), 'the entry it could not read is still there');
        self::assertFileExists($this->tmp . '/apps/' . self::CURRENT . '.env');
        self::assertFileDoesNotExist($this->tmp . '/apps/aaaaaaaa-0000-4000-8000-000000000001.env');
    }

    /** Only an absolute root is judged: a hand-typed --project-root=. names no one project, and could match another project's relative entry. */
    #[Test]
    public function a_relative_root_drops_nothing(): void
    {
        $this->entry('aaaaaaaa-0000-4000-8000-000000000001', '.', 9504);
        $this->entry('bbbbbbbb-0000-4000-8000-000000000002', 'projects/shop', 9505);
        $this->entry('dddddddd-0000-4000-8000-000000000004', '', 9510);
        $kept = ['aaaaaaaa-0000-4000-8000-000000000001.env', 'bbbbbbbb-0000-4000-8000-000000000002.env', 'dddddddd-0000-4000-8000-000000000004.env'];

        foreach (['.', 'projects/shop', ''] as $relative) {
            $this->root = $relative;
            self::assertSame(['went on', 'exit=0', 'free'], $this->drop(), 'nothing said for the root "' . $relative . '"');
            self::assertSame($kept, $this->entries());
        }

        // The same entries under an absolute root: the one that names it goes, so it was the root's form that kept them.
        $this->root = '/home/dev/shop';
        $this->entry('eeeeeeee-0000-4000-8000-000000000005', '/home/dev/shop', 9511);
        self::assertSame([
            'INFO Dropped an earlier registration of this project: app id eeeeeeee-0000-4000-8000-000000000005, port 9511.',
            'went on',
            'exit=0',
            'free',
        ], $this->drop());
        self::assertSame($kept, $this->entries());
    }

    /** Each entry is judged by its own lines: one with no PROJECT_ROOT line, read after an entry of this root, does not inherit that root. */
    #[Test]
    public function an_entry_with_no_root_line_read_after_a_matching_one_is_kept(): void
    {
        $this->entry('aaaaaaaa-0000-4000-8000-000000000001', $this->root, 9504);
        file_put_contents($this->tmp . '/apps/zzzzzzzz-0000-4000-8000-00000000000f.env', "APP_ID=zzzzzzzz-0000-4000-8000-00000000000f\nSWOOLE_PORT=9520\n");
        $this->entry(self::CURRENT, $this->root, 9509);

        self::assertSame([
            'INFO Dropped an earlier registration of this project: app id aaaaaaaa-0000-4000-8000-000000000001, port 9504.',
            'went on',
            'exit=0',
            'free',
        ], $this->drop());
        self::assertSame([self::CURRENT . '.env', 'zzzzzzzz-0000-4000-8000-00000000000f.env'], $this->entries());
    }

    /** As the pruning of gone projects: the lock not taken, nothing is removed and the lock is left to its holder. */
    #[Test]
    public function without_the_lock_nothing_is_removed_and_the_lock_is_left(): void
    {
        $this->entry('aaaaaaaa-0000-4000-8000-000000000001', $this->root, 9504);
        $this->entry(self::CURRENT, $this->root, 9509);

        mkdir($this->tmp . '/locks/registry.lock', 0o777, true);
        self::assertSame(['went on', 'exit=0', 'held'], $this->drop(), 'nothing is said to be dropped');
        self::assertSame(['aaaaaaaa-0000-4000-8000-000000000001.env', self::CURRENT . '.env'], $this->entries(), 'the entry waits for the next start');

        // The same registry with the lock free: the entry goes, so it was the lock that kept it.
        rmdir($this->tmp . '/locks/registry.lock');
        self::assertSame([
            'INFO Dropped an earlier registration of this project: app id aaaaaaaa-0000-4000-8000-000000000001, port 9504.',
            'went on',
            'exit=0',
            'free',
        ], $this->drop());
        self::assertSame([self::CURRENT . '.env'], $this->entries());
    }

    #[Test]
    public function with_nothing_to_drop_it_says_nothing_and_gives_the_lock_back(): void
    {
        $this->entry('dddddddd-0000-4000-8000-000000000004', $this->root . '-2', 9510);
        $this->entry(self::CURRENT, $this->root, 9509);

        // "taken": the lock was this run's while it looked, and is free after it.
        self::assertSame(['taken', 'went on', 'exit=0', 'free'], $this->drop('registry_entry_load() { [ -d "$ROUTER_LOCK_DIR/registry.lock" ] && [ "${LOADS:-0}" = 0 ] && echo taken; LOADS=1; real_registry_entry_load "$@"; }'));
        self::assertSame([self::CURRENT . '.env', 'dddddddd-0000-4000-8000-000000000004.env'], $this->entries());
    }

    /** Under set -e, as bin/semitexa runs: a removal that failed is said, and the start goes on with the lock back. */
    #[Test]
    public function a_removal_that_failed_is_said_and_stops_nothing(): void
    {
        $this->entry('aaaaaaaa-0000-4000-8000-000000000001', $this->root, 9504);
        $this->entry('bbbbbbbb-0000-4000-8000-000000000002', $this->root, 9505);
        $this->entry(self::CURRENT, $this->root, 9509);

        // Refused for the first entry only; the lock's own removal goes through.
        $out = $this->drop('rm() { case "$2" in *aaaaaaaa*.env) return 1 ;; esac; command rm "$@"; }');

        self::assertSame([
            'WARN Could not drop an earlier registration of this project (app id aaaaaaaa-0000-4000-8000-000000000001): remove ' . $this->tmp . '/apps/aaaaaaaa-0000-4000-8000-000000000001.env by hand.',
            'INFO Dropped an earlier registration of this project: app id bbbbbbbb-0000-4000-8000-000000000002, port 9505.',
            'went on',
            'exit=0',
            'free',
        ], $out, 'the next entry is still dropped');
        self::assertSame(['aaaaaaaa-0000-4000-8000-000000000001.env', self::CURRENT . '.env'], $this->entries());
    }

    /** An id the registry would refuse names no entry of its own: nothing is judged against it. */
    #[Test]
    public function an_id_that_is_not_one_drops_nothing(): void
    {
        $this->entry('aaaaaaaa-0000-4000-8000-000000000001', $this->root, 9504);

        self::assertSame(['went on', 'exit=0', 'free'], $this->drop(id: 'Not An Id'));
        self::assertSame(['aaaaaaaa-0000-4000-8000-000000000001.env'], $this->entries());

        self::assertSame([
            'INFO Dropped an earlier registration of this project: app id aaaaaaaa-0000-4000-8000-000000000001, port 9504.',
            'went on',
            'exit=0',
            'free',
        ], $this->drop(), 'the same registry, with the id .env holds');
    }

    /** A start drops them before it registers, so a port an earlier install held is free for this one. */
    #[Test]
    public function a_start_drops_them_before_it_registers(): void
    {
        $this->entry('aaaaaaaa-0000-4000-8000-000000000001', $this->root, 9504);
        $this->entry(self::CURRENT, $this->root, 9509);
        touch($this->root . '/.env');

        $out = $this->shell(
            ['local_app_drop_earlier_ids', 'local_app_reconcile'],
            'env_val() { case "$1" in SWOOLE_PORT) printf 9509 ;; SEMITEXA_APP_ID) printf ' . self::CURRENT . ' ;; *) printf "%s" "$2" ;; esac; }' . "\n"
            . 'router_app_prune_gone_projects() { :; }' . "\n"
            . 'local_app_register_logic() { echo "REGISTER $1 with $(ls "$ROUTER_APP_REGISTRY_DIR" | tr "\n" " ")" >&2; printf 9509; }' . "\n"
            . '( set -e; local_app_reconcile; echo "went on" ); printf "exit=%s\n" "$?"',
        );

        self::assertSame([
            'INFO Dropped an earlier registration of this project: app id aaaaaaaa-0000-4000-8000-000000000001, port 9504.',
            'REGISTER ' . self::CURRENT . ' with ' . self::CURRENT . '.env ',
            'went on',
            'exit=0',
        ], $out);
    }

    /** local-app:register --write-env makes its id the one in .env, so the ids before go then. Without it, .env may hold another id: nothing is dropped. */
    #[Test]
    public function registering_drops_them_only_once_its_id_is_the_one_in_env(): void
    {
        $this->entry('aaaaaaaa-0000-4000-8000-000000000001', $this->root, 9504);
        $stubs = 'env_upsert_kv() { printf "UPSERT %s=%s\n" "$2" "$3"; }; success() { printf "OK %s\n" "$*"; }' . "\n"
            // The allocation reports the entries it sees: the earlier one must be gone by then (review, installer#115).
            . 'local_app_register_logic() { printf "allocating beside %s entries\n" "$(ls "$ROUTER_APP_REGISTRY_DIR" | wc -l | tr -d " ")" >&2; printf 9509; }' . "\n";
        $run = static fn (string $flag): string => '( set -e; cmd_local_app_register --app-id=' . self::CURRENT . ' --project-root="$PROJECT_ROOT" ' . $flag . '; echo "went on" ); printf "exit=%s\n" "$?"';

        self::assertSame(['allocating beside 1 entries', '9509', 'went on', 'exit=0'], $this->shell(['local_app_drop_earlier_ids', 'cmd_local_app_register'], $stubs . $run('')));
        self::assertSame(['aaaaaaaa-0000-4000-8000-000000000001.env'], $this->entries());

        self::assertSame([
            'INFO Dropped an earlier registration of this project: app id aaaaaaaa-0000-4000-8000-000000000001, port 9504.',
            'allocating beside 0 entries',
            'UPSERT SEMITEXA_APP_ID=' . self::CURRENT,
            'UPSERT SWOOLE_PORT=9509',
            'OK Wrote SEMITEXA_APP_ID=' . self::CURRENT . ' and SWOOLE_PORT=9509 to ' . $this->root . '/.env',
            '9509',
            'went on',
            'exit=0',
        ], $this->shell(['local_app_drop_earlier_ids', 'cmd_local_app_register'], $stubs . $run('--write-env')));
        self::assertSame([], $this->entries());
    }

    /**
     * local_app_drop_earlier_ids for this root, as a start calls it: under
     * set -e, then whether the lock is free.
     *
     * @return list<string>
     */
    private function drop(string $stubs = '', string $id = self::CURRENT): array
    {
        return $this->shell(
            ['local_app_drop_earlier_ids'],
            ($stubs === '' ? '' : $stubs . "\n")
            . '( set -e; local_app_drop_earlier_ids ' . escapeshellarg($id) . ' "$PROJECT_ROOT"; echo "went on" ); printf "exit=%s\n" "$?"' . "\n"
            . '[ -d "$ROUTER_LOCK_DIR/registry.lock" ] && echo held || echo free',
        );
    }

    /**
     * @param list<string> $functions those under test; what they read the registry and take the lock with comes as bin/semitexa has it
     *
     * @return list<string> the lines printed
     */
    private function shell(array $functions, string $snippet): array
    {
        $sh = 'ROUTER_APP_REGISTRY_DIR=' . escapeshellarg($this->tmp . '/apps') . "\n"
            . 'ROUTER_LOCK_DIR=' . escapeshellarg($this->tmp . '/locks') . "\n"
            . 'PROJECT_ROOT=' . escapeshellarg($this->root) . "\n"
            . 'router_ensure_app_dirs() { mkdir -p "$ROUTER_APP_REGISTRY_DIR" "$ROUTER_LOCK_DIR"; }' . "\n"
            . 'info() { printf "INFO %s\n" "$*"; }; warn() { printf "WARN %s\n" "$*"; }; error() { printf "ERROR %s\n" "$*"; }' . "\n"
            . str_replace('registry_entry_load() {', 'real_registry_entry_load() {', $this->function('registry_entry_load')) . "\n"
            . 'registry_entry_load() { real_registry_entry_load "$@"; }' . "\n"
            . implode("\n", array_map($this->function(...), ['router_app_entry_path', 'app_validate_app_id', 'router_lock_acquire', 'router_lock_release', ...$functions])) . "\n"
            . $snippet;
        $raw = (string) shell_exec(sprintf('env -i PATH=/usr/bin:/bin sh -c %s 2>&1', escapeshellarg($sh)));

        return array_values(array_filter(explode("\n", $raw), static fn (string $line): bool => $line !== ''));
    }

    private function entry(string $id, string $root, int $port): void
    {
        file_put_contents($this->tmp . '/apps/' . $id . '.env', "APP_ID={$id}\nPROJECT_ROOT={$root}\nPROJECT_NAME=shop\nSWOOLE_PORT={$port}\n");
    }

    /** @return list<string> the entries that are files, by name */
    private function entries(): array
    {
        $names = array_map('basename', array_filter(glob($this->tmp . '/apps/*.env') ?: [], is_file(...)));
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
