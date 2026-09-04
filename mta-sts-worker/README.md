# mta-sts-storno

Cloudflare Worker that serves the MTA-STS policy at
`https://mta-sts.storno.ro/.well-known/mta-sts.txt`.

## Deploy

Requires a Cloudflare API token with `Workers Scripts: Edit` and `Workers Routes: Edit`
on the storno.ro zone (the read-only token in this repo's other `.env` files cannot deploy Workers).

```bash
npx wrangler login           # one-time browser auth
npx wrangler deploy
```

After deploy, verify:

```bash
curl https://mta-sts.storno.ro/.well-known/mta-sts.txt
# Expect: STSv1 policy with mode=testing
```

Then publish the DNS records (handled separately — Claude can do this once endpoint is live):

- `_mta-sts.storno.ro TXT "v=STSv1; id=<unix-timestamp>"`
- Placeholder `mta-sts.storno.ro A 192.0.2.1` (proxied through CF, so route binding works)

## Policy progression

1. `mode: testing` (current) — 30 days. Collects TLS-RPT reports without enforcement.
2. `mode: enforce` — after clean reports, switch and bump the `id=` in the TXT record.

When you change the policy file, bump `id=` in the DNS TXT so senders re-fetch.
