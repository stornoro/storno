# Storno ANAF Agent

Local mTLS proxy for ANAF SPV declarations — supports hardware USB tokens (SafeNet eToken, Feitian, certSIGN, Bit4id).

## Why?

ANAF SPV API requires mTLS (mutual TLS) authentication. Romanian digital certificates are typically stored on hardware USB tokens where private keys are **non-exportable** — the server cannot use them. This agent runs on your local machine and proxies mTLS requests using `curl`, which natively accesses OS certificate stores and PKCS#11 hardware tokens.

## Installation

```bash
# From the project root
cd agent
npm install
npm run build
```

## Usage

```bash
# Start the agent
npx storno-agent start

# Check if running
npx storno-agent status

# List certificates from your OS/hardware token
npx storno-agent certificates

# Configure PKCS#11 module (Linux/macOS; known vendor modules are auto-detected)
npx storno-agent config --pkcs11-module /usr/lib/libeTPkcs11.so

# Change port (default: 17394)
npx storno-agent config --port 17394

# Show current configuration
npx storno-agent config --show
```

## API Endpoints

The agent listens on `http://127.0.0.1:17394` (localhost only).

### `GET /health`

```json
{ "status": "ok", "version": "1.0.0", "platform": "darwin" }
```

### `GET /certificates`

Lists certificates from the OS certificate store or hardware token.

```json
{
  "certificates": [
    {
      "id": "ABC123...",
      "subject": "CN=POPESCU ION",
      "issuer": "certSIGN",
      "notAfter": "2027-01-15T00:00:00.000Z",
      "source": "keychain"
    }
  ]
}
```

### `POST /proxy`

Proxy an mTLS request to ANAF. Requires `X-Storno-Agent: 1` header.

**Request:**
```json
{
  "url": "https://webserviced.anaf.ro/SPVWS2/rest/cerere?tip=D394&cui=12345678",
  "method": "POST",
  "headers": {
    "Authorization": "Bearer eyJ...",
    "Content-Type": "application/xml"
  },
  "body": "<?xml version=\"1.0\"?>...",
  "certificateId": "ABC123..."
}
```

**Response:**
```json
{
  "statusCode": 200,
  "headers": { "content-type": "application/json" },
  "body": "{\"id_solicitare\": \"5000012345\"}"
}
```

## Automatic SPV monitoring (1.7.0+)

The agent can poll the ANAF SPV inbox on a schedule while the web app is closed. Enrollment happens from the web app (Company → ANAF → *Monitorizare SPV automată*), which posts the company, certificate id, PIN and a scoped Storno API key (`declaration.view`, `declaration.submit`) to the agent.

- Secrets go to the OS secure store: macOS Keychain (`security`), Windows DPAPI (PowerShell `ConvertFrom-SecureString`), Linux `secret-tool`, or `~/.storno-agent/secrets.json` (mode 0600) as a fallback. Only the schedule lives in `~/.storno-agent/monitor.json`.
- Scheduler: first tick 90 s after start, then every 15 min it runs any entry whose interval (1–24 h) has elapsed. Failures back off (interval × (1 + failures), capped at 24 h). One company syncs at a time.
- One cycle = `POST /api/v1/spv/sync-prepare` → `listaMesaje` with the certificate → `POST /api/v1/spv/sync-agent-result` → `descarcare` for each pending PDF → `POST /api/v1/spv/documents/{id}/agent-document`.
- Endpoints: `GET /monitor`, `POST /monitor`, `DELETE /monitor/:companyId`, `POST /monitor/:companyId/run` (all require `X-Storno-Agent: 1`; CORS restricted to app.storno.ro). `apiBase` must be a storno.ro host.

## Platform Support

| Platform | Certificate Access | How |
|----------|-------------------|-----|
| macOS | Keychain + USB tokens | `security find-identity` / SecureTransport curl for tokens with a CryptoTokenKit driver; `pkcs11-tool` + Homebrew `curl --engine pkcs11` for vendor PKCS#11 middleware (see below) |
| Windows | Windows Certificate Store | PowerShell `Get-ChildItem Cert:\` / SChannel curl |
| Linux | PKCS#11 hardware tokens | `pkcs11-tool` / `curl --engine pkcs11` |

## Security

- Binds to `127.0.0.1` only — never exposed to the network
- CORS restricted to Storno origins (`https://app.storno.ro`, `http://localhost:3000`)
- URL allowlist: only `webserviced.anaf.ro` and `epatrim.anaf.ro`
- Custom header `X-Storno-Agent: 1` required on proxy requests
- XML piped via stdin to curl, never passed as CLI arguments
- 120s timeout to accommodate hardware token PIN dialogs

## Supported Hardware Tokens

- SafeNet eToken (Thales)
- Longmai mToken CryptoID (CertDigital) — see macOS note below
- Feitian ePass
- Bit4id miniLector
- certSIGN
- Any PKCS#11-compatible token (configure module path)

### macOS tokens without a Keychain driver (e.g. Longmai mToken CryptoID)

Some middleware installs only a PKCS#11 library and no CryptoTokenKit
extension, so the certificate never appears in Keychain and Apple's curl
cannot use it. The agent then goes through OpenSSL's `pkcs11` engine, which
needs a Homebrew toolchain of the **same CPU architecture as the module**:

```bash
# Recommended: self-contained toolchain in ~/.storno-agent/toolchain-<arch>
# (no sudo, no Homebrew, nothing else on the machine is touched; delete the
# folder to uninstall). Pass the architecture of the module, which
# `storno-agent certificates` prints. Takes a few minutes.
./scripts/build-pkcs11-toolchain.sh x86_64   # Longmai standard driver on Apple Silicon
./scripts/build-pkcs11-toolchain.sh arm64    # arm64 / universal modules

# Alternative: Homebrew of the matching architecture
brew install curl libp11 opensc                                   # arm64 module
arch -x86_64 /usr/local/bin/brew install curl libp11 opensc       # x86_64 module, needs Intel Homebrew
```

`storno-agent certificates` prints the detected module, its architecture and
anything still missing. On such tokens the certificates are private objects,
so the list shows a single placeholder until the PIN is entered in the app.

## Configuration

Stored in `~/.storno-agent/config.json`:

```json
{
  "port": 17394,
  "allowedOrigins": ["https://app.storno.ro", "http://localhost:3000"],
  "pkcs11Module": null,
  "curlPath": "curl",
  "opensslPath": null,
  "pkcs11ToolPath": null
}
```

## Troubleshooting

### No certificates found
- Ensure your hardware token is plugged in
- On macOS: check Keychain Access for your certificate
- On Linux: configure the PKCS#11 module path with `storno-agent config --pkcs11-module`
- On Windows: ensure the certificate is in your Personal certificate store

### Port already in use
- Another agent instance may be running. Check with `storno-agent status`
- Change the port with `storno-agent config --port <number>`

### curl errors
- Ensure `curl` is installed and supports your platform's TLS backend
- On macOS: the built-in curl uses SecureTransport (supports Keychain)
- On Linux: curl must be compiled with `--with-engine` for PKCS#11 support
