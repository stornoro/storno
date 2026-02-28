# Storno — Backend

REST API for [Storno.ro](https://app.storno.ro). Built with Symfony 7.4, Doctrine ORM, and MySQL.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Framework | Symfony 7.4 |
| ORM | Doctrine 2.17 + MySQL 8.0 |
| Auth | JWT (lexik) + Google OAuth + WebAuthn/Passkeys |
| API | API Platform 3.2 |
| Queue | Symfony Messenger (Doctrine / RabbitMQ / Redis) |
| Storage | Flysystem (S3-compatible) |
| Email | AWS SES |
| Payments | Stripe (subscriptions + Connect) |
| Real-time | Centrifugo (WebSocket) |
| PDF | wkhtmltopdf + Java service |

## Getting Started

### Prerequisites

- PHP 8.2+
- Composer
- MySQL 8.0
- Redis (optional, for cache/queue)

### Installation

```bash
composer install
cp .env .env.local    # Configure database, JWT, etc.
php bin/console lexik:jwt:generate-keypair
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

### Development

```bash
symfony serve          # API at https://localhost:8000
php bin/console messenger:consume async -vv   # Process async jobs
```

### Docker

```bash
docker build -t storno-backend .
docker run -p 80:80 \
  -e DATABASE_URL="mysql://user:pass@db:3306/storno" \
  -e APP_SECRET=your-secret \
  storno-backend
```

Multi-stage build: PHP 8.2-FPM + Nginx + Supervisor on Alpine. Includes Java 17 runtime for PDF generation and signature verification.

## Environment Variables

### Required

| Variable | Description |
|----------|-------------|
| `APP_SECRET` | Symfony secret (random string) |
| `DATABASE_URL` | MySQL connection string |
| `JWT_PASSPHRASE` | JWT key passphrase |
| `FRONTEND_URL` | Frontend URL for CORS and redirects |

### Authentication

| Variable | Default | Description |
|----------|---------|-------------|
| `REGISTRATION_ENABLED` | `1` | Enable/disable user registration |
| `OAUTH_GOOGLE_CLIENT_ID` | — | Google OAuth client ID (set via `GOOGLE_CLIENT_ID` in deploy/.env) |
| `OAUTH_GOOGLE_CLIENT_SECRET` | — | Google OAuth client secret (set via `GOOGLE_CLIENT_SECRET` in deploy/.env) |
| `TURNSTILE_SECRET_KEY` | — | Cloudflare Turnstile secret (optional) |

### ANAF / e-Factura

| Variable | Description |
|----------|-------------|
| `OAUTH_ANAF_CLIENT_ID` | ANAF OAuth client ID |
| `OAUTH_ANAF_CLIENT_SECRET` | ANAF OAuth client secret |
| `OAUTH_ANAF_CLIENT_REDIRECT_URI` | OAuth callback URL |
| `REDIRECT_AFTER_OAUTH` | Frontend URL to redirect after OAuth |

### Email

| Variable | Default | Description |
|----------|---------|-------------|
| `MAILER_DSN` | — | Mail transport (SES, SMTP, etc.) |
| `MAIL_FROM` | `noreply@storno.ro` | Sender email address |

### Storage

| Variable | Default | Description |
|----------|---------|-------------|
| `AWS_S3_BUCKET` | — | S3 bucket name |
| `AWS_DEFAULT_REGION` | `us-east-1` | AWS region |
| `AWS_ACCESS_KEY_ID` | — | AWS access key |
| `AWS_SECRET_ACCESS_KEY` | — | AWS secret key |
| `STORAGE_ENCRYPTION_KEY` | — | Encryption key for stored credentials |

### Queue & Cache

| Variable | Default | Description |
|----------|---------|-------------|
| `MESSENGER_TRANSPORT_DSN` | `redis://localhost:6379/messages` | Async queue transport (Redis) |
| `REDIS_URL` | `redis://localhost:6379` | Redis connection |

### Real-time

| Variable | Description |
|----------|-------------|
| `CENTRIFUGO_API_URL` | Centrifugo HTTP API URL |
| `CENTRIFUGO_API_KEY` | Centrifugo API key |
| `CENTRIFUGO_TOKEN_HMAC_SECRET` | HMAC secret for client tokens |

### Stripe

| Variable | Description |
|----------|-------------|
| `STRIPE_SECRET_KEY` | Stripe API secret key |
| `STRIPE_PUBLISHABLE_KEY` | Stripe publishable key |
| `STRIPE_WEBHOOK_SECRET` | Stripe webhook signing secret |
| `STRIPE_CONNECT_WEBHOOK_SECRET` | Stripe Connect webhook secret |
| `STRIPE_PLATFORM_FEE_PERCENT` | Platform fee percentage (default: 2.0) |

### PDF & Validation

| Variable | Default | Description |
|----------|---------|-------------|
| `WKHTMLTOPDF_PATH` | `/usr/local/bin/wkhtmltopdf` | wkhtmltopdf binary path |
| `JAVA_SERVICE_URL` | `http://127.0.0.1:8082` | Java PDF/validator service URL |

### Self-Hosted

| Variable | Description |
|----------|-------------|
| `LICENSE_KEY` | License key for self-hosted instances |
| `LICENSE_SERVER_URL` | License validation server URL |

## Architecture

### Controllers (68 total)

**API v1** — 49 REST controllers:
- Invoices, proforma invoices, delivery notes, recurring invoices
- Clients, products, suppliers
- Payments, bank accounts, VAT rates, document series
- Dashboard, reports, export, import, backup
- e-Factura sync, ANAF tokens, SPV messages
- API keys, webhooks, email templates, notifications
- Billing (Stripe), Stripe Connect
- Admin, licensing, system health

**Auth** — Login, register, OAuth flows (Google, ANAF)

**Webhooks** — Stripe, Stripe Connect, Stripe App, AWS SES

### Entities (57 total)

Core domain models:

- `Invoice`, `InvoiceLine`, `InvoiceAttachment` — Main documents
- `ProformaInvoice`, `DeliveryNote`, `RecurringInvoice` — Document variants
- `Client`, `Supplier`, `Product` — Business entities
- `Payment` — Payment tracking
- `Organization`, `Company` — Multi-tenant structure
- `User`, `UserPasskey`, `ApiToken` — Authentication
- `AnafToken`, `EFacturaMessage` — e-Factura integration
- `DocumentSeries`, `VatRate`, `BankAccount` — Configuration
- `WebhookEndpoint`, `WebhookDelivery` — Webhook system
- `EmailTemplate`, `EmailLog` — Email management
- `BackupJob`, `ImportJob` — Async operations
- `StripeConnectAccount` — Payment processing
- `StorageConfig`, `LicenseKey` — Platform settings

### Services (95 total)

Organized by domain:

- **Anaf/** — e-Factura client, XML generation/parsing, sync, validation (11 services)
- **Backup/** — Company backup and restore (3 services)
- **Borderou/** — Bank statement import and matching (6 services)
- **Export/** — CSV, Saga XML, ZIP exports (3 services)
- **Import/** — Data migration from 12 systems with mappers, parsers, persisters (22 services)
- **Storage/** — Multi-backend file storage with encryption (5 services)
- **Centrifugo/** — Real-time WebSocket publishing (2 services)
- **Webhook/** — Event dispatch and delivery (2 services)
- **Report/** — VAT report generation (1 service)
- Core services: PDF generation, email, payments, Stripe, licensing, etc.

### Async Jobs (17 message handlers)

Processed via Symfony Messenger:

- Invoice submission to ANAF
- Company sync with e-Factura
- PDF generation
- ZIP export creation
- Backup/restore operations
- Data import processing
- Webhook dispatch
- Email sending (confirmation, invitation)
- Push notifications (Firebase)

### Console Commands (21 total)

```bash
# e-Factura
php bin/console app:efactura:sync              # Sync invoices from ANAF
php bin/console app:efactura:submit-scheduled   # Submit pending invoices
php bin/console app:efactura:check-uploads      # Check submission status
php bin/console app:anaf:refresh-tokens         # Refresh OAuth tokens

# Recurring Invoices
php bin/console app:invoices:process-recurring  # Generate scheduled invoices

# Notifications
php bin/console app:notifications:due-reminders    # Send payment reminders
php bin/console app:notifications:token-expiry     # Warn of expiring tokens

# Backup
php bin/console app:backup:system              # Full system backup
php bin/console app:backup:restore-system      # Full system restore

# User Management
php bin/console app:user:create                # Create admin user
php bin/console app:user:change-password       # Change password
php bin/console app:user:clear-unconfirmed     # Delete unverified accounts

# Maintenance
php bin/console app:cleanup:archive            # Remove old documents
php bin/console app:post-update                # Post-deployment setup
```

### Data Import

Supports migration from 12 invoicing systems:

Bolt, Ciel, EasyBill, eMag, FacturazePro, Factureaza, Facturis, Facturis Online, FGO, IceFact, Oblio, Saga, SmartBill, and generic CSV format.

### Authorization

RBAC with 40+ granular permissions and 5 roles:

| Role | Scope |
|------|-------|
| `owner` | Full access, billing, member management |
| `admin` | Full access except billing |
| `accountant` | Invoices, clients, products, reports, settings |
| `member` | View + create invoices |
| `viewer` | Read-only access |

### API Endpoints

Health check:

```bash
curl https://api.storno.ro/health
```

Authentication:

```bash
# Login
curl -X POST https://api.storno.ro/api/auth \
  -H "Content-Type: application/json" \
  -d '{"email": "user@example.com", "password": "secret"}'

# Response: { "token": "eyJ...", "refresh_token": "..." }
```

All API calls require:
- `Authorization: Bearer {token}`
- `X-Company: {company-uuid}` (for company-scoped endpoints)

## Testing

```bash
php bin/phpunit
```

30 test files covering all API endpoints (35+ test cases). Uses SQLite in-memory database for speed.

## Self-Hosted Deployment

### Docker Compose

The backend runs as a single container with PHP-FPM, Nginx, and Supervisor (for queue workers).

Required services:
- MySQL 8.0
- Redis (optional, recommended for cache/queue)
- Centrifugo (optional, for real-time features)

### Minimum Configuration

```bash
# deploy/.env
APP_SECRET=change-this-to-a-random-string
DATABASE_URL=mysql://storno:password@db:3306/storno
JWT_PASSPHRASE=change-this-too
FRONTEND_URL=https://app.yourdomain.com
MAILER_DSN=smtp://user:pass@smtp.example.com:587
```

### First Run

```bash
docker compose up -d backend db redis
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec backend php bin/console lexik:jwt:generate-keypair
docker compose exec backend php bin/console app:user:create   # Create admin
```

### Cron Jobs

Add to crontab for automated operations:

```cron
# Sync invoices from ANAF (every 15 minutes)
*/15 * * * * docker compose exec -T backend php bin/console app:efactura:sync

# Process recurring invoices (daily at 6:00)
0 6 * * * docker compose exec -T backend php bin/console app:invoices:process-recurring

# Send payment reminders (daily at 9:00)
0 9 * * * docker compose exec -T backend php bin/console app:notifications:due-reminders

# Refresh ANAF tokens (daily at 3:00)
0 3 * * * docker compose exec -T backend php bin/console app:anaf:refresh-tokens

# Check ANAF upload status (every 30 minutes)
*/30 * * * * docker compose exec -T backend php bin/console app:efactura:check-uploads

# Cleanup unconfirmed accounts (weekly)
0 2 * * 0 docker compose exec -T backend php bin/console app:user:clear-unconfirmed
```

## License

[Business Source License 1.1](../LICENSE)
