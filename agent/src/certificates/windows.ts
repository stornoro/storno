import { execFileSync } from 'node:child_process';
import type { Certificate } from './macos.js';
import type { AgentConfig } from '../config.js';
import { classifyWindowsProvider, isConfiguredCloudId } from './kinds.js';

/**
 * List client certificates from Windows Certificate Store via PowerShell.
 *
 * Hardware USB tokens (SafeNet, Feitian, certSIGN) install a CSP/KSP that
 * makes their certificates visible in Cert:\CurrentUser\My. However, the
 * HasPrivateKey property may return false for hardware-backed keys because
 * the private key isn't directly accessible to .NET — it's on the device.
 *
 * Only returns certificates issued by known Romanian qualified CAs accepted by ANAF.
 */
const KNOWN_RO_CA_ISSUERS = [
  'certsign',
  'digisign',
  'trans sped',
  'alfatrust',
  'centrul de calcul',
];

function isLikelyAnafCert(issuer: string): boolean {
  const lower = issuer.toLowerCase();
  return KNOWN_RO_CA_ISSUERS.some(ca => lower.includes(ca));
}

/** Parse .NET JSON date format \/Date(ms)\/ or ISO strings. */
function parseDotNetDate(value: unknown): string | null {
  if (!value || typeof value !== 'string') return null;
  const match = value.match(/\/Date\((\d+)\)\//);
  if (match) {
    return new Date(parseInt(match[1], 10)).toISOString();
  }
  const d = new Date(value);
  return isNaN(d.getTime()) ? null : d.toISOString();
}

/**
 * Key provider (CSP/KSP) per certificate thumbprint, read with certutil from the
 * certificate's key-provider property. certutil only reads the property; it never
 * opens the key, so no smart-card or cloud-vendor prompt appears.
 */
export function windowsKeyProviders(): Map<string, string> {
  const providers = new Map<string, string>();
  try {
    const output = execFileSync('certutil', ['-user', '-silent', '-store', 'My'], {
      encoding: 'utf-8',
      timeout: 15_000,
      windowsHide: true,
    });
    let hash: string | null = null;
    for (const line of output.split(/\r?\n/)) {
      if (/^=+\s*\S.*\d+\s*=+\s*$/.test(line)) { hash = null; continue; }
      const h = line.match(/\(sha1\):\s*([0-9a-f][0-9a-f\s]{38,}[0-9a-f])\s*$/i);
      if (h) { hash = h[1].replace(/\s+/g, '').toUpperCase(); continue; }
      const p = line.match(/^\s*Provider\s*=\s*(.+?)\s*$/i);
      if (p && hash && !providers.has(hash)) providers.set(hash, p[1]);
    }
  } catch {
    // certutil missing or blocked: every certificate stays a token (PIN required)
  }
  return providers;
}

export function listWindowsCertificates(config?: Pick<AgentConfig, 'cloudCertificateIds' | 'cloudCertificateProviders'>): Certificate[] {
  try {
    // Query all certs — filter later to catch hardware tokens without HasPrivateKey
    const script = `
      Get-ChildItem Cert:/CurrentUser/My |
        Select-Object Thumbprint, Subject, Issuer, NotAfter, HasPrivateKey |
        ConvertTo-Json -Compress
    `;

    const output = execFileSync('powershell', ['-NoProfile', '-Command', script], {
      encoding: 'utf-8',
      timeout: 15_000,
    });

    if (!output.trim()) return [];

    const parsed = JSON.parse(output);
    const items = Array.isArray(parsed) ? parsed : [parsed];
    const providers = windowsKeyProviders();

    return items
      .filter((item: any) => {
        if (!isLikelyAnafCert(item.Issuer ?? '')) return false;
        const expiry = parseDotNetDate(item.NotAfter);
        if (expiry && new Date(expiry) < new Date()) return false;
        return true;
      })
      .map((item: any) => {
        const id = String(item.Thumbprint ?? '').toUpperCase();
        const provider = providers.get(id) ?? '';
        const kind = isConfiguredCloudId(id, config) ? 'cloud' : classifyWindowsProvider(provider, config);
        return {
          id,
          subject: item.Subject ?? '',
          issuer: item.Issuer ?? '',
          notAfter: parseDotNetDate(item.NotAfter),
          source: 'windows-store' as const,
          kind,
          ...(provider ? { provider } : {}),
        };
      });
  } catch {
    return [];
  }
}
