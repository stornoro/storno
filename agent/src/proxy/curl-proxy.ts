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

/** In-memory PIN cache per certificate — avoids re-asking for PIN on every request. */
const pinCache = new Map<string, { pin: string; cachedAt: number }>();
const PIN_CACHE_TTL_MS = 30 * 60 * 1000; // 30 minutes

function cachePin(certificateId: string, pin: string): void {
  pinCache.set(certificateId, { pin, cachedAt: Date.now() });
}

function getCachedPin(certificateId: string): string | null {
  const entry = pinCache.get(certificateId);
  if (!entry) return null;
  if (Date.now() - entry.cachedAt > PIN_CACHE_TTL_MS) {
    pinCache.delete(certificateId);
    return null;
  }
  return entry.pin;
}

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
  bodyEncoding?: 'text' | 'base64';
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
  // Cache PIN in-memory when provided so subsequent requests don't need it from frontend
  if (req.pin) {
    cachePin(req.certificateId, req.pin);
  } else {
    const cached = getCachedPin(req.certificateId);
    if (cached) req = { ...req, pin: cached };
  }

  const cookiePath = getCookieJarPath(req.certificateId);
  const usedSession = hasValidSession(cookiePath);

  let result: ProxyResponse;
  try {
    result = await execRequest(req, config, usedSession);
  } catch (err) {
    // If PIN verification failed, clear cache and don't retry — prevents certificate lockout
    const msg = (err as Error).message;
    if (msg.includes('PIN verification failed') || msg.includes('Failed to set PIN')) {
      pinCache.delete(req.certificateId);
    }
    throw err;
  }

  // If we used cached cookies and the response looks like an expired session,
  // invalidate cookies and retry with full mTLS authentication.
  if (usedSession && isSessionExpired(result)) {
    invalidateSession(req.certificateId);
    return execRequest(req, config, false);
  }

  return result;
}

/**
 * Choose the best execution strategy:
 * - Windows + PIN + no session → PowerShell (sets CNG PIN + Invoke-WebRequest in same process + saves cookies)
 * - Session cookies valid → curl with cookies only (no cert, no PIN)
 * - Otherwise → curl with platform-specific cert handling
 */
async function execRequest(
  req: ProxyRequest,
  config: AgentConfig,
  sessionValid: boolean,
): Promise<ProxyResponse> {
  // On Windows with PIN and no valid session: use PowerShell to set the CNG
  // SmartCardPin and make the request in the SAME process via Invoke-WebRequest.
  // This avoids the native PIN dialog. Cookies are saved for subsequent requests.
  if (platform() === 'win32' && req.pin && !sessionValid) {
    console.log(`[proxy] ${req.method} ${req.url} → PowerShell (cert+PIN, establishing session)`);
    const cookiePath = getCookieJarPath(req.certificateId);
    return powershellProxy(req, cookiePath);
  }

  console.log(`[proxy] ${req.method} ${req.url} → curl (${sessionValid ? 'cookies only' : 'cert'})`);
  return execCurl(req, config);
}

function execCurl(req: ProxyRequest, config: AgentConfig): Promise<ProxyResponse> {
  return new Promise((resolve, reject) => {
    const args = buildCurlArgs(req, config);

    const child = spawn(config.curlPath, args, {
      stdio: ['pipe', 'pipe', 'pipe'],
      timeout: 120_000,
    });

    const stdoutChunks: Buffer[] = [];
    let stderr = '';

    child.stdout.on('data', (chunk: Buffer) => { stdoutChunks.push(chunk); });
    child.stderr.on('data', (chunk: Buffer) => { stderr += chunk.toString(); });

    // Pipe body via stdin for POST/PUT (never as CLI arg)
    if (req.body && req.method !== 'GET' && req.method !== 'HEAD') {
      child.stdin.write(req.body);
    }
    child.stdin.end();

    child.on('close', (code) => {
      const rawBuffer = Buffer.concat(stdoutChunks);

      if (code !== 0 && rawBuffer.length === 0) {
        reject(new Error(`curl exited with code ${code}: ${stderr}`));
        return;
      }

      try {
        const result = parseResponse(rawBuffer);
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
  ];

  // Only pipe body from stdin for methods that have a body
  if (req.method !== 'GET' && req.method !== 'HEAD') {
    args.push('-d', '@-');
  }

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

/** Content types that indicate binary data (should be base64-encoded). */
const BINARY_CONTENT_TYPES = ['application/pdf', 'application/zip', 'application/octet-stream', 'image/'];

function isBinaryContentType(contentType: string | undefined): boolean {
  if (!contentType) return false;
  return BINARY_CONTENT_TYPES.some(t => contentType.toLowerCase().includes(t));
}

function parseResponse(raw: Buffer): ProxyResponse {
  // With -L (follow redirects), curl -D - outputs headers for EVERY response
  // in the redirect chain. We need the LAST response's headers + body.
  // Headers are ASCII, so we search for header boundaries in the raw buffer,
  // then handle the body as binary if needed.

  const headerMarker = Buffer.from('HTTP/');
  const headerBodySep = Buffer.from('\r\n\r\n');

  // Find the last HTTP status line position
  let lastStatusIdx = -1;
  let searchFrom = 0;
  while (true) {
    const idx = raw.indexOf(headerMarker, searchFrom);
    if (idx === -1) break;
    if (idx === 0 || raw[idx - 1] === 0x0A) { // \n
      lastStatusIdx = idx;
    }
    searchFrom = idx + 1;
  }

  if (lastStatusIdx === -1) {
    return { statusCode: 200, headers: {}, body: raw.toString('utf-8') };
  }

  // Find the header/body separator after the last status line
  const headerEnd = raw.indexOf(headerBodySep, lastStatusIdx);
  if (headerEnd === -1) {
    return { statusCode: 200, headers: {}, body: raw.subarray(lastStatusIdx).toString('utf-8') };
  }

  // Parse headers as ASCII text
  const headerSection = raw.subarray(lastStatusIdx, headerEnd).toString('ascii');
  const bodyBuffer = raw.subarray(headerEnd + 4);

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

  // For binary content types, base64-encode the body to preserve data integrity
  if (isBinaryContentType(headers['content-type'])) {
    return { statusCode, headers, body: bodyBuffer.toString('base64'), bodyEncoding: 'base64' };
  }

  return { statusCode, headers, body: bodyBuffer.toString('utf-8') };
}
