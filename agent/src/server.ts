import { type IncomingMessage, type ServerResponse } from 'node:http';
import { createServer } from 'node:https';
import { loadConfig, type AgentConfig } from './config.js';
import { discoverCertificates } from './certificates/discovery.js';
import { curlProxy, type ProxyRequest } from './proxy/curl-proxy.js';
import { CERT, KEY } from './certs.js';
import { checkForUpdate, applyUpdate, type UpdateInfo } from './updater.js';

const VERSION = '1.1.0';

/** Allowed proxy target hosts. */
const ALLOWED_HOSTS = ['webserviced.anaf.ro', 'epatrim.anaf.ro'];

function readBody(req: IncomingMessage): Promise<string> {
  return new Promise((resolve, reject) => {
    let data = '';
    req.on('data', (chunk: Buffer) => { data += chunk.toString(); });
    req.on('end', () => resolve(data));
    req.on('error', reject);
  });
}

function json(res: ServerResponse, statusCode: number, body: unknown): void {
  const payload = JSON.stringify(body);
  res.writeHead(statusCode, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(payload),
  });
  res.end(payload);
}

function setCorsHeaders(res: ServerResponse, origin: string | undefined, config: AgentConfig): boolean {
  if (!origin) {
    return true; // Non-browser requests (curl, etc.) are fine
  }

  const allowed = config.allowedOrigins.some(o => origin === o || origin.startsWith(o));
  if (!allowed) {
    return false;
  }

  res.setHeader('Access-Control-Allow-Origin', origin);
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Storno-Agent');
  res.setHeader('Access-Control-Max-Age', '86400');
  return true;
}

function handleRequest(req: IncomingMessage, res: ServerResponse, config: AgentConfig): void {
  const origin = req.headers['origin'] as string | undefined;
  const corsOk = setCorsHeaders(res, origin, config);

  if (!corsOk) {
    json(res, 403, { error: 'Origin not allowed' });
    return;
  }

  // Handle CORS preflight
  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  const url = req.url ?? '/';

  if (url === '/health' && req.method === 'GET') {
    handleHealth(res);
  } else if (url === '/update' && req.method === 'POST') {
    handleUpdate(res);
  } else if (url === '/certificates' && req.method === 'GET') {
    handleCertificates(res, config);
  } else if (url === '/proxy' && req.method === 'POST') {
    handleProxy(req, res, config);
  } else if (url === '/batch' && req.method === 'POST') {
    handleBatch(req, res, config);
  } else {
    json(res, 404, { error: 'Not found' });
  }
}

async function handleHealth(res: ServerResponse): Promise<void> {
  const update = await checkForUpdate(VERSION);
  json(res, 200, {
    status: 'ok',
    version: VERSION,
    platform: process.platform,
    update: update.updateAvailable ? {
      available: true,
      latest: update.latestVersion,
      download: update.downloadUrl,
    } : { available: false },
  });
}

async function handleUpdate(res: ServerResponse): Promise<void> {
  const result = await applyUpdate(VERSION);
  json(res, result.success ? 200 : 400, result);
}

function handleCertificates(res: ServerResponse, config: AgentConfig): void {
  const certificates = discoverCertificates(config.pkcs11Module);
  json(res, 200, { certificates });
}

async function handleProxy(req: IncomingMessage, res: ServerResponse, config: AgentConfig): Promise<void> {
  // Require X-Storno-Agent header
  if (req.headers['x-storno-agent'] !== '1') {
    json(res, 403, { error: 'Missing X-Storno-Agent header' });
    return;
  }

  let body: string;
  try {
    body = await readBody(req);
  } catch {
    json(res, 400, { error: 'Failed to read request body' });
    return;
  }

  let proxyReq: ProxyRequest;
  try {
    proxyReq = JSON.parse(body);
  } catch {
    json(res, 400, { error: 'Invalid JSON' });
    return;
  }

  // Validate required fields
  if (!proxyReq.url || !proxyReq.method || !proxyReq.certificateId) {
    json(res, 400, { error: 'Missing required fields: url, method, certificateId' });
    return;
  }

  // URL allowlist check
  try {
    const targetUrl = new URL(proxyReq.url);
    if (!ALLOWED_HOSTS.includes(targetUrl.hostname)) {
      json(res, 403, { error: `Host not allowed: ${targetUrl.hostname}` });
      return;
    }
  } catch {
    json(res, 400, { error: 'Invalid URL' });
    return;
  }

  try {
    const result = await curlProxy(proxyReq, config);
    json(res, 200, result);
  } catch (err) {
    json(res, 502, {
      error: 'Proxy request failed',
      details: (err as Error).message,
    });
  }
}

async function handleBatch(req: IncomingMessage, res: ServerResponse, config: AgentConfig): Promise<void> {
  // Require X-Storno-Agent header
  if (req.headers['x-storno-agent'] !== '1') {
    json(res, 403, { error: 'Missing X-Storno-Agent header' });
    return;
  }

  let body: string;
  try {
    body = await readBody(req);
  } catch {
    json(res, 400, { error: 'Failed to read request body' });
    return;
  }

  let payload: { requests: ProxyRequest[] };
  try {
    payload = JSON.parse(body);
  } catch {
    json(res, 400, { error: 'Invalid JSON' });
    return;
  }

  if (!Array.isArray(payload.requests) || payload.requests.length === 0) {
    json(res, 400, { error: 'Missing required field: requests (non-empty array)' });
    return;
  }

  // Validate all requests use the same certificateId
  const certIds = new Set(payload.requests.map(r => r.certificateId));
  if (certIds.size > 1) {
    json(res, 400, { error: 'All requests must use the same certificateId' });
    return;
  }

  // Validate all requests
  for (let i = 0; i < payload.requests.length; i++) {
    const r = payload.requests[i];
    if (!r.url || !r.method || !r.certificateId) {
      json(res, 400, { error: `Request [${i}]: missing required fields: url, method, certificateId` });
      return;
    }
    try {
      const targetUrl = new URL(r.url);
      if (!ALLOWED_HOSTS.includes(targetUrl.hostname)) {
        json(res, 400, { error: `Request [${i}]: host not allowed: ${targetUrl.hostname}` });
        return;
      }
    } catch {
      json(res, 400, { error: `Request [${i}]: invalid URL` });
      return;
    }
  }

  // Execute requests sequentially (PIN cached after first)
  const results: Array<{ index: number; statusCode: number; headers: Record<string, string>; body: string; error?: string }> = [];

  for (let i = 0; i < payload.requests.length; i++) {
    try {
      const result = await curlProxy(payload.requests[i], config);
      results.push({ index: i, statusCode: result.statusCode, headers: result.headers, body: result.body });
    } catch (err) {
      results.push({ index: i, statusCode: 0, headers: {}, body: '', error: (err as Error).message });
    }
  }

  json(res, 200, { results });
}

export function startServer(config?: AgentConfig): void {
  const cfg = config ?? loadConfig();
  const handler = (req: IncomingMessage, res: ServerResponse) => handleRequest(req, res, cfg);

  const server = createServer({ cert: CERT, key: KEY }, handler);
  const port = cfg.port;

  server.listen(port, '127.0.0.1', async () => {
    console.log(`Storno ANAF Agent v${VERSION}`);
    console.log(`Listening on https://agent.storno.ro:${port}`);
    console.log(`Platform: ${process.platform}`);
    console.log('');
    console.log('Endpoints:');
    console.log(`  GET  https://agent.storno.ro:${port}/health`);
    console.log(`  GET  https://agent.storno.ro:${port}/certificates`);
    console.log(`  POST https://agent.storno.ro:${port}/proxy`);
    console.log(`  POST https://agent.storno.ro:${port}/batch`);
    console.log(`  POST https://agent.storno.ro:${port}/update`);
    console.log('');
    console.log('Protocol: storno-agent:// registered');
    console.log('Press Ctrl+C to stop.');

    // Check for updates in background
    try {
      const update = await checkForUpdate(VERSION);
      if (update.updateAvailable) {
        console.log('');
        console.log(`*** Update available: v${update.latestVersion} (current: v${VERSION}) ***`);
        console.log(`    Download: https://get.storno.ro/agent`);
        console.log(`    Or POST https://agent.storno.ro:${port}/update to auto-update`);
      }
    } catch {
      // Silent — don't block startup
    }
  });

  server.on('error', (err: NodeJS.ErrnoException) => {
    if (err.code === 'EADDRINUSE') {
      console.error(`Port ${port} is already in use. Is another agent running?`);
      process.exit(1);
    }
    throw err;
  });
}
