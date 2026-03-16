import { execFileSync } from 'node:child_process';
import type { Certificate } from './macos.js';

/**
 * List client certificates from Windows Certificate Store via PowerShell.
 *
 * Hardware USB tokens (SafeNet, Feitian, certSIGN) install a CSP/KSP that
 * makes their certificates visible in Cert:\CurrentUser\My. However, the
 * HasPrivateKey property may return false for hardware-backed keys because
 * the private key isn't directly accessible to .NET — it's on the device.
 *
 * We look for certificates that either:
 * - Have a private key (software certs), OR
 * - Were issued by known Romanian CAs (hardware token certs)
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

export function listWindowsCertificates(): Certificate[] {
  try {
    // Query all certs — filter later to catch hardware tokens without HasPrivateKey
    const script = `
      Get-ChildItem Cert:\\CurrentUser\\My |
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

    return items
      .filter((item: any) => item.HasPrivateKey || isLikelyAnafCert(item.Issuer ?? ''))
      .map((item: any) => ({
        id: item.Thumbprint,
        subject: item.Subject ?? '',
        issuer: item.Issuer ?? '',
        notAfter: item.NotAfter ? new Date(item.NotAfter).toISOString() : null,
        source: 'windows-store' as const,
      }));
  } catch {
    return [];
  }
}
