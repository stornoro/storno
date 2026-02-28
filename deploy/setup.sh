#!/bin/bash
set -e

echo "╔══════════════════════════════════════════════════╗"
echo "║  Storno.ro Self-Hosted — Setup                   ║"
echo "╚══════════════════════════════════════════════════╝"
echo ""

# Check dependencies
for cmd in docker; do
  if ! command -v $cmd &> /dev/null; then
    echo "ERROR: $cmd is required but not installed."
    exit 1
  fi
done

# Generate .env if missing
if [ ! -f .env ]; then
  echo "→ Creating .env from .env.example..."
  cp .env.example .env

  # Auto-generate secrets
  sed -i.bak "s/APP_SECRET=CHANGE_ME_RUN_openssl_rand_hex_32/APP_SECRET=$(openssl rand -hex 32)/" .env
  sed -i.bak "s/JWT_PASSPHRASE=CHANGE_ME_RUN_openssl_rand_hex_32/JWT_PASSPHRASE=$(openssl rand -hex 32)/" .env
  sed -i.bak "s/CENTRIFUGO_API_KEY=CHANGE_ME_RUN_openssl_rand_hex_32/CENTRIFUGO_API_KEY=$(openssl rand -hex 32)/" .env
  sed -i.bak "s/CENTRIFUGO_TOKEN_HMAC_SECRET=CHANGE_ME_RUN_openssl_rand_hex_32/CENTRIFUGO_TOKEN_HMAC_SECRET=$(openssl rand -hex 32)/" .env
  sed -i.bak "s/MYSQL_ROOT_PASSWORD=CHANGE_ME/MYSQL_ROOT_PASSWORD=$(openssl rand -hex 16)/" .env
  sed -i.bak "s/MYSQL_PASSWORD=CHANGE_ME/MYSQL_PASSWORD=$(openssl rand -hex 16)/" .env
  sed -i.bak "s/CENTRIFUGO_ADMIN_PASSWORD=CHANGE_ME/CENTRIFUGO_ADMIN_PASSWORD=$(openssl rand -hex 16)/" .env
  rm -f .env.bak

  echo "  Secrets generated automatically."
  echo "  Edit .env to configure your domain, email, etc."
  echo ""
fi

# Start services
echo "→ Starting services..."
docker compose up -d

# Wait for DB
echo "→ Waiting for database..."
sleep 10

# Run migrations
echo "→ Running database migrations..."
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction

# Generate JWT keys
echo "→ Generating JWT keys..."
docker compose exec backend php bin/console lexik:jwt:generate-keypair --skip-if-exists

echo ""
echo "══════════════════════════════════════════════════"
echo "  Storno.ro is running!"
echo ""
echo "  Frontend:   http://localhost:${FRONTEND_PORT:-3000}"
echo "  API:        http://localhost:${BACKEND_PORT:-8000}"
echo "  WebSocket:  ws://localhost:${CENTRIFUGO_PORT:-8444}"
echo ""
echo "  Create your first account at the frontend URL."
echo "══════════════════════════════════════════════════"
