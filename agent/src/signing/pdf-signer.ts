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
import { signHashPkcs11 } from './linux-signer.js';
import type { AgentConfig } from '../config.js';
import { resolvePkcs11Toolchain } from '../utils/toolchain.js';
import { isPkcs11CertificateId } from '../certificates/discovery.js';

const SIGNATURE_PLACEHOLDER_LENGTH = 16384; // 16KB for CMS signature — large enough for cert chain

/**
 * Sign a PDF with a USB certificate.
 *
 * @param pdfBuffer - Unsigned PDF as Buffer
 * @param certificateId - Certificate thumbprint/ID
 * @param pin - Optional PIN for smart card
 * @param config - Agent config (PKCS#11 module / toolchain overrides)
 * @returns Signed PDF as Buffer
 */
export interface SignPdfOptions {
  /** Draw a visible signature box (text lines) in the footer of the first or last page. */
  visible?: { lines: string[]; page?: 'first' | 'last' };
}

export async function signPdf(
  pdfBuffer: Buffer,
  certificateId: string,
  pin: string | undefined,
  config: AgentConfig,
  options: SignPdfOptions = {},
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
    const { preparedPdf, byteRange, placeholderOffset } = preparePdfForSigning(pdfBuffer, options);
    writeFileSync(preparedPath, preparedPdf);

    // Compute hash of the byte ranges (everything except the placeholder)
    const hashInput = computeByteRangeData(preparedPdf, byteRange);
    writeFileSync(hashPath, hashInput);

    // Phase 2: Sign the hash using platform-specific signer
    const signature = await platformSign(hashInput, certificateId, pin, config);
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
function pdfText(t: string): string {
  // Standard Helvetica with WinAnsi: transliterate Romanian diacritics, escape PDF string delimiters
  return t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[ȘșŞş]/g, (c) => (c === 'Ș' || c === 'Ş' ? 'S' : 's')).replace(/[ȚțŢţ]/g, (c) => (c === 'Ț' || c === 'Ţ' ? 'T' : 't'))
    .replace(/\\/g, '\\\\').replace(/\(/g, '\\(').replace(/\)/g, '\\)');
}

function preparePdfForSigning(pdf: Buffer, options: SignPdfOptions = {}): {
  preparedPdf: Buffer;
  byteRange: [number, number, number, number];
  placeholderOffset: number;
} {
  // Find existing xref offset from the PDF trailer
  const pdfStr = pdf.toString('binary');
  const startxrefMatches = [...pdfStr.matchAll(/startxref\s+(\d+)/g)];
  if (startxrefMatches.length === 0) throw new Error('Invalid PDF: no startxref found');
  const existingXrefOffset = parseInt(startxrefMatches[startxrefMatches.length - 1][1], 10);

  // Trailer (last one): root object and object count
  const trailers = [...pdfStr.matchAll(/trailer\s*<<([\s\S]*?)>>/g)];
  let rootRef = '1 0 R';
  let currentSize = 10;
  if (trailers.length > 0) {
    const t = trailers[trailers.length - 1][1];
    const rootMatch = t.match(/\/Root\s+(\d+ \d+ R)/);
    if (rootMatch) rootRef = rootMatch[1];
    const sizeMatch = t.match(/\/Size\s+(\d+)/);
    if (sizeMatch) currentSize = parseInt(sizeMatch[1], 10);
  } else if (/\/Type\s*\/XRef/.test(pdfStr)) {
    throw new Error('PDF uses cross-reference streams; re-save it as a classic PDF (DUKIntegrator output is supported)');
  }
  const rootNum = parseInt(rootRef, 10);

  // Helpers over the classic (non-compressed) object syntax used by DUKIntegrator/iText 5
  const objBody = (num: number): string | null => {
    const re = new RegExp(`(?:^|[\\r\\n])${num} 0 obj\\s*([\\s\\S]*?)\\s*endobj`);
    const m = pdfStr.match(re);
    return m ? m[1] : null;
  };
  const catalog = objBody(rootNum);
  if (!catalog) throw new Error(`Invalid PDF: catalog object ${rootNum} not found`);

  // The objects of the incremental update
  const sigObjNum = currentSize;
  const fieldObjNum = currentSize + 1;
  let nextObj = currentSize + 2;
  const updates: Array<{ num: number; body: string }> = [];
  const placeholderHex = '0'.repeat(SIGNATURE_PLACEHOLDER_LENGTH * 2);

  // 1. Signature value
  updates.push({ num: sigObjNum, body: `<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached\n/ByteRange [0 0000000000 0000000000 0000000000]\n/Contents <${placeholderHex}>\n/M (D:${formatPdfDate(new Date())})\n/Reason (Storno Digital Signature)\n>>` });

  // 2. Target page (first or last leaf of the page tree) for the widget's /P and /Annots
  let firstPageNum: number | null = null;
  let pageWidth = 595;
  const wantLast = options.visible?.page === 'last';
  const pagesRef = catalog.match(/\/Pages\s+(\d+) 0 R/);
  if (pagesRef) {
    let node = parseInt(pagesRef[1], 10);
    for (let depth = 0; depth < 8; depth++) {
      const body = objBody(node);
      if (!body) break;
      const kids = [...body.matchAll(/(\d+) 0 R/g)].map((m) => parseInt(m[1], 10));
      const kidsArr = body.match(/\/Kids\s*\[([^\]]*)\]/);
      if (!kidsArr) { firstPageNum = node; break; }
      const refs = [...kidsArr[1].matchAll(/(\d+) 0 R/g)].map((m) => parseInt(m[1], 10));
      if (refs.length === 0) break;
      node = wantLast ? refs[refs.length - 1] : refs[0];
      void kids;
    }
    const pageBody = firstPageNum !== null ? objBody(firstPageNum) : null;
    const mb = pageBody?.match(/\/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)/);
    if (mb) pageWidth = parseFloat(mb[3]) - parseFloat(mb[1]);
  }

  // 3. Signature field + widget, the thing verifiers look for in AcroForm /Fields.
  //    Invisible by default; with options.visible a footer box with the given lines is drawn.
  const fieldName = `Signature${Date.now() % 100000}`;
  let rect = '[0 0 0 0]';
  let apRef = '';
  if (options.visible && firstPageNum !== null) {
    const lines = options.visible.lines.slice(0, 4);
    const w = Math.min(Math.max(pageWidth - 72, 200), 340);
    const h = 14 + lines.length * 11;
    const x = 36;
    const y = 14;
    rect = `[${x} ${y} ${x + w} ${y + h}]`;
    const fontNum = nextObj++;
    const apNum = nextObj++;
    updates.push({ num: fontNum, body: '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>' });
    let content = `q 0.96 0.97 0.98 rg 0 0 ${w} ${h} re f 0.55 0.6 0.66 RG 0.75 w 0.375 0.375 ${w - 0.75} ${h - 0.75} re S Q\n`;
    content += `BT /F1 8 Tf 0.1 0.12 0.15 rg 7 ${h - 12} Td ${lines.map((l, i) => `${i > 0 ? '0 -11 Td ' : ''}(${pdfText(l)}) Tj`).join(' ')} ET\n`;
    updates.push({ num: apNum, body: `<< /Type /XObject /Subtype /Form /BBox [0 0 ${w} ${h}] /Resources << /Font << /F1 ${fontNum} 0 R >> >> /Length ${Buffer.byteLength(content, 'binary')} >>\nstream\n${content}endstream` });
    apRef = ` /AP << /N ${apNum} 0 R >> /DA (/F1 8 Tf 0 g)`;
  }
  updates.push({ num: fieldObjNum, body: `<< /Type /Annot /Subtype /Widget /FT /Sig /T (${fieldName}) /V ${sigObjNum} 0 R /F ${options.visible ? 4 : 132} /Rect ${rect}${firstPageNum !== null ? ` /P ${firstPageNum} 0 R` : ''}${apRef} >>` });

  // 4. AcroForm: append the field, set SigFlags 3 (indirect object or inline in the catalog)
  const acroRef = catalog.match(/\/AcroForm\s+(\d+) 0 R/);
  const patchAcroForm = (dict: string): string => {
    let d = dict.replace(/\/SigFlags\s+\d+/, '');
    if (/\/Fields\s*\[/.test(d)) {
      d = d.replace(/\/Fields\s*\[/, `/Fields [${fieldObjNum} 0 R `);
    } else if (/\/Fields\s+(\d+) 0 R/.test(d)) {
      // Fields kept in a separate array object: rewrite that array
      const arrNum = parseInt(d.match(/\/Fields\s+(\d+) 0 R/)![1], 10);
      const arr = objBody(arrNum);
      if (arr) updates.push({ num: arrNum, body: arr.trim().replace(/^\[/, `[${fieldObjNum} 0 R `) });
    } else {
      d = d.replace(/>>\s*$/, ` /Fields [${fieldObjNum} 0 R] >>`);
    }
    return d.replace(/>>\s*$/, ' /SigFlags 3 >>');
  };
  if (acroRef) {
    const acroNum = parseInt(acroRef[1], 10);
    const acro = objBody(acroNum);
    if (!acro) throw new Error(`Invalid PDF: AcroForm object ${acroNum} not found`);
    updates.push({ num: acroNum, body: patchAcroForm(acro.trim()) });
  } else if (/\/AcroForm\s*<</.test(catalog)) {
    const inline = catalog.match(/\/AcroForm\s*(<<[\s\S]*?>>)(?=\s*\/|\s*>>\s*$)/);
    const patched = inline ? catalog.replace(inline[1], patchAcroForm(inline[1])) : catalog;
    updates.push({ num: rootNum, body: patched.trim() });
  } else {
    // No form at all: create one and point the catalog to it
    const acroNum = nextObj++;
    updates.push({ num: acroNum, body: `<< /Fields [${fieldObjNum} 0 R] /SigFlags 3 >>` });
    updates.push({ num: rootNum, body: catalog.trim().replace(/>>\s*$/, ` /AcroForm ${acroNum} 0 R >>`) });
  }

  // 5. Page /Annots: add the widget when the array is inline or missing (an indirect array is left alone)
  if (firstPageNum !== null) {
    const page = objBody(firstPageNum);
    if (page && !/\/Annots\s+\d+ 0 R/.test(page)) {
      const body = /\/Annots\s*\[/.test(page)
        ? page.trim().replace(/\/Annots\s*\[/, `/Annots [${fieldObjNum} 0 R `)
        : page.trim().replace(/>>\s*$/, ` /Annots [${fieldObjNum} 0 R] >>`);
      updates.push({ num: firstPageNum, body });
    }
  }

  // Serialise the incremental update
  let appendStr = '\n';
  const offsets: Array<{ num: number; offset: number }> = [];
  for (const u of updates) {
    offsets.push({ num: u.num, offset: pdf.length + Buffer.byteLength(appendStr, 'binary') });
    appendStr += `${u.num} 0 obj\n${u.body}\nendobj\n`;
  }
  const fullPdf = Buffer.concat([pdf, Buffer.from(appendStr, 'binary')]);
  const fullStr = fullPdf.toString('binary');

  const contentsStart = fullStr.indexOf(`/Contents <${placeholderHex.substring(0, 10)}`, pdf.length);
  if (contentsStart === -1) throw new Error('Failed to locate /Contents placeholder');
  const contentValueStart = fullStr.indexOf('<', contentsStart + 9);
  const contentValueEnd = contentValueStart + 1 + SIGNATURE_PLACEHOLDER_LENGTH * 2 + 1;

  // xref with one subsection per run of consecutive object numbers
  offsets.sort((a, b) => a.num - b.num);
  const newXrefOffset = fullPdf.length;
  let xrefStr = 'xref\n';
  let i = 0;
  while (i < offsets.length) {
    let j = i;
    while (j + 1 < offsets.length && offsets[j + 1].num === offsets[j].num + 1) j++;
    xrefStr += `${offsets[i].num} ${j - i + 1}\n`;
    for (let k = i; k <= j; k++) xrefStr += `${String(offsets[k].offset).padStart(10, '0')} 00000 n \n`;
    i = j + 1;
  }
  xrefStr += `trailer\n<< /Size ${nextObj} /Root ${rootRef} /Prev ${existingXrefOffset} >>\nstartxref\n${newXrefOffset}\n%%EOF\n`;

  const finalPdf = Buffer.concat([fullPdf, Buffer.from(xrefStr, 'binary')]);
  const totalLength = finalPdf.length;

  const byteRange: [number, number, number, number] = [0, contentValueStart, contentValueEnd, totalLength - contentValueEnd];
  const byteRangeStr = `[0 ${String(contentValueStart).padStart(10, '0')} ${String(contentValueEnd).padStart(10, '0')} ${String(totalLength - contentValueEnd).padStart(10, '0')}]`;
  const brSearchStr = '/ByteRange [0 0000000000 0000000000 0000000000]';
  const finalStr = finalPdf.toString('binary');
  const brIdx = finalStr.indexOf(brSearchStr, pdf.length);
  if (brIdx === -1) throw new Error('Failed to locate /ByteRange placeholder');
  const padded = `/ByteRange ${byteRangeStr}`.padEnd(brSearchStr.length, ' ');
  const patched = Buffer.from(finalStr.substring(0, brIdx) + padded + finalStr.substring(brIdx + brSearchStr.length), 'binary');

  return { preparedPdf: patched, byteRange, placeholderOffset: contentValueStart + 1 };
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
  pin: string | undefined,
  config: AgentConfig,
): Promise<Buffer> {
  const os = platform();
  if (os === 'win32') {
    return signHashWindows(data, certificateId, pin);
  } else if (os === 'darwin') {
    // Keychain-backed identities sign through the Security framework; tokens
    // reachable only via vendor PKCS#11 middleware go through OpenSSL + libp11.
    if (isPkcs11CertificateId(certificateId)) {
      return signHashPkcs11(data, certificateId, pin, resolvePkcs11Toolchain(config));
    }
    return signHashMacos(data, certificateId, pin);
  } else if (os === 'linux') {
    return signHashPkcs11(data, certificateId, pin, resolvePkcs11Toolchain(config));
  }
  throw new Error(`Unsupported platform for PDF signing: ${os}`);
}

function formatPdfDate(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getUTCFullYear()}${pad(d.getUTCMonth() + 1)}${pad(d.getUTCDate())}` +
    `${pad(d.getUTCHours())}${pad(d.getUTCMinutes())}${pad(d.getUTCSeconds())}+00'00'`;
}
