#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Upgrade proof: install the release before the newest one the way a newcomer
# does, give it code and an edit of its own, run `bin/semitexa update`, and
# fail if the project does not come out on the new release, working, with its
# own code and edits intact.
#
# tools/published-install.sh proves a NEW project works. This proves an
# EXISTING one survives the release: the composer move to the new ultimate
# pins, the update re-running itself after it upgrades semitexa/update, the
# scaffold sync that must not overwrite a file the user changed, the data
# patches and the ORM schema sync.
#
# Usage (needs docker with Compose v2 and curl; no PHP, no Composer):
#
#   tools/upgrade-from-previous.sh [--to 2026.10.19.0811] [--from 2026.10.17.1236] [--workdir DIR] [--keep]
#
#   --to       the release to upgrade to; waits (up to 20 min) until Packagist
#              lists it. Default: the newest stable release on Packagist.
#   --from     the release to start from. Default: the one before --to.
#   --workdir  where the throwaway project goes (default: a temp dir)
#   --keep     leave the project, its containers and images behind
#
# Environment:
#   SEMITEXA_INSTALL_URL   the install.sh to run (default https://semitexa.com/install.sh)
#
# A limit worth knowing: the install keeps the installer image's own files
# (docker-compose*.yml, bin/semitexa, .env*) — today's, not the old release's.
# So the old project has the old packages under the current scaffold, and the
# scaffold sync is exercised only for the files the installer does not own
# (the agent docs, phpstan configs, docker/e2e-runner).
#
# Stages: prerequisites, resolve, install-from, own-code, update, restart,
# after-page, after-own-page, self-test, pins, edit-kept. The first failing
# stage stops the run; the summary is printed last and written to
# <workdir>/summary.txt, each stage's output to <workdir>/logs/.
# ─────────────────────────────────────────────────────────────────────────────
set -u

# One compound command, parsed before it runs: see tools/soak/soak.sh.
{

INSTALL_URL=${SEMITEXA_INSTALL_URL:-https://semitexa.com/install.sh}
TO=''
FROM=''
WORKDIR=''
KEEP=0
MARKER='upgrade-from-previous: this line was added by the project and must survive the update'

while [ $# -gt 0 ]; do
    case $1 in
        --to) TO=$2; shift 2 ;;
        --from) FROM=$2; shift 2 ;;
        --workdir) WORKDIR=$2; shift 2 ;;
        --keep) KEEP=1; shift ;;
        *) echo "unknown argument: $1" >&2; exit 2 ;;
    esac
done

[ -n "$WORKDIR" ] || WORKDIR=$(mktemp -d "${TMPDIR:-/tmp}/semitexa-upgrade.XXXXXX")
case $WORKDIR in /*) ;; *) WORKDIR=$PWD/$WORKDIR ;; esac
mkdir -p "$WORKDIR/logs"
NAME="upgrade-proof-$(date -u +%H%M%S)"
PROJECT="$WORKDIR/$NAME"
SUMMARY="$WORKDIR/summary.txt"
: > "$SUMMARY"
FAILED=0

stage() {
    local name=$1 started status
    shift
    if [ "$FAILED" -ne 0 ]; then
        printf '%-15s %-5s\n' "$name" 'SKIP' >> "$SUMMARY"
        return 0
    fi
    started=$(date +%s)
    echo "── $name"
    "$@" > "$WORKDIR/logs/$name.log" 2>&1 < /dev/null
    status=$?
    if [ "$status" -eq 0 ]; then
        printf '%-15s %-5s %ss  %s\n' "$name" 'OK' "$(( $(date +%s) - started ))" "$(tail -n 1 "$WORKDIR/logs/$name.log" | cut -c1-90)" >> "$SUMMARY"
    else
        FAILED=1
        printf '%-15s %-5s %ss  exit %s, see logs/%s.log\n' "$name" 'FAIL' "$(( $(date +%s) - started ))" "$status" "$name" >> "$SUMMARY"
        tail -n 30 "$WORKDIR/logs/$name.log" | sed 's/^/   | /'
    fi
}

port() { sed -n 's/^SWOOLE_PORT=//p' "$PROJECT/.env" 2>/dev/null | tail -n 1; }
in_project() { cd "$PROJECT" && "$@"; }
app_php() { (cd "$PROJECT" && docker compose exec -T app php "$@"); }

prerequisites() {
    docker info > /dev/null || { echo 'docker is not reachable'; return 1; }
    docker compose version | grep -q -E 'v[2-9]|version [2-9]' || { echo 'Compose v2 is required'; return 1; }
    curl -fsS -o /dev/null "$INSTALL_URL" || { echo "cannot fetch $INSTALL_URL"; return 1; }
    echo 'docker, compose v2, install.sh reachable'
}

# Stable releases of semitexa/ultimate, newest first, as Packagist lists them.
releases() {
    curl -fsS "https://repo.packagist.org/p2/semitexa/ultimate.json" \
        | grep -o '"version":"[0-9][0-9.]*"' | cut -d'"' -f4
}

resolve() {
    local deadline list
    deadline=$(( $(date +%s) + 1200 ))
    while :; do
        list=$(releases) || list=''
        if [ -z "$TO" ]; then TO=$(printf '%s\n' "$list" | head -n 1); fi
        if [ -n "$TO" ] && printf '%s\n' "$list" | grep -qx "$TO"; then break; fi
        [ "$(date +%s)" -lt "$deadline" ] || { echo "Packagist still does not list ultimate $TO after 20 min"; return 1; }
        sleep 30
    done
    if [ -z "$FROM" ]; then
        FROM=$(printf '%s\n' "$list" | grep -A1 -x "$TO" | sed -n 2p)
    fi
    [ -n "$FROM" ] || { echo "no release before $TO to start from"; return 1; }
    printf '%s\n' "$list" | grep -qx "$FROM" || { echo "Packagist does not list ultimate $FROM"; return 1; }
    echo "from $FROM to $TO"
}

# Every semitexa/* package installed at exactly the version ultimate $1 pins.
pins_match() {
    local version=$1 pinned installed bad=0 name want got
    pinned=$(curl -fsS "https://repo.packagist.org/p2/semitexa/ultimate.json" | (cd "$PROJECT" && docker compose exec -T -e EXPECT="$version" app php -r '
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
        exit(1);')) || { echo "ultimate $version is not in the Packagist metadata"; return 1; }
    installed=$(app_php -r '
        $d = json_decode(file_get_contents("vendor/composer/installed.json"), true);
        foreach ($d["packages"] ?? $d as $p) { if (str_starts_with($p["name"], "semitexa/")) echo $p["name"], " ", $p["version"], "\n"; }') || return 1
    while read -r name want; do
        [ -n "$name" ] || continue
        got=$(printf '%s\n' "$installed" | awk -v n="$name" '$1 == n { print $2 }')
        if [ "$got" != "$want" ]; then
            echo "$name: ultimate $version pins $want, the project has ${got:-nothing}"
            bad=1
        fi
    done <<EOF
$pinned
EOF
    [ "$bad" -eq 0 ] && echo "all $(printf '%s\n' "$pinned" | grep -c .) semitexa/* pins of ultimate $version installed"
    return "$bad"
}

get() {
    curl -fsS -o /dev/null --retry 30 --retry-connrefused --retry-delay 2 --retry-all-errors "http://localhost:$(port)$1" \
        || { echo "GET $1 failed"; return 1; }
    echo "GET $1 answered"
}

install_from() {
    cd "$WORKDIR" || return 1
    curl -fsSL "$INSTALL_URL" | bash -s "$NAME" || return 1
    # The installer's setup takes the latest release unless told otherwise.
    printf '\nSEMITEXA_ULTIMATE_VERSION=%s\n' "$FROM" >> "$PROJECT/.env"
    (cd "$PROJECT" && sh bin/semitexa server:start) || return 1
    (cd "$PROJECT" && sh bin/semitexa orm:sync) || return 1
    get / || return 1
    # An installer image without the version knob installs the latest instead:
    # that is not an upgrade from $FROM, and must not pass as one.
    pins_match "$FROM"
}

own_code() {
    cd "$PROJECT" || return 1
    sh bin/semitexa make:module --name=UpgradeProbe --write || return 1
    sh bin/semitexa make:page --module=UpgradeProbe --name=UpgradeProbe --path=/upgrade-probe \
        --method=GET --access=public --no-test --write || return 1
    cp CLAUDE.md "$WORKDIR/CLAUDE.md.before"
    printf '%s\n' "$MARKER" >> CLAUDE.md
    sh bin/semitexa server:restart || return 1
    get /upgrade-probe
}

update() { cd "$PROJECT" && sh bin/semitexa update; }

# The edit is kept, and a CLAUDE.md the new release changed arrives beside it as .new.
edit_kept() {
    cd "$PROJECT" || return 1
    grep -qxF "$MARKER" CLAUDE.md || { echo 'the update overwrote the project'"'"'s edit to CLAUDE.md'; return 1; }
    local shipped
    # An empty answer is not "nothing to compare": it is a check that did not run.
    shipped=$(app_php -r '$f = "vendor/semitexa/update/resources/scaffold/CLAUDE.md"; echo is_file($f) ? hash_file("sha256", $f) : "";') \
        || { echo 'could not read the shipped CLAUDE.md: the app container did not answer after the update'; return 1; }
    [ -n "$shipped" ] || { echo "ultimate $TO ships no vendor/semitexa/update/resources/scaffold/CLAUDE.md: the .new check cannot run"; return 1; }
    if [ "$shipped" != "$(sha256sum "$WORKDIR/CLAUDE.md.before" | cut -d' ' -f1)" ] && [ ! -f CLAUDE.md.new ]; then
        echo "ultimate $TO ships a different CLAUDE.md, but no CLAUDE.md.new was written next to the edited one"
        return 1
    fi
    echo "the edit survived; .new: $( [ -f CLAUDE.md.new ] && echo written || echo 'not needed')"
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
    rm -rf "$PROJECT" 2> /dev/null || docker run --rm -v "$WORKDIR":/w alpine rm -rf "/w/$NAME"
}
trap cleanup EXIT

echo "upgrade proof: $INSTALL_URL, in $WORKDIR"
stage prerequisites prerequisites
# resolve sets FROM and TO for every later stage, so it is not run in a subshell.
if [ "$FAILED" -eq 0 ]; then
    started=$(date +%s)
    echo "── resolve"
    if resolve > "$WORKDIR/logs/resolve.log" 2>&1; then
        printf '%-15s %-5s %ss  %s\n' resolve OK "$(( $(date +%s) - started ))" "from $FROM to $TO" >> "$SUMMARY"
    else
        FAILED=1
        printf '%-15s %-5s see logs/resolve.log\n' resolve FAIL >> "$SUMMARY"
        cat "$WORKDIR/logs/resolve.log"
    fi
fi
stage install-from install_from
stage own-code own_code
stage update update
stage restart in_project sh bin/semitexa server:restart
stage after-page get /
stage after-own-page get /upgrade-probe
stage self-test in_project sh bin/semitexa self-test
stage pins pins_match "$TO"
stage edit-kept edit_kept

echo
echo "upgrade ${FROM:-?} -> ${TO:-?}, $(date -u +%Y-%m-%dT%H:%M:%SZ):"
cat "$SUMMARY"
[ "$FAILED" -eq 0 ] && echo 'RESULT: PASS' || echo 'RESULT: FAIL'
exit "$FAILED"
}
