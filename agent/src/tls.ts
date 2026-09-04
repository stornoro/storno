/**
 * TLS material for https://agent.storno.ro (DNS A → 127.0.0.1).
 *
 * The certificate is a public Let's Encrypt cert that lives 90 days. It is
 * embedded in the binary (certs.ts) so a fresh install works offline, but a
 * binary older than the cert can never renew itself, so the agent also:
 *
 *   1. keeps the newest known bundle in ~/.storno-agent/tls/,
 *   2. fetches https://get.storno.ro/agent/tls.json at startup and every 12h,
 *   3. swaps the live server context when a newer, valid bundle shows up.
 *
 * Bundling the private key is intentional and unchanged: the name resolves to
 * 127.0.0.1 only, the key protects nothing but the loopback hop.
 */

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { X509Certificate, createPrivateKey, createPublicKey } from 'node:crypto';
import type { Server } from 'node:https';
import { CERT as EMBEDDED_CERT, KEY as EMBEDDED_KEY } from './certs.js';
import { getConfigDir } from './config.js';

export const TLS_BUNDLE_URL = 'https://get.storno.ro/agent/tls.json';
export const TLS_HOSTNAME = 'agent.storno.ro';
const REFRESH_INTERVAL_MS = 12 * 60 * 60 * 1000;

export interface TlsBundle {
  cert: string;
  key: string;
  /** notAfter of the leaf certificate */
  expiresAt: Date;
  source: 'embedded' | 'cached' | 'remote';
}

function tlsDir(): string {
  return join(getConfigDir(), 'tls');
}

function leaf(pem: string): X509Certificate {
  return new X509Certificate(pem);
}

/**
 * Validate a cert/key pair: parses, is for agent.storno.ro, key matches.
 * Returns the bundle or null. Expiry is NOT enforced here so the caller can
 * still pick the least-expired option when everything is stale.
 */
export function parseBundle(cert: string, key: string, source: TlsBundle['source']): TlsBundle | null {
  try {
    const x = leaf(cert);
    const subjectOk = x.subject.includes(`CN=${TLS_HOSTNAME}`)
      || (x.subjectAltName ?? '').includes(`DNS:${TLS_HOSTNAME}`);
    if (!subjectOk) return null;
    const priv = createPrivateKey(key);
    const pubFromKey = createPublicKey(priv).export({ type: 'spki', format: 'der' }) as Buffer;
    const pubFromCert = x.publicKey.export({ type: 'spki', format: 'der' }) as Buffer;
    if (!pubFromKey.equals(pubFromCert)) return null;
    return { cert, key, expiresAt: new Date(x.validTo), source };
  } catch {
    return null;
  }
}

export function loadCachedBundle(): TlsBundle | null {
  try {
    const dir = tlsDir();
    const certPath = join(dir, 'agent.crt');
    const keyPath = join(dir, 'agent.key');
    if (!existsSync(certPath) || !existsSync(keyPath)) return null;
    return parseBundle(readFileSync(certPath, 'utf-8'), readFileSync(keyPath, 'utf-8'), 'cached');
  } catch {
    return null;
  }
}

export function saveCachedBundle(bundle: TlsBundle): void {
  const dir = tlsDir();
  mkdirSync(dir, { recursive: true });
  writeFileSync(join(dir, 'agent.crt'), bundle.cert, { mode: 0o644 });
  writeFileSync(join(dir, 'agent.key'), bundle.key, { mode: 0o600 });
}

export function embeddedBundle(): TlsBundle {
  return parseBundle(EMBEDDED_CERT, EMBEDDED_KEY, 'embedded')
    ?? { cert: EMBEDDED_CERT, key: EMBEDDED_KEY, expiresAt: new Date(0), source: 'embedded' };
}

/** Newest of embedded and cached, used to start listening immediately. */
export function bestLocalBundle(): TlsBundle {
  const embedded = embeddedBundle();
  const cached = loadCachedBundle();
  if (cached && cached.expiresAt > embedded.expiresAt) return cached;
  return embedded;
}

export function isExpired(bundle: TlsBundle, graceDays = 0): boolean {
  return bundle.expiresAt.getTime() - graceDays * 86_400_000 < Date.now();
}

export async function fetchRemoteBundle(): Promise<TlsBundle | null> {
  try {
    const res = await fetch(TLS_BUNDLE_URL, {
      headers: { 'User-Agent': 'storno-agent' },
      signal: AbortSignal.timeout(15_000),
    });
    if (!res.ok) return null;
    const data = await res.json() as { cert?: string; key?: string };
    if (!data.cert || !data.key) return null;
    const bundle = parseBundle(data.cert, data.key, 'remote');
    if (!bundle || isExpired(bundle)) return null;
    return bundle;
  } catch {
    return null;
  }
}

/**
 * Fetch the published bundle and, when it outlives the current one, persist
 * it and hot-swap the HTTPS server's secure context. Returns the bundle in
 * use afterwards.
 */
export async function refreshTls(server: Server | null, current: TlsBundle): Promise<TlsBundle> {
  const remote = await fetchRemoteBundle();
  if (!remote || remote.expiresAt <= current.expiresAt) return current;

  try {
    saveCachedBundle(remote);
  } catch {
    // still usable in memory
  }
  if (server) {
    server.setSecureContext({ cert: remote.cert, key: remote.key });
  }
  console.log(`[tls] ${TLS_HOSTNAME} certificate renewed, valid until ${remote.expiresAt.toISOString()}`);
  return remote;
}

/** Start the periodic refresh loop; returns a getter for the live bundle. */
export function startTlsRefresh(server: Server, initial: TlsBundle): () => TlsBundle {
  let live = initial;
  const tick = async () => {
    live = await refreshTls(server, live);
  };
  // First check shortly after boot, then twice a day.
  setTimeout(tick, 5_000).unref();
  setInterval(tick, REFRESH_INTERVAL_MS).unref();
  return () => live;
}
