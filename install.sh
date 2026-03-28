#!/bin/sh
# ─────────────────────────────────────────────────────────────────────────────
# Semitexa Ultimate — one-liner installer
#
# Usage:
#   curl -fsSL https://semitexa.com/install.sh | bash
#   curl -fsSL https://semitexa.com/install.sh | bash -s my-project
#   curl -fsSL https://semitexa.com/install.sh | bash -s my-project --start
#
# Options:
#   <name>    Project directory name (prompted if omitted)
#   --start   Auto-start server after install (non-interactive)
#
# Requirements: Docker with Compose v2 (no PHP, no Composer on host)
# Compatible:   macOS, Linux, Windows (Git Bash / WSL)
# Architecture: AMD64, ARM64
#
# ── HOW TO USE SAFELY ────────────────────────────────────────────────────────
#
# BEFORE RUNNING — verify these from the directory where you want the project:
#   docker info              # Docker daemon must be running
#   docker compose version   # Must show v2 (not docker-compose v1)
#
# RECOMMENDED ONE-LINER (avoids TTY / CI hang issues):
#   curl -fsSL https://semitexa.com/install.sh | bash -s my-project
#
# NON-INTERACTIVE / CI (skips all prompts, starts server immediately):
#   curl -fsSL https://semitexa.com/install.sh | bash -s my-project --start
#
# WINDOWS:
#   Prefer WSL2 over Git Bash — Docker socket and id -u/id -g behave
#   correctly in WSL2. In Git Bash: sh install.sh my-project --start
#   Line endings must be LF not CRLF, or sh will fail with obscure parse errors.
#
# PITFALLS:
#   - Run from the PARENT directory of where you want the project created.
#   - Edit .env BEFORE starting the server — changes after start need a restart.
#   - A directory named PROJECT_NAME must not already exist.
#   - SWOOLE_PORT (default 9502) must be free. Check: lsof -i :9502
#   - On Docker rootless setups, ensure your user can run 'docker info'.
#   - If the install is interrupted (Ctrl+C, network drop), the partial
#     directory is automatically removed so you can re-run without errors.
#
# ─────────────────────────────────────────────────────────────────────────────
set -e
# set -e aborts on any non-zero exit. A partial install is worse than a clean
# failure. The last printed step number tells you exactly where it stopped.

INSTALLER_IMAGE="${SEMITEXA_INSTALLER_IMAGE:-semitexa/installer}"
# Host-side installer delegates project scaffolding to the Semitexa installer
# image. The generated project then performs dependency bootstrap in its own
# `setup` container on first `docker compose up -d`.

DEMO_IMAGE="${SEMITEXA_DEMO_IMAGE:-semitexa/demo}"
# Optional demo package image. Adds working example code to the project so
# developers can explore the framework without writing anything from scratch.

# ── Colour helpers ───────────────────────────────────────────────────────────
if [ -t 1 ] && command -v tput >/dev/null 2>&1; then
    C_RESET="$(tput sgr0 2>/dev/null   || true)"
    C_GREEN="$(tput setaf 2 2>/dev/null || true)"
    C_YELLOW="$(tput setaf 3 2>/dev/null || true)"
    C_RED="$(tput setaf 1 2>/dev/null   || true)"
    C_CYAN="$(tput setaf 6 2>/dev/null  || true)"
    C_BOLD="$(tput bold 2>/dev/null     || true)"
else
    # Colour is automatically suppressed in pipes and non-TTY contexts
    # (CI logs, cron, redirected output). No flags needed.
    C_RESET="" C_GREEN="" C_YELLOW="" C_RED="" C_CYAN="" C_BOLD=""
fi

# tty_available: returns true if /dev/tty can actually be opened for reading.
# [ -e /dev/tty ] is NOT sufficient — the file exists even when no controlling
# terminal is attached (e.g. CI containers, tool-spawned shells), but any
# attempt to read from it then fails with "No such device or address".
# We test by actually opening the fd in a subshell; the || true keeps set -e
# from aborting the caller.
tty_available() { [ -t 0 ] || ( exec 0</dev/tty ) 2>/dev/null; }

info()    { printf "%s  →%s  %s\n"      "$C_CYAN"   "$C_RESET" "$*"; }
success() { printf "%s  ✓%s  %s\n"      "$C_GREEN"  "$C_RESET" "$*"; }
warn()    { printf "%s  ⚠%s  %s\n"      "$C_YELLOW" "$C_RESET" "$*" >&2; }
error()   { printf "%s  ✗%s  %s\n"      "$C_RED"    "$C_RESET" "$*" >&2; }
step()    { printf "\n%s%s %s %s\n\n"   "$C_BOLD" "$C_CYAN" "$*" "$C_RESET"; }
banner() {
    printf "\n"
    printf "%s╔══════════════════════════════════════════════════════════════╗%s\n" "$C_CYAN$C_BOLD" "$C_RESET"
    printf "%s║          Semitexa Ultimate — Project Installer               ║%s\n" "$C_CYAN$C_BOLD" "$C_RESET"
    printf "%s║          https://semitexa.com                                ║%s\n" "$C_CYAN$C_BOLD" "$C_RESET"
    printf "%s╚══════════════════════════════════════════════════════════════╝%s\n" "$C_CYAN$C_BOLD" "$C_RESET"
    printf "\n"
}

# ── Cleanup on failure ───────────────────────────────────────────────────────
# _CLEANUP_PROJECT is armed just before we start writing files (step 3).
# It is disarmed once the install is confirmed complete (after step 5).
# On any premature exit — set -e abort, Ctrl+C, SIGTERM — the partial
# project directory is removed so the user can re-run without hitting the
# "directory already exists" guard in validate_project_name.
_CLEANUP_PROJECT=""

_on_exit() {
    _p="${_CLEANUP_PROJECT:-}"
    _CLEANUP_PROJECT=""          # idempotent — prevents double-run if both signal + EXIT fire
    if [ -n "$_p" ] && [ -d "$_p" ]; then
        printf "\n" >&2
        warn "Install did not complete — removing partial directory: ./${_p}"
        # Docker containers (dnsmasq, app) create root-owned files inside the
        # project dir (e.g. var/dns/dnsmasq.conf). Plain rm -rf fails on those.
        # Fall back to a throwaway Alpine container that has root on the volume.
        if ! rm -rf "$_p" 2>/dev/null; then
            if command -v docker >/dev/null 2>&1; then
                docker run --rm -v "$(pwd):/work" alpine \
                    rm -rf "/work/${_p}" 2>/dev/null \
                    || warn "Could not remove '${_p}'. Run: sudo rm -rf ./${_p}"
            else
                warn "Could not remove '${_p}' (permission denied). Run: sudo rm -rf ./${_p}"
            fi
        fi
    fi
}

# EXIT fires on: normal exit, set -e abort, and explicit exit N calls.
# INT/TERM are trapped separately with explicit exit codes so EXIT fires too,
# even on shells that do not propagate signal exits through the EXIT trap.
trap '_on_exit' EXIT
trap '_on_exit; exit 130' INT
trap '_on_exit; exit 143' TERM

# ── Argument parsing ─────────────────────────────────────────────────────────
# Only two inputs matter: PROJECT_NAME and --start.
# Unknown --flags warn but do NOT abort (non-fatal) — check for typos like
# --Start or --autostart if --start seems to have no effect.
PROJECT_NAME=""
AUTO_START=0
# SKIP_START is mutated by check_port_conflicts(). It MUST be declared here
# (not inside main) so check_port_conflicts can write to it and main can read
# the updated value. Calling check_port_conflicts in a subshell ($(...)) would
# isolate variable writes, causing SKIP_START to always remain 0.
SKIP_START=0
# LOCAL_DOMAIN is set by ask_local_domain() and read by print_next_steps().
# Same scope requirement as SKIP_START — must live outside both functions.
LOCAL_DOMAIN=""

for _arg in "$@"; do
    case "$_arg" in
        --start) AUTO_START=1 ;;
        --*)     warn "Unknown option: $_arg (did you mean --start?)" ;;
        *)       [ -z "$PROJECT_NAME" ] && PROJECT_NAME="$_arg" ;;
    esac
done

# ── Prerequisites ────────────────────────────────────────────────────────────
check_docker() {
    # PITFALL: Docker installed but daemon not running gives a misleading
    # "permission denied" on rootless Linux setups.
    # Fix: systemctl start docker  (or start Docker Desktop on mac/Windows).
    if ! command -v docker >/dev/null 2>&1; then
        error "Docker is not installed."
        printf "\n  Install Docker Desktop: %shttps://docs.docker.com/get-docker/%s\n\n" "$C_CYAN" "$C_RESET"
        exit 1
    fi

    if ! docker info >/dev/null 2>&1; then
        _derr="$(docker info 2>&1 || true)"
        case "$_derr" in
            *"permission denied"*|*"Got permission denied"*|*"connect: permission denied"*)
                error "Docker permission denied."
                printf "  Add your user to the 'docker' group:\n"
                printf "    sudo usermod -aG docker \$(whoami)\n"
                printf "  Then log out and back in, and re-run the installer.\n"
                ;;
            *)
                error "Docker daemon is not running. Start Docker and try again."
                ;;
        esac
        exit 1
    fi

    # Compose v2: `docker compose` (plugin) — required.
    # PITFALL: `docker-compose` (v1, standalone binary) will NOT satisfy this.
    # If you have both installed, upgrade: https://docs.docker.com/compose/migrate/
    if ! docker compose version >/dev/null 2>&1; then
        error "Docker Compose v2 is required ('docker compose' not found)."
        printf "\n  Upgrade to Docker Desktop 3.6+ or install the Compose plugin:\n"
        printf "  %shttps://docs.docker.com/compose/install/%s\n\n" "$C_CYAN" "$C_RESET"
        exit 1
    fi

    success "Docker $(docker --version | awk '{print $3}' | tr -d ',')" \
        "/ Compose $(docker compose version --short 2>/dev/null || echo 'v2')"
}

# ── Docker permission check (Linux only) ─────────────────────────────────────
# Docker Desktop on macOS/Windows manages socket access automatically.
# On Linux, /var/run/docker.sock is owned by root:docker. The user must be in
# the 'docker' group — otherwise every docker call requires sudo, and container
# volumes produce root-owned files that break every subsequent step.
#
# Called with the script arguments ("$@") so that check_docker_permissions can
# re-exec this script via 'sg docker' after adding the user to the group,
# allowing the install to continue in the same terminal without a re-login.
check_docker_permissions() {
    # macOS / Windows: Docker Desktop owns the socket — nothing to check.
    _os="$(uname -s 2>/dev/null || echo Unknown)"
    case "$_os" in Linux) ;; *) return ;; esac

    # ── Running as root ───────────────────────────────────────────────────────
    # Root can run docker, but containers create root-owned files in volumes.
    # That breaks setup_env, make_bin_executable, and server:start.
    if [ "$(id -u)" -eq 0 ]; then
        warn "Running as root."
        warn "Docker containers will create files owned by root inside ./${PROJECT_NAME:-<project>}."
        warn "Recommended: use a regular user that belongs to the 'docker' group."
        printf "\n"
        if tty_available; then
            printf "  %sContinue as root anyway?%s [y/N]: " "$C_BOLD" "$C_RESET"
            read -r _root_ans </dev/tty
            case "$_root_ans" in
                y|Y|yes|Yes|YES) return ;;
            esac
            info "Aborted. Re-run as a non-root user."
            exit 0
        fi
        warn "Non-interactive: continuing as root (file ownership issues may follow)."
        return
    fi

    # ── Docker works fine ─────────────────────────────────────────────────────
    docker info >/dev/null 2>&1 && return

    # ── Classify the failure ──────────────────────────────────────────────────
    _derr="$(docker info 2>&1 || true)"
    case "$_derr" in
        *"permission denied"*|*"Got permission denied"*|*"connect: permission denied"*) ;;
        *)
            # Daemon not running, socket missing, etc. — let check_docker handle it.
            return
            ;;
    esac

    _user="$(id -un)"
    printf "\n"
    error "Docker permission denied for user '${_user}'."

    # ── docker group does not exist ───────────────────────────────────────────
    if ! grep -q "^docker:" /etc/group 2>/dev/null && \
       ! getent group docker >/dev/null 2>&1; then
        printf "\n"
        warn "The 'docker' group does not exist — Docker may be installed incorrectly."
        printf "  Reinstall: %shttps://docs.docker.com/engine/install/%s\n\n" "$C_CYAN" "$C_RESET"
        exit 1
    fi

    # ── Determine group membership ────────────────────────────────────────────
    # _in_effective: user is in docker group in THIS session (id -nG shows it)
    # _in_configured: user is in docker group in /etc/group (takes effect after re-login)
    _in_effective=0
    id -nG 2>/dev/null | tr ' ' '\n' | grep -qx "docker" && _in_effective=1

    _in_configured=0
    _gmembers="$(getent group docker 2>/dev/null | cut -d: -f4 \
        || grep "^docker:" /etc/group 2>/dev/null | cut -d: -f4 \
        || true)"
    printf "%s" "$_gmembers" | tr ',' '\n' | grep -qx "$_user" && _in_configured=1

    # ── In /etc/group but session not refreshed yet ───────────────────────────
    if [ "$_in_effective" -eq 0 ] && [ "$_in_configured" -eq 1 ]; then
        printf "\n"
        warn "'${_user}' is already in the docker group, but this terminal session"
        warn "was opened before the change took effect."
        printf "\n"
        printf "  %sOption A%s — open a new terminal and re-run the installer.\n" \
            "$C_BOLD" "$C_RESET"
        if command -v sg >/dev/null 2>&1 && [ -f "$0" ]; then
            printf "  %sOption B%s — continue right now (activates the group in this shell):\n" \
                "$C_BOLD" "$C_RESET"
            printf "\n"
            if tty_available; then
                printf "  %sContinue without re-login?%s [Y/n]: " "$C_BOLD" "$C_RESET"
                read -r _sg_ans </dev/tty
                case "$_sg_ans" in
                    n|N|no|No|NO)
                        info "OK. Open a new terminal and re-run the installer."
                        exit 1
                        ;;
                    *)
                        info "Restarting with docker group active..."
                        exec sg docker -- sh "$0" "$@"
                        ;;
                esac
            fi
        fi
        printf "\n"
        info "Open a new terminal and re-run the installer."
        exit 1
    fi

    # ── User is NOT in the docker group at all ────────────────────────────────
    printf "\n"
    warn "User '${_user}' is not in the 'docker' group."
    printf "\n"
    printf "  Without this:\n"
    printf "    • docker requires sudo on every call\n"
    printf "    • volume mounts create root-owned files  (chmod/chown issues)\n"
    printf "    • bin/semitexa server:start will fail without sudo\n"
    printf "\n"
    printf "  Fix: %ssudo usermod -aG docker %s%s\n" "$C_BOLD" "$_user" "$C_RESET"
    printf "\n"

    if ! command -v sudo >/dev/null 2>&1; then
        error "'sudo' is not available on this system."
        printf "  As root, run:  usermod -aG docker %s\n" "$_user"
        printf "  Then log out and back in, and re-run the installer.\n\n"
        exit 1
    fi

    if ! tty_available; then
        error "Non-interactive mode: cannot run sudo interactively."
        printf "  Fix manually:\n"
        printf "    sudo usermod -aG docker %s\n" "$_user"
        printf "  Then log out and back in, and re-run the installer.\n\n"
        exit 1
    fi

    printf "  %sAdd '${_user}' to the docker group now?%s (requires sudo) [Y/n]: " \
        "$C_BOLD" "$C_RESET"
    read -r _add_ans </dev/tty
    case "$_add_ans" in
        n|N|no|No|NO)
            warn "Skipped. Files created by Docker containers may be owned by root."
            warn "Fix later: sudo usermod -aG docker ${_user}  (then re-login)"
            printf "\n"
            # User explicitly chose to skip — continue and let the ownership
            # fix in run_create_project handle the fallout as best it can.
            return
            ;;
    esac

    if ! sudo usermod -aG docker "$_user"; then
        error "sudo usermod failed."
        printf "  Run manually: sudo usermod -aG docker %s\n" "$_user"
        printf "  Then log out and back in, and re-run the installer.\n\n"
        exit 1
    fi

    success "Added '${_user}' to the 'docker' group."
    printf "\n"

    # Best path: re-exec this script via sg so the group is active immediately.
    if command -v sg >/dev/null 2>&1 && [ -f "$0" ]; then
        printf "  %sContinue the installer with the new group active now?%s [Y/n]: " \
            "$C_BOLD" "$C_RESET"
        read -r _reexec_ans </dev/tty
        case "$_reexec_ans" in
            n|N|no|No|NO)
                printf "\n"
                info "OK. When ready:"
                info "  1. Log out and back in (or open a new terminal)"
                info "  2. Re-run: sh $0 $*"
                printf "\n"
                exit 0
                ;;
            *)
                info "Restarting with docker group active..."
                exec sg docker -- sh "$0" "$@"
                # exec replaces the process — lines below only run if exec fails.
                warn "'sg' failed to re-exec. Open a new terminal and re-run."
                exit 1
                ;;
        esac
    fi

    # sg not available or running via curl|bash (no script file) — give instructions.
    info "Group change saved. Apply it without logging out:"
    info "  newgrp docker   (opens a new shell with the group active)"
    printf "\n"
    info "Then re-run the installer:"
    if [ -f "$0" ]; then
        info "  sh $0 $*"
    else
        _rerun="curl -fsSL https://semitexa.com/install.sh | bash"
        [ -n "$PROJECT_NAME" ] && _rerun="${_rerun} -s ${PROJECT_NAME}"
        [ "$AUTO_START" -eq 1 ]  && _rerun="${_rerun} --start"
        info "  ${_rerun}"
    fi
    printf "\n"
    exit 0
}

# ── Project name ─────────────────────────────────────────────────────────────
ask_project_name() {
    # When piping via `curl | bash`, stdin is the pipe, NOT your terminal.
    # The script detects this and falls back to /dev/tty for interactive input.
    # If /dev/tty is also unavailable (some CI containers), it defaults to
    # "my-semitexa". To avoid ambiguity, always pass a name explicitly:
    #   curl ... | bash -s my-project
    if [ -n "$PROJECT_NAME" ]; then
        return
    fi

    # stdin may be the pipe (curl | bash) — use /dev/tty for interactive input
    if tty_available; then
        printf "%s  Project name%s [my-semitexa]: " "$C_BOLD" "$C_RESET"
        read -r PROJECT_NAME </dev/tty
        PROJECT_NAME="${PROJECT_NAME:-my-semitexa}"
    else
        PROJECT_NAME="my-semitexa"
        warn "Non-interactive mode: using default project name '${PROJECT_NAME}'."
        warn "To choose a name: curl ... | bash -s <name>"
    fi
}

validate_project_name() {
    # ALLOWED: letters, digits, hyphens, underscores, dots.
    # REJECTED: spaces, slashes, @, #, etc. — these break Docker volume mounts
    # and Composer directory handling across all platforms.
    case "$PROJECT_NAME" in
        ''|-*|*[!a-zA-Z0-9._-]*)
            error "Invalid project name: '$PROJECT_NAME'. Use only letters, digits, hyphens, underscores, dots, and do not start with '-'."
            exit 1 ;;
    esac

    # An existing directory blocks the install intentionally — this prevents
    # accidentally clobbering a working project. Remove or rename it first.
    # PITFALL: If a previous install failed and left a partial directory, the
    # cleanup trap should have removed it automatically. If it persists (e.g.
    # the trap was bypassed by SIGKILL), remove it manually: rm -rf <name>
    if [ -e "$PROJECT_NAME" ]; then
        error "A file or directory named '$PROJECT_NAME' already exists."
        printf "\n  Remove it first, or choose a different name.\n\n"
        exit 1
    fi
}

run_create_project() {
    info "Running host-side scaffold via Docker image: ${INSTALLER_IMAGE}"
    info "(First run may pull the installer image — this may take a minute)\n"

    mkdir -p "$PROJECT_NAME"

    docker run --rm \
        -v "$(pwd)/${PROJECT_NAME}:/app" \
        "$INSTALLER_IMAGE" \
        install

    # ── Fix file ownership ─────────────────────────────────────────────────────
    # The installer image typically runs as root (UID 0), so every file it writes
    # to the volume ends up owned by root on the host. This breaks every subsequent
    # step: setup_env (can't write .env), make_bin_executable (can't chmod),
    # and server:start (can't write var/ at runtime).
    #
    # Fix order:
    #   1. Plain chown -R  — works when the user already owns the socket (docker group)
    #   2. Alpine container — needs only docker (no sudo), uses root inside the
    #      volume to chown on the host filesystem
    #   3. Manual sudo hint — last resort if both above fail
    _uid="$(id -u)"
    _gid="$(id -g)"
    _dir_owner="$(stat -c '%u' "$PROJECT_NAME" 2>/dev/null \
        || stat -f '%u' "$PROJECT_NAME" 2>/dev/null \
        || echo "$_uid")"

    if [ "$_dir_owner" != "$_uid" ]; then
        info "Fixing file ownership (container created files as UID ${_dir_owner})..."
        if chown -R "${_uid}:${_gid}" "$PROJECT_NAME" 2>/dev/null; then
            success "Ownership fixed → $(id -un):$(id -gn)"
        elif docker run --rm \
                -v "$(pwd):/work" \
                alpine \
                chown -R "${_uid}:${_gid}" "/work/${PROJECT_NAME}" 2>/dev/null; then
            success "Ownership fixed via Docker Alpine → $(id -un):$(id -gn)"
        else
            warn "Could not fix file ownership automatically."
            warn "Run before continuing: sudo chown -R $(id -un):$(id -gn) ./${PROJECT_NAME}"
        fi
    fi
}

# ── Post-install setup ───────────────────────────────────────────────────────
setup_env() {
    _env_file="$PROJECT_NAME/.env"
    _example="$PROJECT_NAME/.env.example"
    [ -f "$_example" ] || _example="$PROJECT_NAME/env.example"

    if [ ! -f "$_env_file" ] && [ -f "$_example" ]; then
        cp "$_example" "$_env_file"
        success ".env created from $(basename "$_example")"
        info "Edit $PROJECT_NAME/.env before starting if you need custom ports or DB settings."
        # KEY VARIABLES TO REVIEW BEFORE FIRST START:
        #   SWOOLE_PORT — HTTP port (default 9502). Change if already in use.
        #   APP_ENV     — Set to "production" before exposing publicly.
        #   DB_*        — Wrong values cause a silent boot failure.
        #   APP_KEY     — Required for encryption. Generate: bin/semitexa key:generate
        # The server reads .env at boot. Changes after start need a restart.
    elif [ -f "$_env_file" ]; then
        info ".env already exists — not overwritten."
        # Existing .env is preserved to protect custom config on reinstall.
    fi
}

make_bin_executable() {
    _bin="$PROJECT_NAME/bin/semitexa"
    if [ -f "$_bin" ]; then
        chmod +x "$_bin"
    fi
    # PITFALL: rsync without -p drops permissions. Fix: chmod +x bin/semitexa
    # PITFALL: On Windows NTFS volumes mounted in WSL, chmod has no effect.
    # Workaround: sh bin/semitexa <command> (call sh explicitly).
}

# ── Shared port helper ───────────────────────────────────────────────────────
# Reads SWOOLE_PORT from .env with full sanitisation:
#   - strips inline comments  (SWOOLE_PORT=9502 # default → 9502)
#   - strips double and single quotes
#   - strips whitespace
#   - validates it is a pure integer; falls back to 9502 if not
# Used by both print_next_steps and check_port_conflicts to guarantee
# they operate on the same value.
get_swoole_port() {
    _p="9502"
    if [ -f "$PROJECT_NAME/.env" ]; then
        _p="$(grep -E '^SWOOLE_PORT=' "$PROJECT_NAME/.env" 2>/dev/null \
            | head -1 \
            | cut -d= -f2 \
            | cut -d'#' -f1 \
            | tr -d "\"'" \
            | tr -d ' \t' \
            || true)"
        # Reject anything that is not a plain integer to avoid passing garbage
        # to lsof / ss / netstat or printing a misleading URL.
        case "$_p" in
            ''|*[!0-9]*) _p="9502" ;;
        esac
    fi
    printf "%s" "$_p"
}

# ── Port utilities ───────────────────────────────────────────────────────────

# Scan upward from PORT+1 until a port is free on both host and Docker.
# Prints the free port number, or nothing if none found within 100 tries.
find_free_port() {
    _base="$1"
    _try="$(expr "$_base" + 1)"
    _limit="$(expr "$_base" + 100)"
    while [ "$_try" -le "$_limit" ]; do
        _busy=0
        if command -v lsof >/dev/null 2>&1; then
            lsof -i :"$_try" >/dev/null 2>&1 && _busy=1 || true
        elif command -v ss >/dev/null 2>&1; then
            ss -ltn 2>/dev/null | grep -qE "[: ]${_try}( |$)" && _busy=1 || true
        elif command -v netstat >/dev/null 2>&1; then
            netstat -ltn 2>/dev/null | grep -qE "[: ]${_try}( |$)" && _busy=1 || true
        fi
        if [ "$_busy" -eq 0 ] && command -v docker >/dev/null 2>&1; then
            docker ps --format '{{.Ports}}' 2>/dev/null \
                | grep -q ":${_try}->" && _busy=1 || true
        fi
        if [ "$_busy" -eq 0 ]; then
            printf "%s" "$_try"
            return
        fi
        _try="$(expr "$_try" + 1)"
    done
}

# Rewrite SWOOLE_PORT in .env in-place (POSIX-safe: tmp file + mv).
update_swoole_port() {
    _new="$1"
    _env="${PROJECT_NAME}/.env"
    _tmp="${_env}.tmp"
    sed "s/^SWOOLE_PORT=.*/SWOOLE_PORT=${_new}/" "$_env" > "$_tmp" && mv "$_tmp" "$_env"
    success "SWOOLE_PORT updated to ${_new} in .env"
}

# ── Port conflict resolution ──────────────────────────────────────────────────
# Detects port conflicts from TWO independent sources, then actively resolves
# them so the customer never has to fix anything manually:
#
#   Source A — Docker-allocated ports  (`docker ps`)
#     The most common case in dev environments. Docker's network driver
#     reserves ports through its proxy/iptables layer — they are invisible
#     to lsof/ss/netstat, which is why the naive check always missed these.
#
#   Source B — host-level sockets  (lsof / ss / netstat)
#     Processes listening directly on the host (nginx, other apps).
#
# Resolution options offered to the user:
#   [1] Stop the conflicting Docker container → keep the original port
#   [2] Auto-select the next free port       → update .env automatically
#
# Non-interactive (no TTY): option 2 is applied silently.
#
# MUST be called directly (not in a subshell) so SKIP_START propagates.
check_port_conflicts() {
    SKIP_START=0
    _port="$(get_swoole_port)"
    _in_use=0
    _docker_owner=""

    # Source A: Docker-allocated ports
    if command -v docker >/dev/null 2>&1; then
        _docker_owner="$(docker ps --format '{{.Names}} {{.Ports}}' 2>/dev/null \
            | grep ":${_port}->" | awk '{print $1}' | head -1)"
        [ -n "$_docker_owner" ] && _in_use=1
    fi

    # Source B: host-level sockets (fallback / belt-and-suspenders)
    if [ "$_in_use" -eq 0 ]; then
        if command -v lsof >/dev/null 2>&1; then
            lsof -i :"$_port" >/dev/null 2>&1 && _in_use=1 || true
        elif command -v ss >/dev/null 2>&1; then
            ss -ltn 2>/dev/null | grep -qE "[: ]${_port}( |$)" && _in_use=1 || true
        elif command -v netstat >/dev/null 2>&1; then
            netstat -ltn 2>/dev/null | grep -qE "[: ]${_port}( |$)" && _in_use=1 || true
        fi
    fi

    [ "$_in_use" -eq 0 ] && return   # no conflict — nothing to do

    # ── Conflict detected: resolve it ────────────────────────────────────────
    _free="$(find_free_port "$_port")"

    if [ -n "$_docker_owner" ]; then
        warn "Port ${_port} is held by Docker container: ${_docker_owner}"
    else
        warn "Port ${_port} is already in use by a host process."
    fi

    # Non-interactive or --start: silently switch to the next free port
    if ! tty_available || [ "$AUTO_START" -eq 1 ]; then
        if [ -n "$_free" ]; then
            info "Non-interactive: switching to port ${_free} automatically."
            update_swoole_port "$_free"
            # SKIP_START stays 0 — start will proceed on the new port
        else
            warn "Could not find a free port. Skipping server start."
            SKIP_START=1
        fi
        return
    fi

    # Interactive: present options
    printf "\n"
    if [ -n "$_docker_owner" ]; then
        printf "  %s[1]%s Stop container '%s' and start on port %s\n" \
            "$C_BOLD" "$C_RESET" "$_docker_owner" "$_port"
    fi
    if [ -n "$_free" ]; then
        printf "  %s[2]%s Switch to port %s — updates .env automatically\n" \
            "$C_BOLD" "$C_RESET" "$_free"
    fi
    printf "  %s[s]%s Skip — I will start manually\n" "$C_BOLD" "$C_RESET"
    printf "\n"
    printf "  Choice: "
    read -r _choice </dev/tty

    case "$_choice" in
        1)
            if [ -n "$_docker_owner" ]; then
                info "Stopping ${_docker_owner}..."
                if docker stop "$_docker_owner" >/dev/null 2>&1; then
                    success "Container stopped. Starting on port ${_port}."
                    # SKIP_START stays 0
                else
                    warn "Could not stop '${_docker_owner}'."
                    warn "Try manually: docker stop ${_docker_owner}"
                    SKIP_START=1
                fi
            else
                warn "Option 1 is only available for Docker containers."
                SKIP_START=1
            fi
            ;;
        2)
            if [ -n "$_free" ]; then
                update_swoole_port "$_free"
                # SKIP_START stays 0 — start on the new port
            else
                warn "Could not find a free port automatically."
                SKIP_START=1
            fi
            ;;
        *)
            info "Skipping server start."
            info "Run manually: cd ${PROJECT_NAME} && bin/semitexa server:start"
            SKIP_START=1
            ;;
    esac
}

# ── Optional: start server ───────────────────────────────────────────────────
ask_start_server() {
    # In automated pipelines (CI, Dockerfile RUN, provisioning scripts),
    # always pass --start. Without it the script blocks waiting for TTY input.
    if [ "$AUTO_START" -eq 1 ]; then
        return 0
    fi

    if ! tty_available; then
        info "Non-interactive mode: skipping server start. Pass --start to auto-start."
        return 1
    fi

    printf "\n%s  Start the server now?%s [Y/n]: " "$C_BOLD" "$C_RESET"
    _ans="Y"
    read -r _ans </dev/tty
    # Empty input (Enter) defaults to YES.
    # Use --start explicitly in automation to make the intent unambiguous.

    case "$_ans" in
        n|N|no|No|NO) return 1 ;;
        *)             return 0 ;;
    esac
}

start_server() {
    # Run in a subshell so that 'cd' is isolated. If server:start fails,
    # the parent shell's working directory stays at the install root —
    # no stale directory state leaks into subsequent steps or error handlers.
    ( cd "$PROJECT_NAME" && sh bin/semitexa server:start )
    # To verify containers came up:   docker compose ps
    # To watch live logs:             docker compose logs -f
    # To stop cleanly:                bin/semitexa server:stop
}

# ── Local domain setup ───────────────────────────────────────────────────────

# Converts any string into a valid RFC 1123 DNS label:
#   1. Lowercase
#   2. Replace anything not [a-z0-9-] with a hyphen
#   3. Collapse consecutive hyphens into one  (--* = one or more hyphens → one)
#   4. Strip leading and trailing hyphens
#   5. Truncate to 63 chars (RFC 1035 label limit); re-strip trailing hyphen
#      that truncation may expose
sanitize_for_domain() {
    _s="$(printf "%s" "$1" \
        | tr 'A-Z' 'a-z' \
        | sed 's/[^a-z0-9-]/-/g' \
        | sed 's/--*/-/g' \
        | sed 's/^-*//' \
        | sed 's/-*$//')"
    # cut -c1-63 is POSIX and safe on empty strings
    _s="$(printf "%s" "$_s" | cut -c1-63 | sed 's/-*$//')"
    printf "%s" "$_s"
}

# Returns 0 (valid) or 1 (invalid) for a fully-formed domain.
# Rules enforced:
#   - Must end with exactly ".test" (the only TLD allowed for local dev)
#   - Label (part before .test) must be non-empty
#   - Label must contain only [a-z0-9-]  (already lowercased at this point)
#   - Label must not start or end with a hyphen (RFC 1035 §2.3.4)
#   - Label must be ≤ 63 characters (RFC 1035 §2.3.4)
validate_local_domain() {
    _d="$1"
    # Enforce .test suffix
    case "$_d" in *.test) ;; *) return 1 ;; esac
    _label="${_d%.test}"
    # Non-empty
    [ -z "$_label" ] && return 1
    # Only [a-z0-9-]
    case "$_label" in *[!a-z0-9-]*) return 1 ;; esac
    # No leading or trailing hyphen
    case "$_label" in -*|*-) return 1 ;; esac
    # Max 63 chars — POSIX ${#var} is safe here
    [ "${#_label}" -gt 63 ] && return 1
    return 0
}

# Try to free a single fixed port before starting DNS services.
# Returns 0 = port is free (or was freed), 1 = still blocked.
# Checks Docker allocations first (most common case), then host sockets.
# Resolution: stop the conflicting Docker container.
# Auto mode (--start / no TTY): stops silently.
# Interactive: asks the user to confirm or skip.
_free_fixed_port() {
    _ffp="$1"
    _owner=""

    if command -v docker >/dev/null 2>&1; then
        _owner="$(docker ps --format '{{.Names}} {{.Ports}}' 2>/dev/null \
            | grep ":${_ffp}[^0-9]" | awk '{print $1}' | head -1)"
        [ -n "$_owner" ] || {
            if command -v lsof >/dev/null 2>&1; then
                lsof -i :"$_ffp" >/dev/null 2>&1 && _owner="[host process]" || true
            elif command -v ss >/dev/null 2>&1; then
                ss -ltn 2>/dev/null | grep -qE "[: ]${_ffp}( |$)" \
                    && _owner="[host process]" || true
            fi
        }
    fi

    [ -z "$_owner" ] && return 0   # port is free

    warn "Port ${_ffp} is held by: ${_owner}"

    if ! tty_available || [ "$AUTO_START" -eq 1 ]; then
        warn "Port ${_ffp} is busy in auto mode — skipping local domain registration."
        return 1
    fi

    printf "  %s[1]%s Stop '%s' and continue\n" "$C_BOLD" "$C_RESET" "$_owner"
    printf "  %s[s]%s Skip domain registration (app will still start)\n" "$C_BOLD" "$C_RESET"
    printf "\n  Choice [1/s]: "
    read -r _ffp_c </dev/tty
    case "$_ffp_c" in
        1)
            if [ "$_owner" != "[host process]" ] \
               && docker stop "$_owner" >/dev/null 2>&1; then
                success "Stopped '${_owner}'."
                return 0
            fi ;;
    esac
    return 1
}

# Calls the internal DNS registration module via `bin/semitexa dns:add`.
# Non-fatal: a failure here warns the user but does NOT abort the installer.
#
# PORT GATE — all fixed ports used by docker-compose.dns.yml are checked
# BEFORE calling dns:add. If any conflict cannot be resolved we skip domain
# registration entirely, leaving LOCAL_DOMAIN="" so server:start never
# includes docker-compose.dns.yml and the app starts cleanly on its own port.
#
#   Port 80   — Nginx reverse proxy (docker-compose.dns.yml: proxy service)
#   Port 5553 — dnsmasq local DNS   (docker-compose.dns.yml: dns service)
#
# Related commands the user can run manually inside the project directory:
#   bin/semitexa dns:add <domain>     — register a .test domain
#   bin/semitexa dns:list             — list all registered local domains
#   bin/semitexa dns:remove <domain>  — remove a registered local domain
register_local_domain() {
    _domain="$1"

    # ── Fixed-port gate for docker-compose.dns.yml ────────────────────────────
    if [ -f "${PROJECT_NAME}/docker-compose.dns.yml" ]; then
        info "Checking ports required by local DNS services..."
        for _dns_port in 80 5553; do
            if ! _free_fixed_port "$_dns_port"; then
                warn "Cannot free port ${_dns_port} — skipping domain registration."
                warn "App will still start on its own port."
                warn "Register later: cd ${PROJECT_NAME} && bin/semitexa dns:add ${_domain}"
                LOCAL_DOMAIN=""
                return
            fi
        done
    fi

    # ── Register ──────────────────────────────────────────────────────────────
    info "Registering ${_domain} with local DNS..."
    _dns_bin="${PROJECT_NAME}/bin/semitexa"
    if [ -f "$_dns_bin" ]; then
        if ( cd "$PROJECT_NAME" && sh ./bin/semitexa dns:add "$_domain" ); then
            success "Local domain registered: http://${_domain}"
            info "To list domains:   sh bin/semitexa dns:list"
            info "To remove later:   sh bin/semitexa dns:remove ${_domain}"
        else
            warn "DNS registration failed — run manually once the server is up:"
            warn "  cd ${PROJECT_NAME} && sh bin/semitexa dns:add ${_domain}"
            LOCAL_DOMAIN=""
        fi
    else
        warn "bin/semitexa not found — skipping DNS registration."
        warn "Register manually after start: cd ${PROJECT_NAME} && sh bin/semitexa dns:add ${_domain}"
        LOCAL_DOMAIN=""
    fi
}

# Orchestrates the full domain prompt flow.
# Sets the global LOCAL_DOMAIN variable — called directly (not in a subshell)
# so the assignment propagates to main() and print_next_steps().
#
# Behaviour by environment:
#   TTY present      — full interactive prompt (confirm + optional custom name)
#   AUTO_START=1     — non-interactive: use generated default and register
#   No TTY, no auto  — skip with a tip for manual registration later
ask_local_domain() {
    LOCAL_DOMAIN=""
    _suggested="$(sanitize_for_domain "$PROJECT_NAME").test"

    # Guard: if PROJECT_NAME sanitizes to nothing (e.g. all special chars),
    # we cannot generate a safe suggestion — skip gracefully.
    if [ "$_suggested" = ".test" ]; then
        warn "Could not derive a safe domain from project name '${PROJECT_NAME}'."
        warn "Register manually later: bin/semitexa dns:add <name>.test"
        return
    fi

    # ── Non-interactive / automated path ────────────────────────────────────
    # Two conditions skip the interactive prompts:
    #   1. No usable TTY  — can't ask anything
    #   2. --start passed — caller explicitly requested zero interaction
    if ! tty_available || [ "$AUTO_START" -eq 1 ]; then
        if [ "$AUTO_START" -eq 1 ]; then
            info "Auto mode (--start): registering default domain '${_suggested}'."
            LOCAL_DOMAIN="$_suggested"
            register_local_domain "$LOCAL_DOMAIN"
        else
            info "Local domain setup skipped (no TTY)."
            info "Register later: cd ${PROJECT_NAME} && bin/semitexa dns:add <name>.test"
        fi
        return
    fi

    # ── Interactive path ──────────────────────────────────────────────────────
    printf "\n%s  Local domain setup%s\n" "$C_BOLD" "$C_RESET"
    printf "  Would you like to register a local .test domain for this project?\n"
    printf "  %sDefault: %s%s%s  [Y/n]: " "$C_CYAN" "$C_BOLD" "$_suggested" "$C_RESET"
    read -r _confirm </dev/tty

    case "$_confirm" in
        n|N|no|No|NO)
            info "Local domain registration skipped."
            info "Register later: cd ${PROJECT_NAME} && bin/semitexa dns:add <name>.test"
            return ;;
    esac

    printf "  Enter domain name (or press Enter to use %s%s%s): " \
        "$C_BOLD" "$_suggested" "$C_RESET"
    read -r _input </dev/tty

    if [ -z "$_input" ]; then
        LOCAL_DOMAIN="$_suggested"
    else
        _input_lower="$(printf "%s" "$_input" | tr 'A-Z' 'a-z')"

        # Determine the label to sanitize based on what the user provided:
        #   *.test     → strip the suffix, sanitize the label, re-attach .test
        #   *.anything → warn about wrong TLD, strip it, sanitize, attach .test
        #   no dot     → treat whole input as the label, attach .test
        case "$_input_lower" in
            *.test)
                _label="${_input_lower%.test}"
                ;;
            *.*)
                _tld="${_input_lower##*.}"
                warn "'.${_tld}' is not allowed — only .test is supported for local development safety."
                warn "Automatically replacing with .test."
                _label="${_input_lower%.*}"
                ;;
            *)
                _label="$_input_lower"
                ;;
        esac

        _label="$(sanitize_for_domain "$_label")"
        LOCAL_DOMAIN="${_label}.test"

        # Inform the user if their input was altered during sanitization
        if [ "$LOCAL_DOMAIN" != "$_input_lower" ]; then
            info "Sanitized to: ${LOCAL_DOMAIN}"
        fi
    fi

    # Final validation — catches edge cases that sanitization alone cannot
    # guarantee (e.g. input was entirely special characters, leaving empty label)
    if ! validate_local_domain "$LOCAL_DOMAIN"; then
        error "Could not produce a valid .test domain from your input."
        error "Requirements: [a-z0-9-] only, no leading/trailing hyphens, 1–63 chars before .test"
        LOCAL_DOMAIN=""
        info "Skipping. Register later: cd ${PROJECT_NAME} && bin/semitexa dns:add <name>.test"
        return
    fi

    success "Domain confirmed: ${LOCAL_DOMAIN}"
    register_local_domain "$LOCAL_DOMAIN"
}

# ── Demo package ─────────────────────────────────────────────────────────────
# Semitexa Demo is a standalone Composer package (semitexa/demo) that ships
# working example code for developers to explore, run, and copy from.
# It installs into vendor/ like any other package and is removable at any time:
#   cd <project> && composer remove semitexa/demo

ask_install_demo() {
    # Non-interactive without --start: silently skip (can't prompt).
    if ! tty_available && [ "$AUTO_START" -ne 1 ]; then
        return
    fi
    # Fully automated (--start): skip without prompting.
    if [ "$AUTO_START" -eq 1 ]; then
        info "Auto mode: skipping demo. Add it later: cd ${PROJECT_NAME} && bin/semitexa demo:install"
        return
    fi

    printf "\n"
    printf "%s  ╭──────────────────────────────────────────────────────────────╮%s\n" "$C_CYAN$C_BOLD" "$C_RESET"
    printf "%s  │   First time with Semitexa? Explore it with the Demo.        │%s\n" "$C_CYAN$C_BOLD" "$C_RESET"
    printf "%s  ╰──────────────────────────────────────────────────────────────╯%s\n" "$C_CYAN$C_BOLD" "$C_RESET"
    printf "\n"
    printf "  %sSemitexa Demo%s is a Composer package with working example code\n" "$C_BOLD" "$C_RESET"
    printf "  you can read, run, and copy from right away:\n"
    printf "\n"
    printf "    %s•%s REST API endpoints with typed payloads and handlers\n"    "$C_GREEN" "$C_RESET"
    printf "    %s•%s Authentication flow  (login, token refresh, logout)\n"    "$C_GREEN" "$C_RESET"
    printf "    %s•%s Event dispatching and async background jobs\n"            "$C_GREEN" "$C_RESET"
    printf "    %s•%s Module structure and DI wiring you can copy-paste\n"      "$C_GREEN" "$C_RESET"
    printf "\n"
    printf "  Installs as a regular package in %svendor/%s — remove it anytime:\n" "$C_BOLD" "$C_RESET"
    printf "  %scomposer remove semitexa/demo%s\n" "$C_CYAN" "$C_RESET"
    printf "\n"

    printf "  %sInstall Semitexa Demo?%s [y/N]: " "$C_BOLD" "$C_RESET"
    read -r _demo_ans </dev/tty

    case "$_demo_ans" in
        y|Y|yes|Yes|YES)
            install_demo
            ;;
        *)
            info "Skipped. You can add it later from inside your project:"
            info "  cd ${PROJECT_NAME} && bin/semitexa demo:install"
            printf "\n"
            ;;
    esac
}

install_demo() {
    info "Installing Semitexa Demo..."
    info "(Pulling demo image — this may take a moment)\n"

    if docker run --rm \
            -v "$(pwd)/${PROJECT_NAME}:/app" \
            "$DEMO_IMAGE" \
            install; then

        # Fix ownership — demo image may run as root just like the installer image.
        _uid="$(id -u)"
        _gid="$(id -g)"
        _vendor_owner="$(stat -c '%u' "${PROJECT_NAME}/vendor" 2>/dev/null \
            || stat -f '%u' "${PROJECT_NAME}/vendor" 2>/dev/null \
            || echo "$_uid")"
        if [ "$_vendor_owner" != "$_uid" ]; then
            chown -R "${_uid}:${_gid}" "$PROJECT_NAME" 2>/dev/null \
            || docker run --rm -v "$(pwd):/work" alpine \
                chown -R "${_uid}:${_gid}" "/work/${PROJECT_NAME}" 2>/dev/null \
            || warn "Could not fix demo file ownership. Run: sudo chown -R $(id -un):$(id -gn) ./${PROJECT_NAME}"
        fi

        printf "\n"
        success "Semitexa Demo installed (semitexa/demo)."
        info "Start the server and explore the demo routes to see it in action."
        info "Remove when done: cd ${PROJECT_NAME} && composer remove semitexa/demo"
        printf "\n"
    else
        warn "Demo installation failed — the image may not be available yet."
        warn "Try again later: cd ${PROJECT_NAME} && bin/semitexa demo:install"
        printf "\n"
    fi
}

# ── Print next steps ─────────────────────────────────────────────────────────
print_next_steps() {
    # Port is read from .env via get_swoole_port() — reflects any custom value.
    _port="$(get_swoole_port)"

    printf "\n"
    printf "%s╔══════════════════════════════════════════════════════════════╗%s\n" "$C_GREEN$C_BOLD" "$C_RESET"
    printf "%s║   Installation complete!                                     ║%s\n" "$C_GREEN$C_BOLD" "$C_RESET"
    printf "%s╚══════════════════════════════════════════════════════════════╝%s\n" "$C_GREEN$C_BOLD" "$C_RESET"
    printf "\n"
    printf "  %sProject created in:%s  ./%s\n"            "$C_BOLD" "$C_RESET" "$PROJECT_NAME"
    printf "\n"
    printf "  %sNext steps:%s\n"                          "$C_BOLD" "$C_RESET"
    printf "    cd %s\n"                                  "$PROJECT_NAME"
    printf "    \$EDITOR .env                   %s# configure ports, DB, etc%s\n" "$C_YELLOW" "$C_RESET"
    printf "    bin/semitexa server:start       %s# start Swoole + Docker%s\n"    "$C_YELLOW" "$C_RESET"
    printf "\n"
    printf "  %sOnce running:%s\n"                        "$C_BOLD" "$C_RESET"
    printf "    http://localhost:%s             %s# app%s\n"   "$_port" "$C_YELLOW" "$C_RESET"
    if [ -n "$LOCAL_DOMAIN" ]; then
        printf "    http://%s          %s# local domain%s\n"  "$LOCAL_DOMAIN" "$C_YELLOW" "$C_RESET"
    fi
    printf "    bin/semitexa list              %s# all CLI commands%s\n"           "$C_YELLOW" "$C_RESET"
    printf "    docker compose logs -f         %s# live logs%s\n"                 "$C_YELLOW" "$C_RESET"
    printf "\n"
    printf "  %sDocs:%s https://semitexa.com/docs\n"      "$C_BOLD" "$C_RESET"
    printf "\n"
}

# ── Main ─────────────────────────────────────────────────────────────────────
# STEP ORDER — do not skip steps when recovering from a partial failure:
#   1. check_docker_permissions — Linux only: ensure user is in docker group
#      check_docker             — abort before any writes if environment is wrong
#   2. ask/validate name        — resolve name before touching the filesystem
#   3. run_create_project       — Docker-based scaffold + fix file ownership
#   4. setup_env                — .env must exist before server:start reads it
#   5. make_bin_executable      — chmod must run before server:start calls the bin
#   6. ask_local_domain         — optional; requires bin to be executable
#   7. ask_install_demo         — optional; offers working example code
#   8. check_port_conflicts     — guard before binding a network port
#   9. start_server             — only when env + permissions + port are confirmed
#
# RECOVERING FROM A PARTIAL FAILURE:
#   The cleanup trap removes the partial directory on abort. Just re-run the
#   full installer. If you prefer to resume manually, execute the individual
#   function bodies directly — do NOT re-run the full script while the
#   directory exists, as validate_project_name will block you.
main() {
    banner

    step "[1/7] Checking prerequisites..."
    # check_docker_permissions must run before check_docker: it distinguishes
    # "permission denied" (fixable by adding to docker group) from "daemon not
    # running" and offers an interactive resolution with sudo + optional re-exec.
    check_docker_permissions "$@"
    check_docker

    step "[2/7] Project name..."
    ask_project_name
    validate_project_name
    info "Creating project: ${C_BOLD}${PROJECT_NAME}${C_RESET}"

    step "[3/7] Installing Semitexa Ultimate..."
    # Arm the cleanup trap. From this point onward, any unexpected exit will
    # remove the partial project directory so the user can re-run cleanly.
    _CLEANUP_PROJECT="$PROJECT_NAME"
    run_create_project

    # Explicit post-install guard: the Docker-based scaffold can still fail to
    # materialize the project directory fully (e.g. disk full, volume mount
    # permission issue). Catching this here prevents cryptic downstream errors.
    if [ ! -f "$PROJECT_NAME/bin/semitexa" ] || [ ! -f "$PROJECT_NAME/server.php" ]; then
        error "Project scaffold in '${PROJECT_NAME}' is incomplete."
        error "Possible causes: disk full, Docker volume permission error, or installer image failure."
        exit 1
    fi

    step "[4/7] Environment setup..."
    setup_env
    make_bin_executable

    step "[5/7] Local domain (optional)..."
    # ask_local_domain must run AFTER make_bin_executable so that
    # register_local_domain can call bin/semitexa dns:add.
    # It must also run BEFORE print_next_steps so LOCAL_DOMAIN is available
    # for display. Called directly (not in a subshell) — see LOCAL_DOMAIN note.
    ask_local_domain

    step "[6/7] Semitexa Demo (optional)..."
    # Offer working example code the developer can explore immediately.
    # Must run AFTER make_bin_executable (demo:remove uses bin/semitexa).
    ask_install_demo

    step "[7/7] Done!"
    # Disarm the cleanup trap — the install is complete and the directory is
    # intentional. From here, no automatic removal should happen on exit.
    _CLEANUP_PROJECT=""
    print_next_steps

    if ask_start_server; then
        step "Starting server..."
        # check_port_conflicts MUST be called directly (not via $()) so that
        # its mutation of SKIP_START propagates to this scope.
        check_port_conflicts
        if [ "$SKIP_START" -eq 1 ]; then
            warn "Server start skipped due to port conflict."
            warn "Resolve the conflict, then: cd ${PROJECT_NAME} && bin/semitexa server:start"
        else
            start_server
            if [ -n "$LOCAL_DOMAIN" ]; then
                printf "\n"
                success "Your app is also available at: http://${LOCAL_DOMAIN}"
            fi
        fi
    else
        info "Run 'bin/semitexa server:start' when you're ready."
    fi
}

main "$@"
