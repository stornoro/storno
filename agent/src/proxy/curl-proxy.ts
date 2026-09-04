import { spawn, execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, statSync, unlinkSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir, platform } from 'node:os';
import type { AgentConfig } from '../config.js';
import { powershellProxy } from './powershell-proxy.js';
import { resolvePkcs11Toolchain, pkcs11Uri, PKCS11_AUTO_ID, type Pkcs11Toolchain } from '../utils/toolchain.js';
import { parseCertificateObjects } from '../certificates/linux.js';
import { isPkcs11CertificateId } from '../certificates/discovery.js';

/** Per-certificate cookie jar directory for session reuse. */
const COOKIE_DIR = join(tmpdir(), 'storno-agent-cookies');

/** Session TTL — cookie files older than this are stale. */
const SESSION_TTL_MS = 30 * 60 * 1000; // 30 minutes (ANAF F5 sessions were observed to survive longer; expiry is detected anyway)

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
    const st = statSync(cookiePath);
    // curl writes the jar even when the TLS handshake failed: an empty jar
    // (or one holding only the Netscape header) is not a session.
    if (st.size < 64) return false;
    const age = Date.now() - st.mtimeMs;
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
  // A PDF / ZIP (base64-encoded by parseResponse) is exactly what we asked for.
  // Judging it by its first character used to mark every SPV download as an
  // expired session, drop the cookie jar and redo the request with the token.
  if (res.bodyEncoding === 'base64' || isBinaryContentType(res.headers['content-type'])) return false;
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
  /** multipart/form-data upload of one file (e-guvernare WAS6DUS "linkdoc"); replaces `body`. */
  multipart?: {
    field: string;
    fileName: string;
    contentBase64: string;
    contentType?: string;
  };
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
/**
 * PKCS#11 tokens whose certificates are private objects list nothing until a
 * login. Before handing a PIN to curl (whose engine gives no feedback and
 * would burn PIN attempts on every retry), verify it once with pkcs11-tool
 * and learn the real certificate id. Results are cached per PIN.
 */
const pkcs11LoginCache = new Map<string, { ok: boolean; message: string; certIds: string[] }>();

function verifyPkcs11Login(toolchain: Pkcs11Toolchain, pin: string): Promise<{ ok: boolean; message: string; certIds: string[] }> {
  const key = createHash('sha256').update(toolchain.module + '\0' + pin).digest('hex');
  const cached = pkcs11LoginCache.get(key);
  if (cached) return Promise.resolve(cached);
  if (!toolchain.pkcs11ToolPath) return Promise.resolve({ ok: true, message: 'no pkcs11-tool, skipping PIN check', certIds: [] });

  return new Promise((resolve) => {
    execFile(toolchain.pkcs11ToolPath as string, [
      '--module', toolchain.module, '--login', '--pin', pin, '-O', '--type', 'cert',
    ], { encoding: 'utf-8', timeout: 60_000, env: toolchain.env }, (err, stdout, stderr) => {
      const out = `${stdout ?? ''}\n${stderr ?? ''}`;
      let result: { ok: boolean; message: string; certIds: string[] };
      if (/CKR_PIN_LOCKED/i.test(out)) {
        result = { ok: false, message: 'PIN blocat (CKR_PIN_LOCKED). Deblocheaza tokenul cu PUK-ul din aplicatia producatorului.', certIds: [] };
      } else if (/CKR_PIN_INCORRECT|CKR_PIN_INVALID|CKR_PIN_LEN_RANGE/i.test(out)) {
        result = { ok: false, message: 'PIN verification failed', certIds: [] };
      } else if (err && !/present token/i.test(out)) {
        result = { ok: false, message: `pkcs11-tool login failed: ${out.trim().split('\n').slice(-2).join(' | ')}`, certIds: [] };
      } else {
        const certs = parseCertificateObjects(out);
        result = { ok: true, message: `login ok, ${certs.length} certificate(s)`, certIds: certs.map((c) => c.id) };
      }
      console.log(`[pkcs11] ${result.message}${result.certIds.length ? ' ids=' + result.certIds.join(',') : ''}`);
      pkcs11LoginCache.set(key, result);

      // Diagnostic when the login worked but no certificate object is visible:
      // list every object class so we can see how this middleware exposes it.
      if (result.ok && result.certIds.length === 0) {
        execFile(toolchain.pkcs11ToolPath as string, [
          '--module', toolchain.module, '--login', '--pin', pin, '-O',
        ], { encoding: 'utf-8', timeout: 60_000, env: toolchain.env }, (_e2, so2, se2) => {
          const all = `${so2 ?? ''}\n${se2 ?? ''}`;
          const summary = all.split('\n')
            .filter((l) => /Object|label:|ID:|type:|Usage:|Access:|Subject|Key size/i.test(l))
            .map((l) => l.trim()).join(' | ');
          console.log(`[pkcs11] objects after login: ${summary || all.trim().split('\n').slice(-3).join(' | ')}`);
          resolve(result);
        });
        return;
      }
      resolve(result);
    });
  });
}

export async function curlProxy(req: ProxyRequest, config: AgentConfig): Promise<ProxyResponse> {
  // Cache PIN in-memory when provided so subsequent requests don't need it from frontend
  if (req.pin) {
    cachePin(req.certificateId, req.pin);
  } else {
    const cached = getCachedPin(req.certificateId);
    if (cached) req = { ...req, pin: cached };
  }

  // PKCS#11 placeholder certificate: verify the PIN once and pin down the real id
  if (req.certificateId === PKCS11_AUTO_ID && req.pin) {
    const toolchain = pkcs11ToolchainFor(req, config);
    if (toolchain) {
      const login = await verifyPkcs11Login(toolchain, req.pin);
      if (!login.ok) {
        pinCache.delete(req.certificateId);
        throw new Error(login.message);
      }
      if (login.certIds.length > 0) {
        req = { ...req, certificateId: login.certIds[0] };
      }
    }
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

/**
 * PKCS#11 toolchain for this request, or null when the certificate lives in
 * the macOS Keychain / Windows store and platform curl handles it.
 */
function pkcs11ToolchainFor(req: ProxyRequest, config: AgentConfig): Pkcs11Toolchain | null {
  const os = platform();
  if (os === 'win32') return null;
  if (os === 'darwin' && !isPkcs11CertificateId(req.certificateId)) return null;
  const toolchain = resolvePkcs11Toolchain(config);
  if (!toolchain) return null;
  if (toolchain.missing.length > 0) {
    throw new Error(`PKCS#11 toolchain incomplete: ${toolchain.missing.join('; ')}`);
  }
  return toolchain;
}

function execCurl(req: ProxyRequest, config: AgentConfig): Promise<ProxyResponse> {
  return new Promise((resolve, reject) => {
    const toolchain = pkcs11ToolchainFor(req, config);
    const opts = buildCurlOptions(req, config, toolchain);
    const curlPath = toolchain?.curlPath ?? config.curlPath;
    const configFile = writeCurlConfig(opts);

    const child = spawn(curlPath, ['-K', configFile], {
      stdio: ['pipe', 'pipe', 'pipe'],
      timeout: 120_000,
      env: toolchain?.env ?? process.env,
    });
    child.on('exit', () => {
      try { unlinkSync(configFile); } catch { /* gone */ }
      for (const o of opts) {
        if (o.opt === 'form' && o.value) {
          const m = o.value.match(/=@([^;]+)/);
          if (m) { try { unlinkSync(m[1]); } catch { /* gone */ } }
        }
      }
    });

    const stdoutChunks: Buffer[] = [];
    let stderr = '';

    child.stdout.on('data', (chunk: Buffer) => { stdoutChunks.push(chunk); });
    child.stderr.on('data', (chunk: Buffer) => { stderr += chunk.toString(); });

    // Pipe body via stdin for POST/PUT (never as CLI arg)
    if (req.body && !req.multipart && req.method !== 'GET' && req.method !== 'HEAD') {
      child.stdin.write(req.body);
    }
    child.stdin.end();

    child.on('close', (code) => {
      const rawBuffer = Buffer.concat(stdoutChunks);

      if (code !== 0 && rawBuffer.length === 0) {
        console.error(`[proxy] ← curl exit ${code}: ${redactPin(stderr.trim().split('\n').slice(-3).join(' | '))}`);
        reject(new Error(`curl exited with code ${code}: ${redactPin(stderr)}`));
        return;
      }

      try {
        const result = parseResponse(rawBuffer);
        const preview = result.bodyEncoding === 'base64' ? `<binary ${result.body.length}b>` : result.body.replace(/\s+/g, ' ').slice(0, 160);
        console.log(`[proxy] ← ${result.statusCode} ${result.headers['content-type'] ?? ''} ${preview}`);
        if (code !== 0) console.error(`[proxy] curl exit ${code}: ${redactPin(stderr.trim().split('\n').slice(-2).join(' | '))}`);
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

/** One curl option; `value` undefined for flags. */
type CurlOpt = { opt: string; value?: string };

/** Options common to every transfer of a process (cookies, cert, redirects). */
function transferOptions(req: ProxyRequest, toolchain: Pkcs11Toolchain | null, useCert: boolean): CurlOpt[] {
  const cookiePath = getCookieJarPath(req.certificateId);
  const opts: CurlOpt[] = [
    { opt: 'location' },                       // ANAF's F5 does 302 chains
    { opt: 'cookie', value: cookiePath },
    { opt: 'cookie-jar', value: cookiePath },
    { opt: 'max-time', value: '120' },
  ];

  // Only attach the client certificate when there is no valid session cookie.
  // After the first mTLS handshake ANAF's F5 sets session cookies that allow
  // subsequent requests without re-presenting the certificate.
  if (useCert) {
    const os = platform();
    if (toolchain) {
      // PKCS#11 token via libp11's OpenSSL engine (macOS without a Keychain
      // driver, or Linux). The PIN travels inside the URI, inside the 0600
      // config file — never on the command line.
      const certUri = pkcs11Uri(req.certificateId, req.pin);
      opts.push(
        { opt: 'engine', value: 'pkcs11' },
        { opt: 'cert-type', value: 'ENG' },
        { opt: 'cert', value: certUri },
        { opt: 'key-type', value: 'ENG' },
        { opt: 'key', value: certUri },
      );
    } else if (os === 'darwin') {
      opts.push({ opt: 'cert', value: req.certificateId });
      if (req.pin) opts.push({ opt: 'pass', value: req.pin });
    } else if (os === 'win32') {
      opts.push({ opt: 'cert', value: `CurrentUser\\My\\${req.certificateId}` });
    }
  }
  return opts;
}

function buildCurlOptions(req: ProxyRequest, config: AgentConfig, toolchain: Pkcs11Toolchain | null): CurlOpt[] {
  const sessionValid = hasValidSession(getCookieJarPath(req.certificateId));
  const opts: CurlOpt[] = [
    { opt: 'silent' },
    { opt: 'show-error' },
    { opt: 'dump-header', value: '-' },        // headers to stdout, before the body
    { opt: 'request', value: req.method },
    ...transferOptions(req, toolchain, !sessionValid),
  ];

  // Body comes from stdin for methods that have one (never as a CLI arg)
  if (req.multipart) {
    // curl builds the multipart body itself from a private temp file; the file
    // is removed by execCurl once the process exits.
    const upload = writeUploadFile(req.multipart.fileName, req.multipart.contentBase64);
    opts.push({ opt: 'form', value: `${req.multipart.field}=@${upload};filename=${req.multipart.fileName.replace(/[;"\\]/g, '_')};type=${req.multipart.contentType ?? 'application/pdf'}` });
  } else if (req.method !== 'GET' && req.method !== 'HEAD') {
    opts.push({ opt: 'data', value: '@-' });
  }
  for (const [key, value] of Object.entries(req.headers)) {
    opts.push({ opt: 'header', value: `${key}: ${value}` });
  }
  opts.push({ opt: 'url', value: req.url });
  return opts;
}

/** curl config-file quoting: backslash, double quote and control characters are escaped. */
function curlQuote(value: string): string {
  return '"' + value.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\r/g, '\\r').replace(/\t/g, '\\t') + '"';
}

function curlConfigText(opts: CurlOpt[]): string {
  return opts.map((o) => (o.value === undefined ? o.opt : `${o.opt} = ${curlQuote(o.value)}`)).join('\n') + '\n';
}

const CONFIG_DIR = join(tmpdir(), 'storno-agent-curl');

/** Private (0600) copy of a file to upload; deleted together with the config file. */
function writeUploadFile(fileName: string, contentBase64: string): string {
  mkdirSync(CONFIG_DIR, { recursive: true, mode: 0o700 });
  const safe = fileName.replace(/[^A-Za-z0-9._-]+/g, '_').slice(0, 80) || 'upload.bin';
  const file = join(CONFIG_DIR, `${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}-${safe}`);
  writeFileSync(file, Buffer.from(contentBase64, 'base64'), { mode: 0o600 });
  return file;
}

/** Write a private (0600) curl config file; the caller deletes it once curl exits. */
function writeCurlConfig(opts: CurlOpt[]): string {
  mkdirSync(CONFIG_DIR, { recursive: true, mode: 0o700 });
  const file = join(CONFIG_DIR, `${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}.cfg`);
  writeFileSync(file, curlConfigText(opts), { mode: 0o600 });
  return file;
}

// ── Batch: many GETs in ONE curl process ─────────────────────────────────────
//
// Spawning curl per document meant loading the PKCS#11 module (≈12 s on the
// Longmai token) and a fresh mTLS handshake for every PDF. With `next`-separated
// transfers in a single process the engine is initialised once and the TLS
// connection is reused, so a download costs well under a second.

export interface BatchItemResult {
  index: number;
  result?: ProxyResponse;
  error?: string;
}

/**
 * Fetch several GET requests (same certificate) in one curl process.
 * Throws for whole-batch failures (PIN, toolchain, spawn); per-item problems
 * are reported in the returned array so the caller can retry them singly.
 */
export async function curlBatch(requests: ProxyRequest[], config: AgentConfig): Promise<BatchItemResult[]> {
  if (requests.length === 0) return [];
  let first = requests[0];
  for (const r of requests) {
    if (r.certificateId !== first.certificateId) throw new Error('curlBatch: all requests must use the same certificate');
    if ((r.method || 'GET').toUpperCase() !== 'GET' || r.body) throw new Error('curlBatch: only GET requests without a body');
  }

  if (first.pin) {
    cachePin(first.certificateId, first.pin);
  } else {
    const cached = getCachedPin(first.certificateId);
    if (cached) first = { ...first, pin: cached };
  }

  if (first.certificateId === PKCS11_AUTO_ID && first.pin) {
    const toolchain = pkcs11ToolchainFor(first, config);
    if (toolchain) {
      const login = await verifyPkcs11Login(toolchain, first.pin);
      if (!login.ok) {
        pinCache.delete(first.certificateId);
        throw new Error(login.message);
      }
      if (login.certIds.length > 0) first = { ...first, certificateId: login.certIds[0] };
    }
  }

  const cookiePath = getCookieJarPath(first.certificateId);
  const usedSession = hasValidSession(cookiePath);
  if (platform() === 'win32' && first.pin && !usedSession) {
    throw new Error('BATCH_UNSUPPORTED: Windows certificate store needs the PowerShell path for the first request');
  }

  let results = await execCurlBatch(first, requests, config, usedSession);
  if (usedSession && results.some((r) => r.result && isSessionExpired(r.result))) {
    invalidateSession(first.certificateId);
    results = await execCurlBatch(first, requests, config, false);
  }
  return results;
}

function execCurlBatch(auth: ProxyRequest, requests: ProxyRequest[], config: AgentConfig, sessionValid: boolean): Promise<BatchItemResult[]> {
  return new Promise((resolve, reject) => {
    const toolchain = pkcs11ToolchainFor(auth, config);
    const curlPath = toolchain?.curlPath ?? config.curlPath;
    const workDir = mkdtempSync(join(tmpdir(), 'storno-agent-batch-'));
    const common = transferOptions(auth, toolchain, !sessionValid);

    const opts: CurlOpt[] = [{ opt: 'silent' }, { opt: 'show-error' }];
    requests.forEach((req, i) => {
      if (i > 0) opts.push({ opt: 'next' });
      opts.push(...common);
      for (const [key, value] of Object.entries(req.headers)) opts.push({ opt: 'header', value: `${key}: ${value}` });
      opts.push(
        { opt: 'dump-header', value: join(workDir, `h${i}`) },
        { opt: 'output', value: join(workDir, `b${i}`) },
        { opt: 'url', value: req.url },
      );
    });
    const configFile = writeCurlConfig(opts);
    console.log(`[proxy] batch GET ×${requests.length} → curl (${sessionValid ? 'cookies only' : 'cert'}), one process`);
    const startedAt = Date.now();

    const child = spawn(curlPath, ['-K', configFile], {
      stdio: ['ignore', 'ignore', 'pipe'],
      timeout: Math.min(120_000 * requests.length, 20 * 60_000),
      env: toolchain?.env ?? process.env,
    });
    let stderr = '';
    child.stderr.on('data', (chunk: Buffer) => { stderr += chunk.toString(); });

    const cleanup = () => {
      try { unlinkSync(configFile); } catch { /* gone */ }
      try { rmSync(workDir, { recursive: true, force: true }); } catch { /* best effort */ }
    };

    child.on('error', (err) => {
      cleanup();
      reject(new Error(`Failed to spawn curl: ${err.message}`));
    });

    child.on('close', (code) => {
      const results: BatchItemResult[] = [];
      try {
        const stderrTail = redactPin(stderr.trim().split('\n').slice(-3).join(' | '));
        if (/PIN verification failed|Failed to set PIN|CKR_PIN_INCORRECT|CKR_PIN_LOCKED/i.test(stderr)) {
          cleanup();
          reject(new Error(`PIN verification failed: ${stderrTail}`));
          return;
        }
        for (let i = 0; i < requests.length; i++) {
          const hFile = join(workDir, `h${i}`);
          const bFile = join(workDir, `b${i}`);
          if (!existsSync(hFile) || statSync(hFile).size === 0) {
            results.push({ index: i, error: `no response (curl exit ${code}: ${stderrTail})` });
            continue;
          }
          const body = existsSync(bFile) ? readFileSync(bFile) : Buffer.alloc(0);
          results.push({ index: i, result: buildResponse(readFileSync(hFile), body) });
        }
        const ok = results.filter((r) => r.result).length;
        console.log(`[proxy] ← batch ${ok}/${requests.length} ok in ${((Date.now() - startedAt) / 1000).toFixed(1)}s${code !== 0 ? ` (curl exit ${code}: ${stderrTail})` : ''}`);
      } finally {
        cleanup();
      }
      resolve(results);
    });
  });
}

/** Never let a PIN reach logs or error messages (it travels inside the PKCS#11 URI). */
function redactPin(text: string): string {
  return text.replace(/pin-value=[^;'"\s]*/g, 'pin-value=<redacted>');
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
  const lastStatusIdx = lastStatusLineIndex(raw);
  if (lastStatusIdx === -1) {
    return { statusCode: 200, headers: {}, body: raw.toString('utf-8') };
  }
  const headerEnd = raw.indexOf(Buffer.from('\r\n\r\n'), lastStatusIdx);
  if (headerEnd === -1) {
    return { statusCode: 200, headers: {}, body: raw.subarray(lastStatusIdx).toString('utf-8') };
  }
  const { statusCode, headers } = parseHeaderSection(raw.subarray(lastStatusIdx, headerEnd).toString('ascii'));
  return finishResponse(statusCode, headers, raw.subarray(headerEnd + 4));
}

/** Response from a separate header dump (-D file) and body file (-o file). */
function buildResponse(headerDump: Buffer, body: Buffer): ProxyResponse {
  const lastStatusIdx = lastStatusLineIndex(headerDump);
  if (lastStatusIdx === -1) return finishResponse(200, {}, body);
  const { statusCode, headers } = parseHeaderSection(headerDump.subarray(lastStatusIdx).toString('ascii'));
  return finishResponse(statusCode, headers, body);
}

function lastStatusLineIndex(raw: Buffer): number {
  const headerMarker = Buffer.from('HTTP/');
  let lastStatusIdx = -1;
  let searchFrom = 0;
  while (true) {
    const idx = raw.indexOf(headerMarker, searchFrom);
    if (idx === -1) break;
    if (idx === 0 || raw[idx - 1] === 0x0A) lastStatusIdx = idx;
    searchFrom = idx + 1;
  }
  return lastStatusIdx;
}

function parseHeaderSection(headerSection: string): { statusCode: number; headers: Record<string, string> } {
  const headers: Record<string, string> = {};
  let statusCode = 200;
  for (const line of headerSection.split('\r\n')) {
    const statusMatch = line.match(/^HTTP\/[\d.]+ (\d+)/);
    if (statusMatch) {
      statusCode = parseInt(statusMatch[1], 10);
      continue;
    }
    const colonIdx = line.indexOf(':');
    if (colonIdx > 0) {
      headers[line.substring(0, colonIdx).trim().toLowerCase()] = line.substring(colonIdx + 1).trim();
    }
  }
  return { statusCode, headers };
}

function finishResponse(statusCode: number, headers: Record<string, string>, bodyBuffer: Buffer): ProxyResponse {
  // For binary content types, base64-encode the body to preserve data integrity
  if (isBinaryContentType(headers['content-type'])) {
    return { statusCode, headers, body: bodyBuffer.toString('base64'), bodyEncoding: 'base64' };
  }
  return { statusCode, headers, body: bodyBuffer.toString('utf-8') };
}
