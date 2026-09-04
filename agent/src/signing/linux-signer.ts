/**
 * CMS/PKCS#7 signing through OpenSSL with libp11's PKCS#11 engine.
 *
 * Used on Linux, and on macOS for tokens whose middleware has no Keychain
 * (CryptoTokenKit) driver. The toolchain (openssl binary, engines dir,
 * module path) is resolved by utils/toolchain.ts so the same code serves an
 * Intel-under-Rosetta chain on Apple Silicon.
 */

import { execFile } from 'node:child_process';
import { writeFileSync, readFileSync, unlinkSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { randomUUID } from 'node:crypto';
import { pkcs11Uri, type Pkcs11Toolchain } from '../utils/toolchain.js';

export async function signHashPkcs11(
  data: Buffer,
  certificateId: string,
  pin: string | undefined,
  toolchain: Pkcs11Toolchain | null,
): Promise<Buffer> {
  if (!toolchain) {
    throw new Error('PDF signing needs a PKCS#11 module. Configure with: storno-agent config --pkcs11-module /path/to/module');
  }
  if (toolchain.missing.length > 0 || !toolchain.opensslPath) {
    throw new Error(`PKCS#11 toolchain incomplete: ${toolchain.missing.join('; ')}`);
  }

  const id = randomUUID();
  const workDir = join(tmpdir(), 'storno-pdfsign');
  mkdirSync(workDir, { recursive: true });

  const dataPath = join(workDir, `${id}_data.bin`);
  const sigPath = join(workDir, `${id}_sig.der`);

  writeFileSync(dataPath, data);

  try {
    const keyUri = pkcs11Uri(certificateId, pin);
    const certUri = pkcs11Uri(certificateId);

    await new Promise<void>((resolve, reject) => {
      const args = [
        'cms', '-sign',
        '-binary',
        '-in', dataPath,
        '-outform', 'DER',
        '-out', sigPath,
        '-md', 'sha256',
        '-nodetach',
        '-engine', 'pkcs11',
        '-keyform', 'engine',
        '-inkey', keyUri,
        '-certform', 'engine',
        '-signer', certUri,
      ];

      execFile(toolchain.opensslPath as string, args, {
        timeout: 60_000,
        env: toolchain.env,
      }, (err, _stdout, stderr) => {
        if (err) {
          const msg = stderr || err.message;
          if (/pin/i.test(msg) || msg.includes('CKR_PIN')) {
            reject(new Error('PIN verification failed'));
          } else {
            reject(new Error(`PKCS#11 signing failed: ${msg}`));
          }
          return;
        }
        resolve();
      });
    });

    return readFileSync(sigPath);
  } finally {
    try { unlinkSync(dataPath); } catch { /* ignore */ }
    try { unlinkSync(sigPath); } catch { /* ignore */ }
  }
}

/** @deprecated use signHashPkcs11 */
export const signHashLinux = signHashPkcs11;
