# Contributing to Storno

Thanks for your interest in contributing to Storno! This document covers the process for contributing to this project.

## Getting started

1. Fork the repository
2. Create a feature branch from `main`
3. Make your changes
4. Submit a pull request

## Development setup

See the [README](README.md#local-development) for instructions on running each service locally.

## Project structure

| Directory | Stack | Description |
|-----------|-------|-------------|
| `backend/` | PHP 8.2, Symfony 7.4 | REST API, business logic, ANAF integration |
| `frontend/` | Nuxt 4, Vue 3 | Web application |
| `deploy/` | Docker Compose, Helm | Deployment configuration |

## Pull requests

- Keep PRs focused on a single change
- Include a clear description of what the PR does and why
- Add tests for new functionality when applicable
- Make sure existing tests pass before submitting
- Follow the existing code style in each service

### Backend (PHP)

```bash
cd backend
composer install
php bin/phpunit
```

### Frontend (Nuxt)

```bash
cd frontend
npm install
npm run lint
npm run typecheck
```

## Commit messages

Write clear, concise commit messages. Use the imperative mood ("Add feature" not "Added feature").

Examples:
- `Add bank statement CSV import`
- `Fix e-Factura submission retry logic`
- `Update VAT rate validation rules`

## Reporting bugs

Open an issue with:
- Steps to reproduce
- Expected behavior
- Actual behavior
- Environment details (OS, browser, Docker version, etc.)

## Feature requests

Open an issue describing:
- The problem you're trying to solve
- Your proposed solution
- Any alternatives you've considered

## Cross-platform changes

When making changes that affect plans, features, types, or translations, update **all** platforms:

1. **Backend** — `backend/src/`
2. **Frontend** — `frontend/app/` + `frontend/i18n/`
3. **Docs** — [docs](https://github.com/stornoro/docs)
4. **Mobile** — [storno-mobile-app](https://github.com/stornoro/storno-mobile-app)

## License

By contributing, you agree that your contributions will be licensed under the [Elastic License 2.0](LICENSE), the same license that covers the project.
