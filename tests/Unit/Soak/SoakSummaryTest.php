<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Soak;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tools/soak/summary.php is what a 72-hour run is reported through, so the
 * numbers it prints have to be the ones in the run. Each fixture here carries
 * one thing the soak exists to catch — a worker whose own memory climbs, a
 * worker the server had to replace, a forced termination, a container that
 * restarted — and the summary has to name it rather than read as healthy.
 */
final class SoakSummaryTest extends TestCase
{
    private string $run;

    protected function setUp(): void
    {
        $this->run = sys_get_temp_dir() . '/semitexa-soak-summary-' . uniqid();
        mkdir($this->run, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->run));
    }

    #[Test]
    public function a_climbing_worker_a_replaced_worker_and_a_forced_termination_are_all_named(): void
    {
        $this->write('run.json', '{"started_at":"2026-10-19T00:00:00Z","container":"app","duration_s":7200,"interval_s":3600,"max_request":"100"}');
        $this->write('load.csv', [
            'ts,scenario,count,errors,dropped,p50_ms,p95_ms,p99_ms,max_ms,open',
            '2026-10-19T00:00:00Z,ssr,100,0,0,10,20,30,40,',
            '2026-10-19T01:00:00Z,ssr,100,2,1,10,20,50,90,',
            '2026-10-19T02:00:00Z,ssr,100,0,0,10,20,70,80,',
            '2026-10-19T02:00:00Z,live,4,1,0,300000,300000,300000,300000,3',
        ]);
        // Master 1; worker 7 grows 10 MB of RSS an hour, of which only the
        // second hour is its own (anon); worker 8 is replaced by 9.
        $this->write('workers.csv', [
            'ts,pid,ppid,rss_kb,anon_kb,shmem_kb,elapsed_s,start_ticks',
            '2026-10-19T00:00:00Z,1,0,300000,80000,220000,10,100',
            '2026-10-19T00:00:00Z,7,1,102400,51200,51200,10,200',
            '2026-10-19T00:00:00Z,8,1,100000,50000,50000,10,200',
            '2026-10-19T01:00:00Z,1,0,300000,80000,220000,3610,100',
            '2026-10-19T01:00:00Z,7,1,112640,51200,61440,3610,200',
            '2026-10-19T01:00:00Z,9,1,90000,45000,45000,5,360500',
            '2026-10-19T02:00:00Z,1,0,300000,80000,220000,7210,100',
            '2026-10-19T02:00:00Z,7,1,122880,61440,61440,7210,200',
            '2026-10-19T02:00:00Z,9,1,91000,46000,45000,3605,360500',
        ]);
        // Worker 7's heap, as /metrics reported it: 50, 52, 54 MB.
        $this->write('metrics.csv', [
            'ts,http,worker_pid,connection_num,coroutine_num,worker_memory_usage,request_count',
            '2026-10-19T00:00:00Z,200,7,5,3,52428800,1000',
            '2026-10-19T01:00:00Z,200,7,12,5,54525952,1200',
            '2026-10-19T02:00:00Z,200,7,9,5,56623104,1350',
            '2026-10-19T02:00:00Z,404,,,,,',
        ]);
        $this->write('stack.csv', [
            'ts,cpu_percent,mem_usage,container_started_at,restart_count',
            '2026-10-19T00:00:00Z,5,1GiB,2026-10-18T23:00:00Z,0',
            '2026-10-19T02:00:00Z,5,1GiB,2026-10-18T23:00:00Z,0',
        ]);
        $this->write('log.csv', [
            'ts,file,new_lines,forced_terminations,abnormal_exits,errors,warnings,stdout_fatals',
            '2026-10-19T01:00:00Z,var/log/swoole.log.20261019,4,1,1,1,2,0',
            '2026-10-19T02:00:00Z,var/log/swoole.log.20261019,0,0,0,0,0,1',
        ]);

        $summary = $this->summarise();

        $workers = array_column($summary['workers'], null, 'pid');
        self::assertSame('master', $workers[1]['role']);
        self::assertEquals(10.0, $workers[7]['rss_mb_per_hour'], 'RSS of the climbing worker');
        self::assertEquals(0.0, $workers[1]['rss_mb_per_hour'], 'a flat process');
        // Shared pages touched in the first hour are not the worker's growth: 0, 0, 10 MB of anon.
        self::assertEquals(5.0, $workers[7]['anon_mb_per_hour'], 'the leak signal reads anon, not RSS');
        self::assertEquals(60.0, $workers[7]['anon_last_mb']);
        self::assertEquals(2.0, $workers[7]['heap_mb_per_hour'], 'the heap /metrics reported for that pid');
        self::assertSame(3, $workers[7]['heap_samples']);
        self::assertArrayHasKey(8, $workers, 'worker 8 is in the summary');
        self::assertArrayHasKey('heap_mb_per_hour', $workers[8]);
        self::assertNull($workers[8]['heap_mb_per_hour'], 'no /metrics answer from this pid: unmeasured, not flat');

        self::assertSame(1, $summary['stack']['worker_replacements'], 'worker 8 was replaced by 9');
        self::assertSame(350, $summary['stack']['requests_served']);
        self::assertSame(3, $summary['stack']['recycles_expected_from_max_request']);
        self::assertSame(0, $summary['stack']['container_restarts']);
        self::assertSame('3 of ~3', $summary['stack']['samples']);
        self::assertSame(1, $summary['stack']['forced_terminations']);
        self::assertSame(1, $summary['stack']['abnormal_exits']);
        self::assertSame(1, $summary['stack']['log_errors']);
        self::assertSame(1, $summary['stack']['container_output_fatals']);
        self::assertSame('var/log/swoole.log.20261019', $summary['stack']['log_files']);
        self::assertSame(12, $summary['stack']['connection_num_max'], 'only answered /metrics samples count');
        self::assertSame('3 of 4', $summary['stack']['metrics_samples']);

        $ssr = $summary['scenarios']['ssr'];
        self::assertSame(300, $ssr['requests']);
        self::assertSame(2, $ssr['errors']);
        self::assertSame(1, $ssr['dropped']);
        self::assertEquals(50.0, $ssr['p99_ms'], 'the median window p99');
        self::assertEquals(90.0, $ssr['worst_window_max_ms']);
        self::assertArrayNotHasKey('live', $summary['scenarios'], 'a held stream is not a latency');
        self::assertEquals(300.0, $summary['live']['held_median_s']);
        self::assertSame(1, $summary['live']['errors']);
        self::assertSame(3, $summary['live']['open_min']);
        self::assertEquals(2.0, $summary['hours']);
    }

    /**
     * A restarted container begins its pids at 1 again. Tracked by pid alone,
     * the old and the new master merge into one flat line and nothing was
     * replaced; a log nobody found reads as zero incidents.
     */
    #[Test]
    public function a_restart_that_reuses_pids_and_a_log_that_was_never_found_do_not_read_as_healthy(): void
    {
        $this->write('run.json', '{"duration_s":3600,"interval_s":1800}');
        $this->write('load.csv', [
            'ts,scenario,count,errors,dropped,p50_ms,p95_ms,p99_ms,max_ms,open',
            '2026-10-19T00:00:00Z,ssr,10,0,0,10,20,30,40,',
            '2026-10-19T01:00:00Z,ssr,10,10,0,,,,,',
        ]);
        $this->write('workers.csv', [
            'ts,pid,ppid,rss_kb,elapsed_s,start_ticks',
            '2026-10-19T00:00:00Z,1,0,300000,10,100',
            '2026-10-19T00:30:00Z,1,0,300000,1810,100',
            '2026-10-19T01:00:00Z,1,0,250000,5,900000',
        ]);
        $this->write('stack.csv', [
            'ts,cpu_percent,mem_usage,container_started_at,restart_count',
            '2026-10-19T00:00:00Z,5,1GiB,2026-10-18T23:00:00Z,0',
            '2026-10-19T01:00:00Z,5,1GiB,2026-10-19T00:59:55Z,1',
        ]);
        $this->write('log.csv', [
            'ts,file,new_lines,forced_terminations,abnormal_exits,errors,warnings,stdout_fatals',
            '2026-10-19T00:00:00Z,unresolved,0,0,0,0,0,0',
        ]);

        $summary = $this->summarise();

        self::assertCount(2, $summary['workers'], 'the same pid after a restart is another process');
        self::assertSame(1, $summary['stack']['worker_replacements']);
        self::assertSame(1, $summary['stack']['container_restarts']);
        self::assertArrayHasKey('anon_mb_per_hour', $summary['workers'][0]);
        self::assertNull($summary['workers'][0]['anon_mb_per_hour'], 'no anon column: unmeasured, not flat');
        self::assertArrayHasKey('forced_terminations', $summary['stack']);
        self::assertNull($summary['stack']['forced_terminations'], 'no log found: unmeasured, not zero');
        self::assertStringStartsWith('unmeasured', $summary['stack']['log_files']);
        self::assertSame('3 of ~3', $summary['stack']['samples']);
    }

    /**
     * Warm-up is not growth: a worker that climbs for its first hour and is
     * flat after it must read flat where a report looks, and one that keeps
     * climbing must not.
     */
    #[Test]
    public function the_steady_rate_leaves_the_first_hour_out(): void
    {
        $this->write('run.json', '{"duration_s":10800,"interval_s":1800}');
        $this->write('load.csv', ['ts,scenario,count,errors,dropped,p50_ms,p95_ms,p99_ms,max_ms,open']);
        $rows = ['ts,pid,ppid,rss_kb,anon_kb,shmem_kb,elapsed_s,start_ticks'];
        // Worker 7: 50 -> 80 MB in its first hour, then flat. Worker 8: +10 MB every hour.
        foreach ([[0, 51200, 51200], [1800, 66560, 56320], [3600, 81920, 61440], [5400, 81920, 66560], [7200, 81920, 71680], [9000, 81920, 76800], [10800, 81920, 81920]] as [$at, $seven, $eight]) {
            $ts = gmdate('Y-m-d\TH:i:s\Z', 1792368000 + $at);
            $rows[] = "$ts,7,1,$seven,$seven,0,$at,200";
            $rows[] = "$ts,8,1,$eight,$eight,0,$at,200";
        }
        $this->write('workers.csv', $rows);

        $workers = array_column($this->summarise()['workers'], null, 'pid');

        self::assertGreaterThan(5.0, $workers[7]['anon_mb_per_hour'], 'over the whole run the warm-up shows');
        self::assertEquals(0.0, $workers[7]['anon_mb_per_hour_after_first_hour'], 'after it, flat');
        self::assertEquals(10.0, $workers[8]['anon_mb_per_hour_after_first_hour'], 'a steady climb stays a climb');
    }

    /** @param string|list<string> $content */
    private function write(string $file, string|array $content): void
    {
        file_put_contents($this->run . '/' . $file, is_array($content) ? implode("\n", $content) . "\n" : $content);
    }

    /** @return array<string, mixed> */
    private function summarise(): array
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/tools/soak/summary.php')
            . ' ' . escapeshellarg($this->run) . ' --json 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));

        return json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
    }
}
