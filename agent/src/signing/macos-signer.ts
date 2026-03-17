/**
 * macOS CMS/PKCS#7 signing using the Security framework.
 *
 * Uses `security cms -S -N <certName>` which accesses the private key
 * directly from the keychain (works with USB tokens / smart cards).
 */

import { execFile } from 'node:child_process';
import { writeFileSync, readFileSync, unlinkSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { randomUUID } from 'node:crypto';

export async function signHashMacos(
  data: Buffer,
  certificateId: string,
  _pin?: string,
): Promise<Buffer> {
  const id = randomUUID();
  const workDir = join(tmpdir(), 'storno-pdfsign');
  mkdirSync(workDir, { recursive: true });

  const dataPath = join(workDir, `${id}_data.bin`);
  const sigPath = join(workDir, `${id}_sig.der`);

  writeFileSync(dataPath, data);

  try {
    // Resolve thumbprint → certificate common name for `security cms -S -N`
    const certName = await resolveCertName(certificateId);

    // Sign using `security cms -S -N <name> -T` — detached CMS signature
    // -T excludes content from the CMS structure (required for PDF /adbe.pkcs7.detached)
    await new Promise<void>((resolve, reject) => {
      execFile('/usr/bin/security', [
        'cms', '-S',
        '-N', certName,
        '-T',
        '-H', 'SHA256',
        '-i', dataPath,
        '-o', sigPath,
      ], { timeout: 60_000 }, (err, _stdout, stderr) => {
        if (err) {
          reject(new Error(`macOS signing failed: ${stderr || err.message}`));
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

/**
 * Resolve a SHA-1 certificate thumbprint to the certificate's common name
 * (label) in the macOS keychain. This is needed because `security cms -S -N`
 * expects a certificate nickname, not a thumbprint.
 */
async function resolveCertName(thumbprint: string): Promise<string> {
  return new Promise<string>((resolve, reject) => {
    execFile('security', [
      'find-certificate', '-a', '-Z',
    ], { timeout: 10_000, maxBuffer: 10 * 1024 * 1024 }, (err, stdout, stderr) => {
      if (err) {
        reject(new Error(`Failed to list certificates: ${stderr || err.message}`));
        return;
      }

      // Parse output to find the certificate with matching SHA-1 hash
      const blocks = stdout.split('SHA-256 hash:');
      for (const block of blocks) {
        if (block.includes(`SHA-1 hash: ${thumbprint.toUpperCase()}`)) {
          // Extract the label ("labl" attribute) — this is the certificate nickname
          const lablMatch = block.match(/"labl"<blob>="([^"]+)"/);
          if (lablMatch) {
            resolve(lablMatch[1]);
            return;
          }
        }
      }

      reject(new Error(`Certificate ${thumbprint} not found in keychain`));
    });
  });
}
