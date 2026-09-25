#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Whole-stack smoke gate: installs this skeleton, swaps every semitexa/* package
# for a git checkout of its development line, and proves the result boots,
# answers every route, passes its linters and doctor, and passes every
# package's own test suite inside the app.
#
# Usage (from a checkout of semitexa-ultimate; needs PHP 8.4 + Swoole, composer,
# git and curl):
#
#   ci/stack-smoke.sh
#
# Environment:
#   SEMITEXA_REF_DEFAULT=develop       ref every package is checked out at
#   SEMITEXA_REF_<pkg>=<sha|branch>    per package; <pkg> is the vendor dir name
#                                      with '-' as '_' (SEMITEXA_REF_core,
#                                      SEMITEXA_REF_project_graph)
#   SEMITEXA_OVERRIDE_REPO=<name>      one package to take from a local checkout
#   SEMITEXA_OVERRIDE_PATH=<dir>       instead (a package's own PR). <name> may be
#                                      `core`, `semitexa/core` or `semitexa-core`
#   SWOOLE_PORT=9502                   port the server listens on
#   SEMITEXA_SMOKE_SERVICES=present    `absent` when no MySQL/Redis is reachable:
#                                      only then are the checks and routes in
#                                      NEEDS_SERVICES below allowed to fail
#   SEMITEXA_SMOKE_STAGES=install,server,lint,doctor,tests
#   SEMITEXA_SMOKE_WORKDIR=var/stack-smoke   logs, junit, generated configs
#   SEMITEXA_SMOKE_SKIP_SUITES=""      space-separated test suites not to run
#                                      (`app`, or a vendor dir name like `dev`);
#                                      each is listed as SKIP in the summary
#   SMOKE_GITHUB_TOKEN                 optional, for composer's GitHub downloads
#
# The server stage runs straight after install, before anything else touches
# var/: it is there to catch a fresh install that does not boot, and the first
# request must be served the way a first visitor's would be.
#
# Exit status is non-zero when any stage fails. A summary table is printed last.
# ─────────────────────────────────────────────────────────────────────────────
set -u

ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT" || exit 2

PORT=${SWOOLE_PORT:-9502}
SERVICES=${SEMITEXA_SMOKE_SERVICES:-present}
STAGES=${SEMITEXA_SMOKE_STAGES:-install,server,lint,doctor,tests}
DEFAULT_REF=${SEMITEXA_REF_DEFAULT:-develop}
WORK=${SEMITEXA_SMOKE_WORKDIR:-$ROOT/var/stack-smoke}
case $WORK in /*) ;; *) WORK=$ROOT/$WORK ;; esac
BASE=http://127.0.0.1:$PORT
PHP=${PHP:-php}
COMPOSER=${COMPOSER_BIN:-composer}
CLI="$PHP vendor/bin/semitexa"

export COMPOSER_NO_INTERACTION=1 COMPOSER_PROCESS_TIMEOUT=900
# Optional token for composer's GitHub downloads only; it is not exported to
# anything else the script runs.
SMOKE_GITHUB_TOKEN=${SMOKE_GITHUB_TOKEN:-}
export -n SMOKE_GITHUB_TOKEN

# What may fail when (and only when) SEMITEXA_SMOKE_SERVICES=absent. Keep this
# short: every entry is something CI does not verify locally.
NEEDS_SERVICES_DOCTOR="orm.database"
NEEDS_SERVICES_ROUTES="/platform/calendar/events"
# Doctor checks that need a PHP extension, tolerated only while it is missing.
NEEDS_EXTENSION_DOCTOR="media.imagick-coders:imagick"
# Routes whose documented purpose is to answer 5xx.
EXPECTED_5XX_ROUTES="/__semitexa/error/500"
# Lines in the server log that fail the run.
LOG_FAIL_PATTERN='PHP Fatal|Uncaught|Deprecated|Warning'

rm -rf "$WORK" && mkdir -p "$WORK/junit" "$WORK/phpunit" "$WORK/logs"
SUMMARY=$WORK/summary.tsv
: > "$SUMMARY"
FAILED=0

log()  { printf '\n==> %s\n' "$*"; }
note() { printf '    %s\n' "$*"; }
record() { # stage status detail
    printf '%s\t%s\t%s\n' "$1" "$2" "$3" >> "$SUMMARY"
    [ "$2" = FAIL ] && FAILED=1
    return 0
}
# Tab-separated rows on stdin -> aligned columns (no dependency on column(1)).
table() {
    awk -F'\t' '{ for (i = 1; i <= NF; i++) { c[NR, i] = $i; if (length($i) > w[i]) w[i] = length($i) } n[NR] = NF }
        END { for (r = 1; r <= NR; r++) { line = "    "; for (i = 1; i <= n[r]; i++) line = line sprintf(i < n[r] ? "%-" w[i] + 2 "s" : "%s", c[r, i]); print line } }'
}
want() { case ",$STAGES," in *",$1,"*) return 0 ;; esac; return 1; }
in_list() { case " $2 " in *" $1 "*) return 0 ;; esac; return 1; }
retry() { # attempts cmd...
    local n=$1 i=1; shift
    until "$@"; do
        [ "$i" -ge "$n" ] && return 1
        note "attempt $i failed, retrying: $*"; i=$((i + 1)); sleep $((i * 5))
    done
}

# ── PHP helper: JSON/XML parsing the shell is bad at ─────────────────────────
HELPER=$WORK/helper.php
cat > "$HELPER" <<'PHP'
<?php
declare(strict_types=1);
[$self, $cmd] = [$argv[0], $argv[1] ?? ''];
$root = getcwd();
switch ($cmd) {
    case 'repo-url': // <pkg dir name> -> repo URL from composer.lock source.url
        $name = 'semitexa/' . $argv[2];
        $lock = is_file("$root/composer.lock") ? json_decode((string) file_get_contents("$root/composer.lock"), true) : [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $p) {
            if (($p['name'] ?? '') === $name && !empty($p['source']['url'])) { echo $p['source']['url']; exit(0); }
        }
        echo 'https://github.com/semitexa/semitexa-' . $argv[2] . '.git';
        exit(0);
    case 'sync-installed': // align installed.json autoload with the checked-out composer.json files
        $file = "$root/vendor/composer/installed.json";
        $data = json_decode((string) file_get_contents($file), true);
        $pkgs = &$data['packages'];
        $have = [];
        foreach ($pkgs as $p) { $have[$p['name']] = true; }
        foreach (($data['packages'] ?? []) as $p) { foreach (($p['provide'] ?? []) + ($p['replace'] ?? []) as $n => $_) { $have[$n] = true; } }
        $missing = [];
        foreach ($pkgs as &$p) {
            if (!str_starts_with($p['name'], 'semitexa/')) { continue; }
            $cj = "$root/vendor/{$p['name']}/composer.json";
            if (!is_file($cj)) { continue; }
            $json = json_decode((string) file_get_contents($cj), true) ?: [];
            foreach (['autoload', 'bin', 'extra', 'type'] as $k) {
                if (isset($json[$k])) { $p[$k] = $json[$k]; } else { unset($p[$k]); }
            }
            foreach (array_keys($json['require'] ?? []) as $req) {
                if ($req === 'php' || str_starts_with($req, 'ext-') || str_starts_with($req, 'lib-') || $req === 'composer-plugin-api' || $req === 'composer-runtime-api') { continue; }
                if (!isset($have[$req])) { $missing[] = "{$p['name']} requires $req"; }
            }
        }
        unset($p);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        if ($missing) { fwrite(STDERR, implode("\n", $missing) . "\n"); exit(1); }
        exit(0);
    case 'routes': // routes:list JSON on stdin -> "path<TAB>fill|skip:<reason>" lines
        $routes = json_decode((string) stream_get_contents(STDIN), true);
        if (!is_array($routes)) { fwrite(STDERR, "routes:list did not return JSON\n"); exit(1); }
        $seen = [];
        foreach ($routes as $r) {
            $path = (string) ($r['path'] ?? '');
            if ($path === '' || isset($seen[$path])) { continue; }
            $seen[$path] = true;
            if (!str_contains($path, '{')) { echo "$path\t$path\n"; continue; }
            $detail = json_decode((string) shell_exec(PHP_BINARY . ' vendor/bin/semitexa routes:show ' . escapeshellarg($path) . ' --json 2>/dev/null'), true) ?: [];
            $req = $detail['requirements'] ?? []; $def = $detail['defaults'] ?? [];
            $skip = null;
            $filled = preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($req, $def, &$skip): string {
                $name = $m[1];
                if (isset($def[$name]) && is_scalar($def[$name])) { return (string) $def[$name]; }
                $pattern = isset($req[$name]) ? (string) $req[$name] : null;
                foreach (['1', 'smoke'] as $candidate) {
                    if ($pattern === null || preg_match('#^(?:' . $pattern . ')$#', $candidate) === 1) { return $candidate; }
                }
                $skip = "param {$name} ({$pattern}) is not trivially fillable";
                return $m[0];
            }, $path);
            echo $skip === null ? "$path\t$filled\n" : "$path\tskip:$skip\n";
        }
        exit(0);
    case 'junit': // junit.xml -> "tests assertions failures errors skipped"
        $x = @simplexml_load_file($argv[2]);
        if ($x === false) { echo "0 0 0 0 0"; exit(1); }
        $s = $x->testsuite[0] ?? $x;
        printf('%d %d %d %d %d', (int) $s['tests'], (int) $s['assertions'], (int) $s['failures'], (int) $s['errors'], (int) $s['skipped']);
        exit(0);
    case 'doctor': // doctor JSON on stdin -> "status<TAB>name<TAB>message"
        $d = json_decode((string) stream_get_contents(STDIN), true);
        if (!is_array($d) || !isset($d['checks'])) { fwrite(STDERR, "system:doctor --json did not return a report\n"); exit(1); }
        foreach ($d['checks'] as $c) { printf("%s\t%s\t%s\n", $c['status'], $c['name'], str_replace(["\t", "\n"], ' ', (string) $c['message'])); }
        exit(0);
}
fwrite(STDERR, "unknown helper command $cmd\n");
exit(2);
PHP

# ── Stage: install ───────────────────────────────────────────────────────────
override_matches() { # <pkg dir name>
    [ -n "${SEMITEXA_OVERRIDE_REPO:-}" ] || return 1
    local o=${SEMITEXA_OVERRIDE_REPO##*/}
    o=${o%.git}
    [ "$o" = "$1" ] || [ "$o" = "semitexa-$1" ] || [ "${2:-}" = "$o" ]
}

checkout_package() { # <pkg> <url> <ref> <dest>
    local tmp=$4.smoke-tmp
    rm -rf "$tmp"; git init -q "$tmp" || return 1
    retry 3 git -C "$tmp" fetch -q --depth 1 "$2" "$3" || { rm -rf "$tmp"; return 1; }
    git -C "$tmp" checkout -q FETCH_HEAD || { rm -rf "$tmp"; return 1; }
    rm -rf "$4"; mv "$tmp" "$4"
}

stage_install() {
    log "install: composer install"
    local ignore=""
    $PHP -r 'exit(extension_loaded("imagick") ? 0 : 1);' || ignore="--ignore-platform-req=ext-imagick"
    [ -n "$ignore" ] && note "ext-imagick missing: $ignore"
    # --no-plugins/--no-scripts: nothing composer resolves gets to run code
    # during install; the registry sync the core plugin would do runs below,
    # explicitly, after the packages are swapped.
    # Dist archives come from api.github.com, which rate-limits anonymous
    # callers; a token (read-only is enough) lifts that. Composer falls back to
    # a git clone by itself when a dist download fails, and the whole install
    # is retried on top of that.
    local auth=${COMPOSER_AUTH:-}
    if [ -z "$auth" ] && [ -n "$SMOKE_GITHUB_TOKEN" ]; then
        auth="{\"github-oauth\":{\"github.com\":\"$SMOKE_GITHUB_TOKEN\"}}"
    fi
    if ! COMPOSER_AUTH=$auth retry 3 $COMPOSER install --no-plugins --no-scripts --no-progress --prefer-dist $ignore; then
        record install FAIL "composer install failed"; return 1
    fi
    if [ ! -f .env ]; then
        sed "s/{{ default_swoole_port }}/$PORT/" .env.default > .env
        note "created .env from .env.default"
    fi

    log "install: swap vendor/semitexa/* for git checkouts (default ref $DEFAULT_REF)"
    local pkg url ref var sha n=0 bad=0 ptable=$WORK/packages.tsv
    : > "$ptable"
    for dir in vendor/semitexa/*/; do
        pkg=$(basename "$dir")
        url=$($PHP "$HELPER" repo-url "$pkg")
        repo=$(basename "$url" .git)
        if override_matches "$pkg" "$repo"; then
            [ -d "${SEMITEXA_OVERRIDE_PATH:-}" ] || { note "SEMITEXA_OVERRIDE_PATH is not a directory"; bad=1; continue; }
            rm -rf "vendor/semitexa/$pkg"; mkdir -p "vendor/semitexa/$pkg"
            tar -C "$SEMITEXA_OVERRIDE_PATH" --exclude=./vendor --exclude=./.git -cf - . | tar -C "vendor/semitexa/$pkg" -xf -
            sha=$(git -C "$SEMITEXA_OVERRIDE_PATH" rev-parse --short HEAD 2>/dev/null || echo local)
            printf '%s\t%s\t%s\n' "$pkg" "override:$SEMITEXA_OVERRIDE_PATH" "$sha" >> "$ptable"
        else
            var=SEMITEXA_REF_$(printf '%s' "$pkg" | tr '-' '_')
            ref=${!var:-$DEFAULT_REF}
            if ! checkout_package "$pkg" "$url" "$ref" "vendor/semitexa/$pkg"; then
                note "could not check out $url @ $ref"; bad=1; continue
            fi
            sha=$(git -C "vendor/semitexa/$pkg" rev-parse --short HEAD)
            printf '%s\t%s\t%s\n' "$pkg" "$ref" "$sha" >> "$ptable"
        fi
        n=$((n + 1))
    done
    table < "$ptable"
    [ "$bad" = 0 ] || { record install FAIL "package checkout failed"; return 1; }

    if ! $PHP "$HELPER" sync-installed 2> "$WORK/logs/missing-deps.txt"; then
        sed 's/^/    missing: /' "$WORK/logs/missing-deps.txt"
        record install FAIL "a checked-out package needs a dependency the release did not install"; return 1
    fi
    $COMPOSER dump-autoload --no-plugins --no-scripts -q || { record install FAIL "dump-autoload failed"; return 1; }
    $CLI registry:sync > "$WORK/logs/registry-sync.txt" 2>&1 || { tail -20 "$WORK/logs/registry-sync.txt"; record install FAIL "registry:sync failed"; return 1; }
    # With a database reachable, give it the schema the app declares, so a
    # route that reads a table is judged on its code and not on an empty DB.
    if [ "$SERVICES" = present ]; then
        log "install: orm:sync"
        $CLI orm:sync --no-interaction > "$WORK/logs/orm-sync.txt" 2>&1 || { tail -20 "$WORK/logs/orm-sync.txt"; record install FAIL "orm:sync failed"; return 1; }
    fi
    record install PASS "$n packages checked out"
}

# ── Stage: server ────────────────────────────────────────────────────────────
PIDFILE=$WORK/server.pid
SERVER_OUT=$WORK/logs/server.out
SERVER_LOG=$WORK/logs/swoole.log

stop_server() {
    [ -f "$PIDFILE" ] || return 0
    local pid; pid=$(cat "$PIDFILE"); rm -f "$PIDFILE"
    kill -0 "$pid" 2>/dev/null || return 0
    # The server runs in its own session, so its process group holds the
    # master, manager and every worker.
    kill -TERM -- "-$pid" 2>/dev/null || kill -TERM "$pid" 2>/dev/null
    for _ in $(seq 1 30); do kill -0 "$pid" 2>/dev/null || return 0; sleep 0.5; done
    note "server did not stop on TERM; killing"
    kill -KILL -- "-$pid" 2>/dev/null || kill -KILL "$pid" 2>/dev/null
}
trap stop_server EXIT
trap 'stop_server; exit 130' INT
trap 'stop_server; exit 143' TERM

probe() { # method path [accept] -> prints "code exit content-type"
    local args=(-s -o /dev/null -m 10 -w '%{http_code} %{content_type}')
    [ "$1" = HEAD ] && args+=(-I)
    [ -n "${3:-}" ] && args+=(-H "Accept: $3")
    local out rc
    out=$(curl "${args[@]}" "$BASE$2" 2>/dev/null); rc=$?
    set -- $out
    printf '%s %s %s\n' "${1:-000}" "$rc" "${2:-}"
}

stage_server() {
    log "server: routes"
    local routes=$WORK/routes.tsv
    if ! $CLI routes:list --json 2> "$WORK/logs/routes.err" | $PHP "$HELPER" routes > "$routes"; then
        cat "$WORK/logs/routes.err"; record server FAIL "routes:list --json failed"; return 1
    fi
    note "$(wc -l < "$routes") routes"

    log "server: start on :$PORT"
    if curl -s -o /dev/null -m 2 "$BASE/"; then
        record server FAIL "port $PORT is already answering"; return 1
    fi
    # Remember where each app log ends, so only what this server writes is read.
    local f; : > "$WORK/applog-offsets"
    for f in var/log/*.log; do [ -f "$f" ] && printf '%s\t%s\n' "$f" "$(wc -c < "$f")" >> "$WORK/applog-offsets"; done
    SWOOLE_PORT=$PORT SWOOLE_LOG_FILE=$SERVER_LOG setsid $PHP server.php > "$SERVER_OUT" 2>&1 &
    echo $! > "$PIDFILE"
    local pid; pid=$(cat "$PIDFILE") ready=0
    for _ in $(seq 1 120); do
        kill -0 "$pid" 2>/dev/null || break
        # Listening socket only: the first HTTP request is the one under test.
        if (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; then ready=1; break; fi
        sleep 0.5
    done
    if [ "$ready" != 1 ]; then
        tail -40 "$SERVER_OUT"; record server FAIL "server did not start listening"; return 1
    fi

    local res code rc ctype fail=0 skipped=0 tolerated=0 total=0 first
    res=$(curl -s -o "$WORK/logs/first-response.html" -m 60 -w '%{http_code}' "$BASE/"); first=$res
    if [ "$first" = 000 ] || [ "$first" -ge 500 ]; then
        note "FIRST request GET / -> $first"; fail=1
    else
        note "first request GET / -> $first"
    fi

    log "server: crawl (GET, HEAD, GET+Accept: application/json)"
    local results=$WORK/crawl.tsv path url variant
    : > "$results"
    while IFS="$(printf '\t')" read -r path url; do
        case $url in skip:*) printf '%s\t-\tSKIP\t%s\n' "$path" "${url#skip:}" >> "$results"; skipped=$((skipped + 1)); continue ;; esac
        local stream=0
        for variant in GET JSON HEAD; do
            if [ "$variant" = HEAD ] && [ "$stream" = 1 ]; then
                printf '%s\tHEAD\tSKIP\tSSE/stream route\n' "$path" >> "$results"; skipped=$((skipped + 1)); continue
            fi
            case $variant in
                GET)  set -- $(probe GET "$url") ;;
                JSON) set -- $(probe GET "$url" application/json) ;;
                HEAD) set -- $(probe HEAD "$url") ;;
            esac
            code=$1 rc=$2 ctype=${3:-}
            total=$((total + 1))
            # A stream answers 2xx and then keeps the connection open.
            if [ "$variant" != HEAD ] && { [ "$rc" = 28 ] || [[ $ctype == text/event-stream* ]]; } && [ "$code" != 000 ] && [ "$code" -lt 500 ]; then
                stream=1; printf '%s\t%s\t%s\tstream\n' "$path" "$variant" "$code" >> "$results"; continue
            fi
            if [ "$code" != 000 ] && [ "$code" -lt 500 ]; then
                printf '%s\t%s\t%s\tok\n' "$path" "$variant" "$code" >> "$results"
            elif in_list "$path" "$EXPECTED_5XX_ROUTES"; then
                printf '%s\t%s\t%s\texpected\n' "$path" "$variant" "$code" >> "$results"
            elif [ "$SERVICES" = absent ] && in_list "$path" "$NEEDS_SERVICES_ROUTES"; then
                printf '%s\t%s\t%s\ttolerated (services absent)\n' "$path" "$variant" "$code" >> "$results"; tolerated=$((tolerated + 1))
            else
                printf '%s\t%s\t%s\tFAIL\n' "$path" "$variant" "$code" >> "$results"; fail=$((fail + 1))
            fi
        done
    done < "$routes"
    grep -E 'FAIL|tolerated|SKIP|stream' "$results" | table

    kill -0 "$pid" 2>/dev/null || { note "server died during the crawl"; fail=$((fail + 1)); }
    stop_server

    local loghits=$WORK/logs/server-log-hits.txt
    local applog=$WORK/logs/app-log-during-crawl.txt off
    : > "$applog"
    for f in var/log/*.log; do
        [ -f "$f" ] || continue
        off=$(awk -F'\t' -v f="$f" '$1 == f {print $2}' "$WORK/applog-offsets"); off=${off:-0}
        tail -c "+$((off + 1))" "$f" >> "$applog"
    done
    # stdout/stderr (PHP's own diagnostics), Swoole's log (rotated files
    # carry a date suffix), and the app log lines written during the crawl.
    cat "$SERVER_OUT" "$SERVER_LOG"* "$applog" 2>/dev/null | grep -E "$LOG_FAIL_PATTERN" > "$loghits"
    note "app log: $(grep -c '"level":"error"' "$applog") error-level lines during the crawl (see $applog)"
    if [ -s "$loghits" ]; then
        note "server log matches /$LOG_FAIL_PATTERN/:"; head -20 "$loghits" | sed 's/^/      /'
        fail=$((fail + 1))
    fi
    local detail="first GET / $first; $total requests, $skipped skipped, $tolerated tolerated, $(wc -l < "$loghits") bad log lines"
    if [ "$fail" = 0 ]; then record server PASS "$detail"; else record server FAIL "$detail"; return 1; fi
}

# ── Stage: lint ──────────────────────────────────────────────────────────────
stage_lint() {
    log "lint: every lint:* command"
    local cmds fail=0 n=0
    cmds=$($CLI list --raw 2>/dev/null | awk '$1 ~ /^lint:/ {print $1}')
    [ -n "$cmds" ] || { record lint FAIL "no lint:* commands found"; return 1; }
    for c in $cmds; do
        n=$((n + 1))
        # Log names drop the colon: upload-artifact refuses it (NTFS-safe names).
        if $CLI "$c" --no-interaction > "$WORK/logs/${c//:/-}.txt" 2>&1; then
            note "PASS $c"
        else
            note "FAIL $c"; tail -15 "$WORK/logs/${c//:/-}.txt" | sed 's/^/      /'; fail=$((fail + 1))
        fi
    done
    if [ "$fail" = 0 ]; then record lint PASS "$n commands"; else record lint FAIL "$fail of $n commands failed"; return 1; fi
}

# ── Stage: doctor ────────────────────────────────────────────────────────────
stage_doctor() {
    log "doctor: system:doctor"
    local rows=$WORK/doctor.tsv status name msg fail=0 tolerated=0 n=0
    if ! $CLI system:doctor --json 2> "$WORK/logs/doctor.err" | $PHP "$HELPER" doctor > "$rows"; then
        cat "$WORK/logs/doctor.err"; record doctor FAIL "system:doctor --json failed"; return 1
    fi
    while IFS="$(printf '\t')" read -r status name msg; do
        n=$((n + 1))
        [ "$status" = fail ] || { note "$status $name"; continue; }
        local ok=0 entry
        if [ "$SERVICES" = absent ] && in_list "$name" "$NEEDS_SERVICES_DOCTOR"; then ok=1; fi
        for entry in $NEEDS_EXTENSION_DOCTOR; do
            if [ "${entry%%:*}" = "$name" ] && ! $PHP -r "exit(extension_loaded('${entry#*:}') ? 0 : 1);"; then ok=1; fi
        done
        if [ "$ok" = 1 ]; then note "fail $name (tolerated): $msg"; tolerated=$((tolerated + 1))
        else note "FAIL $name: $msg"; fail=$((fail + 1)); fi
    done < "$rows"
    local detail="$n checks, $tolerated tolerated"
    if [ "$fail" = 0 ]; then record doctor PASS "$detail"; else record doctor FAIL "$fail failing; $detail"; return 1; fi
}

# ── Stage: tests ─────────────────────────────────────────────────────────────
run_suite() { # <name> <dir with tests/ or tests dirs...>
    local name=$1; shift
    local xml=$WORK/phpunit/$name.xml junit=$WORK/junit/$name.xml out=$WORK/logs/phpunit-$name.txt dirs="" d
    for d in "$@"; do dirs="$dirs<directory>$d</directory>"; done
    cat > "$xml" <<XML
<?xml version="1.0"?>
<phpunit bootstrap="$WORK/bootstrap-pkg.php" colors="false" cacheDirectory="$WORK/phpunit/$name.cache">
  <testsuites><testsuite name="$name">$dirs</testsuite></testsuites>
</phpunit>
XML
    PKG_DIR=${PKG_DIR:-} timeout 1800 $PHP -d zend.assertions=1 -d memory_limit=2G vendor/bin/phpunit -c "$xml" --log-junit "$junit" > "$out" 2>&1
    local rc=$? counts
    counts=$($PHP "$HELPER" junit "$junit")
    set -- $counts
    local line="$1 tests, $3 failures, $4 errors, $5 skipped"
    if [ "$rc" = 0 ]; then
        note "PASS $name: $line"; record "tests:$name" PASS "$line"
    else
        note "FAIL $name (exit $rc): $line"
        # Each failure with the first lines of its message, so a red run can
        # be read from the job log alone.
        awk '/^[0-9]+\) /{n=5} n>0{print; n--}' "$out" | head -60 | sed 's/^/      /'
        [ "$1" = 0 ] && tail -15 "$out" | sed 's/^/      /'
        record "tests:$name" FAIL "$line"
    fi
}

stage_tests() {
    log "tests: PHPUnit suites inside the app"
    [ -x vendor/bin/phpunit ] || { record tests FAIL "vendor/bin/phpunit missing"; return 1; }
    cat > "$WORK/bootstrap-pkg.php" <<'PHP'
<?php
// The app's own test bootstrap (module PSR-4), plus the autoload-dev PSR-4 of
// the package under test (PKG_DIR), which Composer does not load for a
// dependency.
require getcwd() . '/vendor/semitexa/testing/bootstrap/phpunit.php';
$pkg = rtrim((string) getenv('PKG_DIR'), '/');
if ($pkg !== '' && is_file($pkg . '/composer.json')) {
    $json = json_decode((string) file_get_contents($pkg . '/composer.json'), true) ?: [];
    foreach (($json['autoload-dev']['psr-4'] ?? []) as $prefix => $dirs) {
        foreach ((array) $dirs as $dir) {
            $base = $pkg . '/' . rtrim($dir, '/') . '/';
            spl_autoload_register(static function (string $c) use ($prefix, $base): void {
                if (str_starts_with($c, $prefix)) {
                    $f = $base . str_replace('\\', '/', substr($c, strlen($prefix))) . '.php';
                    if (is_file($f)) { require $f; }
                }
            });
        }
    }
}
PHP
    local appdirs=() d
    for d in "$ROOT/tests" "$ROOT"/src/modules/*/tests; do [ -d "$d" ] && appdirs+=("$d"); done
    local skip=${SEMITEXA_SMOKE_SKIP_SUITES:-} name
    if [ "${#appdirs[@]}" -gt 0 ]; then
        if in_list app "$skip"; then record tests:app SKIP "SEMITEXA_SMOKE_SKIP_SUITES"
        else PKG_DIR= run_suite app "${appdirs[@]}"; fi
    fi
    for d in "$ROOT"/vendor/semitexa/*/; do
        d=${d%/}; name=$(basename "$d")
        [ -d "$d/tests" ] || continue
        if in_list "$name" "$skip"; then record "tests:$name" SKIP "SEMITEXA_SMOKE_SKIP_SUITES"; continue; fi
        PKG_DIR=$d run_suite "$name" "$d/tests"
    done
}

# ── Run ──────────────────────────────────────────────────────────────────────
want install && { stage_install || { printf '\ninstall failed; later stages skipped\n'; STAGES=; }; }
want server && stage_server
want lint && stage_lint
want doctor && stage_doctor
want tests && stage_tests

printf '\n==> summary (services: %s)\n' "$SERVICES"
{ printf 'STAGE\tRESULT\tDETAIL\n'; cat "$SUMMARY"; } | table
[ "$FAILED" = 0 ] && { printf '\nstack smoke: PASS\n'; exit 0; }
printf '\nstack smoke: FAIL\n'
exit 1
