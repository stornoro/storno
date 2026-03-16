import { execFileSync } from 'node:child_process';
import type { Certificate } from './macos.js';

/**
 * List certificates from a PKCS#11 token (Linux) using `pkcs11-tool`.
 */
export function listLinuxCertificates(pkcs11Module: string | null): Certificate[] {
  if (!pkcs11Module) {
    return [];
  }

  try {
    const output = execFileSync('pkcs11-tool', [
      '--module', pkcs11Module,
      '--list-certificates',
    ], {
      encoding: 'utf-8',
      timeout: 15_000,
    });

    const certs: Certificate[] = [];
    const blocks = output.split('Certificate Object');

    for (const block of blocks) {
      const labelMatch = block.match(/label:\s+(.+)/i);
      const idMatch = block.match(/ID:\s+([a-f0-9]+)/i);
      const subjectMatch = block.match(/subject:\s+(.+)/i);

      if (labelMatch && idMatch) {
        certs.push({
          id: idMatch[1],
          subject: subjectMatch?.[1] ?? labelMatch[1],
          issuer: '',
          notAfter: null,
          source: 'pkcs11',
        });
      }
    }

    return certs;
  } catch {
    return [];
  }
}
