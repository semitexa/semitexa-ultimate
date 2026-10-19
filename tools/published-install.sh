#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Published-install proof: install Semitexa the way a newcomer does, from
# public sources only, and fail if any step of that path does not work.
#
# tools/stack-smoke.sh proves the development lines boot together. This proves
# what people actually get: the install.sh semitexa.com serves, the
# semitexa/installer image it pulls, and the semitexa/ultimate release
# Packagist hands to `composer create-project`. Nothing from this checkout is
# used — on purpose: a release that only works from a workspace is the failure
# this exists to catch.
#
# Usage (needs docker with Compose v2 and curl; no PHP, no Composer):
#
#   tools/published-install.sh [--expect-version 2026.10.19.0811] [--workdir DIR] [--keep]
#
#   --expect-version  wait (up to 20 min) until Packagist lists this
#                     semitexa/ultimate version, then require the project to
#                     have installed exactly the semitexa/* versions it pins
#   --workdir         where the throwaway project goes (default: a temp dir)
#   --keep            leave the project, its containers and images behind
#
# Environment:
#   SEMITEXA_INSTALL_URL   the install.sh to run (default https://semitexa.com/install.sh)
#
# Stages, in the order the README promises them: prerequisites, packagist,
# install (curl | bash -s <name> --start), page, orm-sync, self-test, cli,
# pins. The first failing stage stops the run; a summary table is printed
# last and written to <workdir>/summary.txt, with every stage's output in
# <workdir>/logs/. Exit status is non-zero when any stage failed.
# ─────────────────────────────────────────────────────────────────────────────
set -u

# One compound command, parsed before it runs: see tools/soak/soak.sh.
{

INSTALL_URL=${SEMITEXA_INSTALL_URL:-https://semitexa.com/install.sh}
EXPECT_VERSION=''
WORKDIR=''
KEEP=0

while [ $# -gt 0 ]; do
    case $1 in
        --expect-version) EXPECT_VERSION=$2; shift 2 ;;
        --workdir) WORKDIR=$2; shift 2 ;;
        --keep) KEEP=1; shift ;;
        *) echo "unknown argument: $1" >&2; exit 2 ;;
    esac
done

[ -n "$WORKDIR" ] || WORKDIR=$(mktemp -d "${TMPDIR:-/tmp}/semitexa-published-install.XXXXXX")
case $WORKDIR in /*) ;; *) WORKDIR=$PWD/$WORKDIR ;; esac
mkdir -p "$WORKDIR/logs"
NAME="published-install-$(date -u +%H%M%S)"
PROJECT="$WORKDIR/$NAME"
SUMMARY="$WORKDIR/summary.txt"
: > "$SUMMARY"
FAILED=0

# stage <name> <command...>: run it, time it, keep its output, stop at the first failure.
stage() {
    local name=$1 started status
    shift
    if [ "$FAILED" -ne 0 ]; then
        printf '%-14s %-5s %s\n' "$name" 'SKIP' '' >> "$SUMMARY"
        return 0
    fi
    started=$(date +%s)
    echo "── $name"
    "$@" > "$WORKDIR/logs/$name.log" 2>&1 < /dev/null
    status=$?
    if [ "$status" -eq 0 ]; then
        printf '%-14s %-5s %ss\n' "$name" 'OK' "$(( $(date +%s) - started ))" >> "$SUMMARY"
    else
        FAILED=1
        printf '%-14s %-5s %ss  exit %s, see logs/%s.log\n' "$name" 'FAIL' "$(( $(date +%s) - started ))" "$status" "$name" >> "$SUMMARY"
        tail -n 30 "$WORKDIR/logs/$name.log" | sed 's/^/   | /'
    fi
}

port() { sed -n 's/^SWOOLE_PORT=//p' "$PROJECT/.env" 2>/dev/null | tail -n 1; }

prerequisites() {
    docker info > /dev/null || { echo 'docker is not reachable'; return 1; }
    docker compose version | grep -q -E 'v[2-9]|version [2-9]' || { echo 'Compose v2 is required'; return 1; }
    curl -fsS -o /dev/null "$INSTALL_URL" || { echo "cannot fetch $INSTALL_URL"; return 1; }
}

# Packagist's view of semitexa/ultimate: what create-project will be handed.
ultimate_metadata() { curl -fsS "https://repo.packagist.org/p2/semitexa/ultimate.json"; }

wait_for_packagist() {
    local deadline=$(( $(date +%s) + 1200 ))
    while :; do
        if ultimate_metadata | grep -q "\"version\":\"$EXPECT_VERSION\""; then
            echo "Packagist lists semitexa/ultimate $EXPECT_VERSION"
            return 0
        fi
        [ "$(date +%s)" -lt "$deadline" ] || { echo "Packagist still does not list $EXPECT_VERSION after 20 min"; return 1; }
        sleep 30
    done
}

install() {
    cd "$WORKDIR" || return 1
    # Exactly the one-liner the README gives, non-interactive.
    curl -fsSL "$INSTALL_URL" | bash -s "$NAME" --start
}

page() {
    local p body
    p=$(port)
    [ -n "$p" ] || { echo 'no SWOOLE_PORT in the project .env'; return 1; }
    # The first request after a start can land while workers are still booting.
    body=$(curl -fsS --retry 30 --retry-connrefused --retry-delay 2 --retry-all-errors "http://localhost:$p/") || { echo "GET http://localhost:$p/ failed"; return 1; }
    printf '%s' "$body" | grep -q -i '<title>[^<]*Semitexa' || { echo 'the home page has no Semitexa title:'; printf '%s' "$body" | head -c 600; return 1; }
    echo "GET http://localhost:$p/ answered the Semitexa home page"
}

in_project() { cd "$PROJECT" && "$@"; }

# Every semitexa/* package installed at exactly the version this ultimate release pins.
pins() {
    local pinned installed
    # The project's own PHP reads the metadata: the host needs none. Packagist's
    # p2 files are minified — a version lists only what changed from the one
    # before it ("__unset" removes a key) — so the versions are replayed in order.
    pinned=$(ultimate_metadata | (cd "$PROJECT" && docker compose exec -T -e EXPECT="$EXPECT_VERSION" app php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        $current = [];
        foreach ($d["packages"]["semitexa/ultimate"] ?? [] as $v) {
            foreach ($v as $key => $value) {
                if ($value === "__unset") { unset($current[$key]); } else { $current[$key] = $value; }
            }
            if (($current["version"] ?? "") === getenv("EXPECT")) {
                foreach ($current["require"] ?? [] as $name => $c) { if (str_starts_with($name, "semitexa/")) echo $name, " ", $c, "\n"; }
                exit(0);
            }
        }
        fwrite(STDERR, "version not in the metadata\n"); exit(1);')) || return 1
    [ -n "$pinned" ] || { echo "ultimate $EXPECT_VERSION pins no semitexa/* package"; return 1; }
    installed=$(cd "$PROJECT" && docker compose exec -T app php -r '
        $d = json_decode(file_get_contents("vendor/composer/installed.json"), true);
        foreach ($d["packages"] ?? $d as $p) { if (str_starts_with($p["name"], "semitexa/")) echo $p["name"], " ", $p["version"], "\n"; }') || return 1
    local bad=0 name want got
    while read -r name want; do
        got=$(printf '%s\n' "$installed" | awk -v n="$name" '$1 == n { print $2 }')
        if [ "$got" != "$want" ]; then
            echo "$name: ultimate $EXPECT_VERSION pins $want, the project installed ${got:-nothing}"
            bad=1
        fi
    done <<EOF
$pinned
EOF
    [ "$bad" -eq 0 ] && echo "every semitexa/* package matches ultimate $EXPECT_VERSION ($(printf '%s\n' "$pinned" | grep -c .) pins)"
    return "$bad"
}

cleanup() {
    [ "$KEEP" -eq 1 ] && return 0
    [ -d "$PROJECT" ] || return 0
    (
        cd "$PROJECT" || exit 0
        app_id=$(sed -n 's/^SEMITEXA_APP_ID=//p' .env 2>/dev/null | tail -n 1)
        docker compose down -v --remove-orphans --rmi local > /dev/null 2>&1
        [ -z "$app_id" ] || sh bin/semitexa local-app:remove "$app_id" > /dev/null 2>&1
    )
    # Containers ran as the host user, but a setup step may have left root-owned files.
    rm -rf "$PROJECT" 2> /dev/null || docker run --rm -v "$WORKDIR":/w alpine rm -rf "/w/$NAME"
}
trap cleanup EXIT

echo "published install: $INSTALL_URL${EXPECT_VERSION:+, ultimate $EXPECT_VERSION}, in $WORKDIR"
stage prerequisites prerequisites
[ -z "$EXPECT_VERSION" ] || stage packagist wait_for_packagist
stage install install
stage page page
stage orm-sync in_project sh bin/semitexa orm:sync
stage self-test in_project sh bin/semitexa self-test
stage cli in_project sh bin/semitexa list
[ -z "$EXPECT_VERSION" ] || stage pins pins

echo
echo "published install, $(date -u +%Y-%m-%dT%H:%M:%SZ):"
cat "$SUMMARY"
[ "$FAILED" -eq 0 ] && echo 'RESULT: PASS' || echo 'RESULT: FAIL'
exit "$FAILED"
}
