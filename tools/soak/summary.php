<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Turns a soak run (tools/soak/soak.sh) into the numbers a report quotes.
//
//   php tools/soak/summary.php var/soak/<run> [--json]
//
// Plain PHP, no framework: it reads a directory of CSVs on the host.
// Percentiles come per load window, so a run-wide p99 here is the median of
// the windows' p99s (steady state) next to the worst window (the spike);
// "first" and "last" compare the first and last hour, or thirds of a shorter run.
//
// It reports, it does not judge — except where a number would read as good
// while nothing was measured: a log that was never found says "unmeasured",
// not 0, and a missing column is null, not a flat line.
// ─────────────────────────────────────────────────────────────────────────────

$dir = $argv[1] ?? '';
$asJson = in_array('--json', $argv, true);
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: php summary.php <run dir> [--json]\n");
    exit(2);
}

/** @return list<array<string, string>> */
function readCsv(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $handle = fopen($path, 'rb');
    $header = fgetcsv($handle, escape: '');
    $rows = [];
    while (($row = fgetcsv($handle, escape: '')) !== false) {
        if ($header !== false && count($row) === count($header)) {
            $rows[] = array_combine($header, $row);
        }
    }
    fclose($handle);

    return $rows;
}

function median(array $values): ?float
{
    $values = array_values(array_filter($values, static fn ($v) => $v !== '' && $v !== null));
    if ($values === []) {
        return null;
    }
    sort($values);
    $n = count($values);

    return $n % 2 === 1 ? (float) $values[intdiv($n, 2)] : ((float) $values[$n / 2 - 1] + (float) $values[$n / 2]) / 2;
}

/** Least-squares slope of y over x; null below three points. */
function slope(array $xs, array $ys): ?float
{
    $n = count($xs);
    if ($n < 3 || $n !== count($ys)) {
        return null;
    }
    $mx = array_sum($xs) / $n;
    $my = array_sum($ys) / $n;
    $num = 0.0;
    $den = 0.0;
    foreach ($xs as $i => $x) {
        $num += ($x - $mx) * ($ys[$i] - $my);
        $den += ($x - $mx) ** 2;
    }

    return $den > 0 ? $num / $den : null;
}

/**
 * @param list<int> $times
 * @param list<?float> $values
 * @return ?float per hour, or null when a value is missing or there are too few
 */
function perHour(array $times, array $values): ?float
{
    if (in_array(null, $values, true) || $times === []) {
        return null;
    }
    $rate = slope(array_map(static fn ($t) => ($t - $times[0]) / 3600, $times), $values);

    return $rate === null ? null : round($rate, 3);
}

/**
 * The same rate without the first hour a process lived: a worker fills its
 * caches and compiles its templates after it starts, and over a whole run that
 * warm-up would read as growth. This is the number a report quotes.
 *
 * @param list<int> $times
 * @param list<?float> $values
 */
function perHourSteady(array $times, array $values): ?float
{
    $from = $times === [] ? 0 : $times[0] + 3600;
    $keep = array_keys(array_filter($times, static fn ($t) => $t >= $from));

    return perHour(array_values(array_intersect_key($times, array_flip($keep))), array_values(array_intersect_key($values, array_flip($keep))));
}

$run = json_decode((string) @file_get_contents("$dir/run.json"), true) ?: [];
$load = readCsv("$dir/load.csv");
$workers = readCsv("$dir/workers.csv");
$metrics = readCsv("$dir/metrics.csv");
$stack = readCsv("$dir/stack.csv");
$log = readCsv("$dir/log.csv");

$times = array_map(static fn ($r) => strtotime($r['ts']), $load);
$start = $times === [] ? 0 : min($times);
$end = $times === [] ? 0 : max($times);
$hours = max(0.0, ($end - $start) / 3600);
$edge = $hours >= 3 ? 3600 : max(1, intdiv($end - $start, 3));

$summary = ['run' => $run, 'hours' => round($hours, 2), 'scenarios' => [], 'live' => null, 'workers' => [], 'stack' => []];

$scenarios = [];
foreach ($load as $row) {
    $s = &$scenarios[$row['scenario']];
    $s['count'] = ($s['count'] ?? 0) + (int) $row['count'];
    $s['errors'] = ($s['errors'] ?? 0) + (int) $row['errors'];
    $s['dropped'] = ($s['dropped'] ?? 0) + (int) $row['dropped'];
    $t = strtotime($row['ts']);
    $s['p50'][] = $row['p50_ms'];
    $s['p99'][] = $row['p99_ms'];
    $s['max'] = max($s['max'] ?? 0.0, (float) ($row['max_ms'] ?: 0));
    $s['open'][] = $row['open'] ?? '';
    if ($t - $start <= $edge) {
        $s['p99_first'][] = $row['p99_ms'];
    }
    if ($end - $t < $edge) {
        $s['p99_last'][] = $row['p99_ms'];
    }
    unset($s);
}
foreach ($scenarios as $name => $s) {
    $row = [
        'requests' => $s['count'],
        'errors' => $s['errors'],
        'error_rate' => $s['count'] > 0 ? round($s['errors'] / $s['count'], 5) : null,
        'dropped' => $s['dropped'],
        'p50_ms' => median($s['p50']),
        'p99_ms' => median($s['p99']),
        'p99_first_ms' => median($s['p99_first'] ?? []),
        'p99_last_ms' => median($s['p99_last'] ?? []),
        'worst_window_max_ms' => $s['max'],
    ];
    if ($name !== 'live') {
        $summary['scenarios'][$name] = $row;
        continue;
    }
    // A live "request" is a held stream: its time is how long it stayed open,
    // and its errors are refusals, early closes and streams that went silent.
    $open = array_values(array_filter($s['open'], static fn ($v) => $v !== ''));
    $summary['live'] = [
        'streams' => $row['requests'],
        'errors' => $row['errors'],
        'held_median_s' => $row['p50_ms'] === null ? null : round($row['p50_ms'] / 1000, 1),
        'open_min' => $open === [] ? null : min(array_map('intval', $open)),
        'open_median' => median($open),
    ];
}

// A process is its pid AND its start: a restarted container begins its pids at 1 again.
$byProcess = [];
foreach ($workers as $row) {
    $key = $row['pid'] . '@' . ($row['start_ticks'] ?? '');
    $byProcess[$key]['pid'] = (int) $row['pid'];
    $byProcess[$key]['ppid'] = $row['ppid'];
    $byProcess[$key]['t'][] = strtotime($row['ts']);
    $byProcess[$key]['rss'][] = (int) $row['rss_kb'] / 1024;
    $byProcess[$key]['anon'][] = isset($row['anon_kb']) && $row['anon_kb'] !== '' ? (int) $row['anon_kb'] / 1024 : null;
}
$parents = array_count_values(array_column($byProcess, 'ppid'));
$sampleTimes = array_values(array_unique(array_column($workers, 'ts')));
$atStart = array_unique(array_map(
    static fn ($r) => $r['pid'] . '@' . ($r['start_ticks'] ?? ''),
    array_filter($workers, static fn ($r) => $r['ts'] === ($sampleTimes[0] ?? null)),
));

// The heap PHP itself reports, by the worker that answered /metrics.
$heap = [];
foreach ($metrics as $row) {
    if ($row['http'] === '200' && ($row['worker_pid'] ?? '') !== '' && $row['worker_memory_usage'] !== '') {
        $heap[$row['worker_pid']]['t'][] = strtotime($row['ts']);
        $heap[$row['worker_pid']]['mb'][] = (int) $row['worker_memory_usage'] / 1048576;
    }
}

foreach ($byProcess as $p) {
    // metrics.csv knows the pid, not the process: after a container restart the
    // same pid is another worker, so only samples inside this one's span count.
    $h = null;
    foreach (array_keys($heap[(string) $p['pid']]['t'] ?? []) as $i) {
        $at = $heap[(string) $p['pid']]['t'][$i];
        if ($at >= $p['t'][0] && $at <= end($p['t'])) {
            $h['t'][] = $at;
            $h['mb'][] = $heap[(string) $p['pid']]['mb'][$i];
        }
    }
    $summary['workers'][] = [
        'pid' => $p['pid'],
        'role' => isset($parents[(string) $p['pid']]) ? 'master' : 'worker',
        'samples' => count($p['rss']),
        'hours_seen' => round((end($p['t']) - $p['t'][0]) / 3600, 2),
        'rss_first_mb' => round($p['rss'][0], 1),
        'rss_last_mb' => round(end($p['rss']), 1),
        'rss_max_mb' => round(max($p['rss']), 1),
        'rss_mb_per_hour' => perHour($p['t'], $p['rss']),
        // The leak signal. RSS also counts shared memory (opcache, Swoole
        // tables) a worker touches for the first time, which grows and then
        // stops; anon is the worker's own memory, and a steady slope there is
        // what a leak looks like.
        'anon_first_mb' => $p['anon'][0] === null ? null : round($p['anon'][0], 1),
        'anon_last_mb' => end($p['anon']) === null ? null : round(end($p['anon']), 1),
        'anon_mb_per_hour' => perHour($p['t'], $p['anon']),
        'anon_mb_per_hour_after_first_hour' => perHourSteady($p['t'], $p['anon']),
        // PHP's own heap, from /metrics answers this pid gave.
        'heap_samples' => $h === null ? 0 : count($h['mb']),
        'heap_mb_per_hour' => $h === null ? null : perHour($h['t'], $h['mb']),
        'heap_mb_per_hour_after_first_hour' => $h === null ? null : perHourSteady($h['t'], $h['mb']),
    ];
}

$interval = (int) ($run['interval_s'] ?? 30);
$expected = isset($run['duration_s']) ? intdiv((int) $run['duration_s'], max(1, $interval)) + 1 : null;
$summary['stack']['samples'] = count($sampleTimes) . ($expected === null ? '' : ' of ~' . $expected);
$summary['stack']['processes_at_start'] = count($atStart);
$summary['stack']['processes_seen'] = count($byProcess);
// A worker the server replaced (max_request, the memory watchdog, a crash) is a new process.
$summary['stack']['worker_replacements'] = max(0, count($byProcess) - count($atStart));

$ok = array_values(array_filter($metrics, static fn ($r) => $r['http'] === '200'));
$summary['stack']['metrics_samples'] = count($ok) . ' of ' . count($metrics);
$summary['stack']['connection_num_max'] = $ok === [] ? null : max(array_map(static fn ($r) => (int) $r['connection_num'], $ok));
$summary['stack']['coroutine_num_median'] = median(array_column($ok, 'coroutine_num'));
// What max_request alone accounts for: about one recycle per max_request
// requests served. Replacements well above it are something else recycling workers.
$counts = array_values(array_filter(array_map(static fn ($r) => $r['request_count'], $ok), static fn ($v) => $v !== ''));
$served = count($counts) >= 2 ? max(0, (int) end($counts) - (int) $counts[0]) : null;
$maxRequest = (int) ($run['max_request'] ?? 0);
$summary['stack']['requests_served'] = $served;
$summary['stack']['recycles_expected_from_max_request'] = $served === null || $maxRequest <= 0 ? null : intdiv($served, $maxRequest);

$starts = array_values(array_unique(array_filter(array_column($stack, 'container_started_at'))));
$summary['stack']['container_restarts'] = max(0, count($starts) - 1);

$files = array_values(array_unique(array_filter(array_column($log, 'file'), static fn ($f) => $f !== 'unresolved')));
$measured = $files !== [];
$summary['stack']['log_files'] = $measured ? implode(' ', $files) : 'unmeasured (no swoole.log found)';
foreach (['log_lines' => 'new_lines', 'forced_terminations' => 'forced_terminations', 'abnormal_exits' => 'abnormal_exits', 'log_errors' => 'errors', 'log_warnings' => 'warnings'] as $key => $column) {
    $summary['stack'][$key] = $measured ? array_sum(array_map(static fn ($r) => (int) ($r[$column] ?? 0), $log)) : null;
}
$summary['stack']['container_output_fatals'] = array_sum(array_map(static fn ($r) => (int) ($r['stdout_fatals'] ?? 0), $log));

if ($asJson) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$fmt = static fn ($v) => $v === null ? '-' : (is_float($v) ? number_format($v, 1, '.', '') : (string) $v);
printf("Soak %s .. %s, %.2f h, container %s\n", $run['started_at'] ?? '?', trim((string) @file_get_contents("$dir/finished_at")) ?: '?', $hours, $run['container'] ?? '?');
printf("PHP %s, Swoole %s, scale %s, max_request %s\n\n", $run['php'] ?? '?', $run['swoole'] ?? '?', $run['scale'] ?? '?', $run['max_request'] ?? '?');
printf("%-7s %10s %8s %8s %8s %8s %10s %10s %10s\n", 'load', 'requests', 'errors', 'rate', 'dropped', 'p50 ms', 'p99 ms', 'p99 first', 'p99 last');
foreach ($summary['scenarios'] as $name => $s) {
    printf("%-7s %10d %8d %8s %8d %8s %10s %10s %10s\n", $name, $s['requests'], $s['errors'], $fmt($s['error_rate']), $s['dropped'], $fmt($s['p50_ms']), $fmt($s['p99_ms']), $fmt($s['p99_first_ms']), $fmt($s['p99_last_ms']));
}
if ($summary['live'] !== null) {
    $l = $summary['live'];
    printf("\nlive streams: %d held, %d errors, median hold %s s, open at a window end: min %s, median %s\n", $l['streams'], $l['errors'], $fmt($l['held_median_s']), $fmt($l['open_min']), $fmt($l['open_median']));
}
// "steady" = after the first hour of each process; "-" until there is one.
printf("\n%-7s %-7s %7s %6s %9s %9s %8s %9s %9s %9s %9s %9s\n", 'pid', 'role', 'samples', 'hours', 'rss first', 'rss last', 'rss MB/h', 'anon frst', 'anon last', 'anon MB/h', 'anon stdy', 'heap stdy');
foreach ($summary['workers'] as $w) {
    printf("%-7d %-7s %7d %6s %9s %9s %8s %9s %9s %9s %9s %9s\n", $w['pid'], $w['role'], $w['samples'], $fmt($w['hours_seen']), $fmt($w['rss_first_mb']), $fmt($w['rss_last_mb']), $fmt($w['rss_mb_per_hour']), $fmt($w['anon_first_mb']), $fmt($w['anon_last_mb']), $fmt($w['anon_mb_per_hour']), $fmt($w['anon_mb_per_hour_after_first_hour']), $fmt($w['heap_mb_per_hour_after_first_hour']));
}
echo "\n";
foreach ($summary['stack'] as $key => $value) {
    printf("%-36s %s\n", $key, $fmt($value));
}
