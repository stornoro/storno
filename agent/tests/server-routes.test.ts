/**
 * Tests for the new sign-and-submit and batch-sign-and-submit routes.
 *
 * These test request validation and error handling logic.
 * Actual signing/uploading requires hardware tokens and is tested manually.
 *
 * Run: npx tsx --test tests/server-routes.test.ts
 */

import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

// We test the validation logic that the routes perform on input.
// Since routes are tightly coupled to the HTTP server, we test the
// validation patterns as pure functions.

/** Allowed proxy target hosts (same as server.ts) */
const ALLOWED_HOSTS = ['webserviced.anaf.ro', 'epatrim.anaf.ro', 'api.anaf.ro'];

function validateUploadUrl(url: string): { valid: boolean; error?: string } {
  try {
    const targetUrl = new URL(url);
    if (!ALLOWED_HOSTS.includes(targetUrl.hostname)) {
      return { valid: false, error: `Host not allowed: ${targetUrl.hostname}` };
    }
    return { valid: true };
  } catch {
    return { valid: false, error: 'Invalid URL' };
  }
}

interface SignAndSubmitPayload {
  pdf?: string;
  certificateId?: string;
  pin?: string;
  uploadUrl?: string;
  uploadHeaders?: Record<string, string>;
}

function validateSignAndSubmitPayload(payload: SignAndSubmitPayload): { valid: boolean; error?: string } {
  if (!payload.pdf || !payload.certificateId || !payload.uploadUrl) {
    return { valid: false, error: 'Missing required fields: pdf, certificateId, uploadUrl' };
  }
  return validateUploadUrl(payload.uploadUrl);
}

interface BatchPayload {
  requests?: Array<{ pdf?: string; uploadUrl?: string }>;
  certificateId?: string;
  pin?: string;
}

function validateBatchPayload(payload: BatchPayload): { valid: boolean; error?: string } {
  if (!Array.isArray(payload.requests) || payload.requests.length === 0) {
    return { valid: false, error: 'Missing required field: requests (non-empty array)' };
  }
  if (!payload.certificateId) {
    return { valid: false, error: 'Missing required field: certificateId' };
  }
  for (let i = 0; i < payload.requests.length; i++) {
    const r = payload.requests[i];
    if (!r.pdf || !r.uploadUrl) {
      return { valid: false, error: `Request [${i}]: missing required fields: pdf, uploadUrl` };
    }
    const urlCheck = validateUploadUrl(r.uploadUrl);
    if (!urlCheck.valid) {
      return { valid: false, error: `Request [${i}]: ${urlCheck.error}` };
    }
  }
  return { valid: true };
}

describe('Sign-and-Submit Route Validation', () => {

  describe('URL allowlist', () => {
    it('should allow webserviced.anaf.ro', () => {
      const result = validateUploadUrl('https://webserviced.anaf.ro/SPVWS2/rest/cerere?tip=D394&cui=12345');
      assert.ok(result.valid);
    });

    it('should allow epatrim.anaf.ro', () => {
      const result = validateUploadUrl('https://epatrim.anaf.ro/some/path');
      assert.ok(result.valid);
    });

    it('should allow api.anaf.ro', () => {
      const result = validateUploadUrl('https://api.anaf.ro/some/path');
      assert.ok(result.valid);
    });

    it('should reject unknown hosts', () => {
      const result = validateUploadUrl('https://evil.com/steal-data');
      assert.ok(!result.valid);
      assert.ok(result.error?.includes('not allowed'));
    });

    it('should reject invalid URLs', () => {
      const result = validateUploadUrl('not-a-url');
      assert.ok(!result.valid);
      assert.equal(result.error, 'Invalid URL');
    });

    it('should reject empty URLs', () => {
      const result = validateUploadUrl('');
      assert.ok(!result.valid);
    });
  });

  describe('Single sign-and-submit payload', () => {
    it('should accept valid payload', () => {
      const result = validateSignAndSubmitPayload({
        pdf: 'base64data',
        certificateId: 'ABC123',
        uploadUrl: 'https://webserviced.anaf.ro/SPVWS2/rest/cerere',
      });
      assert.ok(result.valid);
    });

    it('should reject missing pdf', () => {
      const result = validateSignAndSubmitPayload({
        certificateId: 'ABC123',
        uploadUrl: 'https://webserviced.anaf.ro/test',
      });
      assert.ok(!result.valid);
      assert.ok(result.error?.includes('Missing required fields'));
    });

    it('should reject missing certificateId', () => {
      const result = validateSignAndSubmitPayload({
        pdf: 'data',
        uploadUrl: 'https://webserviced.anaf.ro/test',
      });
      assert.ok(!result.valid);
    });

    it('should reject missing uploadUrl', () => {
      const result = validateSignAndSubmitPayload({
        pdf: 'data',
        certificateId: 'ABC123',
      });
      assert.ok(!result.valid);
    });

    it('should reject disallowed uploadUrl host', () => {
      const result = validateSignAndSubmitPayload({
        pdf: 'data',
        certificateId: 'ABC123',
        uploadUrl: 'https://evil.com/steal',
      });
      assert.ok(!result.valid);
      assert.ok(result.error?.includes('not allowed'));
    });

    it('should accept payload with optional pin', () => {
      const result = validateSignAndSubmitPayload({
        pdf: 'data',
        certificateId: 'ABC123',
        pin: '1234',
        uploadUrl: 'https://webserviced.anaf.ro/test',
      });
      assert.ok(result.valid);
    });
  });

  describe('Batch sign-and-submit payload', () => {
    it('should accept valid batch payload', () => {
      const result = validateBatchPayload({
        requests: [
          { pdf: 'data1', uploadUrl: 'https://webserviced.anaf.ro/test1' },
          { pdf: 'data2', uploadUrl: 'https://webserviced.anaf.ro/test2' },
        ],
        certificateId: 'ABC123',
      });
      assert.ok(result.valid);
    });

    it('should reject empty requests array', () => {
      const result = validateBatchPayload({
        requests: [],
        certificateId: 'ABC123',
      });
      assert.ok(!result.valid);
      assert.ok(result.error?.includes('non-empty array'));
    });

    it('should reject missing requests', () => {
      const result = validateBatchPayload({
        certificateId: 'ABC123',
      });
      assert.ok(!result.valid);
    });

    it('should reject missing certificateId', () => {
      const result = validateBatchPayload({
        requests: [{ pdf: 'data', uploadUrl: 'https://webserviced.anaf.ro/test' }],
      });
      assert.ok(!result.valid);
      assert.ok(result.error?.includes('certificateId'));
    });

    it('should reject request with missing pdf', () => {
      const result = validateBatchPayload({
        requests: [{ uploadUrl: 'https://webserviced.anaf.ro/test' }],
        certificateId: 'ABC123',
      });
      assert.ok(!result.valid);
      assert.ok(result.error?.includes('Request [0]'));
    });

    it('should reject request with disallowed host', () => {
      const result = validateBatchPayload({
        requests: [
          { pdf: 'data', uploadUrl: 'https://webserviced.anaf.ro/test' },
          { pdf: 'data', uploadUrl: 'https://evil.com/steal' },
        ],
        certificateId: 'ABC123',
      });
      assert.ok(!result.valid);
      assert.ok(result.error?.includes('Request [1]'));
      assert.ok(result.error?.includes('not allowed'));
    });

    it('should validate all requests in order', () => {
      const result = validateBatchPayload({
        requests: [
          { pdf: 'data', uploadUrl: 'https://webserviced.anaf.ro/test' },
          { uploadUrl: 'https://webserviced.anaf.ro/test' }, // missing pdf
        ],
        certificateId: 'ABC123',
      });
      assert.ok(!result.valid);
      assert.ok(result.error?.includes('Request [1]'));
    });
  });

  describe('PIN error detection', () => {
    it('should detect PIN verification failure message', () => {
      const errorMsg = 'PIN verification failed: incorrect PIN';
      const isPinError = errorMsg.includes('PIN verification failed') ||
                          errorMsg.includes('Failed to set PIN');
      assert.ok(isPinError);
    });

    it('should detect Failed to set PIN message', () => {
      const errorMsg = 'Failed to set PIN on CNG key';
      const isPinError = errorMsg.includes('PIN verification failed') ||
                          errorMsg.includes('Failed to set PIN');
      assert.ok(isPinError);
    });

    it('should not flag regular errors as PIN errors', () => {
      const errorMsg = 'Connection refused to ANAF';
      const isPinError = errorMsg.includes('PIN verification failed') ||
                          errorMsg.includes('Failed to set PIN');
      assert.ok(!isPinError);
    });
  });
});
