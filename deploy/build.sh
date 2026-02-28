#!/usr/bin/env bash
set -euo pipefail

# ╔══════════════════════════════════════════════════════════════════╗
# ║  Storno.ro — Local Build & Push Script                         ║
# ╚══════════════════════════════════════════════════════════════════╝
#
# Build Docker images locally and push to ghcr.io.
# Works for all services, including individual repos.
#
# Usage:
#   ./build.sh docs             Build & push docs
#   ./build.sh backend docs     Build & push multiple services
#   ./build.sh --all            Build & push everything
#   ./build.sh --deploy docs    Build, push, then deploy on server
#   ./build.sh --list           Show available services
#

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
REGISTRY="ghcr.io/stornoro"

# Service → local directory mapping
declare -A SVC_DIR=(
    [backend]="$PROJECT_ROOT/backend"
    [frontend]="$PROJECT_ROOT/frontend"
    [docs]="$PROJECT_ROOT/docs"
)

ALL_SERVICES=(backend frontend docs)

# ── Colors ─────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

info()  { echo -e "${GREEN}[INFO]${NC} $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }
step()  { echo -e "${CYAN}  →${NC} $1"; }

# ── Parse arguments ───────────────────────────────────────────────
DEPLOY=false
SERVICES=()

for arg in "$@"; do
    case "$arg" in
        --deploy)  DEPLOY=true ;;
        --all)     SERVICES=("${ALL_SERVICES[@]}") ;;
        --list)
            echo "Available services:"
            for svc in "${ALL_SERVICES[@]}"; do
                echo "  $svc  →  ${SVC_DIR[$svc]}"
            done
            exit 0
            ;;
        --help|-h)
            echo "Usage: ./build.sh [--deploy] [--all] <service ...>"
            echo ""
            echo "  ./build.sh docs               Build & push docs"
            echo "  ./build.sh backend docs        Build & push multiple"
            echo "  ./build.sh --all               Build & push all services"
            echo "  ./build.sh --deploy docs       Build, push, then deploy"
            echo "  ./build.sh --list              Show available services"
            exit 0
            ;;
        *)  SERVICES+=("$arg") ;;
    esac
done

[ ${#SERVICES[@]} -eq 0 ] && error "No services specified. Use --all or provide service names. Run --list to see options."

# ── Ensure Docker is available ────────────────────────────────────
command -v docker >/dev/null 2>&1 || error "Docker is not installed"

# ── Ensure logged in to ghcr.io ──────────────────────────────────
if ! docker pull "$REGISTRY/backend" --quiet >/dev/null 2>&1; then
    step "Logging in to ghcr.io..."
    echo "Run: echo \$GITHUB_TOKEN | docker login ghcr.io -u USERNAME --password-stdin"
fi

# ── Build & push ─────────────────────────────────────────────────
for svc in "${SERVICES[@]}"; do
    dir="${SVC_DIR[$svc]:-}"
    [ -z "$dir" ] && error "Unknown service: $svc (run --list to see options)"
    [ ! -d "$dir" ] && error "Directory not found: $dir"
    [ ! -f "$dir/Dockerfile" ] && error "No Dockerfile in $dir"

    IMAGE="$REGISTRY/$svc"

    info "Building $svc..."
    step "Context: $dir"

    docker build \
        -t "$IMAGE:latest" \
        -t "$IMAGE:$(date +%Y%m%d-%H%M%S)" \
        "$dir"

    info "Pushing $svc..."
    docker push "$IMAGE:latest"

    info "$svc built and pushed ✓"
    echo ""
done

# ── Deploy on server ─────────────────────────────────────────────
if [ "$DEPLOY" = true ]; then
    # Load server credentials from .env
    ENV_FILE=""
    [ -f "$PROJECT_ROOT/.env" ] && ENV_FILE="$PROJECT_ROOT/.env"
    [ -f "$SCRIPT_DIR/.env" ] && ENV_FILE="$SCRIPT_DIR/.env"

    if [ -z "$ENV_FILE" ]; then
        warn "No .env file found — skipping remote deploy"
        warn "Run on server: cd /storage/www/storno && ./deploy/deploy.sh ${SERVICES[*]}"
        exit 0
    fi

    source "$ENV_FILE"
    SERVER_HOST="${SERVER_HOST:-}"
    SERVER_PORT="${SERVER_PORT:-22}"
    SERVER_SSH_KEY="${SERVER_SSH_KEY:-}"

    if [ -z "$SERVER_HOST" ]; then
        warn "SERVER_HOST not set in .env — skipping remote deploy"
        warn "Run on server: cd /storage/www/storno && ./deploy/deploy.sh ${SERVICES[*]}"
        exit 0
    fi

    info "Deploying on server..."
    ssh -p "$SERVER_PORT" "root@$SERVER_HOST" \
        "cd /storage/www/storno && ./deploy/deploy.sh ${SERVICES[*]}"

    info "Deploy complete!"
fi

echo -e "${GREEN}Done!${NC}"
