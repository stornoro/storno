/**
 * Background certificate discovery cache.
 *
 * discoverCertificates() shells out to pkcs11-tool, and some vendor
 * middleware (Longmai) takes ~12s to initialise. The frontend gives
 * /certificates 5s, so the server answers from this cache and refreshes it
 * off the request path: once at boot, then every 60s, asynchronously.
 */

import type { AgentConfig } from '../config.js';
import type { Certificate } from './macos.js';
import { discoverCertificatesAsync } from './discovery.js';

const REFRESH_MS = 60_000;

let certificates: Certificate[] = [];
let refreshedAt: string | null = null;
let running = false;

export function getCachedCertificates(): { certificates: Certificate[]; refreshedAt: string | null } {
  return { certificates, refreshedAt };
}

async function refresh(config: AgentConfig): Promise<void> {
  if (running) return;
  running = true;
  try {
    certificates = await discoverCertificatesAsync(config);
    refreshedAt = new Date().toISOString();
  } catch (err) {
    console.error('[certificates] discovery failed:', (err as Error).message);
  } finally {
    running = false;
  }
}

export function startCertificateCache(config: AgentConfig): void {
  void refresh(config);
  setInterval(() => { void refresh(config); }, REFRESH_MS).unref();
}
