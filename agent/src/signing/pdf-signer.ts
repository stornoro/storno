/**
 * PDF signing orchestrator.
 *
 * Two-phase signing process:
 * 1. Prepare: Add a signature placeholder to the PDF, compute the byte range hash
 * 2. Sign: Use platform-native crypto to sign the hash with USB certificate
 * 3. Embed: Insert the CMS/PKCS#7 signature back into the PDF placeholder
 *
 * Uses pure-JS PDF manipulation (no external binaries).
 */

import { writeFileSync, readFileSync, unlinkSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir, platform } from 'node:os';
import { randomUUID } from 'node:crypto';
import { signHashWindows } from './windows-signer.js';
import { signHashMacos } from './macos-signer.js';
import { signHashLinux } from './linux-signer.js';

const SIGNATURE_PLACEHOLDER_LENGTH = 16384; // 16KB for CMS signature — large enough for cert chain

/**
 * Sign a PDF with a USB certificate.
 *
 * @param pdfBuffer - Unsigned PDF as Buffer
 * @param certificateId - Certificate thumbprint/ID
 * @param pin - Optional PIN for smart card
 * @param pkcs11Module - PKCS#11 module path (Linux only)
 * @returns Signed PDF as Buffer
 */
export async function signPdf(
  pdfBuffer: Buffer,
  certificateId: string,
  pin?: string,
  pkcs11Module?: string | null,
): Promise<Buffer> {
  const id = randomUUID();
  const workDir = join(tmpdir(), 'storno-pdfsign');
  mkdirSync(workDir, { recursive: true });

  const unsignedPath = join(workDir, `${id}_unsigned.pdf`);
  const preparedPath = join(workDir, `${id}_prepared.pdf`);
  const hashPath = join(workDir, `${id}_hash.bin`);
  const sigPath = join(workDir, `${id}_sig.der`);
  const signedPath = join(workDir, `${id}_signed.pdf`);

  try {
    writeFileSync(unsignedPath, pdfBuffer);

    // Phase 1: Prepare PDF with signature placeholder
    const { preparedPdf, byteRange, placeholderOffset } = preparePdfForSigning(pdfBuffer);
    writeFileSync(preparedPath, preparedPdf);

    // Compute hash of the byte ranges (everything except the placeholder)
    const hashInput = computeByteRangeData(preparedPdf, byteRange);
    writeFileSync(hashPath, hashInput);

    // Phase 2: Sign the hash using platform-specific signer
    const signature = await platformSign(hashInput, certificateId, pin, pkcs11Module);
    writeFileSync(sigPath, signature);

    // Phase 3: Embed signature into prepared PDF
    const signedPdf = embedSignature(preparedPdf, signature, placeholderOffset);
    writeFileSync(signedPath, signedPdf);

    return signedPdf;
  } finally {
    // Cleanup temp files
    for (const f of [unsignedPath, preparedPath, hashPath, sigPath, signedPath]) {
      try { unlinkSync(f); } catch { /* ignore */ }
    }
  }
}

/**
 * Prepare a PDF for signing by adding a signature dictionary with a placeholder.
 *
 * This modifies the PDF to include:
 * - A Sig dictionary with /Type /Sig, /Filter /Adobe.PPKLite, /SubFilter /adbe.pkcs7.detached
 * - /ByteRange and /Contents placeholders
 * - An updated xref and trailer
 */
function preparePdfForSigning(pdf: Buffer): {
  preparedPdf: Buffer;
  byteRange: [number, number, number, number];
  placeholderOffset: number;
} {
  // Find existing xref offset from the PDF trailer
  const pdfStr = pdf.toString('binary');
  const startxrefMatch = pdfStr.match(/startxref\s+(\d+)/);
  if (!startxrefMatch) throw new Error('Invalid PDF: no startxref found');

  const existingXrefOffset = parseInt(startxrefMatch[1], 10);

  // Parse the trailer to find the root object reference
  const trailerMatch = pdfStr.match(/trailer\s*<<([\s\S]*?)>>/);
  let rootRef = '1 0 R';
  let currentSize = 10;
  if (trailerMatch) {
    const rootMatch = trailerMatch[1].match(/\/Root\s+(\d+ \d+ R)/);
    if (rootMatch) rootRef = rootMatch[1];
    const sizeMatch = trailerMatch[1].match(/\/Size\s+(\d+)/);
    if (sizeMatch) currentSize = parseInt(sizeMatch[1], 10);
  }

  // Create new objects for the signature
  const sigObjNum = currentSize;
  const sigFieldObjNum = currentSize + 1;

  // Build signature dictionary object — placeholder hex string for /Contents
  const placeholderHex = '0'.repeat(SIGNATURE_PLACEHOLDER_LENGTH * 2);

  // We'll build the incremental update
  const parts: string[] = [];

  // Signature value object
  parts.push(`${sigObjNum} 0 obj\n`);
  parts.push(`<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached\n`);
  parts.push(`/ByteRange [0 0000000000 0000000000 0000000000]\n`);
  parts.push(`/Contents <${placeholderHex}>\n`);
  parts.push(`/M (D:${formatPdfDate(new Date())})\n`);
  parts.push(`/Reason (Storno Digital Signature)\n`);
  parts.push(`>>\nendobj\n\n`);

  // Append to existing PDF
  const appendStr = parts.join('');
  const appendOffset = pdf.length;

  // Compute actual positions for ByteRange patching
  const fullPdf = Buffer.concat([pdf, Buffer.from(appendStr, 'binary')]);
  const fullStr = fullPdf.toString('binary');

  // Find the /Contents < position in the appended part
  const contentsStart = fullStr.indexOf(`/Contents <${placeholderHex.substring(0, 10)}`, appendOffset);
  if (contentsStart === -1) throw new Error('Failed to locate /Contents placeholder');

  const contentValueStart = fullStr.indexOf('<', contentsStart + 9); // after "/Contents "
  const contentValueEnd = contentValueStart + 1 + SIGNATURE_PLACEHOLDER_LENGTH * 2 + 1; // < + hex + >

  // ByteRange: [0, contentValueStart, contentValueEnd, totalLength - contentValueEnd]
  // But we need to know total length first, which includes the updated xref+trailer

  // Build xref for incremental update
  const newXrefOffset = fullPdf.length;
  let xrefStr = `xref\n${sigObjNum} 1\n`;
  xrefStr += `${String(appendOffset).padStart(10, '0')} 00000 n \n`;
  xrefStr += `\ntrailer\n<< /Size ${currentSize + 1} /Root ${rootRef} /Prev ${existingXrefOffset} >>\n`;
  xrefStr += `startxref\n${newXrefOffset}\n%%EOF\n`;

  const finalPdf = Buffer.concat([fullPdf, Buffer.from(xrefStr, 'binary')]);
  const totalLength = finalPdf.length;

  // Now patch the ByteRange in the final PDF
  const byteRange: [number, number, number, number] = [
    0,
    contentValueStart,
    contentValueEnd,
    totalLength - contentValueEnd,
  ];

  const byteRangeStr = `[0 ${String(contentValueStart).padStart(10, '0')} ${String(contentValueEnd).padStart(10, '0')} ${String(totalLength - contentValueEnd).padStart(10, '0')}]`;

  // Patch ByteRange in place
  const brSearchStr = '/ByteRange [0 0000000000 0000000000 0000000000]';
  const brReplaceStr = `/ByteRange ${byteRangeStr}`;

  const finalStr = finalPdf.toString('binary');
  const brIdx = finalStr.indexOf(brSearchStr, appendOffset);
  if (brIdx === -1) throw new Error('Failed to locate /ByteRange placeholder');

  // Ensure replacement is exactly the same length
  const padded = brReplaceStr.padEnd(brSearchStr.length, ' ');
  const patched = Buffer.from(finalStr.substring(0, brIdx) + padded + finalStr.substring(brIdx + brSearchStr.length), 'binary');

  return {
    preparedPdf: patched,
    byteRange,
    placeholderOffset: contentValueStart + 1, // skip the '<'
  };
}

/**
 * Extract the byte range data from a prepared PDF (the parts that get hashed).
 */
function computeByteRangeData(
  pdf: Buffer,
  byteRange: [number, number, number, number],
): Buffer {
  const [offset1, length1, offset2, length2] = byteRange;
  const part1 = pdf.subarray(offset1, offset1 + length1);
  const part2 = pdf.subarray(offset2, offset2 + length2);
  return Buffer.concat([part1, part2]);
}

/**
 * Embed a CMS/PKCS#7 signature into a prepared PDF's placeholder.
 */
function embedSignature(
  preparedPdf: Buffer,
  signature: Buffer,
  placeholderOffset: number,
): Buffer {
  if (signature.length > SIGNATURE_PLACEHOLDER_LENGTH) {
    throw new Error(`Signature too large (${signature.length} bytes, max ${SIGNATURE_PLACEHOLDER_LENGTH})`);
  }

  // Convert signature to hex string, padded with zeros
  const sigHex = signature.toString('hex').padEnd(SIGNATURE_PLACEHOLDER_LENGTH * 2, '0');

  // Write the hex string into the placeholder position
  const result = Buffer.from(preparedPdf);
  result.write(sigHex, placeholderOffset, sigHex.length, 'ascii');

  return result;
}

/**
 * Dispatch to platform-specific signer.
 */
async function platformSign(
  data: Buffer,
  certificateId: string,
  pin?: string,
  pkcs11Module?: string | null,
): Promise<Buffer> {
  const os = platform();
  if (os === 'win32') {
    return signHashWindows(data, certificateId, pin);
  } else if (os === 'darwin') {
    return signHashMacos(data, certificateId, pin);
  } else if (os === 'linux') {
    return signHashLinux(data, certificateId, pin, pkcs11Module ?? undefined);
  }
  throw new Error(`Unsupported platform for PDF signing: ${os}`);
}

function formatPdfDate(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getUTCFullYear()}${pad(d.getUTCMonth() + 1)}${pad(d.getUTCDate())}` +
    `${pad(d.getUTCHours())}${pad(d.getUTCMinutes())}${pad(d.getUTCSeconds())}+00'00'`;
}
