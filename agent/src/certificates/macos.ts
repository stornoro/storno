import { execFileSync } from 'node:child_process';

import type { CertificateKind } from './kinds.js';

export interface Certificate {
  id: string;
  subject: string;
  issuer: string;
  notAfter: string | null;
  source: 'keychain' | 'windows-store' | 'pkcs11';
  /** token (PIN), cloud (vendor approval, no PIN) or software (no PIN); absent on agents < 1.8. */
  kind: CertificateKind;
  /** Windows key provider (CSP/KSP) that holds the key, when known. */
  provider?: string;
}

/**
 * List SSL client certificates from macOS Keychain using `security`.
 */
export function listMacOSCertificates(): Certificate[] {
  try {
    const output = execFileSync('security', ['find-identity', '-p', 'ssl-client'], {
      encoding: 'utf-8',
      timeout: 10_000,
    });

    const certs: Certificate[] = [];
    // Lines: 1) THUMBPRINT "CN Name" or 1) THUMBPRINT "CN Name" (CSSMERR_...)
    const regex = /^\s*\d+\)\s+([A-F0-9]+)\s+"(.+?)"(?:\s+\((.+?)\))?$/gm;
    let match: RegExpExecArray | null;

    while ((match = regex.exec(output)) !== null) {
      const status = match[3] || '';
      // Skip expired certificates
      if (status.includes('CERT_EXPIRED')) continue;

      certs.push({
        id: match[1],
        subject: match[2],
        issuer: '',
        notAfter: null,
        source: 'keychain',
        kind: 'token',
      });
    }

    return certs;
  } catch {
    return [];
  }
}
