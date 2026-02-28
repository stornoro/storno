# Security Policy

## Reporting a vulnerability

If you discover a security vulnerability in Storno, please report it responsibly.

**Do not open a public issue.**

Instead, email **security@storno.ro** with:

- A description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

We will acknowledge your report within 48 hours and aim to provide a fix within 7 days for critical issues.

## Supported versions

| Version | Supported |
|---------|-----------|
| Latest release | Yes |
| Older releases | Best effort |

## Scope

The following are in scope:

- Backend API (`backend/`)
- Frontend application (`frontend/`)
- Docker Compose deployment (`deploy/`)
- Authentication and authorization flows
- Data handling and storage

Out of scope:

- Third-party dependencies (report these to the upstream maintainers)
- Social engineering attacks
- Denial of service attacks

## Disclosure

We follow coordinated disclosure. We will credit reporters in the release notes unless they prefer to remain anonymous.
