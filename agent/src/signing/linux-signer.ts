/**
 * CMS/PKCS#7 signing through OpenSSL with libp11's PKCS#11 engine.
 *
 * Used on Linux, and on macOS for tokens whose middleware has no Keychain
 * (CryptoTokenKit) driver. The toolchain (openssl binary, engines dir,
 * module path) is resolved by utils/toolchain.ts so the same code serves an
 * Intel-under-Rosetta chain on Apple Silicon.
 */

import { execFile } from 'node:child_process';
import { existsSync } from 'node:fs';
import { getConfigDir } from '../config.js';
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
    // OpenSSL 3 dropped `-certform engine`: the signer certificate must be a PEM
    // file, so it is read once from the token with pkcs11-tool and cached.
    const certPem = await exportCertificatePem(certificateId, pin, toolchain);

    await new Promise<void>((resolve, reject) => {
      // `smime` (PKCS#7 SignedData) instead of `cms`: with OpenSSL 3 + the pkcs11 engine,
      // `cms -sign` produces signatures that do not verify (ANAF: "Eroare citire semnatura"),
      // while `smime -sign` verifies. The output is a detached DER SignedData, which is
      // exactly what a PDF /Contents (adbe.pkcs7.detached) expects.
      const args = [
        'smime', '-sign',
        '-binary',
        '-in', dataPath,
        '-outform', 'DER',
        '-out', sigPath,
        '-md', 'sha256',
        // detached CMS: the PDF byte range is the content, only the signature goes into /Contents
        '-engine', 'pkcs11',
        '-keyform', 'engine',
        '-inkey', keyUri,
        '-signer', certPem,
      ];

      execFile(toolchain.opensslPath as string, args, {
        timeout: 60_000,
        env: toolchain.env,
      }, (err, _stdout, stderr) => {
        if (err) {
          const msg = stderr || err.message;
          // Only a real PIN rejection counts as a PIN failure (the URI itself contains "pin-value").
          if (/CKR_PIN_INCORRECT|CKR_PIN_INVALID|CKR_PIN_LEN_RANGE|CKR_PIN_LOCKED|incorrect PIN|PIN incorrect/i.test(msg)) {
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

/**
 * The signer certificate as a PEM file (cached in the config dir). Certificates
 * are usually public objects; when the token keeps them private, retry with the PIN.
 */
async function exportCertificatePem(certificateId: string, pin: string | undefined, toolchain: Pkcs11Toolchain): Promise<string> {
  const dir = join(getConfigDir(), 'certs');
  mkdirSync(dir, { recursive: true, mode: 0o700 });
  const pem = join(dir, `${certificateId.replace(/[^a-zA-Z0-9]/g, '')}.pem`);
  if (existsSync(pem)) return pem;
  if (!toolchain.pkcs11ToolPath) throw new Error('pkcs11-tool is needed to read the certificate from the token');

  const der = pem + '.der';
  const read = (login: boolean) => new Promise<void>((resolve, reject) => {
    const args = ['--module', toolchain.module, '--read-object', '--type', 'cert', '--id', certificateId, '-o', der];
    if (login && pin) args.push('--login', '--pin', pin);
    execFile(toolchain.pkcs11ToolPath as string, args, { timeout: 60_000, env: toolchain.env }, (err, _out, stderr) => {
      if (err || !existsSync(der)) reject(new Error(`certificate export failed: ${(stderr || err?.message || '').toString().replace(/--pin\s+\S+/g, '--pin <redacted>').trim()}`));
      else resolve();
    });
  });
  try {
    await read(false);
  } catch (e) {
    if (!pin) throw e;
    await read(true);
  }
  await new Promise<void>((resolve, reject) => {
    execFile(toolchain.opensslPath as string, ['x509', '-inform', 'DER', '-in', der, '-out', pem], { timeout: 30_000, env: toolchain.env }, (err, _o, stderr) => {
      if (err) reject(new Error(`certificate conversion failed: ${stderr || err.message}`)); else resolve();
    });
  });
  try { unlinkSync(der); } catch { /* ignore */ }
  return pem;
}
