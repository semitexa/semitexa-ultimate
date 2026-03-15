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
# ─────────────────────────────────────────────────────────────────────────────
set -e

SEMITEXA_VERSION="semitexa/ultimate"
COMPOSER_IMAGE="composer:2"
PACKAGIST_URL="https://packagist.org/packages/semitexa/ultimate.json"

# ── Colour helpers ───────────────────────────────────────────────────────────
if [ -t 1 ] && command -v tput >/dev/null 2>&1; then
    C_RESET="$(tput sgr0 2>/dev/null   || true)"
    C_GREEN="$(tput setaf 2 2>/dev/null || true)"
    C_YELLOW="$(tput setaf 3 2>/dev/null || true)"
    C_RED="$(tput setaf 1 2>/dev/null   || true)"
    C_CYAN="$(tput setaf 6 2>/dev/null  || true)"
    C_BOLD="$(tput bold 2>/dev/null     || true)"
else
    C_RESET="" C_GREEN="" C_YELLOW="" C_RED="" C_CYAN="" C_BOLD=""
fi

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

# ── Argument parsing ─────────────────────────────────────────────────────────
PROJECT_NAME=""
AUTO_START=0

for _arg in "$@"; do
    case "$_arg" in
        --start) AUTO_START=1 ;;
        --*)     warn "Unknown option: $_arg" ;;
        *)       [ -z "$PROJECT_NAME" ] && PROJECT_NAME="$_arg" ;;
    esac
done

# ── Prerequisites ────────────────────────────────────────────────────────────
check_docker() {
    if ! command -v docker >/dev/null 2>&1; then
        error "Docker is not installed."
        printf "\n  Install Docker Desktop: %shttps://docs.docker.com/get-docker/%s\n\n" "$C_CYAN" "$C_RESET"
        exit 1
    fi

    if ! docker info >/dev/null 2>&1; then
        error "Docker daemon is not running. Start Docker and try again."
        exit 1
    fi

    # Compose v2: `docker compose` (plugin) — required
    if ! docker compose version >/dev/null 2>&1; then
        error "Docker Compose v2 is required ('docker compose' not found)."
        printf "\n  Upgrade to Docker Desktop 3.6+ or install the Compose plugin:\n"
        printf "  %shttps://docs.docker.com/compose/install/%s\n\n" "$C_CYAN" "$C_RESET"
        exit 1
    fi

    success "Docker $(docker --version | awk '{print $3}' | tr -d ',')" \
        "/ Compose $(docker compose version --short 2>/dev/null || echo 'v2')"
}

# ── Project name ─────────────────────────────────────────────────────────────
ask_project_name() {
    if [ -n "$PROJECT_NAME" ]; then
        return
    fi

    # stdin may be the pipe (curl | bash) — use /dev/tty for interactive input
    if [ -t 0 ] || [ -e /dev/tty ]; then
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
    case "$PROJECT_NAME" in
        # Must start with letter/digit, only alphanum + hyphen/underscore
        ''|*[!a-zA-Z0-9._-]*)
            error "Invalid project name: '$PROJECT_NAME'. Use only letters, digits, hyphens, underscores, dots."
            exit 1 ;;
    esac

    if [ -e "$PROJECT_NAME" ]; then
        error "A file or directory named '$PROJECT_NAME' already exists."
        printf "\n  Remove it first, or choose a different name.\n\n"
        exit 1
    fi
}

# ── Fetch latest published version from Packagist ────────────────────────────
fetch_latest_version() {
    _version=""
    if command -v curl >/dev/null 2>&1; then
        _version="$(curl -fsSL "$PACKAGIST_URL" 2>/dev/null \
            | grep -o '"latest-stable":"[^"]*"' \
            | head -1 \
            | sed 's/"latest-stable":"//;s/"//')"
    elif command -v wget >/dev/null 2>&1; then
        _version="$(wget -qO- "$PACKAGIST_URL" 2>/dev/null \
            | grep -o '"latest-stable":"[^"]*"' \
            | head -1 \
            | sed 's/"latest-stable":"//;s/"//')"
    fi
    printf "%s" "${_version:-}"
}

# ── composer create-project ──────────────────────────────────────────────────
run_create_project() {
    _package="$SEMITEXA_VERSION"

    # Try to pin latest stable version for reproducibility
    _latest="$(fetch_latest_version)"
    if [ -n "$_latest" ]; then
        _package="semitexa/ultimate:${_latest}"
        info "Latest version: ${_latest}"
    fi

    info "Running: composer create-project ${_package} ${PROJECT_NAME}"
    info "(First run pulls the PHP + Composer Docker image — this may take a minute)\n"

    docker run --rm \
        --user "$(id -u):$(id -g)" \
        -v "$(pwd):/app" \
        -w /app \
        "$COMPOSER_IMAGE" \
        create-project "$_package" "$PROJECT_NAME" \
        --no-interaction \
        --prefer-dist \
        --no-progress
}

# ── Post-install setup ───────────────────────────────────────────────────────
setup_env() {
    _env_file="$PROJECT_NAME/.env"
    _example="$PROJECT_NAME/.env.example"

    if [ ! -f "$_env_file" ] && [ -f "$_example" ]; then
        cp "$_example" "$_env_file"
        success ".env created from .env.example"
        info "Edit $PROJECT_NAME/.env before starting if you need custom ports or DB settings."
    elif [ -f "$_env_file" ]; then
        info ".env already exists — not overwritten."
    fi
}

make_bin_executable() {
    _bin="$PROJECT_NAME/bin/semitexa"
    if [ -f "$_bin" ]; then
        chmod +x "$_bin"
    fi
}

# ── Optional: start server ───────────────────────────────────────────────────
ask_start_server() {
    if [ "$AUTO_START" -eq 1 ]; then
        return 0
    fi

    printf "\n%s  Start the server now?%s [Y/n]: " "$C_BOLD" "$C_RESET"
    _ans="Y"
    if [ -t 0 ] || [ -e /dev/tty ]; then
        read -r _ans </dev/tty
    fi

    case "$_ans" in
        n|N|no|No|NO) return 1 ;;
        *)             return 0 ;;
    esac
}

start_server() {
    cd "$PROJECT_NAME"
    sh bin/semitexa server:start
    cd ..
}

# ── Print next steps ─────────────────────────────────────────────────────────
print_next_steps() {
    _port="9502"
    if [ -f "$PROJECT_NAME/.env" ]; then
        _port="$(grep -E '^SWOOLE_PORT=' "$PROJECT_NAME/.env" 2>/dev/null | cut -d= -f2 | tr -d '"' || echo 9502)"
        _port="${_port:-9502}"
    fi

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
    printf "    bin/semitexa list              %s# all CLI commands%s\n"           "$C_YELLOW" "$C_RESET"
    printf "    docker compose logs -f         %s# live logs%s\n"                 "$C_YELLOW" "$C_RESET"
    printf "\n"
    printf "  %sDocs:%s https://semitexa.com/docs\n"      "$C_BOLD" "$C_RESET"
    printf "\n"
}

# ── Main ─────────────────────────────────────────────────────────────────────
main() {
    banner

    step "[1/5] Checking prerequisites..."
    check_docker

    step "[2/5] Project name..."
    ask_project_name
    validate_project_name
    info "Creating project: ${C_BOLD}${PROJECT_NAME}${C_RESET}"

    step "[3/5] Installing Semitexa Ultimate..."
    run_create_project

    step "[4/5] Environment setup..."
    setup_env
    make_bin_executable

    step "[5/5] Done!"
    print_next_steps

    if ask_start_server; then
        step "Starting server..."
        start_server
    else
        info "Run 'bin/semitexa server:start' when you're ready."
    fi
}

main "$@"
