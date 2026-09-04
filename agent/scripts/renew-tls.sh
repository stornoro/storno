#!/usr/bin/env bash
#
# Renew the Let's Encrypt certificate for agent.storno.ro (DNS A → 127.0.0.1)
# and publish it so every installed agent picks it up automatically.
#
# Flow:
#   1. acme.sh in manual-DNS mode asks for the TXT challenge value
#   2. we create _acme-challenge.agent.storno.ro via the Cloudflare API
#      (acme.sh's own dns_cf module fails with "Authentication error" on this
#      account, the raw API works — hence the manual mode)
#   3. acme.sh finishes the order, the TXT record is deleted
#   4. src/certs.ts (embedded boot fallback) is regenerated
#   5. tls.json {cert,key} is uploaded to get.storno.ro/agent/tls.json —
#      running agents refresh from it twice a day (src/tls.ts)
#
# Credentials: Cloudflare Global API Key + email, read from acme.sh's
# account.conf (SAVED_CF_Key / SAVED_CF_Email) or from CF_Key / CF_Email env.
# A scoped token (Zone:DNS:Edit on storno.ro) works too: set CF_Token.
#
# Usage:
#   renew-tls.sh            renew unconditionally
#   renew-tls.sh --if-due   renew only when the current cert has < 30 days left
#
set -euo pipefail

DOMAIN="agent.storno.ro"
ZONE="storno.ro"
ACME="${ACME:-$HOME/.acme.sh/acme.sh}"
ACME_DIR="$HOME/.acme.sh/${DOMAIN}_ecc"
SERVER="${SERVER:-server}"                       # ssh alias of the production host
REMOTE_JSON="/storage/www/storno/deploy/download/tls.json"
AGENT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
RENEW_BEFORE_DAYS=30

if [ "${1:-}" = "--if-due" ] && [ -f "$ACME_DIR/fullchain.cer" ]; then
  if openssl x509 -in "$ACME_DIR/fullchain.cer" -noout -checkend $((RENEW_BEFORE_DAYS * 86400)) >/dev/null; then
    echo "certificate still has more than $RENEW_BEFORE_DAYS days, nothing to do"
    exit 0
  fi
fi

conf() { grep -E "^SAVED_$1=" "$HOME/.acme.sh/account.conf" 2>/dev/null | cut -d= -f2- | tr -d "'\"" || true; }
CF_Token="${CF_Token:-$(conf CF_Token)}"
CF_Key="${CF_Key:-$(conf CF_Key)}"
CF_Email="${CF_Email:-$(conf CF_Email)}"

cf() { # method path [json]
  if [ -n "$CF_Token" ]; then
    curl -sS -m 30 -X "$1" -H "Authorization: Bearer $CF_Token" -H "Content-Type: application/json" \
      "https://api.cloudflare.com/client/v4$2" ${3:+--data "$3"}
  else
    curl -sS -m 30 -X "$1" -H "X-Auth-Email: $CF_Email" -H "X-Auth-Key: $CF_Key" -H "Content-Type: application/json" \
      "https://api.cloudflare.com/client/v4$2" ${3:+--data "$3"}
  fi
}
jq_() { python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"; }

[ -n "$CF_Token$CF_Key" ] || { echo "no Cloudflare credentials (CF_Token, or CF_Key + CF_Email)"; exit 1; }

ZONE_ID=$(cf GET "/zones?name=$ZONE" | jq_ "d['result'][0]['id']")

echo "== requesting challenge"
TXT=$("$ACME" --issue -d "$DOMAIN" --ecc --force --dns --yes-I-know-dns-manual-mode-enough-go-ahead-please 2>&1 \
  | sed -n "s/.*TXT value: '\([^']*\)'.*/\1/p" | head -1)
[ -n "$TXT" ] || { echo "acme.sh did not return a TXT value"; exit 1; }

echo "== publishing TXT record"
REC_ID=$(cf POST "/zones/$ZONE_ID/dns_records" \
  "{\"type\":\"TXT\",\"name\":\"_acme-challenge.$DOMAIN\",\"content\":\"$TXT\",\"ttl\":120}" \
  | jq_ "d['result']['id'] if d['success'] else exit('cloudflare: '+str(d['errors']))")
cleanup() { cf DELETE "/zones/$ZONE_ID/dns_records/$REC_ID" >/dev/null || true; }
trap cleanup EXIT

for _ in $(seq 1 30); do
  dig +short TXT "_acme-challenge.$DOMAIN" @adrian.ns.cloudflare.com | grep -q "$TXT" && break
  sleep 3
done

echo "== finishing order"
"$ACME" --renew -d "$DOMAIN" --ecc --yes-I-know-dns-manual-mode-enough-go-ahead-please >/dev/null

CERT="$ACME_DIR/fullchain.cer"
KEY="$ACME_DIR/$DOMAIN.key"
NOT_AFTER=$(openssl x509 -in "$CERT" -noout -enddate | cut -d= -f2)
echo "new certificate valid until: $NOT_AFTER"

echo "== regenerating src/certs.ts and tls.json"
python3 - "$CERT" "$KEY" "$AGENT_DIR/src/certs.ts" "$AGENT_DIR/build/tls.json" "$NOT_AFTER" <<'EOF'
import sys, json, os, datetime, email.utils
cert, key, ts, out_json, not_after = sys.argv[1:6]
c = open(cert).read().strip() + "\n"; k = open(key).read().strip() + "\n"
exp = datetime.datetime.strptime(not_after.strip(), "%b %d %H:%M:%S %Y %Z").strftime("%Y-%m-%d")
hdr = f"""// Let's Encrypt TLS certificate for agent.storno.ro (DNS A → 127.0.0.1)
// This allows HTTPS from any browser without mixed-content issues.
// The private key is bundled intentionally — it only serves 127.0.0.1 traffic.
// Embedded copy is the boot fallback; the agent refreshes it from
// https://get.storno.ro/agent/tls.json (see tls.ts). Regenerate with
// scripts/renew-tls.sh, which also publishes tls.json.
// Expires: {exp}

"""
open(ts, "w").write(hdr + "export const CERT = `" + c + "`;\n\nexport const KEY = `" + k + "`;\n")
os.makedirs(os.path.dirname(out_json), exist_ok=True)
json.dump({"hostname": "agent.storno.ro", "cert": c, "key": k, "notAfter": exp,
           "publishedAt": datetime.datetime.now(datetime.timezone.utc).isoformat()}, open(out_json, "w"))
EOF

echo "== uploading tls.json to $SERVER:$REMOTE_JSON"
scp -q "$AGENT_DIR/build/tls.json" "$SERVER:$REMOTE_JSON"
ssh "$SERVER" "chmod 644 $REMOTE_JSON"
curl -sS -m 15 https://get.storno.ro/agent/tls.json | jq_ "'published: ' + d['notAfter']"

# local cache for the agent on this machine
mkdir -p "$HOME/.storno-agent/tls"
cp "$CERT" "$HOME/.storno-agent/tls/agent.crt"
cp "$KEY" "$HOME/.storno-agent/tls/agent.key"; chmod 600 "$HOME/.storno-agent/tls/agent.key"

echo "done. Commit agent/src/certs.ts and cut a new agent release when convenient."
