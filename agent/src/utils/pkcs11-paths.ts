/**
 * Known PKCS#11 middleware paths for common Romanian hardware tokens.
 */

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
