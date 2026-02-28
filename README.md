<p align="center">
  <a href="https://storno.ro">
    <img src=".github/logo.png" alt="Storno" width="120" />
  </a>
</p>

<h3 align="center">Invoicing & e-Factura platform for any business</h3>

<p align="center">
  <a href="https://storno.ro">Website</a> &middot;
  <a href="https://docs.storno.ro">Docs</a> &middot;
  <a href="https://status.storno.ro">Status</a> &middot;
  <a href="https://app.storno.ro">Cloud</a>
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-Elastic--2.0-blue" alt="License" /></a>
</p>

---

Storno is a full-stack invoicing platform built for Romanian companies. It handles invoice creation, ANAF e-Factura submission, payment tracking, recurring billing, team collaboration, and financial reporting — available as a managed cloud service or self-hosted on your own infrastructure.

## Features

- **Invoicing** — Invoices, proforma invoices, delivery notes, receipts, recurring invoices
- **e-Factura** — Automatic submission to ANAF, SPV message handling, OAuth integration
- **Payments** — Payment tracking, bank statement import & reconciliation, multi-currency
- **Clients & Products** — Client/supplier management, product catalog, VAT rates
- **Reports** — VAT reports, revenue dashboards, financial analytics
- **Team collaboration** — Multi-company support, role-based access control (40+ permissions)
- **Data import** — Migrate from 12+ systems (SmartBill, Ciel, eMag, Bolt, and more)
- **Authentication** — Email/password, Google Sign-In, passkeys, two-factor authentication
- **Real-time** — WebSocket-powered live updates across all platforms
- **API & Webhooks** — REST API with API keys and webhook delivery
- **Mobile** — Native iOS & Android apps with biometric auth
- **Self-hosted** — One-command install, Docker Compose, optional Kubernetes (Helm)

## Architecture

```
storno/
├── backend/        PHP 8.2 · Symfony 7.4 · Doctrine ORM · MySQL 8 · Redis
├── frontend/       Nuxt 4 · Vue 3 · Pinia · Nuxt UI · Tailwind CSS
└── deploy/         Docker Compose · Nginx · Helm charts
```

**Related repositories:**
- [docs](https://github.com/stornoro/docs) — API documentation site (Next.js, Markdoc)
- [storno-mobile-app](https://github.com/stornoro/storno-mobile-app) — Mobile app (React Native, Expo)
- [stripe-app](https://github.com/stornoro/stripe-app) — Stripe Dashboard extension
- [storno-cli](https://github.com/stornoro/storno-cli) — MCP CLI tool for API integration

## Self-hosting

### Quick start

```bash
curl -fsSL https://get.storno.ro | bash
```

The installer checks prerequisites, downloads the Docker Compose stack, generates secrets, runs database migrations, and creates JWT keys.

### Manual setup

```bash
git clone https://github.com/stornoro/storno.git
cd storno/deploy

cp .env.example .env
# Edit .env — fill in your domain, database credentials, and secrets

docker compose --profile local-db up -d
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec backend php bin/console lexik:jwt:generate-keypair
docker compose exec backend php bin/console app:user:create
```

### Services

| Service | Image | Port |
|---------|-------|------|
| Backend (PHP-FPM + Nginx) | `ghcr.io/stornoro/backend` | 8900 |
| Frontend (Nuxt SSR) | `ghcr.io/stornoro/frontend` | 8901 |
| MySQL 8.0 | `mysql:8.0` | 3306 |
| Redis 7 | `redis:7-alpine` | 6379 |
| Centrifugo v5 (WebSockets) | `centrifugo/centrifugo:v5` | 8445 |

### Requirements

- Docker 24+ with Compose v2
- 2 GB RAM minimum (4 GB recommended)
- Domain name with DNS configured (for production)

## Local development

```bash
# Backend
cd backend
composer install
symfony server:start    # https://api.storno.test:8000

# Frontend
cd frontend
npm install
npm run dev             # https://app.storno.test:3000
```

## Editions

| | Freemium | Starter | Professional | Business |
|---|:---:|:---:|:---:|:---:|
| Invoices per month | 100 | 500 | Unlimited | Unlimited |
| Companies | 1 | 3 | 10 | Unlimited |
| Team members | 3 | 3 | 10 | Unlimited |
| e-Factura sync interval | 24h | 12h | 4h | 1h |
| PDF generation | Yes | Yes | Yes | Yes |
| Email sending & templates | Yes | Yes | Yes | Yes |
| Reports & exchange rates | Yes | Yes | Yes | Yes |
| Mobile app | — | Yes | Yes | Yes |
| Payment links | — | Yes | Yes | Yes |
| Data import/export | — | Yes | Yes | Yes |
| Recurring invoices | — | — | Yes | Yes |
| Bank statement import | — | — | Yes | Yes |
| Webhooks | — | — | Yes | Yes |
| Backup & restore | — | — | Yes | Yes |
| Real-time notifications | — | — | — | Yes |
| Self-hosting license | — | — | — | Yes |
| Priority support | — | — | — | Yes |
| **Price** | **Free** | **19 RON/mo** | **39 RON/mo** | **69 RON/mo** |

Self-hosted installations use a license key to unlock features. Get yours at [app.storno.ro/settings/billing](https://app.storno.ro/settings/billing).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on how to contribute.

## Security

Found a vulnerability? Please report it responsibly. See [SECURITY.md](SECURITY.md) for details.

## License

This repository is licensed under the [Elastic License 2.0](LICENSE). See the [LICENSE](LICENSE) file for the full text.
