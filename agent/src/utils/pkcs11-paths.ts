/**
 * Known PKCS#11 middleware paths for common Romanian hardware tokens.
 */

import { existsSync } from 'node:fs';
import { platform, homedir } from 'node:os';
import { join } from 'node:path';

export interface Pkcs11Module {
  name: string;
  vendor: string;
  paths: {
    win32?: string[];
    darwin?: string[];
    linux?: string[];
  };
}

export const KNOWN_PKCS11_MODULES: Pkcs11Module[] = [
  {
    name: 'SafeNet eToken',
    vendor: 'SafeNet / Thales',
    paths: {
      win32: [
        'C:\\Windows\\System32\\eTPKCS11.dll',
        'C:\\Windows\\System32\\eToken.dll',
      ],
      darwin: [
        '/usr/local/lib/libeTPkcs11.dylib',
        '/Library/Frameworks/eToken.framework/Versions/Current/libeToken.dylib',
      ],
      linux: [
        '/usr/lib/libeTPkcs11.so',
        '/usr/lib64/libeTPkcs11.so',
        '/usr/lib/x86_64-linux-gnu/libeTPkcs11.so',
      ],
    },
  },
  {
    // Sold in Romania by CertDigital. The standard (non-FIPS) macOS build is
    // x86_64 only, so on Apple Silicon it needs an Intel toolchain under Rosetta.
    name: 'Longmai mToken CryptoID',
    vendor: 'Century Longmai (CertDigital, Trans Sped)',
    paths: {
      win32: [
        'C:\\Windows\\System32\\cryptoide_pkcs11.dll',
      ],
      darwin: [
        // Trans Sped "macOS Sonoma" kit, v2.2.23.1017: universal (arm64 + x86_64)
        // and correct. Copy libcryptoide_pkcs11.dylib here or install its dmg app.
        '~/.storno-agent/lib/libcryptoide_pkcs11.dylib',
        '/Applications/CryptoIDEUserTools.app/Contents/lib/mac/libcryptoide_pkcs11.dylib',
        // CertDigital 2021 kit: x86_64 only and C_GetTokenInfo returns CKR_CANCEL
        // although the struct is filled (needs scripts/pkcs11-shim.c). Last resort.
        '/opt/CryptoIDE/lib/libcryptoide_pkcs11.dylib',
        '/Applications/CryptoUserTools.app/Contents/lib/mac/libcryptoide_pkcs11.dylib',
      ],
      linux: [
        '/opt/CryptoIDE/lib/libcryptoide_pkcs11.so',
        '/usr/lib/libcryptoide_pkcs11.so',
      ],
    },
  },
  {
    name: 'Longmai mToken CryptoID FIPS 140-3',
    vendor: 'Century Longmai / CertDigital',
    paths: {
      darwin: [
        '/Applications/CryptoIDFipsUserTools.app/Contents/lib/mac/liblm_cryptoide_pkcs11.dylib',
      ],
    },
  },
  {
    name: 'Feitian ePass',
    vendor: 'Feitian',
    paths: {
      win32: [
        'C:\\Windows\\System32\\eps2003csp11.dll',
        'C:\\Windows\\System32\\ngp11v211.dll',
      ],
      darwin: [
        '/usr/local/lib/libeps2003csp11.dylib',
      ],
      linux: [
        '/usr/lib/libeps2003csp11.so',
        '/usr/lib64/libeps2003csp11.so',
      ],
    },
  },
  {
    name: 'Bit4id miniLector',
    vendor: 'Bit4id',
    paths: {
      win32: [
        'C:\\Windows\\System32\\bit4ipki.dll',
      ],
      darwin: [
        '/usr/local/lib/libbit4ipki.dylib',
      ],
      linux: [
        '/usr/lib/libbit4ipki.so',
      ],
    },
  },
  {
    name: 'certSIGN',
    vendor: 'certSIGN',
    paths: {
      win32: [
        'C:\\Windows\\System32\\csepkcs11.dll',
      ],
      linux: [
        '/usr/lib/libcsepkcs11.so',
      ],
    },
  },
  {
    name: 'OpenSC',
    vendor: 'OpenSC',
    paths: {
      win32: [
        'C:\\Program Files\\OpenSC Project\\OpenSC\\pkcs11\\opensc-pkcs11.dll',
      ],
      darwin: [
        '/usr/local/lib/opensc-pkcs11.so',
        '/opt/homebrew/lib/opensc-pkcs11.so',
        '/Library/OpenSC/lib/opensc-pkcs11.so',
      ],
      linux: [
        '/usr/lib/opensc-pkcs11.so',
        '/usr/lib64/opensc-pkcs11.so',
        '/usr/lib/x86_64-linux-gnu/opensc-pkcs11.so',
      ],
    },
  },
];

/**
 * First known PKCS#11 module present on this machine, or null.
 * Vendor modules are listed before OpenSC so a token-specific driver wins.
 */
export function expandHome(p: string): string {
  return p.startsWith('~/') ? join(homedir(), p.slice(2)) : p;
}

export function detectPkcs11Module(): { path: string; name: string } | null {
  const os = platform() as 'win32' | 'darwin' | 'linux';
  for (const mod of KNOWN_PKCS11_MODULES) {
    for (const raw of mod.paths[os] ?? []) {
      const p = expandHome(raw);
      if (existsSync(p)) return { path: p, name: mod.name };
    }
  }
  return null;
}
