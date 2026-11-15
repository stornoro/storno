#!/usr/bin/env bash
# Smoke test of the realtime stack as deploy/docker-compose.yml ships it.
#
# Starts the compose file's own `redis` and `centrifugo` services in a throwaway
# project on a spare port, then runs smoke.mjs against them: the backend's
# CentrifugoService on one side, the frontend's centrifuge-js on the other.
#
#   deploy/tests/centrifugo/run.sh            # test and tear down
#   KEEP=1 deploy/tests/centrifugo/run.sh     # leave the containers up
#
# Needs: docker, node >= 20, php, `composer install` in backend/, `npm ci` in frontend/.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
PROJECT="${PROJECT:-storno-realtime-test}"
PORT="${PORT:-18445}"
COMPOSE_FILE="${COMPOSE_FILE:-$ROOT/deploy/docker-compose.yml}"

export CENTRIFUGO_API_KEY="smoke-api-key"
export CENTRIFUGO_TOKEN_HMAC_SECRET="smoke-hmac-secret"
export CENTRIFUGO_TEST_ORIGIN="https://app.storno.test"

ENV_FILE="$(mktemp)"
cat > "$ENV_FILE" <<EOF
BIND_ADDRESS=127.0.0.1
CENTRIFUGO_PORT=$PORT
CENTRIFUGO_API_KEY=$CENTRIFUGO_API_KEY
CENTRIFUGO_TOKEN_HMAC_SECRET=$CENTRIFUGO_TOKEN_HMAC_SECRET
CENTRIFUGO_ALLOWED_ORIGINS=$CENTRIFUGO_TEST_ORIGIN
EOF

compose() {
  docker compose -p "$PROJECT" --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@" 2> >(grep -v 'variable is not set' >&2)
}

cleanup() {
  status=$?
  if [ "$status" -ne 0 ]; then
    echo "── centrifugo logs ──"
    compose logs --no-color --tail 40 centrifugo || true
  fi
  if [ -z "${KEEP:-}" ]; then
    compose down -v --remove-orphans >/dev/null 2>&1 || true
  fi
  rm -f "$ENV_FILE"
  exit "$status"
}
trap cleanup EXIT

compose up -d redis centrifugo

for _ in $(seq 1 60); do
  if curl -fs "http://127.0.0.1:$PORT/health" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 0.5
done
[ -n "${ready:-}" ] || { echo "Centrifugo did not become healthy on port $PORT"; exit 1; }

CENTRIFUGO_HTTP_URL="http://127.0.0.1:$PORT" node --test "$HERE/smoke.mjs"
