import { platform } from 'node:os';
import { listMacOSCertificates, type Certificate } from './macos.js';
import { listWindowsCertificates } from './windows.js';
import { listLinuxCertificates } from './linux.js';

export type { Certificate };

/**
 * Discover certificates from the platform-appropriate store.
 */
export function discoverCertificates(pkcs11Module: string | null): Certificate[] {
  const os = platform();

  switch (os) {
    case 'darwin':
      return listMacOSCertificates();
    case 'win32':
      return listWindowsCertificates();
    case 'linux':
      return listLinuxCertificates(pkcs11Module);
    default:
      return [];
  }
}
