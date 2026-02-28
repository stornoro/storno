#!/usr/bin/env bash
set -euo pipefail

# ╔══════════════════════════════════════════════════════════════════╗
# ║  Storno.ro — Self-Hosted Installer                              ║
# ║                                                                  ║
# ║  Usage:  curl -fsSL https://get.storno.ro | bash                ║
# ║      or: bash install.sh                                         ║
# ╚══════════════════════════════════════════════════════════════════╝

STORNO_DIR="${STORNO_DIR:-./storno}"
GITHUB_RAW="https://raw.githubusercontent.com/stornoro/storno/main/deploy"
MIN_DOCKER_MAJOR=24

# ── Colors ─────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

ok()    { echo -e "${GREEN}  ✔${NC}  $1"; }
info()  { echo -e "${CYAN}  →${NC}  $1"; }
warn()  { echo -e "${YELLOW}  ⚠${NC}  $1"; }
error() { echo -e "${RED}  ✘  ERROR:${NC} $1" >&2; exit 1; }
step()  { echo -e "\n${BOLD}$1${NC}"; }

# ── Portable sed -i that works on both macOS (BSD sed) and Linux (GNU sed) ──
# Usage: sedit 's/old/new/' /path/to/file
sedit() {
  local expr="$1"
  local file="$2"
  sed -i.bak "${expr}" "${file}"
  rm -f "${file}.bak"
}

# ── Banner ─────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${CYAN}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${CYAN}║          Storno.ro — Self-Hosted Installer               ║${NC}"
echo -e "${BOLD}${CYAN}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""

# ══════════════════════════════════════════════════════════════════
# 1. PREREQUISITES
# ══════════════════════════════════════════════════════════════════
step "[1/6] Checking prerequisites..."

# docker
if ! command -v docker >/dev/null 2>&1; then
  error "Docker is not installed. Install it from https://docs.docker.com/get-docker/"
fi
ok "docker found: $(docker --version)"

# curl
if ! command -v curl >/dev/null 2>&1; then
  error "curl is not installed. Install it with your package manager (apt/yum/brew install curl)."
fi
ok "curl found"

# openssl
if ! command -v openssl >/dev/null 2>&1; then
  error "openssl is not installed. Install it with your package manager (apt/yum/brew install openssl)."
fi
ok "openssl found"

# Docker Compose v2 (docker compose — not docker-compose)
if ! docker compose version >/dev/null 2>&1; then
  error "Docker Compose v2 is required ('docker compose' plugin). Found: $(docker-compose --version 2>/dev/null || echo 'none').
  Install Docker Desktop or add the Compose plugin: https://docs.docker.com/compose/install/"
fi
ok "Docker Compose v2 found: $(docker compose version --short)"

# Docker daemon is running
if ! docker info >/dev/null 2>&1; then
  error "Docker daemon is not running. Start it and try again."
fi
ok "Docker daemon is running"

# ── Recommended: Docker version >= MIN_DOCKER_MAJOR ───────────────
docker_major=$(docker version --format '{{.Server.Version}}' 2>/dev/null | cut -d. -f1 || echo "0")
if [ "${docker_major}" -lt "${MIN_DOCKER_MAJOR}" ] 2>/dev/null; then
  warn "Docker v${docker_major} detected. Docker v${MIN_DOCKER_MAJOR}+ is recommended."
fi

# ══════════════════════════════════════════════════════════════════
# 2. CREATE INSTALLATION DIRECTORY
# ══════════════════════════════════════════════════════════════════
step "[2/6] Preparing installation directory..."

if [ -d "${STORNO_DIR}" ]; then
  info "Directory '${STORNO_DIR}' already exists."
else
  mkdir -p "${STORNO_DIR}"
  ok "Created directory: ${STORNO_DIR}"
fi

# Resolve to absolute path so all subsequent commands are unambiguous
STORNO_DIR="$(cd "${STORNO_DIR}" && pwd)"

# ══════════════════════════════════════════════════════════════════
# 3. DOWNLOAD COMPOSE FILE + ENV TEMPLATE
# ══════════════════════════════════════════════════════════════════

IS_FIRST_INSTALL=false

if [ -f "${STORNO_DIR}/.env" ]; then
  echo ""
  echo -e "${YELLOW}  Existing installation detected — updating...${NC}"
  info "Skipping interactive configuration (re-using existing .env)."
  info "To reconfigure, delete '${STORNO_DIR}/.env' and re-run the installer."
else
  IS_FIRST_INSTALL=true
fi

step "[3/6] Downloading configuration files..."

# Always refresh docker-compose.yml to pick up new service definitions
if [ -f "${STORNO_DIR}/docker-compose.yml" ]; then
  info "docker-compose.yml already exists — refreshing from GitHub..."
fi
info "Fetching docker-compose.yml..."
if ! curl -fsSL "${GITHUB_RAW}/docker-compose.yml" -o "${STORNO_DIR}/docker-compose.yml"; then
  if [ -f "${STORNO_DIR}/docker-compose.yml" ]; then
    warn "Could not download latest docker-compose.yml — using existing file."
  else
    error "Failed to download docker-compose.yml from GitHub. Check your internet connection."
  fi
fi
ok "docker-compose.yml ready"

# Only download .env.example on first install; never overwrite an existing .env
if [ "${IS_FIRST_INSTALL}" = true ]; then
  info "Fetching .env.example..."
  if ! curl -fsSL "${GITHUB_RAW}/.env.example" -o "${STORNO_DIR}/.env.example"; then
    if [ -f "${STORNO_DIR}/.env.example" ]; then
      warn "Could not download latest .env.example — using existing file."
    else
      error "Failed to download .env.example from GitHub. Check your internet connection."
    fi
  fi
  ok ".env.example ready"
fi

# ══════════════════════════════════════════════════════════════════
# 4. INTERACTIVE CONFIGURATION (first install only)
# ══════════════════════════════════════════════════════════════════
if [ "${IS_FIRST_INSTALL}" = true ]; then
  step "[4/6] Configuring your installation..."
  echo ""
  echo "  Please answer the following questions."
  echo "  Press Enter to accept the default shown in [brackets]."
  echo ""

  # ── Domain ──────────────────────────────────────────────────────
  read -r -p "  Domain name (e.g. storno.example.com): " DOMAIN
  DOMAIN="${DOMAIN:-}"
  while [ -z "${DOMAIN}" ]; do
    warn "Domain is required."
    read -r -p "  Domain name: " DOMAIN
  done
  DOMAIN="${DOMAIN#https://}"   # strip accidental https://
  DOMAIN="${DOMAIN#http://}"    # strip accidental http://
  DOMAIN="${DOMAIN%/}"          # strip trailing slash
  ok "Domain: ${DOMAIN}"

  # ── Email ────────────────────────────────────────────────────────
  read -r -p "  Admin email (for Let's Encrypt / notifications): " ADMIN_EMAIL
  ADMIN_EMAIL="${ADMIN_EMAIL:-}"
  while [ -z "${ADMIN_EMAIL}" ]; do
    warn "Email is required."
    read -r -p "  Admin email: " ADMIN_EMAIL
  done
  ok "Email: ${ADMIN_EMAIL}"

  # ── License key ──────────────────────────────────────────────────
  echo ""
  echo "  License key — leave empty for Community Edition (free forever)."
  echo "  Get a key at: https://app.storno.ro/settings/billing"
  read -r -p "  License key [community]: " LICENSE_KEY
  LICENSE_KEY="${LICENSE_KEY:-}"

  if [ -z "${LICENSE_KEY}" ]; then
    ok "Edition: Community Edition (Starter features, free forever)"
    EDITION_LABEL="Community Edition (Starter features, free forever)"
  else
    KEY_MASKED="${LICENSE_KEY:0:4}****${LICENSE_KEY: -4}"
    ok "Edition: Licensed Edition (key: ${KEY_MASKED})"
    EDITION_LABEL="Licensed Edition (key: ${KEY_MASKED})"
  fi
  echo ""

  # ── Generate secrets ────────────────────────────────────────────
  info "Generating cryptographic secrets..."

  gen() { openssl rand -hex 32; }
  gen16() { openssl rand -hex 16; }

  APP_SECRET=$(gen)
  JWT_PASSPHRASE=$(gen)
  CENTRIFUGO_API_KEY=$(gen)
  CENTRIFUGO_TOKEN_HMAC_SECRET=$(gen)
  MYSQL_ROOT_PASSWORD=$(gen16)
  MYSQL_PASSWORD=$(gen16)
  CENTRIFUGO_ADMIN_PASSWORD=$(gen16)
  STORAGE_ENCRYPTION_KEY=$(gen)

  ok "Secrets generated"

  # ── Build derived URL values ─────────────────────────────────────
  # Storno uses a single-domain model for self-hosted:
  #   https://<domain>          → Frontend (Nuxt, port 8901)
  #   https://<domain>/api      → Backend API (port 8900, proxied via Nginx /api)
  #   wss://<domain>/connection/websocket → Centrifugo (port 8445, proxied)
  FRONTEND_URL="https://${DOMAIN}"
  PUBLIC_API_BASE="https://${DOMAIN}"
  # Escape dots in the domain for use inside a regex value in .env
  DOMAIN_ESCAPED="${DOMAIN//./\\.}"
  CORS_ALLOW_ORIGIN="^https?://${DOMAIN_ESCAPED}(:[0-9]+)?$"
  CENTRIFUGO_ALLOWED_ORIGINS="https://${DOMAIN}"
  PUBLIC_CENTRIFUGO_WS="wss://${DOMAIN}/connection/websocket"
  DATABASE_URL="mysql://storno:${MYSQL_PASSWORD}@db:3306/storno?serverVersion=8.0&charset=utf8mb4"
  MAIL_FROM="noreply@${DOMAIN}"

  # ── Write .env ───────────────────────────────────────────────────
  info "Writing .env..."
  cp "${STORNO_DIR}/.env.example" "${STORNO_DIR}/.env"

  # Secrets
  sedit "s|APP_SECRET=CHANGE_ME_RUN_openssl_rand_hex_32|APP_SECRET=${APP_SECRET}|" "${STORNO_DIR}/.env"
  sedit "s|JWT_PASSPHRASE=CHANGE_ME_RUN_openssl_rand_hex_32|JWT_PASSPHRASE=${JWT_PASSPHRASE}|" "${STORNO_DIR}/.env"
  sedit "s|CENTRIFUGO_API_KEY=CHANGE_ME_RUN_openssl_rand_hex_32|CENTRIFUGO_API_KEY=${CENTRIFUGO_API_KEY}|" "${STORNO_DIR}/.env"
  sedit "s|CENTRIFUGO_TOKEN_HMAC_SECRET=CHANGE_ME_RUN_openssl_rand_hex_32|CENTRIFUGO_TOKEN_HMAC_SECRET=${CENTRIFUGO_TOKEN_HMAC_SECRET}|" "${STORNO_DIR}/.env"
  sedit "s|MYSQL_ROOT_PASSWORD=CHANGE_ME|MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}|" "${STORNO_DIR}/.env"
  sedit "s|MYSQL_PASSWORD=CHANGE_ME|MYSQL_PASSWORD=${MYSQL_PASSWORD}|" "${STORNO_DIR}/.env"
  sedit "s|CENTRIFUGO_ADMIN_PASSWORD=CHANGE_ME|CENTRIFUGO_ADMIN_PASSWORD=${CENTRIFUGO_ADMIN_PASSWORD}|" "${STORNO_DIR}/.env"
  sedit "s|STORAGE_ENCRYPTION_KEY=CHANGE_ME_RUN_openssl_rand_hex_32|STORAGE_ENCRYPTION_KEY=${STORAGE_ENCRYPTION_KEY}|" "${STORNO_DIR}/.env"

  # Domain-derived URLs (use | as delimiter to avoid clashes with slashes in values)
  sedit "s|FRONTEND_URL=.*|FRONTEND_URL=${FRONTEND_URL}|" "${STORNO_DIR}/.env"
  sedit "s|PUBLIC_API_BASE=.*|PUBLIC_API_BASE=${PUBLIC_API_BASE}|" "${STORNO_DIR}/.env"
  sedit "s|CORS_ALLOW_ORIGIN=.*|CORS_ALLOW_ORIGIN=${CORS_ALLOW_ORIGIN}|" "${STORNO_DIR}/.env"
  sedit "s|CENTRIFUGO_ALLOWED_ORIGINS=.*|CENTRIFUGO_ALLOWED_ORIGINS=${CENTRIFUGO_ALLOWED_ORIGINS}|" "${STORNO_DIR}/.env"
  sedit "s|PUBLIC_CENTRIFUGO_WS=.*|PUBLIC_CENTRIFUGO_WS=${PUBLIC_CENTRIFUGO_WS}|" "${STORNO_DIR}/.env"
  sedit "s|DATABASE_URL=.*|DATABASE_URL=${DATABASE_URL}|" "${STORNO_DIR}/.env"
  sedit "s|MAIL_FROM=.*|MAIL_FROM=${MAIL_FROM}|" "${STORNO_DIR}/.env"

  # License
  sedit "s|LICENSE_KEY=.*|LICENSE_KEY=${LICENSE_KEY}|" "${STORNO_DIR}/.env"

  ok ".env written to ${STORNO_DIR}/.env"
else
  step "[4/6] Configuration — skipped (existing .env retained)"
  # Read edition label from existing .env for the final summary
  existing_key=$(grep -E '^LICENSE_KEY=' "${STORNO_DIR}/.env" | cut -d= -f2- || true)
  if [ -z "${existing_key}" ]; then
    EDITION_LABEL="Community Edition (Starter features, free forever)"
  else
    KEY_MASKED="${existing_key:0:4}****${existing_key: -4}"
    EDITION_LABEL="Licensed Edition (key: ${KEY_MASKED})"
  fi
  # Read DOMAIN from existing .env for the final summary
  DOMAIN=$(grep -E '^FRONTEND_URL=' "${STORNO_DIR}/.env" | cut -d= -f2- | sed 's|https://||' | sed 's|http://||' || true)
fi

# ══════════════════════════════════════════════════════════════════
# 5. PULL IMAGES AND START SERVICES
# ══════════════════════════════════════════════════════════════════
step "[5/6] Pulling images and starting services..."

DC="docker compose -f ${STORNO_DIR}/docker-compose.yml --env-file ${STORNO_DIR}/.env"

info "Pulling images (this may take a few minutes on first run)..."
if ! ${DC} --profile local-db pull; then
  warn "Some images could not be pulled. Attempting to continue..."
fi
ok "Images pulled"

info "Starting containers..."
${DC} --profile local-db up -d
ok "Containers started"

# ── Wait for DB ──────────────────────────────────────────────────
info "Waiting for MySQL to be ready..."

DB_RETRIES=30
DB_WAIT=2
DB_READY=false

for i in $(seq 1 "${DB_RETRIES}"); do
  if ${DC} exec -T db mysqladmin ping -h localhost --silent >/dev/null 2>&1; then
    DB_READY=true
    break
  fi
  printf "  ."
  sleep "${DB_WAIT}"
done
echo ""

if [ "${DB_READY}" = false ]; then
  error "MySQL did not become ready after $((DB_RETRIES * DB_WAIT))s.
  Check logs with: docker compose -f ${STORNO_DIR}/docker-compose.yml logs db"
fi
ok "MySQL is ready"

# ── Wait for Backend ─────────────────────────────────────────────
info "Waiting for backend to be ready..."

BACKEND_RETRIES=30
BACKEND_WAIT=3
BACKEND_READY=false
BACKEND_PORT=$(grep -E '^BACKEND_PORT=' "${STORNO_DIR}/.env" | cut -d= -f2- || echo "8900")

for i in $(seq 1 "${BACKEND_RETRIES}"); do
  if curl -sf "http://localhost:${BACKEND_PORT}/health" >/dev/null 2>&1; then
    BACKEND_READY=true
    break
  fi
  printf "  ."
  sleep "${BACKEND_WAIT}"
done
echo ""

if [ "${BACKEND_READY}" = false ]; then
  warn "Backend health check timed out. Proceeding — it may still be warming up."
  warn "Check with: docker compose -f ${STORNO_DIR}/docker-compose.yml logs backend"
fi
[ "${BACKEND_READY}" = true ] && ok "Backend is ready"

# ══════════════════════════════════════════════════════════════════
# 6. DATABASE MIGRATIONS + JWT KEYPAIR
# ══════════════════════════════════════════════════════════════════
step "[6/6] Running post-start tasks..."

# ── Database setup ────────────────────────────────────────────────
info "Setting up database..."

# Check if the database already has tables (upgrade vs fresh install)
TABLE_COUNT=$(${DC} exec -T backend php bin/console dbal:run-sql "SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE()" --no-interaction 2>/dev/null | grep -oE '[0-9]+' | tail -1 || echo "0")

if [ "${TABLE_COUNT:-0}" -gt "0" ] 2>/dev/null; then
  info "Existing database detected — running migrations..."
  if ${DC} exec -T backend php bin/console doctrine:migrations:migrate --no-interaction; then
    ok "Database migrations applied"
  else
    warn "Migrations failed — this may be normal if the schema is already up to date."
  fi
else
  info "Fresh database — creating schema..."
  if ${DC} exec -T backend php bin/console doctrine:schema:create --no-interaction; then
    ok "Database schema created"
  else
    error "Database schema creation failed.
  Check logs with: docker compose -f ${STORNO_DIR}/docker-compose.yml logs backend"
  fi

  # Mark all migrations as executed so future updates use migrations
  if ${DC} exec -T backend php bin/console doctrine:migrations:version --add --all --no-interaction 2>/dev/null; then
    ok "Migration versions synced"
  fi
fi

# ── JWT Keypair ──────────────────────────────────────────────────
if [ "${IS_FIRST_INSTALL}" = true ]; then
  info "Generating JWT keypair..."
  if ${DC} exec -T backend php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction; then
    ok "JWT keypair generated"
  else
    error "JWT keypair generation failed.
  Check logs with: docker compose -f ${STORNO_DIR}/docker-compose.yml logs backend"
  fi
else
  info "Checking JWT keypair..."
  if ${DC} exec -T backend php bin/console lexik:jwt:generate-keypair --skip-if-exists; then
    ok "JWT keypair ready"
  else
    error "JWT keypair generation failed.
  Check logs with: docker compose -f ${STORNO_DIR}/docker-compose.yml logs backend"
  fi
fi

# ══════════════════════════════════════════════════════════════════
# SUCCESS
# ══════════════════════════════════════════════════════════════════
echo ""
echo -e "${GREEN}${BOLD}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}${BOLD}║          Storno.ro is running!                           ║${NC}"
echo -e "${GREEN}${BOLD}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""

if [ -n "${DOMAIN:-}" ]; then
  echo -e "  ${BOLD}App URL:${NC}        https://${DOMAIN}"
  echo -e "  ${BOLD}API URL:${NC}        https://${DOMAIN}/api"
  echo -e "  ${BOLD}WebSocket:${NC}      wss://${DOMAIN}/connection/websocket"
else
  FRONTEND_PORT=$(grep -E '^FRONTEND_PORT=' "${STORNO_DIR}/.env" | cut -d= -f2- || echo "8901")
  BACKEND_PORT_DISPLAY=$(grep -E '^BACKEND_PORT=' "${STORNO_DIR}/.env" | cut -d= -f2- || echo "8900")
  CENTRIFUGO_PORT=$(grep -E '^CENTRIFUGO_PORT=' "${STORNO_DIR}/.env" | cut -d= -f2- || echo "8445")
  echo -e "  ${BOLD}App URL:${NC}        http://localhost:${FRONTEND_PORT}"
  echo -e "  ${BOLD}API URL:${NC}        http://localhost:${BACKEND_PORT_DISPLAY}"
  echo -e "  ${BOLD}WebSocket:${NC}      ws://localhost:${CENTRIFUGO_PORT}"
fi

echo ""
echo -e "  ${BOLD}Edition:${NC}        ${EDITION_LABEL}"
echo ""
echo -e "  ${BOLD}Install dir:${NC}    ${STORNO_DIR}"
echo -e "  ${BOLD}Config file:${NC}    ${STORNO_DIR}/.env"
echo ""
echo -e "  ${CYAN}Useful commands:${NC}"
echo -e "    View logs:    docker compose -f ${STORNO_DIR}/docker-compose.yml logs -f"
echo -e "    Stop:         docker compose -f ${STORNO_DIR}/docker-compose.yml --profile local-db stop"
echo -e "    Start:        docker compose -f ${STORNO_DIR}/docker-compose.yml --profile local-db up -d"
echo -e "    Update:       curl -fsSL https://get.storno.ro | bash"
echo ""

if [ -z "${LICENSE_KEY:-}" ] && [ "${IS_FIRST_INSTALL}" = true ]; then
  echo -e "  ${YELLOW}Running Community Edition — Starter features, free forever.${NC}"
  echo -e "  Upgrade anytime at: https://app.storno.ro/settings/billing"
  echo ""
fi

echo -e "  Create your first account by visiting the app URL above."
echo ""
