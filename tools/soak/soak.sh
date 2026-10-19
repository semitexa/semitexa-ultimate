#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Swoole soak: hold a running Semitexa stack under mixed load for a long time
# and record whether its workers stay healthy.
#
# The question it answers is the one max_request cannot: does a worker that
# keeps serving keep a flat memory curve, a stable p99 and clean recycles?
# (August 2026: portal workers wedged on a blocking read and grew until PHP
# killed them mid-request.) It does not judge; it records, and summary.php
# turns the record into the numbers a report quotes.
#
# Usage (from a checkout of semitexa-ultimate, on the host that runs the stack):
#
#   tools/soak/soak.sh --container <app container> [--duration 259200] \
#                      [--port 9502] [--out var/soak/<stamp>] [--scale 1] [--live 20]
#
#   --container  the app container of the stack under test (docker ps name or id)
#   --duration   seconds of load; 259200 is the 72-hour run
#   --port       the port Swoole listens on INSIDE that container
#   --out        where the run is written (created; must not exist yet)
#   --scale      multiplies every request rate in load.mjs
#   --live       long-held KISS streams
#   --host       the host name the load sends, when the app answers its pages
#                only on a tenant domain (demo.<base>): the load container gets
#                an /etc/hosts naming it as 127.0.0.1. Node's fetch drops a
#                Host header, so a name is the only way to send one.
#
# Needs docker and php on the host (curl runs inside the app container). The load runs in the semitexa/e2e-runner
# image on the container's own network, so no port has to be published.
#
# The stack under test should run as production does, plus two knobs:
#   SEMITEXA_METRICS_ENABLED=1   /metrics answers (from inside the container)
#   SSE_MAX_CONN_PER_IP          above --live + 40: every stream of the load
#                                comes from one address, and the default 50
#                                would refuse the load, not a visitor
# and a queue worker (queue:work nats async), or queued events are only queued.
#
# What it loads: Demo pages and its JSON API, the Execution Arena events and
# the UI Playground pings writes (load.mjs names each path) — a stack built the
# way the release clone is (ultimate + semitexa/demo + the workspace's
# src/modules). The pings write adds a row each time (about 1800 an hour at
# scale 1); every read of that table is paginated, so the growth costs rows,
# not worker memory, but a report should still say so.
#
# Writes, every 30 seconds:
#   workers.csv  every server.php process: RSS, its own (anon) and shared memory
#   metrics.csv  /metrics as answered: which worker, connections, coroutines, heap
#   stack.csv    docker stats, and the container's start time and restart count
#   log.csv      what swoole.log (the live, rotated file) and the container output gained
#   log-incidents.txt  the forced terminations, crashes and errors themselves
# and load.csv + errors.ndjson from load.mjs, run.json describing the run.
# Exit status: the load generator's (non-zero when its preflight failed).
# ─────────────────────────────────────────────────────────────────────────────
set -u

# The whole run is one compound command, parsed before any of it runs: bash
# reads a script as it executes it, and an edit to this file during a 72-hour
# run would otherwise be read from the middle of a line.
{

CONTAINER=''
DURATION=600
PORT=9502
OUT=''
SCALE=1
LIVE=20
HOST=''
INTERVAL=30
RUNNER_IMAGE=${SOAK_RUNNER_IMAGE:-semitexa/e2e-runner:1.59.1-axe4.13.0}

while [ $# -gt 0 ]; do
    case $1 in
        --container) CONTAINER=$2; shift 2 ;;
        --duration) DURATION=$2; shift 2 ;;
        --port) PORT=$2; shift 2 ;;
        --out) OUT=$2; shift 2 ;;
        --scale) SCALE=$2; shift 2 ;;
        --live) LIVE=$2; shift 2 ;;
        --host) HOST=$2; shift 2 ;;
        *) echo "unknown argument: $1" >&2; exit 2 ;;
    esac
done

HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
[ -n "$CONTAINER" ] || { echo "--container is required" >&2; exit 2; }
[ -n "$OUT" ] || OUT="$ROOT/var/soak/$(date -u +%Y%m%d-%H%M%S)"
case $OUT in /*) ;; *) OUT=$PWD/$OUT ;; esac
[ ! -e "$OUT" ] || { echo "$OUT already exists: a run never overwrites another" >&2; exit 2; }
docker inspect "$CONTAINER" >/dev/null 2>&1 || { echo "no such container: $CONTAINER" >&2; exit 2; }
# Host php reads every sample and writes the summary; without it the run would
# record nothing for 72 hours and say so only at the end.
command -v php >/dev/null 2>&1 || { echo "soak.sh needs php on the host" >&2; exit 2; }
mkdir -p "$OUT"

in_app() { docker exec "$CONTAINER" sh -c "$1"; }

BASE="http://127.0.0.1:$PORT"
LOAD_BASE=$BASE
HOSTS_MOUNT=''
if [ -n "$HOST" ]; then
    LOAD_BASE="http://$HOST:$PORT"
    printf '127.0.0.1 localhost %s\n' "$HOST" > "$OUT/hosts"
    HOSTS_MOUNT="-v $OUT/hosts:/etc/hosts:ro"
fi
in_app "curl -fsS -o /dev/null -H 'Host: ${HOST:-127.0.0.1}' $BASE/" || { echo "the stack does not answer $LOAD_BASE/ inside $CONTAINER" >&2; exit 2; }

# A setting as the server sees it: the container's environment first, then the
# project's .env, which the framework reads but `docker exec` does not.
setting() {
    in_app "v=\${$1:-}; [ -n \"\$v\" ] || v=\$(sed -n 's/^$1=//p' .env 2>/dev/null | tail -n 1); printf %s \"\${v:-$2}\""
}
MAX_REQUEST=$(setting SWOOLE_MAX_REQUEST 10000)
LOG_BASE=$(setting SWOOLE_LOG_FILE var/log/swoole.log)

# What the run measured, so a report can name it exactly.
{
    printf '{"started_at":"%s","container":"%s","port":%s,"host":"%s","duration_s":%s,"interval_s":%s,"scale":%s,"live":%s,' \
        "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$CONTAINER" "$PORT" "$HOST" "$DURATION" "$INTERVAL" "$SCALE" "$LIVE"
    printf '"max_request":"%s","log_base":"%s",' "$MAX_REQUEST" "$LOG_BASE"
    printf '"image":"%s",' "$(docker inspect -f '{{.Config.Image}}' "$CONTAINER")"
    printf '"swoole":"%s",' "$(in_app "php -r 'echo phpversion(\"swoole\");'" 2>/dev/null)"
    printf '"php":"%s",' "$(in_app "php -r 'echo PHP_VERSION;'" 2>/dev/null)"
    printf '"packages":%s}\n' "$(in_app "cat vendor/composer/installed.json" 2>/dev/null \
        | php -r '$d=json_decode(stream_get_contents(STDIN),true); $o=[]; foreach(($d["packages"]??$d??[]) as $p){ if(str_starts_with($p["name"]??"","semitexa/")) $o[$p["name"]]=($p["version"]??"")." ".substr($p["source"]["reference"]??$p["dist"]["reference"]??"",0,12); } echo json_encode($o, JSON_UNESCAPED_SLASHES);' 2>/dev/null || echo '{}')"
} > "$OUT/run.json"

echo 'ts,pid,ppid,rss_kb,anon_kb,shmem_kb,elapsed_s,start_ticks' > "$OUT/workers.csv"
echo 'ts,http,worker_pid,connection_num,coroutine_num,worker_memory_usage,request_count' > "$OUT/metrics.csv"
echo 'ts,cpu_percent,mem_usage,container_started_at,restart_count' > "$OUT/stack.csv"
echo 'ts,file,new_lines,forced_terminations,abnormal_exits,errors,warnings,stdout_fatals' > "$OUT/log.csv"

# Every server.php process as pid,ppid,rss_kb,anon_kb,shmem_kb,elapsed_s,start_ticks,
# read from /proc. RSS alone cannot tell a leak from shared memory a worker
# touches for the first time (opcache, Swoole tables): anon is the process's own.
# start_ticks tells two processes apart that got the same pid (a container
# restart begins its pids again at 1). Images differ in their ps (BusyBox has
# no etimes and prints RSS as "298m"), so it is /proc, not ps.
PROCS='hz=$(getconf CLK_TCK 2>/dev/null || echo 100); up=$(cut -d" " -f1 /proc/uptime)
for d in /proc/[0-9]*; do
    case "$(tr "\0" " " < "$d/cmdline" 2>/dev/null)" in php\ *server.php*|*/php\ *server.php*) ;; *) continue ;; esac
    awk -v up="$up" -v hz="$hz" -v pid="${d#/proc/}" "
        FILENAME ~ /status\$/ && /^PPid:/ { ppid = \$2 }
        FILENAME ~ /status\$/ && /^VmRSS:/ { rss = \$2 }
        FILENAME ~ /status\$/ && /^RssAnon:/ { anon = \$2 }
        FILENAME ~ /status\$/ && /^RssShmem:/ { shmem = \$2 }
        FILENAME ~ /stat\$/ { sub(/^.*\\) /, \"\"); started = \$20 }
        END { if (rss != \"\") printf \"%s,%s,%s,%s,%s,%d,%s\\n\", pid, ppid, rss, anon, shmem, up - started / hz, started }
    " "$d/status" "$d/stat" 2>/dev/null
done'

# The file Swoole is writing now. With SWOOLE_LOG_ROTATION on (the default,
# daily) it writes to "<log_file>.<date>" and leaves <log_file> itself empty,
# so the newest non-empty one of them is the live one, and it changes at midnight.
ACTIVE_LOG="for f in \$(ls -t '$LOG_BASE' '$LOG_BASE'.* 2>/dev/null); do [ -s \"\$f\" ] && { printf %s \"\$f\"; break; }; done"
is_number() { case $1 in ''|*[!0-9]*) return 1 ;; *) return 0 ;; esac; }
size_of() { in_app "wc -c < '$1' 2>/dev/null" | tr -d ' '; }
read_log() { in_app "tail -c +$(($2 + 1)) '$1' 2>/dev/null | head -c $(($3 - $2))"; }

log_file=''
log_offset=0
last_logs=''

sample() {
    local ts
    ts=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    in_app "$PROCS" 2>/dev/null | sed "s/^/$ts,/" >> "$OUT/workers.csv"

    # One worker answers each request; a few in a row sample more than one of them.
    for _ in 1 2 3 4; do
        in_app "curl -sS -m 5 -w '\n%{http_code}' $BASE/metrics" 2>/dev/null \
            | php -r '$all = stream_get_contents(STDIN); $at = strrpos($all, "\n"); $code = $at === false ? "" : substr($all, $at + 1); $d = $at === false ? null : json_decode(substr($all, 0, $at), true);
                $d = is_array($d) ? $d : [];
                echo $argv[1], ",", $code, ",", $d["worker_pid"] ?? "", ",", $d["connection_num"] ?? "", ",", $d["coroutine_num"] ?? "", ",", $d["worker_memory_usage"] ?? "", ",", $d["request_count"] ?? "", "\n";' "$ts" >> "$OUT/metrics.csv"
    done

    # A restarted container is a different run: its processes, its network,
    # and the load is left talking to a namespace that is gone.
    printf '%s,%s,%s\n' "$ts" \
        "$(docker stats --no-stream --format '{{.CPUPerc}},{{.MemUsage}}' "$CONTAINER" 2>/dev/null | sed 's/%//; s/ \/ .*//')" \
        "$(docker inspect -f '{{.State.StartedAt}},{{.RestartCount}}' "$CONTAINER" 2>/dev/null)" >> "$OUT/stack.csv"

    local active size new=''
    active=$(in_app "$ACTIVE_LOG" 2>/dev/null)
    if [ -n "$active" ] && [ "$active" != "$log_file" ]; then
        if [ -z "$log_file" ]; then
            # The first sample starts at the end: what was there before is not this run's.
            size=$(size_of "$active")
            is_number "$size" && log_offset=$size || log_offset=0
        else
            # The day turned: finish the previous file, then the new one from its start.
            size=$(size_of "$log_file")
            if is_number "$size" && [ "$size" -gt "$log_offset" ]; then
                new=$(read_log "$log_file" "$log_offset" "$size")
            fi
            log_offset=0
        fi
        log_file=$active
    fi
    if [ -n "$log_file" ]; then
        size=$(size_of "$log_file")
        if is_number "$size"; then
            # Smaller than what was read: truncated or replaced, so read it from the start.
            [ "$size" -ge "$log_offset" ] || log_offset=0
            if [ "$size" -gt "$log_offset" ]; then
                new="$new$(read_log "$log_file" "$log_offset" "$size")"
            fi
            log_offset=$size
        fi
    fi

    # What never reaches swoole.log: a PHP fatal of a worker that is not
    # daemonized goes to the container's output.
    # Each window ends where the next begins, at the moment of the call and to
    # the nanosecond: bounded by the sample's start, a line written between it
    # and this call was counted in two windows.
    local fatals=0 logs_now
    logs_now=$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)
    if [ -n "$last_logs" ]; then
        fatals=$(docker logs --since "$last_logs" --until "$logs_now" "$CONTAINER" 2>&1 \
            | grep -E 'Fatal error|Allowed memory size|Segmentation fault|Out of memory|abnormal exit' \
            | tee -a "$OUT/log-incidents.txt" | grep -c . || true)
    fi
    last_logs=$logs_now

    # swoole.log lines are "[time *pid.worker]<TAB>LEVEL<TAB>message".
    printf '%s\n' "$new" | awk -F'\t' -v ts="$ts" -v file="${log_file:-unresolved}" -v fatals="$fatals" '
        NF == 0 { next }
        { lines++ }
        /forced termination/ { forced++ }
        /abnormal exit/ { abnormal++ }
        $2 == "ERROR" || /Fatal error/ { errors++ }
        $2 == "WARNING" { warnings++ }
        END { printf "%s,%s,%d,%d,%d,%d,%d,%d\n", ts, file, lines, forced, abnormal, errors, warnings, fatals }' >> "$OUT/log.csv"
    printf '%s\n' "$new" | awk -F'\t' '/forced termination|abnormal exit|Fatal error/ || $2 == "ERROR"' >> "$OUT/log-incidents.txt"
}

# Named, so a stopped run takes its load with it instead of leaving it running.
LOAD_NAME="soak-load-$(date -u +%Y%m%d%H%M%S)-$$"
trap 'docker stop -t 5 "$LOAD_NAME" >/dev/null 2>&1' INT TERM EXIT
# shellcheck disable=SC2086 # HOSTS_MOUNT is empty or one -v option
docker run --rm --name "$LOAD_NAME" --network "container:$CONTAINER" --user "$(id -u):$(id -g)" \
    -v "$HERE":/soak:ro -v "$OUT":/out $HOSTS_MOUNT \
    "$RUNNER_IMAGE" node /soak/load.mjs --base-url "$LOAD_BASE" --duration "$DURATION" \
    --scale "$SCALE" --live "$LIVE" --out /out > "$OUT/load.log" 2>&1 &
load_pid=$!

echo "soak: $CONTAINER for ${DURATION}s, writing $OUT"
sample
while kill -0 "$load_pid" 2>/dev/null; do
    sleep "$INTERVAL" & wait $!
    sample
done
wait "$load_pid"
status=$?
sample
date -u +%Y-%m-%dT%H:%M:%SZ > "$OUT/finished_at"
php "$HERE/summary.php" "$OUT" | tee "$OUT/summary.txt"
exit "$status"
}
