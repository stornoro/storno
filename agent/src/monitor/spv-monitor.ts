/**
 * Unattended SPV inbox sync.
 *
 * Once a company is enrolled (from the web app, Company → ANAF → "Monitorizare
 * SPV automată"), the agent periodically repeats what the browser would do:
 *   1. POST {api}/api/v1/spv/sync-prepare        → ANAF listaMesaje URL
 *   2. GET  listaMesaje through the certificate   (curl + PKCS#11 / Keychain)
 *   3. POST {api}/api/v1/spv/sync-agent-result    → documents to fetch
 *   4. GET  each descarcare URL through the certificate
 *   5. POST {api}/api/v1/spv/documents/{id}/agent-document
 * The backend classifies, archives and pushes notifications (somatii etc.).
 *
 * Credentials: the Storno API key (scopes declaration.view + declaration.submit)
 * and the token PIN live in the OS secret store (see secrets.ts); the rest of
 * the enrollment is in ~/.storno-agent/monitor.json.
 */

import { existsSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import type { AgentConfig } from '../config.js';
import { getConfigDir } from '../config.js';
import { curlProxy, curlBatch, type ProxyResponse } from '../proxy/curl-proxy.js';
import { getSecret, setSecret, deleteSecret, secretStoreName, certPinAccount } from './secrets.js';

export interface MonitorEntry {
  companyId: string;
  organizationId: string | null;
  cif: string;
  name: string;
  certificateId: string;
  intervalHours: number;
  enabled: boolean;
  apiBase: string;
  /** Backend id of the API key, so the web app can revoke it when disabling. */
  apiTokenId: string | null;
  createdAt: string;
  lastRunAt: string | null;
  lastSuccessAt: string | null;
  lastResult: SpvSyncResult | null;
  lastError: string | null;
  consecutiveFailures: number;
}

export interface SpvSyncResult {
  received: number;
  created: number;
  skipped: number;
  downloaded: number;
  failed: number;
}

interface MonitorFile {
  entries: MonitorEntry[];
}

const FILE = () => join(getConfigDir(), 'monitor.json');
const TICK_MS = 15 * 60 * 1000;          // scheduler wakes every 15 min
const BOOT_DELAY_MS = 90 * 1000;         // let the token/middleware settle after boot
const WAKE_SETTLE_MS = 60 * 1000;        // same after the computer wakes from sleep (USB re-enumeration)
/** Retries when the token answers but does not expose the certificate yet (typical right after wake). */
const TOKEN_RETRY_DELAYS_MS = [20_000, 40_000, 90_000];
const MIN_INTERVAL_HOURS = 1;
const MAX_INTERVAL_HOURS = 24 * 7;
const DAYS_BACK = 60;

/** Backends the agent is allowed to talk to with the stored API key. */
const ALLOWED_API_HOSTS = [/^api\.storno\.ro$/, /^api\.storno\.test$/, /^localhost$/, /^127\.0\.0\.1$/, /\.storno\.ro$/];

export function loadMonitor(): MonitorFile {
  try {
    if (!existsSync(FILE())) return { entries: [] };
    const parsed = JSON.parse(readFileSync(FILE(), 'utf-8')) as MonitorFile;
    return { entries: Array.isArray(parsed.entries) ? parsed.entries : [] };
  } catch {
    return { entries: [] };
  }
}

function saveMonitor(data: MonitorFile): void {
  mkdirSync(getConfigDir(), { recursive: true });
  writeFileSync(FILE(), JSON.stringify(data, null, 2) + '\n', { mode: 0o600 });
}

function assertApiBase(apiBase: string): string {
  let u: URL;
  try {
    u = new URL(apiBase);
  } catch {
    throw new Error('apiBase must be an absolute URL');
  }
  const ok = ALLOWED_API_HOSTS.some((re) => re.test(u.hostname));
  if (!ok || (u.protocol !== 'https:' && !/^(localhost|127\.0\.0\.1|api\.storno\.test)$/.test(u.hostname))) {
    throw new Error(`apiBase host not allowed: ${u.hostname}`);
  }
  return `${u.protocol}//${u.host}${u.pathname.replace(/\/+$/, '')}`;
}

export interface EnrollInput {
  companyId: string;
  organizationId?: string | null;
  cif: string;
  name?: string;
  certificateId: string;
  intervalHours?: number;
  enabled?: boolean;
  apiBase: string;
  apiTokenId?: string | null;
  /** Secrets — stored in the OS secret store, never in monitor.json */
  apiKey?: string;
  pin?: string;
}

export function enroll(input: EnrollInput): MonitorEntry {
  if (!input.companyId || !input.cif || !input.certificateId) {
    throw new Error('companyId, cif and certificateId are required');
  }
  const apiBase = assertApiBase(input.apiBase);
  const data = loadMonitor();
  const existing = data.entries.find((e) => e.companyId === input.companyId);

  if (input.apiKey) setSecret(`apikey:${input.companyId}`, input.apiKey);
  else if (!existing && !getSecret(`apikey:${input.companyId}`)) throw new Error('apiKey is required on first enrollment');
  if (input.pin) setSecret(`pin:${input.companyId}`, input.pin);
  else if (!existing && !getSecret(`pin:${input.companyId}`)) {
    // The PIN remembered for this certificate ("Retine PIN-ul pe acest calculator") is enough.
    const remembered = getSecret(certPinAccount(input.certificateId));
    if (!remembered) throw new Error('pin is required on first enrollment');
    setSecret(`pin:${input.companyId}`, remembered);
  }

  const interval = Math.min(MAX_INTERVAL_HOURS, Math.max(MIN_INTERVAL_HOURS, Number(input.intervalHours ?? existing?.intervalHours ?? 6)));
  const entry: MonitorEntry = {
    companyId: input.companyId,
    organizationId: input.organizationId ?? existing?.organizationId ?? null,
    cif: input.cif,
    name: input.name ?? existing?.name ?? input.cif,
    certificateId: input.certificateId,
    intervalHours: interval,
    enabled: input.enabled ?? true,
    apiBase,
    apiTokenId: input.apiTokenId ?? existing?.apiTokenId ?? null,
    createdAt: existing?.createdAt ?? new Date().toISOString(),
    lastRunAt: existing?.lastRunAt ?? null,
    lastSuccessAt: existing?.lastSuccessAt ?? null,
    lastResult: existing?.lastResult ?? null,
    lastError: existing?.lastError ?? null,
    consecutiveFailures: existing?.consecutiveFailures ?? 0,
  };

  data.entries = [...data.entries.filter((e) => e.companyId !== entry.companyId), entry];
  saveMonitor(data);
  return entry;
}

/** Remove the enrollment and its secrets. Returns the API token id to revoke server-side. */
export function unenroll(companyId: string): { removed: boolean; apiTokenId: string | null } {
  const data = loadMonitor();
  const entry = data.entries.find((e) => e.companyId === companyId);
  data.entries = data.entries.filter((e) => e.companyId !== companyId);
  saveMonitor(data);
  deleteSecret(`apikey:${companyId}`);
  deleteSecret(`pin:${companyId}`);
  return { removed: !!entry, apiTokenId: entry?.apiTokenId ?? null };
}

export interface MonitorStatus extends MonitorEntry {
  nextRunAt: string | null;
  running: boolean;
  hasPin: boolean;
  hasApiKey: boolean;
  secretStore: string;
}

let runningCompany: string | null = null;

export function statusList(): MonitorStatus[] {
  const store = secretStoreName();
  return loadMonitor().entries.map((e) => ({
    ...e,
    nextRunAt: nextRunAt(e),
    running: runningCompany === e.companyId,
    hasPin: !!getSecret(`pin:${e.companyId}`),
    hasApiKey: !!getSecret(`apikey:${e.companyId}`),
    secretStore: store,
  }));
}

function nextRunAt(e: MonitorEntry): string | null {
  if (!e.enabled) return null;
  if (!e.lastRunAt) return new Date().toISOString();
  // back off after repeated failures: interval × (1 + failures), capped at 24h
  const hours = Math.min(24, e.intervalHours * (1 + Math.min(e.consecutiveFailures, 3)));
  return new Date(new Date(e.lastRunAt).getTime() + hours * 3600_000).toISOString();
}

function isDue(e: MonitorEntry): boolean {
  const n = nextRunAt(e);
  return !!n && new Date(n).getTime() <= Date.now();
}

// ── Backend calls ──────────────────────────────────────────────────

async function api<T>(entry: MonitorEntry, apiKey: string, method: string, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    // API keys go raw in Authorization (no "Bearer"), see ApiKeyAuthenticator
    'Authorization': apiKey,
    'X-Company': entry.companyId,
    'User-Agent': 'storno-agent-monitor',
  };
  if (entry.organizationId) headers['X-Organization'] = entry.organizationId;

  const res = await fetch(`${entry.apiBase}/api/v1${path}`, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
    signal: AbortSignal.timeout(60_000),
  });
  const text = await res.text();
  let json: unknown = null;
  try { json = text ? JSON.parse(text) : null; } catch { /* not json */ }
  if (!res.ok) {
    const err = (json as { error?: string; code?: string } | null);
    throw new Error(`${method} ${path} → HTTP ${res.status}${err?.code ? ' ' + err.code : ''}${err?.error ? ': ' + err.error : ''}`);
  }
  return json as T;
}

// ── One sync run ───────────────────────────────────────────────────

/**
 * Errors that mean "the token is plugged in but not usable right now": the
 * PKCS#11 middleware has not re-enumerated the USB device after sleep, or
 * curl's engine could not find the certificate object. They pass after a few
 * seconds, so a run is retried before it counts as a failure.
 */
export function isTransientTokenError(message: string): boolean {
  return /object not found|cannot load client cert|failed to load private key|CKR_TOKEN_NOT_PRESENT|CKR_DEVICE_REMOVED|CKR_DEVICE_ERROR|CKR_SLOT_ID_INVALID|CKR_CRYPTOKI_NOT_INITIALIZED|CKR_FUNCTION_FAILED|CKR_GENERAL_ERROR|no slot with a token|token not present|Token-ul nu a raspuns/i.test(message);
}

/** Romanian explanation shown on the ANAF page when every retry failed the same way. */
export function describeTokenError(message: string): string {
  return `Token-ul nu a raspuns: middleware-ul nu a gasit certificatul pe token (se intampla imediat dupa sleep sau dupa reconectarea token-ului). Verifica sa fie conectat; agentul reincearca la urmatoarea rulare. Detalii: ${message}`;
}

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

async function withTokenRetry<T>(label: string, fn: () => Promise<T>): Promise<T> {
  for (let attempt = 0; ; attempt++) {
    try {
      return await fn();
    } catch (err) {
      const message = (err as Error).message;
      if (!isTransientTokenError(message) || attempt >= TOKEN_RETRY_DELAYS_MS.length) throw err;
      const delay = TOKEN_RETRY_DELAYS_MS[attempt];
      console.warn(`[spv-monitor] ${label}: token not ready (${redactPin(message).split('\n')[0]}); retry ${attempt + 1}/${TOKEN_RETRY_DELAYS_MS.length} in ${delay / 1000}s`);
      await sleep(delay);
    }
  }
}

function redactPin(message: string): string {
  return message.replace(/pin-value=[^;'"\s]*/g, 'pin-value=<redacted>').replace(/--pin\s+\S+/g, '--pin <redacted>');
}

export async function runSync(companyId: string, config: AgentConfig): Promise<SpvSyncResult> {
  const data = loadMonitor();
  const entry = data.entries.find((e) => e.companyId === companyId);
  if (!entry) throw new Error(`Company ${companyId} is not enrolled`);
  if (runningCompany) throw new Error(`A sync is already running for ${runningCompany}`);

  const apiKey = getSecret(`apikey:${companyId}`);
  const pin = getSecret(`pin:${companyId}`);
  if (!apiKey) throw new Error('API key missing from the secret store; re-enable monitoring from the web app');
  if (!pin) throw new Error('PIN missing from the secret store; re-enable monitoring from the web app');

  runningCompany = companyId;
  const startedAt = new Date().toISOString();
  console.log(`[spv-monitor] ${entry.name} (${entry.cif}): sync started`);

  try {
    const prepared = await api<{ anafUrl: string }>(entry, apiKey, 'POST', '/spv/sync-prepare', { days: DAYS_BACK });

    const listing = await withTokenRetry(entry.cif, () => curlProxy({
      url: prepared.anafUrl,
      method: 'GET',
      headers: {},
      body: '',
      certificateId: entry.certificateId,
      pin,
    }, config));

    const result = await api<{
      stats: { created: number; skipped: number; received: number };
      documents: Array<{ documentId: string; anafUrl: string; messageType: string }>;
    }>(entry, apiKey, 'POST', '/spv/sync-agent-result', { statusCode: listing.statusCode, body: listing.body });

    let downloaded = 0;
    let failed = 0;
    const docs = result.documents ?? [];
    const CHUNK = 25;
    for (let i = 0; i < docs.length; i += CHUNK) {
      const chunk = docs.slice(i, i + CHUNK);
      const reqs = chunk.map((doc) => ({ url: doc.anafUrl, method: 'GET', headers: {}, body: '', certificateId: entry.certificateId, pin }));
      // One curl process for the whole chunk; anything it missed is retried singly.
      const responses = new Map<number, ProxyResponse>();
      try {
        for (const item of await curlBatch(reqs, config)) if (item.result) responses.set(item.index, item.result);
      } catch (err) {
        const msg = (err as Error).message;
        if (msg.includes('PIN verification failed') || msg.includes('Failed to set PIN')) throw err;
      }
      for (let j = 0; j < chunk.length; j++) {
        const doc = chunk[j];
        try {
          const res = responses.get(j) ?? await curlProxy(reqs[j], config);
          await api(entry, apiKey, 'POST', `/spv/documents/${doc.documentId}/agent-document`, {
            statusCode: res.statusCode,
            body: res.body,
            bodyEncoding: res.bodyEncoding,
          });
          downloaded++;
        } catch (err) {
          failed++;
          console.error(`[spv-monitor] ${entry.cif}: document ${doc.documentId} (${doc.messageType}) failed: ${(err as Error).message}`);
        }
      }
    }

    const summary: SpvSyncResult = { ...result.stats, downloaded, failed };
    persistRun(companyId, { lastRunAt: startedAt, lastSuccessAt: new Date().toISOString(), lastResult: summary, lastError: null, consecutiveFailures: 0 });
    console.log(`[spv-monitor] ${entry.cif}: ${summary.received} messages, ${summary.created} new, ${downloaded} PDFs archived, ${failed} failed`);
    return summary;
  } catch (err) {
    const raw = redactPin((err as Error).message);
    const message = isTransientTokenError(raw) && !raw.startsWith('Token-ul nu a raspuns') ? describeTokenError(raw) : raw;
    persistRun(companyId, { lastRunAt: startedAt, lastError: message, consecutiveFailures: (entry.consecutiveFailures ?? 0) + 1 });
    console.error(`[spv-monitor] ${entry.cif}: sync failed: ${message}`);
    throw new Error(message);
  } finally {
    runningCompany = null;
  }
}

function persistRun(companyId: string, patch: Partial<MonitorEntry>): void {
  const data = loadMonitor();
  data.entries = data.entries.map((e) => (e.companyId === companyId ? { ...e, ...patch } : e));
  saveMonitor(data);
}

// ── Scheduler ──────────────────────────────────────────────────────

let timer: NodeJS.Timeout | null = null;
let lastTickAt = 0;
let ticking = false;

export function startSpvMonitor(config: AgentConfig): void {
  if (timer) return;
  const tick = async () => {
    if (ticking) return;
    ticking = true;
    try {
      // A tick much later than scheduled means the computer slept: the USB
      // token is re-enumerated on wake and the middleware needs a moment.
      const now = Date.now();
      const slept = lastTickAt > 0 && now - lastTickAt > TICK_MS * 2;
      lastTickAt = now;
      const due = loadMonitor().entries.filter((e) => e.enabled && isDue(e));
      if (due.length === 0) return;
      if (slept) {
        console.log(`[spv-monitor] resumed after sleep; waiting ${WAKE_SETTLE_MS / 1000}s for the token`);
        await sleep(WAKE_SETTLE_MS);
      }
      for (const entry of due) {
        try {
          await runSync(entry.companyId, config);
        } catch {
          // already logged and persisted
        }
      }
    } finally {
      ticking = false;
    }
  };
  setTimeout(() => { void tick(); }, BOOT_DELAY_MS).unref();
  timer = setInterval(() => { void tick(); }, TICK_MS);
  timer.unref();

  const enabled = loadMonitor().entries.filter((e) => e.enabled);
  if (enabled.length) {
    console.log(`[spv-monitor] ${enabled.length} enrolled compan${enabled.length === 1 ? 'y' : 'ies'} (secrets in ${secretStoreName()})`);
  }
}
