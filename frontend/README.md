# Storno — Frontend

Web application for [Storno.ro](https://app.storno.ro). Built with Nuxt 4, Nuxt UI v4.4, and Pinia.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Framework | Nuxt 4 (Vue 3, SSR) |
| UI | Nuxt UI v4.4 (Radix Vue + Tailwind CSS v4) |
| State | Pinia (25 stores) |
| Forms | VeeValidate + Zod |
| Real-time | Centrifugo (WebSocket) |
| Charts | Chart.js + vue-chartjs |
| i18n | Romanian (`i18n/ro.ts`) |
| Auth | JWT + Google Sign-In + Passkeys (WebAuthn) |

## Getting Started

### Prerequisites

- Node.js 20+
- Backend API running on `localhost:8000`

### Development

```bash
npm install
npm run dev
```

Dev server starts at `https://localhost:8887` with HTTPS. API requests are proxied to the backend via Nitro (`/api/**` → `localhost:8000/api`).

### Production Build

```bash
npm run build
node .output/server/index.mjs
```

### Docker

```bash
docker build -t storno-frontend .
docker run -p 3000:3000 \
  -e NUXT_PUBLIC_API_BASE=https://api.yourdomain.com \
  storno-frontend
```

Multi-stage build: Node 20 Alpine, produces a minimal image serving on port 3000.

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `NUXT_PUBLIC_API_BASE` | `https://api.storno.ro` | Backend API URL (browser-facing) |
| `API_BACKEND_URL` | `http://api.storno.test:8000` | Backend URL for SSR proxy (internal) |
| `NUXT_PUBLIC_CENTRIFUGO_WS` | — | WebSocket URL for real-time updates |
| `NUXT_PUBLIC_GOOGLE_CLIENT_ID` | — | Google OAuth client ID (set via `GOOGLE_CLIENT_ID` in deploy/.env) |
| `NUXT_PUBLIC_TURNSTILE_SITE_KEY` | — | Cloudflare Turnstile CAPTCHA key (optional) |
| `NUXT_PUBLIC_CHATWOOT_BASE_URL` | — | Chatwoot instance URL (optional) |
| `NUXT_PUBLIC_CHATWOOT_WEBSITE_TOKEN` | — | Chatwoot widget token (optional) |

### Self-Hosted Configuration

In Docker Compose, the frontend connects to the backend via internal network:

```yaml
environment:
  API_BACKEND_URL: http://backend          # Internal Docker network
  NUXT_PUBLIC_API_BASE: https://api.yourdomain.com  # Public URL for browser
  NUXT_PUBLIC_CENTRIFUGO_WS: wss://app.yourdomain.com/connection/websocket
```

## Project Structure

```
frontend/
├── app/
│   ├── assets/css/main.css          # Tailwind v4 + Nuxt UI theme
│   ├── components/                  # 58 Vue components
│   │   ├── dashboard/               # Stats cards, charts, activity feed
│   │   ├── invoices/                # Invoice form, preview, email, payments
│   │   ├── proforma-invoices/       # Proforma form
│   │   ├── recurring-invoices/      # Recurring template form
│   │   ├── borderou/                # Bank statement table, import, filters
│   │   ├── import/                  # Multi-step import wizard
│   │   ├── export/                  # Accounting export modals
│   │   ├── backup/                  # Backup/restore UI
│   │   ├── shared/                  # Reusable modals, bulk bar
│   │   └── team/                    # Member invite/edit modals
│   ├── composables/                 # 26 composition functions
│   ├── layouts/
│   │   ├── default.vue              # App shell (sidebar, header, nav)
│   │   └── auth.vue                 # Split-screen auth layout
│   ├── middleware/
│   │   ├── auth.ts                  # Redirect unauthenticated to /login
│   │   ├── guest.ts                 # Redirect authenticated to /dashboard
│   │   └── permissions.ts           # RBAC route guard
│   ├── pages/                       # 57 file-based routes
│   ├── plugins/
│   │   ├── auth-cookies.ts          # Persist JWT + company to cookies
│   │   └── chatwoot.client.ts       # Live chat widget (client-only)
│   ├── stores/                      # 25 Pinia stores
│   ├── types/
│   │   ├── index.ts                 # Domain interfaces (~60 types)
│   │   └── enums.ts                 # Status enums + color maps
│   └── utils/
│       ├── constants.ts             # Pagination defaults
│       ├── permissions.ts           # 40+ RBAC permission constants
│       └── translateApiError.ts     # API error translation
├── i18n/ro.ts                       # Romanian translations
├── server/routes/_chatwoot/         # Chatwoot HMAC endpoint
├── public/                          # Static assets (logos, favicons)
├── nuxt.config.ts
├── Dockerfile
└── package.json
```

## Pages & Routes

### Authentication

| Route | Description |
|-------|-------------|
| `/login` | Email/password + Google Sign-In + Passkey |
| `/register` | Account creation |
| `/forgot-password` | Password reset flow |
| `/confirm-email` | Email verification |
| `/invite/[token]` | Team invitation acceptance |

### Core

| Route | Description |
|-------|-------------|
| `/dashboard` | Revenue stats, charts, recent invoices, sync status |
| `/invoices` | Invoice list with filters, bulk actions, export |
| `/invoices/new` | Create invoice with line items |
| `/invoices/[uuid]` | Invoice detail — preview, PDF, XML, email, payments |
| `/invoices/[uuid]/edit` | Edit draft invoice |
| `/proforma-invoices` | Proforma invoice management |
| `/recurring-invoices` | Recurring templates (auto-generate on schedule) |
| `/clients` | Client list |
| `/products` | Product catalog |
| `/suppliers` | Supplier list |

### e-Factura

| Route | Description |
|-------|-------------|
| `/efactura` | Sync status, manual sync trigger |
| `/spv-messages` | SPV validation messages |
| `/companies/[uuid]/anaf` | Tax authority OAuth linking per company |

### Reports & Sharing

| Route | Description |
|-------|-------------|
| `/reports` | VAT reports (period breakdown) |
| `/share/[token]` | Public invoice share page (with payment) |

### Settings

| Route | Description |
|-------|-------------|
| `/settings/profile` | User info, timezone, preferences |
| `/settings/organization` | Organization name, theme |
| `/settings/team` | Members, invitations, roles |
| `/settings/billing` | Subscription, plan management |
| `/settings/payments` | Stripe Connect setup |
| `/settings/bank-accounts` | IBAN configuration |
| `/settings/vat-rates` | VAT rates |
| `/settings/document-series` | Invoice numbering series |
| `/settings/email-templates` | Email content templates |
| `/settings/api-keys` | Scoped API tokens |
| `/settings/webhooks` | Webhook endpoints + delivery log |
| `/settings/backup` | Company data backup/restore |
| `/settings/import-export` | CSV/Excel import + data export |
| `/settings/storage` | File storage provider (S3, MinIO) |
| `/settings/license-keys` | Self-hosted license management |

### Admin (super admin only)

| Route | Description |
|-------|-------------|
| `/admin` | Platform dashboard |
| `/admin/users` | User management |
| `/admin/organizations` | Organization management |
| `/admin/revenue` | Revenue analytics |

## Key Features

- **Invoice lifecycle** — Draft, issue, submit, validate, pay, cancel
- **Proforma invoices** — Send, accept, convert to final invoice
- **Recurring invoices** — Auto-generate on schedule (weekly to annual)
- **Multi-company** — Switch between companies, per-company settings
- **Real-time updates** — WebSocket notifications for sync status, invoice changes
- **Dashboard** — Revenue charts, payment tracking, activity feed, period selector
- **Bank statements** — Import and reconcile against invoices
- **Data import/export** — CSV/Excel import wizard, ZIP/CSV export
- **Backup/restore** — Company-level backup with progress tracking
- **RBAC** — 40+ permissions across 5 roles (owner, admin, accountant, member, viewer)
- **Keyboard shortcuts** — Navigation, create, notifications panel
- **Theme** — Light/dark mode, primary/neutral color customization

## API Integration

All API calls go through the `useApi()` composable:

```typescript
const { get, post, put, del } = useApi()

const response = await get<PaginatedResponse<Invoice>>('/v1/invoices', {
  params: { page: 1, limit: 20, status: 'issued' }
})

const invoice = await post<Invoice>('/v1/invoices', {
  clientId, issueDate, dueDate, currency, lines
})
```

Automatically attaches `Authorization`, `X-Company`, and `X-Organization` headers.

## Troubleshooting

### Frontend can't reach backend

- In Docker: set `API_BACKEND_URL=http://backend` (internal network name)
- In dev: set `API_BACKEND_URL=http://api.storno.test:8000`
- Verify backend health: `curl http://backend/health`

### WebSocket connection fails

- Verify Centrifugo is running
- Check `NUXT_PUBLIC_CENTRIFUGO_WS` points to the correct URL
- Ensure firewall allows WebSocket traffic

### Turnstile CAPTCHA error

- Set `NUXT_PUBLIC_TURNSTILE_SITE_KEY` to your Cloudflare site key
- Add your domain to the Turnstile sites list in Cloudflare dashboard

## Scripts

| Command | Description |
|---------|-------------|
| `npm run dev` | Start dev server (HTTPS, port 8887) |
| `npm run build` | Build for production |
| `npm run preview` | Preview production build locally |

## License

[Business Source License 1.1](../LICENSE)
