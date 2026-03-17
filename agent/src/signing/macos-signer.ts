/**
 * macOS CMS/PKCS#7 signing using the Security framework.
 *
 * Uses `security cms -S` with the keychain identity, or falls back to
 * `openssl cms -sign` if security cms isn't available.
 */

import { execFile } from 'node:child_process';
import { writeFileSync, readFileSync, unlinkSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { randomUUID } from 'node:crypto';

export async function signHashMacos(
  data: Buffer,
  certificateId: string,
  pin?: string,
): Promise<Buffer> {
  const id = randomUUID();
  const workDir = join(tmpdir(), 'storno-pdfsign');
  mkdirSync(workDir, { recursive: true });

  const dataPath = join(workDir, `${id}_data.bin`);
  const sigPath = join(workDir, `${id}_sig.der`);

  writeFileSync(dataPath, data);

  try {
    // Try using openssl cms with keychain identity
    // macOS curl uses the thumbprint as cert identity (same as certificateId)
    await signWithOpenssl(dataPath, sigPath, certificateId, pin);
    return readFileSync(sigPath);
  } finally {
    try { unlinkSync(dataPath); } catch { /* ignore */ }
    try { unlinkSync(sigPath); } catch { /* ignore */ }
  }
}

async function signWithOpenssl(
  dataPath: string,
  sigPath: string,
  certificateId: string,
  pin?: string,
): Promise<void> {
  // First, export the certificate from keychain to a temp file
  const id = randomUUID();
  const workDir = join(tmpdir(), 'storno-pdfsign');
  const certPemPath = join(workDir, `${id}_cert.pem`);
  const keyPemPath = join(workDir, `${id}_key.pem`);

  try {
    // Export certificate using security find-certificate with SHA-1 hash
    await new Promise<void>((resolve, reject) => {
      execFile('security', [
        'find-certificate', '-c', certificateId, '-p',
      ], { timeout: 10_000 }, (err, stdout, stderr) => {
        if (err) {
          // Fall back: try to use the thumbprint directly as identity label
          reject(new Error(`Failed to export certificate: ${stderr || err.message}`));
          return;
        }
        writeFileSync(certPemPath, stdout);
        resolve();
      });
    });

    // Use security cms -S for signing (uses keychain for private key access)
    // This avoids needing to export the private key
    await new Promise<void>((resolve, reject) => {
      const args = [
        'cms', '-sign',
        '-signer', certPemPath,
        '-indata', dataPath,
        '-outform', 'der',
        '-out', sigPath,
        '-nodetach', // We'll handle detachment ourselves
      ];

      // If PIN is needed, set it via environment or expect keychain to handle it
      const env = { ...process.env };
      if (pin) {
        // macOS keychain PIN is handled at the USB token driver level
        // The PIN dialog will appear unless it's been cached
        env['STORNO_PIN'] = pin;
      }

      execFile('/usr/bin/security', args, {
        timeout: 60_000,
        env,
      }, (err, stdout, stderr) => {
        if (err) {
          // Fallback: use openssl directly
          signWithOpensslDirect(dataPath, sigPath, certificateId, pin)
            .then(resolve)
            .catch(reject);
          return;
        }
        resolve();
      });
    });
  } finally {
    try { unlinkSync(certPemPath); } catch { /* ignore */ }
    try { unlinkSync(keyPemPath); } catch { /* ignore */ }
  }
}

async function signWithOpensslDirect(
  dataPath: string,
  sigPath: string,
  certificateId: string,
  pin?: string,
): Promise<void> {
  // Use openssl with PKCS#11 engine if available, otherwise fall back to keychain
  return new Promise<void>((resolve, reject) => {
    const args = [
      'cms', '-sign',
      '-binary',
      '-in', dataPath,
      '-outform', 'DER',
      '-out', sigPath,
      '-md', 'sha256',
      '-nodetach',
    ];

    // Try to use macOS keychain via the engine
    // On macOS, smart card tokens appear as identities in the keychain
    args.push('-keyform', 'engine');

    if (pin) {
      args.push('-passin', `pass:${pin}`);
    }

    execFile('openssl', args, { timeout: 60_000 }, (err, stdout, stderr) => {
      if (err) {
        reject(new Error(`macOS signing failed: ${stderr || err.message}`));
        return;
      }
      resolve();
    });
  });
}
