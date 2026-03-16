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

# Configure PKCS#11 module (Linux only)
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

## Platform Support

| Platform | Certificate Access | How |
|----------|-------------------|-----|
| macOS | Keychain + USB tokens | `security find-identity` / SecureTransport curl |
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
- Feitian ePass
- Bit4id miniLector
- certSIGN
- Any PKCS#11-compatible token (configure module path)

## Configuration

Stored in `~/.storno-agent/config.json`:

```json
{
  "port": 17394,
  "allowedOrigins": ["https://app.storno.ro", "http://localhost:3000"],
  "pkcs11Module": null,
  "curlPath": "curl"
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
