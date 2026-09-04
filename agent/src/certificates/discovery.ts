import { platform } from 'node:os';
import { listMacOSCertificates, type Certificate } from './macos.js';
import { listWindowsCertificates } from './windows.js';
import { listPkcs11Certificates, listPkcs11CertificatesAsync } from './linux.js';
import type { AgentConfig } from '../config.js';
import { resolvePkcs11Toolchain } from '../utils/toolchain.js';

export type { Certificate };

/**
 * Discover certificates from the platform-appropriate store(s).
 *
 * - Windows: certificate store
 * - macOS: Keychain (tokens with a CryptoTokenKit driver) plus any PKCS#11
 *   module that is configured or auto-detected (vendor middleware without a
 *   Keychain driver, e.g. Longmai mToken CryptoID)
 * - Linux: PKCS#11 module, configured or auto-detected
 */
export function discoverCertificates(config: AgentConfig): Certificate[] {
  const os = platform();

  switch (os) {
    case 'win32':
      return listWindowsCertificates();
    case 'darwin': {
      const keychain = listMacOSCertificates();
      const toolchain = resolvePkcs11Toolchain(config);
      const pkcs11 = toolchain && toolchain.missing.length === 0 ? listPkcs11Certificates(toolchain) : [];
      return [...keychain, ...pkcs11];
    }
    case 'linux':
      return listPkcs11Certificates(resolvePkcs11Toolchain(config));
    default:
      return [];
  }
}

/** Non-blocking variant for the server's background cache. */
export async function discoverCertificatesAsync(config: AgentConfig): Promise<Certificate[]> {
  const os = platform();
  switch (os) {
    case 'win32':
      return listWindowsCertificates();
    case 'darwin': {
      const keychain = listMacOSCertificates();
      const toolchain = resolvePkcs11Toolchain(config);
      const pkcs11 = toolchain && toolchain.missing.length === 0 ? await listPkcs11CertificatesAsync(toolchain) : [];
      return [...keychain, ...pkcs11];
    }
    case 'linux':
      return listPkcs11CertificatesAsync(resolvePkcs11Toolchain(config));
    default:
      return [];
  }
}

/**
 * Does this certificate id belong to the PKCS#11 side (as opposed to the
 * macOS Keychain)? Keychain ids are SHA-1 thumbprints that `security
 * find-identity` lists; anything else on macOS is assumed to be PKCS#11.
 */
export function isPkcs11CertificateId(id: string): boolean {
  if (platform() !== 'darwin') return true;
  const keychainIds = new Set(listMacOSCertificates().map((c) => c.id.toUpperCase()));
  return !keychainIds.has(id.toUpperCase());
}
