#!/usr/bin/env bash
set -euo pipefail

# ╔══════════════════════════════════════════════════════════════════╗
# ║  Storno.ro — Zero-Downtime Deploy Script                       ║
# ╚══════════════════════════════════════════════════════════════════╝
#
# Usage:
#   First run:          ./deploy.sh --init
#   Deploy everything:  ./deploy.sh
#   Deploy one service: ./deploy.sh backend
#   Deploy multiple:    ./deploy.sh backend frontend
#   Build from source:  ./deploy.sh --build backend frontend
#   Quick PHP deploy:   ./deploy.sh --quick (git pull + cache clear, no rebuild)
#   Run migrations:     ./deploy.sh --migrate
#   Rollback a service: ./deploy.sh --rollback backend
#
# Services: backend, frontend, docs
#
# The --build flag forces building images from source (no GitHub/ghcr.io needed).
# Without --build, it tries to pull pre-built images first, falling back to build.
#
# How it works (blue-green per service):
#   1. Pull/build new image
#   2. Start a backup container on alt port (green slot)
#   3. Wait for backup healthcheck
#   4. Stop main container (Nginx auto-failovers to backup)
#   5. Restart main container with new image
#   6. Wait for main healthcheck
#   7. Stop backup container
#
# Nginx upstreams are configured with primary + backup servers,
# so traffic seamlessly shifts during the swap.

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
NGINX_DIR="/etc/nginx/sites-enabled"

ALL_SERVICES=(backend frontend docs)

# Service config: main_port:backup_port:internal_port:health_path
declare -A SVC_CONFIG=(
    [backend]="8900:8910:80:/health"
    [frontend]="8901:8911:3000:/"
    [docs]="8903:8913:3000:/"
)

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

# ── Check prerequisites ───────────────────────────────────────────
command -v docker >/dev/null 2>&1 || error "Docker is not installed"
command -v nginx >/dev/null 2>&1 || error "Nginx is not installed"

# ── Parse arguments ──────────────────────────────────────────────
INIT=false
MIGRATE=false
ROLLBACK=false
BUILD_FROM_SOURCE=false
QUICK=false
SERVICES=()

for arg in "$@"; do
    case "$arg" in
        --init)       INIT=true ;;
        --migrate)    MIGRATE=true ;;
        --rollback)   ROLLBACK=true ;;
        --build|--from-source) BUILD_FROM_SOURCE=true ;;
        --quick)      QUICK=true ;;
        *)            SERVICES+=("$arg") ;;
    esac
done

# ── Ensure .env exists ───────────────────────────────────────────
# Single .env at the project root (fall back to deploy/.env for backwards compat)
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
if [ -f "$PROJECT_ROOT/.env" ]; then
    ENV_FILE="$PROJECT_ROOT/.env"
elif [ -f "$SCRIPT_DIR/.env" ]; then
    ENV_FILE="$SCRIPT_DIR/.env"
else
    error "No .env file found. Create .env in the project root and configure it."
fi

cd "$SCRIPT_DIR"

# Wrap docker compose to always use the resolved .env file
docker_compose() {
    docker compose --env-file "$ENV_FILE" "$@"
}

# Docker network name = <project_name>_storno (project name = directory name)
DOCKER_NETWORK="$(basename "$SCRIPT_DIR")_storno"


# ── Nginx configs (only install new ones, don't overwrite certbot-managed) ──
info "Checking Nginx configs..."
NGINX_CHANGED=false
for conf in "$SCRIPT_DIR/nginx/"*.conf; do
    name=$(basename "$conf")
    if [ ! -f "$NGINX_DIR/$name" ]; then
        step "Installing new config: $name"
        cp "$conf" "$NGINX_DIR/$name"
        NGINX_CHANGED=true
    fi
done
if [ "$NGINX_CHANGED" = true ]; then
    nginx -t || error "Nginx config test failed"
    systemctl reload nginx
    info "Nginx reloaded"
fi

# ── Migrations only ──────────────────────────────────────────────
if [ "$MIGRATE" = true ] && [ ${#SERVICES[@]} -eq 0 ]; then
    info "Running migrations..."
    docker_compose exec -T backend php bin/console doctrine:migrations:migrate --no-interaction
    info "Migrations complete"
    exit 0
fi

# ── Quick deploy (git pull + cache clear, no rebuild) ────────────
if [ "$QUICK" = true ]; then
    info "Quick deploy — pulling latest code..."
    cd "$PROJECT_ROOT"
    git pull

    info "Clearing Symfony cache..."
    cd "$SCRIPT_DIR"
    docker_compose exec -T backend php bin/console cache:clear --env=prod

    info "Running migrations..."
    docker_compose exec -T backend php bin/console doctrine:migrations:migrate --no-interaction || warn "No pending migrations"

    info "Quick deploy complete!"
    exit 0
fi

# Local directories for all services (relative to project root)
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
declare -A SVC_DIR=(
    [backend]="$PROJECT_ROOT/backend"
    [frontend]="$PROJECT_ROOT/frontend"
    [docs]="$PROJECT_ROOT/docs"
)

# ── Helper: build from local directory ───────────────────────────
build_local() {
    local svc="$1"
    local dir="${SVC_DIR[$svc]:-}"
    [ -z "$dir" ] || [ ! -d "$dir" ] && error "Directory not found for $svc: $dir"
    [ ! -f "$dir/Dockerfile" ] && error "No Dockerfile in $dir"

    local image
    image=$(docker_compose config --images 2>/dev/null | grep -i "$svc" | head -1)
    [ -z "$image" ] && image="ghcr.io/stornoro/${svc}:latest"

    step "Building $svc from $dir..."
    docker build -t "$image" "$dir"
}

# ── Helper: pull or build ────────────────────────────────────────
pull_or_build() {
    local svc="$1"
    if [ "$BUILD_FROM_SOURCE" = true ]; then
        build_local "$svc"
        return 0
    fi
    if docker_compose pull "$svc" 2>/dev/null; then
        return 0
    fi
    step "Pull failed for $svc, building locally..."
    build_local "$svc"
}

# ── Helper: wait for health ──────────────────────────────────────
wait_for_health() {
    local port="$1"
    local path="$2"
    local max_wait="${3:-60}"
    local elapsed=0

    while [ $elapsed -lt $max_wait ]; do
        if curl -sf "http://127.0.0.1:${port}${path}" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
        elapsed=$((elapsed + 1))
    done
    return 1
}

# ── Helper: save current image for rollback ──────────────────────
save_rollback_image() {
    local svc="$1"
    local current_id
    current_id=$(docker_compose ps -q "$svc" 2>/dev/null || true)
    if [ -n "$current_id" ]; then
        local current_image
        current_image=$(docker inspect "$current_id" --format '{{.Config.Image}}' 2>/dev/null || true)
        if [ -n "$current_image" ]; then
            echo "$current_image" > "$SCRIPT_DIR/.rollback-${svc}"
            step "Saved rollback image: $current_image"
        fi
    fi
}

# ── Helper: get env vars from running container ─────────────────
get_container_env_file() {
    local svc="$1"
    local env_file="/tmp/storno-${svc}-env"
    local container_id
    container_id=$(docker_compose ps -q "$svc" 2>/dev/null || true)
    if [ -n "$container_id" ]; then
        docker inspect "$container_id" --format '{{range .Config.Env}}{{println .}}{{end}}' > "$env_file"
    fi
    echo "$env_file"
}

# ── Helper: get volume mounts from running container ─────────────
get_container_volumes() {
    local container_id="$1"
    docker inspect "$container_id" --format '{{range .Mounts}}{{if eq .Type "volume"}}-v {{.Name}}:{{.Destination}} {{end}}{{end}}' 2>/dev/null || true
}

# ── Blue-green deploy ────────────────────────────────────────────
deploy_service() {
    local svc="$1"
    IFS=: read -r main_port backup_port internal_port health_path <<< "${SVC_CONFIG[$svc]}"

    info "Deploying $svc (zero-downtime)..."

    # Save current image for rollback BEFORE building (build overwrites the tag)
    save_rollback_image "$svc"

    # Pull/build new image
    step "Pulling/building image..."
    pull_or_build "$svc"

    # Get the new image name
    local new_image
    new_image=$(docker_compose config --images 2>/dev/null | grep -i "$svc" | head -1)
    if [ -z "$new_image" ]; then
        warn "Could not determine image for $svc, falling back to direct restart"
        docker_compose up -d --no-deps --force-recreate "$svc"
        return
    fi

    local backup_name="storno-${svc}-green"
    local current_id
    current_id=$(docker_compose ps -q "$svc" 2>/dev/null || true)

    # If no running container, just start normally
    if [ -z "$current_id" ]; then
        step "No running container, starting fresh..."

        # Kill anything holding the main port
        for stale in $(docker ps -q --filter "publish=${main_port}" 2>/dev/null); do
            warn "Killing stale container $(docker inspect --format '{{.Name}}' "$stale") holding port $main_port"
            docker stop "$stale" 2>/dev/null || true
            docker rm -f "$stale" 2>/dev/null || true
        done

        docker_compose up -d --no-deps "$svc"
        if ! wait_for_health "$main_port" "$health_path" 60; then
            warn "$svc healthcheck timed out — check logs: docker compose logs $svc"
        fi
        run_post_deploy "$svc"
        info "$svc deployed"
        return
    fi

    # ── Step 1: Start backup container on alt port ───────────────
    step "Starting backup container on port $backup_port..."

    # Clean up any leftover backup container
    docker stop "$backup_name" 2>/dev/null || true
    docker rm "$backup_name" 2>/dev/null || true

    # Get env and volumes from current container
    local env_file
    env_file=$(get_container_env_file "$svc")
    local volumes
    volumes=$(get_container_volumes "$current_id")

    # Start backup container
    # shellcheck disable=SC2086
    docker run -d \
        --name "$backup_name" \
        --network "$DOCKER_NETWORK" \
        --env-file "$env_file" \
        $volumes \
        -p "127.0.0.1:${backup_port}:${internal_port}" \
        --restart no \
        "$new_image" || {
            warn "Failed to start backup container, falling back to direct restart"
            docker_compose up -d --no-deps --force-recreate "$svc"
            wait_for_health "$main_port" "$health_path" 60 || true
            run_post_deploy "$svc"
            info "$svc deployed (with brief interruption)"
            rm -f "$env_file"
            return
        }

    # ── Step 2: Wait for backup to be healthy ────────────────────
    step "Waiting for backup healthcheck on port $backup_port..."
    if ! wait_for_health "$backup_port" "$health_path" 60; then
        warn "Backup container failed healthcheck, falling back to direct restart"
        docker stop "$backup_name" 2>/dev/null || true
        docker rm "$backup_name" 2>/dev/null || true
        docker_compose up -d --no-deps --force-recreate "$svc"
        wait_for_health "$main_port" "$health_path" 60 || true
        run_post_deploy "$svc"
        info "$svc deployed (with brief interruption)"
        rm -f "$env_file"
        return
    fi

    # ── Step 3: Stop main container (Nginx fails over to backup) ─
    step "Switching traffic to backup..."
    docker_compose stop "$svc"

    # ── Step 4: Restart main with new image ──────────────────────
    step "Starting main container with new image..."
    docker_compose up -d --no-deps --force-recreate "$svc"

    # ── Step 5: Wait for main to be healthy ──────────────────────
    step "Waiting for main healthcheck on port $main_port..."
    if ! wait_for_health "$main_port" "$health_path" 60; then
        warn "$svc main container healthcheck timed out — backup still serving traffic"
    fi

    # ── Step 6: Stop backup container ────────────────────────────
    step "Removing backup container..."
    docker stop "$backup_name" 2>/dev/null || true
    docker rm "$backup_name" 2>/dev/null || true
    rm -f "$env_file"

    # ── Post-deploy tasks ────────────────────────────────────────
    run_post_deploy "$svc"

    info "$svc deployed (zero-downtime)"
}

# ── Post-deploy tasks (migrations, etc.) ─────────────────────────
run_post_deploy() {
    local svc="$1"
    if [ "$svc" = "backend" ]; then
        step "Running migrations..."
        docker_compose exec -T backend php bin/console doctrine:migrations:migrate --no-interaction || warn "Migrations skipped (none pending)"
    fi
}

# ── Rollback ─────────────────────────────────────────────────────
rollback_service() {
    local svc="$1"
    local rollback_file="$SCRIPT_DIR/.rollback-${svc}"

    if [ ! -f "$rollback_file" ]; then
        error "No rollback image saved for $svc"
    fi

    local old_image
    old_image=$(cat "$rollback_file")
    info "Rolling back $svc to: $old_image"

    IFS=: read -r main_port _ _ health_path <<< "${SVC_CONFIG[$svc]}"

    docker_compose stop "$svc"
    docker_compose pull "$svc" 2>/dev/null || true

    # Override image and restart
    IMAGE="$old_image" docker_compose up -d --no-deps "$svc"

    if wait_for_health "$main_port" "$health_path" 60; then
        info "$svc rolled back successfully"
    else
        warn "$svc rollback healthcheck timed out — check logs: docker compose logs $svc"
    fi
}

# ── Handle rollback ──────────────────────────────────────────────
if [ "$ROLLBACK" = true ]; then
    if [ ${#SERVICES[@]} -eq 0 ]; then
        error "Specify which service to rollback: ./deploy.sh --rollback backend"
    fi
    for svc in "${SERVICES[@]}"; do
        rollback_service "$svc"
    done
    exit 0
fi

# ── Deploy specific services ────────────────────────────────────
if [ ${#SERVICES[@]} -gt 0 ]; then
    for svc in "${SERVICES[@]}"; do
        deploy_service "$svc"
    done

    docker image prune -f >/dev/null 2>&1
    info "Deploy complete!"
    exit 0
fi

# ── Deploy all ───────────────────────────────────────────────────
info "Deploying all services..."

# Pull or build all images first
step "Pulling/building all images..."
for svc in "${ALL_SERVICES[@]}"; do
    pull_or_build "$svc"
done

# Ensure infrastructure is running (kill stale containers holding ports)
declare -A INFRA_PORTS=([redis]=6379 [centrifugo]="${CENTRIFUGO_PORT:-8445}")

for infra in redis centrifugo; do
    if ! docker_compose ps "$infra" --format '{{.Status}}' 2>/dev/null | grep -q "Up"; then
        step "Starting $infra..."

        # Remove stale compose container
        docker_compose rm -fsv "$infra" 2>/dev/null || true

        # Kill ANY container holding the port (from other compose projects, orphans, etc.)
        local_port="${INFRA_PORTS[$infra]}"
        for stale in $(docker ps -q --filter "publish=${local_port}" 2>/dev/null); do
            warn "Killing stale container $(docker inspect --format '{{.Name}}' "$stale") holding port $local_port"
            docker stop "$stale" 2>/dev/null || true
            docker rm -f "$stale" 2>/dev/null || true
        done

        docker_compose up -d "$infra"
    fi
done

# Wait for redis
step "Waiting for Redis..."
redis_retries=10
while [ $redis_retries -gt 0 ]; do
    if docker_compose exec -T redis redis-cli ping >/dev/null 2>&1; then
        break
    fi
    sleep 1
    redis_retries=$((redis_retries - 1))
done

# Deploy each service with zero-downtime
for svc in "${ALL_SERVICES[@]}"; do
    deploy_service "$svc"
done

# ── Cleanup ────────────────────────────────────────────────────────
docker image prune -f >/dev/null 2>&1

info "Deploy complete!"
echo ""
echo "  app.storno.ro        → Frontend + API"
echo "  api.storno.ro        → API (direct)"
echo "  docs.storno.ro       → Documentation"
echo ""

# ── First-run initialization ───────────────────────────────────────
if [ "$INIT" = true ]; then
    info "Running first-time initialization..."

    # Generate JWT keys
    docker_compose exec -T backend php bin/console lexik:jwt:generate-keypair --skip-if-exists
    info "JWT keys generated"

    # SSL certificates
    if command -v certbot >/dev/null 2>&1; then
        info "Setting up SSL certificates..."
        certbot --nginx \
            -d app.storno.ro \
            -d api.storno.ro \
            -d docs.storno.ro \
            --non-interactive --agree-tos --email admin@storno.ro
        info "SSL certificates installed"
    else
        warn "Certbot not found. Install with: apt install certbot python3-certbot-nginx"
    fi
fi
