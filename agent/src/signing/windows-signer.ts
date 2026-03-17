/**
 * Windows CMS/PKCS#7 signing using .NET CmsSigner via PowerShell.
 *
 * Uses the same CNG SmartCardPin pattern as powershell-proxy.ts.
 */

import { execFile } from 'node:child_process';
import { writeFileSync, readFileSync, unlinkSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { randomUUID } from 'node:crypto';

export async function signHashWindows(
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

  // PowerShell script that:
  // 1. Loads certificate from Windows store
  // 2. Sets PIN on CNG key (if provided)
  // 3. Creates CMS/PKCS#7 detached signature
  // 4. Writes DER-encoded signature to file
  const pinBlock = pin ? `
# Set SmartCard PIN on CNG key to avoid dialog
$key = $cert.PrivateKey
if ($key -ne $null) {
    $cngKey = [System.Security.Cryptography.X509Certificates.RSACertificateExtensions]::GetRSAPrivateKey($cert)
    if ($cngKey -is [System.Security.Cryptography.RSACng]) {
        $cngKey.Key.SetProperty(
            (New-Object System.Security.Cryptography.CngProperty(
                "SmartCardPin",
                [System.Text.Encoding]::UTF8.GetBytes("${pin.replace(/"/g, '`"')}"),
                [System.Security.Cryptography.CngPropertyOptions]::None
            ))
        )
    }

    # Verify PIN works with a test sign
    try {
        $testData = [byte[]]@(1,2,3,4)
        $null = $cngKey.SignData($testData, [System.Security.Cryptography.HashAlgorithmName]::SHA256, [System.Security.Cryptography.RSASignaturePadding]::Pkcs1)
    } catch {
        Write-Error "PIN verification failed: $_"
        exit 1
    }
}
` : '';

  const script = `
$ErrorActionPreference = "Stop"

# Load certificate
$cert = Get-ChildItem -Path "Cert:\\CurrentUser\\My\\${certificateId}" -ErrorAction Stop

${pinBlock}

# Read data to sign
$dataBytes = [System.IO.File]::ReadAllBytes("${dataPath.replace(/\\/g, '\\\\')}")

# Create ContentInfo (the data being signed)
$contentInfo = New-Object System.Security.Cryptography.Pkcs.ContentInfo(,$dataBytes)

# Create SignedCms for detached signature
$signedCms = New-Object System.Security.Cryptography.Pkcs.SignedCms($contentInfo, $true)

# Create CmsSigner
$cmsSigner = New-Object System.Security.Cryptography.Pkcs.CmsSigner($cert)
$cmsSigner.DigestAlgorithm = New-Object System.Security.Cryptography.Oid("2.16.840.1.101.3.4.2.1") # SHA-256
$cmsSigner.IncludeOption = [System.Security.Cryptography.X509Certificates.X509IncludeOption]::EndCertOnly

# Sign
$signedCms.ComputeSignature($cmsSigner, $false)

# Get DER-encoded CMS signature
$sigBytes = $signedCms.Encode()

# Write to file
[System.IO.File]::WriteAllBytes("${sigPath.replace(/\\/g, '\\\\')}", $sigBytes)

Write-Output "OK"
`;

  try {
    await new Promise<void>((resolve, reject) => {
      execFile('powershell.exe', [
        '-NoProfile', '-NonInteractive', '-Command', script,
      ], { timeout: 60_000 }, (err, stdout, stderr) => {
        if (err) {
          const msg = stderr || stdout || err.message;
          if (msg.includes('PIN verification failed')) {
            reject(new Error('PIN verification failed'));
          } else {
            reject(new Error(`Windows signing failed: ${msg}`));
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
