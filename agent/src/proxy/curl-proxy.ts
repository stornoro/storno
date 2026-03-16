import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, statSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir, platform } from 'node:os';
import type { AgentConfig } from '../config.js';
import { powershellProxy } from './powershell-proxy.js';

/** Per-certificate cookie jar directory for session reuse. */
const COOKIE_DIR = join(tmpdir(), 'storno-agent-cookies');

/** Session TTL — cookie files older than this are stale. */
const SESSION_TTL_MS = 10 * 60 * 1000; // 10 minutes

function getCookieJarPath(certificateId: string): string {
  mkdirSync(COOKIE_DIR, { recursive: true });
  // Sanitise thumbprint for use as filename
  const safe = certificateId.replace(/[^a-zA-Z0-9]/g, '');
  return join(COOKIE_DIR, `${safe}.txt`);
}

function hasValidSession(cookiePath: string): boolean {
  try {
    if (!existsSync(cookiePath)) return false;
    const age = Date.now() - statSync(cookiePath).mtimeMs;
    return age < SESSION_TTL_MS;
  } catch {
    return false;
  }
}

function invalidateSession(certificateId: string): void {
  try {
    const cookiePath = getCookieJarPath(certificateId);
    if (existsSync(cookiePath)) unlinkSync(cookiePath);
  } catch {
    // Best effort
  }
}

/** Detect ANAF F5 session expiry — redirects to logout/error pages. */
function isSessionExpired(res: ProxyResponse): boolean {
  const body = res.body.trimStart();

  // F5 redirects to logout/error pages when session is invalid
  if (body.includes('my.logout.php3') || body.includes('errorcode=')) return true;
  if (body.includes('Pagina logout') || body.includes('<html')) return true;

  // ANAF API returns JSON ({/[) or XML (<?xml). Anything else = session issue.
  if (res.statusCode === 200 && body.length > 0) {
    const firstChar = body[0];
    if (firstChar !== '{' && firstChar !== '[' && firstChar !== '<') return true;
    // Got XML/HTML but not valid ANAF XML (which starts with <?xml)
    if (firstChar === '<' && !body.startsWith('<?xml') && !body.startsWith('<')) return false;
  }

  // 302/403 from ANAF when session cookies are rejected
  if (res.statusCode === 302 || res.statusCode === 403) return true;
  return false;
}

export interface ProxyRequest {
  url: string;
  method: string;
  headers: Record<string, string>;
  body: string;
  certificateId: string;
  pin?: string;
}

export interface ProxyResponse {
  statusCode: number;
  headers: Record<string, string>;
  body: string;
}

/**
 * Proxy an mTLS request to ANAF using curl.
 *
 * Uses session cookie reuse: first request authenticates via mTLS and saves
 * F5 session cookies. Subsequent requests reuse cookies (no cert needed).
 * If the session expires, cookies are invalidated and the request is retried
 * with the certificate.
 */
export async function curlProxy(req: ProxyRequest, config: AgentConfig): Promise<ProxyResponse> {
  const cookiePath = getCookieJarPath(req.certificateId);
  const usedSession = hasValidSession(cookiePath);

  const result = await execRequest(req, config, cookiePath, usedSession);

  // If we used cached cookies and the response looks like an expired session,
  // invalidate cookies and retry with full mTLS authentication.
  if (usedSession && isSessionExpired(result)) {
    invalidateSession(req.certificateId);
    return execRequest(req, config, cookiePath, false);
  }

  return result;
}

/**
 * Choose the best execution strategy:
 * - Session cookies valid → curl with cookies (no cert, fast)
 * - Windows + PIN provided → PowerShell with CNG SmartCardPin (silent)
 * - Otherwise → curl with Schannel/SecureTransport (may show native PIN dialog)
 */
async function execRequest(
  req: ProxyRequest,
  config: AgentConfig,
  cookiePath: string,
  sessionValid: boolean,
): Promise<ProxyResponse> {
  // When session is valid, always use curl (cookies only, no cert needed)
  if (sessionValid) {
    return execCurl(req, config);
  }

  // On Windows with PIN: use PowerShell to set SmartCardPin on CNG key
  if (platform() === 'win32' && req.pin) {
    return powershellProxy(req, cookiePath);
  }

  // Default: curl with platform-specific cert handling
  return execCurl(req, config);
}

function execCurl(req: ProxyRequest, config: AgentConfig): Promise<ProxyResponse> {
  return new Promise((resolve, reject) => {
    const args = buildCurlArgs(req, config);

    const child = spawn(config.curlPath, args, {
      stdio: ['pipe', 'pipe', 'pipe'],
      timeout: 120_000,
    });

    let stdout = '';
    let stderr = '';

    child.stdout.on('data', (chunk: Buffer) => { stdout += chunk.toString(); });
    child.stderr.on('data', (chunk: Buffer) => { stderr += chunk.toString(); });

    // Pipe XML body via stdin (never as CLI arg)
    if (req.body) {
      child.stdin.write(req.body);
    }
    child.stdin.end();

    child.on('close', (code) => {
      if (code !== 0 && !stdout) {
        reject(new Error(`curl exited with code ${code}: ${stderr}`));
        return;
      }

      try {
        const result = parseResponse(stdout);
        resolve(result);
      } catch (err) {
        reject(new Error(`Failed to parse curl response: ${(err as Error).message}`));
      }
    });

    child.on('error', (err) => {
      reject(new Error(`Failed to spawn curl: ${err.message}`));
    });
  });
}

function buildCurlArgs(req: ProxyRequest, config: AgentConfig): string[] {
  const cookiePath = getCookieJarPath(req.certificateId);
  const sessionValid = hasValidSession(cookiePath);

  const args: string[] = [
    '-s',                  // Silent mode
    '-S',                  // Show errors
    '-D', '-',             // Dump headers to stdout
    '-L',                  // Follow redirects (ANAF F5 load balancer does 302 chains)
    '-b', cookiePath,      // Read cookies from jar
    '-c', cookiePath,      // Write cookies to jar after response
    '-X', req.method,
    '--max-time', '120',
    '-d', '@-',            // Read body from stdin
  ];

  // Add request headers
  for (const [key, value] of Object.entries(req.headers)) {
    args.push('-H', `${key}: ${value}`);
  }

  // Only attach client certificate if there's no valid session cookie.
  // After the first mTLS handshake, ANAF's F5 sets session cookies that
  // allow subsequent requests without re-presenting the certificate.
  if (!sessionValid) {
    const os = platform();
    if (os === 'darwin') {
      args.push('--cert', req.certificateId);
      if (req.pin) args.push('--pass', req.pin);
    } else if (os === 'win32') {
      args.push('--cert', `CurrentUser\\My\\${req.certificateId}`);
    } else if (os === 'linux' && config.pkcs11Module) {
      const certUri = req.pin
        ? `pkcs11:id=%${req.certificateId};pin-value=${req.pin}`
        : `pkcs11:id=%${req.certificateId}`;
      args.push(
        '--engine', 'pkcs11',
        '--cert-type', 'ENG',
        '--cert', certUri,
      );
    }
  }

  args.push(req.url);

  return args;
}

function parseResponse(raw: string): ProxyResponse {
  // With -L (follow redirects), curl -D - outputs headers for EVERY response
  // in the redirect chain. We need the LAST response's headers + body.
  // Split on double CRLF to find all header/body boundaries.
  // The last HTTP status line starts the final response headers.

  // Find the last HTTP status line position
  let lastStatusIdx = -1;
  let searchFrom = 0;
  while (true) {
    const idx = raw.indexOf('HTTP/', searchFrom);
    if (idx === -1) break;
    // Verify it's a status line (HTTP/x.x NNN)
    if (idx === 0 || raw[idx - 1] === '\n') {
      lastStatusIdx = idx;
    }
    searchFrom = idx + 1;
  }

  if (lastStatusIdx === -1) {
    return { statusCode: 200, headers: {}, body: raw };
  }

  // Parse from the last status line
  const finalResponse = raw.substring(lastStatusIdx);
  const headerEnd = finalResponse.indexOf('\r\n\r\n');
  if (headerEnd === -1) {
    return { statusCode: 200, headers: {}, body: finalResponse };
  }

  const headerSection = finalResponse.substring(0, headerEnd);
  const body = finalResponse.substring(headerEnd + 4);

  const headerLines = headerSection.split('\r\n');
  const headers: Record<string, string> = {};
  let statusCode = 200;

  for (const line of headerLines) {
    const statusMatch = line.match(/^HTTP\/[\d.]+ (\d+)/);
    if (statusMatch) {
      statusCode = parseInt(statusMatch[1], 10);
      continue;
    }

    const colonIdx = line.indexOf(':');
    if (colonIdx > 0) {
      const key = line.substring(0, colonIdx).trim().toLowerCase();
      const value = line.substring(colonIdx + 1).trim();
      headers[key] = value;
    }
  }

  return { statusCode, headers, body };
}
