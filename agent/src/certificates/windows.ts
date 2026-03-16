import { execFileSync } from 'node:child_process';
import type { Certificate } from './macos.js';

/**
 * List SSL client certificates from Windows Certificate Store via PowerShell.
 */
export function listWindowsCertificates(): Certificate[] {
  try {
    const script = `
      Get-ChildItem Cert:\\CurrentUser\\My |
        Where-Object { $_.HasPrivateKey } |
        Select-Object Thumbprint, Subject, Issuer, NotAfter |
        ConvertTo-Json -Compress
    `;

    const output = execFileSync('powershell', ['-NoProfile', '-Command', script], {
      encoding: 'utf-8',
      timeout: 15_000,
    });

    const parsed = JSON.parse(output);
    const items = Array.isArray(parsed) ? parsed : [parsed];

    return items.map((item: any) => ({
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
