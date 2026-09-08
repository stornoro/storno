/**
 * Permanently remembered certificate PINs.
 *
 * The web app (and the MCP tools) may leave `pin` out of a request when the
 * user chose to remember it on this computer: the PIN then comes from the OS
 * secret store (macOS Keychain, Windows DPAPI, libsecret; see
 * monitor/secrets.ts). A certificate enrolled for unattended SPV monitoring
 * already has its PIN stored under the company, so that copy counts too.
 *
 * Nothing is ever sent to ANAF without a PIN: when neither the request nor
 * the store has one, the proxy still answers PIN_REQUIRED.
 */

import { getSecret, setSecret, deleteSecret, certPinAccount, secretStoreName } from './monitor/secrets.js';
import { loadMonitor } from './monitor/spv-monitor.js';

function sameId(a: string, b: string): boolean {
  return a.trim().toUpperCase() === b.trim().toUpperCase();
}

export function storePin(certificateId: string, pin: string): void {
  if (!certificateId.trim()) throw new Error('certificateId is required');
  if (!pin) throw new Error('pin is required');
  setSecret(certPinAccount(certificateId), pin);
}

export function forgetPin(certificateId: string): void {
  deleteSecret(certPinAccount(certificateId));
}

/** The remembered PIN for a certificate: its own entry first, then any monitor enrollment using the same certificate. */
export function getStoredPin(certificateId: string): string | null {
  if (!certificateId) return null;
  const own = getSecret(certPinAccount(certificateId));
  if (own) return own;
  for (const entry of loadMonitor().entries) {
    if (!sameId(entry.certificateId, certificateId)) continue;
    const pin = getSecret(`pin:${entry.companyId}`);
    if (pin) return pin;
  }
  return null;
}

export function hasStoredPin(certificateId: string): boolean {
  return getStoredPin(certificateId) !== null;
}

export function pinStoreName(): string {
  return secretStoreName();
}

/** Fill in the PIN from the store when the request did not carry one. */
export function withStoredPin<T extends { certificateId: string; pin?: string }>(req: T): T {
  if (req.pin) return req;
  const pin = getStoredPin(req.certificateId);
  return pin ? { ...req, pin } : req;
}
