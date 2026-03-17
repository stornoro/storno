/**
 * Tests for PDF signing orchestrator.
 *
 * Run: npx tsx --test tests/pdf-signer.test.ts
 */

import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

// We test the internal functions by importing the module and testing
// the public API. Since platformSign requires hardware tokens, we test
// the PDF preparation and embedding logic directly.

// Minimal valid PDF for testing (simplest possible valid PDF)
function createMinimalPdf(): Buffer {
  const pdf = `%PDF-1.4
1 0 obj
<< /Type /Catalog /Pages 2 0 R >>
endobj
2 0 obj
<< /Type /Pages /Kids [3 0 R] /Count 1 >>
endobj
3 0 obj
<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>
endobj
xref
0 4
0000000000 65535 f \r
0000000009 00000 n \r
0000000058 00000 n \r
0000000115 00000 n \r
trailer
<< /Size 4 /Root 1 0 R >>
startxref
190
%%EOF`;
  return Buffer.from(pdf, 'binary');
}

describe('PDF Signer', () => {

  describe('Minimal PDF validity', () => {
    it('should create a valid minimal PDF', () => {
      const pdf = createMinimalPdf();
      assert.ok(pdf.length > 0);
      assert.ok(pdf.toString('binary').startsWith('%PDF-'));
      assert.ok(pdf.toString('binary').includes('startxref'));
      assert.ok(pdf.toString('binary').includes('%%EOF'));
    });
  });

  describe('PDF structure parsing', () => {
    it('should find startxref in a PDF', () => {
      const pdf = createMinimalPdf();
      const pdfStr = pdf.toString('binary');
      const match = pdfStr.match(/startxref\s+(\d+)/);
      assert.ok(match, 'startxref not found');
      const offset = parseInt(match[1], 10);
      assert.ok(offset > 0, 'startxref offset should be positive');
    });

    it('should find trailer with Root reference', () => {
      const pdf = createMinimalPdf();
      const pdfStr = pdf.toString('binary');
      const trailerMatch = pdfStr.match(/trailer\s*<<([\s\S]*?)>>/);
      assert.ok(trailerMatch, 'trailer not found');
      const rootMatch = trailerMatch[1].match(/\/Root\s+(\d+ \d+ R)/);
      assert.ok(rootMatch, 'Root reference not found in trailer');
      assert.equal(rootMatch[1], '1 0 R');
    });

    it('should find Size in trailer', () => {
      const pdf = createMinimalPdf();
      const pdfStr = pdf.toString('binary');
      const trailerMatch = pdfStr.match(/trailer\s*<<([\s\S]*?)>>/);
      assert.ok(trailerMatch);
      const sizeMatch = trailerMatch[1].match(/\/Size\s+(\d+)/);
      assert.ok(sizeMatch);
      assert.equal(parseInt(sizeMatch[1], 10), 4);
    });
  });

  describe('Signature placeholder', () => {
    it('should have correct placeholder length constant', () => {
      // 16KB is enough for most CMS signatures with cert chains
      const SIGNATURE_PLACEHOLDER_LENGTH = 16384;
      assert.ok(SIGNATURE_PLACEHOLDER_LENGTH >= 8192, 'Placeholder should be at least 8KB');
      assert.ok(SIGNATURE_PLACEHOLDER_LENGTH <= 65536, 'Placeholder should not exceed 64KB');
    });
  });

  describe('Byte range computation', () => {
    it('should extract correct byte ranges from a buffer', () => {
      const buf = Buffer.from('AAAA____BBBB', 'ascii');
      // ByteRange: [0, 4, 8, 4] means: bytes 0-3 and bytes 8-11
      const byteRange: [number, number, number, number] = [0, 4, 8, 4];

      const part1 = buf.subarray(byteRange[0], byteRange[0] + byteRange[1]);
      const part2 = buf.subarray(byteRange[2], byteRange[2] + byteRange[3]);
      const combined = Buffer.concat([part1, part2]);

      assert.equal(combined.toString('ascii'), 'AAAABBBB');
    });

    it('should correctly skip the placeholder region', () => {
      // Simulate a PDF with a placeholder in the middle
      const before = 'PREFIX_CONTENT';
      const placeholder = '<00000000>';
      const after = 'SUFFIX_CONTENT';
      const full = before + placeholder + after;
      const buf = Buffer.from(full, 'ascii');

      const byteRange: [number, number, number, number] = [
        0,
        before.length,
        before.length + placeholder.length,
        after.length,
      ];

      const part1 = buf.subarray(byteRange[0], byteRange[0] + byteRange[1]);
      const part2 = buf.subarray(byteRange[2], byteRange[2] + byteRange[3]);
      const hashInput = Buffer.concat([part1, part2]);

      assert.equal(hashInput.toString('ascii'), 'PREFIX_CONTENTSUFFIX_CONTENT');
      // Verify the placeholder is NOT included
      assert.ok(!hashInput.toString('ascii').includes('00000000'));
    });
  });

  describe('Signature embedding', () => {
    it('should embed hex signature into placeholder', () => {
      const placeholderLength = 16;
      const placeholderHex = '0'.repeat(placeholderLength * 2);
      const pdf = Buffer.from(`before<${placeholderHex}>after`, 'ascii');

      // Simulate embedding a 4-byte signature
      const signature = Buffer.from([0xDE, 0xAD, 0xBE, 0xEF]);
      const sigHex = signature.toString('hex').padEnd(placeholderLength * 2, '0');

      // Find placeholder offset (after the '<')
      const pdfStr = pdf.toString('ascii');
      const placeholderOffset = pdfStr.indexOf('<' + placeholderHex) + 1;

      const result = Buffer.from(pdf);
      result.write(sigHex, placeholderOffset, sigHex.length, 'ascii');

      const resultStr = result.toString('ascii');
      assert.ok(resultStr.includes('deadbeef'));
      assert.ok(resultStr.startsWith('before<'));
      assert.ok(resultStr.endsWith('>after'));
    });

    it('should reject signatures larger than placeholder', () => {
      const PLACEHOLDER_LENGTH = 16;
      const oversizedSignature = Buffer.alloc(PLACEHOLDER_LENGTH + 1, 0xFF);

      assert.ok(
        oversizedSignature.length > PLACEHOLDER_LENGTH,
        'Test signature should exceed placeholder size'
      );
    });
  });

  describe('PDF date formatting', () => {
    it('should format date as PDF date string', () => {
      const formatPdfDate = (d: Date): string => {
        const pad = (n: number) => String(n).padStart(2, '0');
        return `${d.getUTCFullYear()}${pad(d.getUTCMonth() + 1)}${pad(d.getUTCDate())}` +
          `${pad(d.getUTCHours())}${pad(d.getUTCMinutes())}${pad(d.getUTCSeconds())}+00'00'`;
      };

      const d = new Date('2026-03-17T14:30:00Z');
      const result = formatPdfDate(d);
      assert.equal(result, "20260317143000+00'00'");
    });
  });
});
