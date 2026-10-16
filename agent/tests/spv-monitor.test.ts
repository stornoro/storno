/**
 * Pure helpers of the SPV monitor: which curl/PKCS#11 errors are retried as
 * "token not ready" and how they are explained to the user.
 *
 * Run: npx tsx --test tests/spv-monitor.test.ts
 */

import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { isTransientTokenError, isTransientNetworkError, describeTokenError } from '../src/monitor/spv-monitor.js';

describe('isTransientTokenError', () => {
  it('matches the libp11 "object not found" failure seen right after wake', () => {
    const msg = "curl exited with code 58: curl: (58) ssl engine cannot load client cert with id 'pkcs11:id=%37%46;pin-value=<redacted>' [error:40000065:pkcs11 engine::object not found]";
    assert.equal(isTransientTokenError(msg), true);
  });

  it('matches token-not-present style PKCS#11 errors', () => {
    for (const m of ['CKR_TOKEN_NOT_PRESENT', 'pkcs11-tool: No slot with a token was found.', 'CKR_DEVICE_REMOVED']) {
      assert.equal(isTransientTokenError(m), true, m);
    }
  });

  it('does not retry PIN problems, ANAF errors or backend errors', () => {
    for (const m of ['PIN verification failed', 'PIN blocat (CKR_PIN_LOCKED)', 'POST /spv/sync-prepare → HTTP 401', 'PIN_REQUIRED: the certificate PIN was not provided', 'Failed to spawn curl: ENOENT']) {
      assert.equal(isTransientTokenError(m), false, m);
    }
  });
});

describe('describeTokenError', () => {
  it('keeps the raw details and is itself recognised (no double wrapping)', () => {
    const text = describeTokenError('object not found');
    assert.match(text, /Token-ul nu a raspuns/);
    assert.match(text, /Detalii: object not found$/);
    assert.equal(isTransientTokenError(text), true);
  });
});

describe('isTransientNetworkError', () => {
  it('matches curl resets, timeouts and TLS handshake drops', () => {
    for (const m of [
      'curl exited with code 56: curl: (56) Recv failure: Connection reset by peer\n',
      'curl exited with code 28: curl: (28) Operation timed out after 120000 milliseconds with 0 bytes received',
      'curl exited with code 35: curl: (35) OpenSSL SSL_connect: Connection reset by peer',
      'curl exited with code 52: curl: (52) Empty reply from server',
      'TypeError: fetch failed',
    ]) {
      assert.equal(isTransientNetworkError(m), true, m);
    }
  });

  it('does not retry PIN, token, HTTP or ANAF errors', () => {
    for (const m of ['PIN verification failed', 'curl exited with code 58: ssl engine cannot load client cert', 'POST /spv/sync-prepare → HTTP 500', 'curl exited with code 22: The requested URL returned error: 403']) {
      assert.equal(isTransientNetworkError(m), false, m);
    }
  });
});
