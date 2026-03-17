/**
 * Linux CMS/PKCS#7 signing using OpenSSL with PKCS#11 engine.
 *
 * Uses the PKCS#11 module configured for the agent (same as cert discovery/curl).
 */

import { execFile } from 'node:child_process';
import { writeFileSync, readFileSync, unlinkSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { randomUUID } from 'node:crypto';

export async function signHashLinux(
  data: Buffer,
  certificateId: string,
  pin?: string,
  pkcs11Module?: string,
): Promise<Buffer> {
  if (!pkcs11Module) {
    throw new Error('Linux PDF signing requires a PKCS#11 module. Configure with: storno-agent config --pkcs11-module /path/to/module.so');
  }

  const id = randomUUID();
  const workDir = join(tmpdir(), 'storno-pdfsign');
  mkdirSync(workDir, { recursive: true });

  const dataPath = join(workDir, `${id}_data.bin`);
  const sigPath = join(workDir, `${id}_sig.der`);

  writeFileSync(dataPath, data);

  try {
    // Build PKCS#11 URI for the key
    const keyUri = pin
      ? `pkcs11:id=%${certificateId};pin-value=${pin}`
      : `pkcs11:id=%${certificateId}`;

    const certUri = `pkcs11:id=%${certificateId}`;

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
        '-signer', certUri,
      ];

      const env = {
        ...process.env,
        MODULE_PATH: pkcs11Module,
      };

      execFile('openssl', args, {
        timeout: 60_000,
        env,
      }, (err, stdout, stderr) => {
        if (err) {
          const msg = stderr || err.message;
          if (msg.includes('PIN') || msg.includes('pin')) {
            reject(new Error('PIN verification failed'));
          } else {
            reject(new Error(`Linux signing failed: ${msg}`));
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
