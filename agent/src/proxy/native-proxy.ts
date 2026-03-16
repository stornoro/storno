import { request as httpsRequest } from 'node:https';
import { readFileSync } from 'node:fs';
import type { ProxyRequest, ProxyResponse } from './curl-proxy.js';

/**
 * Native Node.js proxy for software PFX certificates.
 * Uses https.request() with pfx + passphrase for mTLS.
 *
 * This is a fallback for when the user has a PFX file rather than
 * a hardware token. The certificateId should be the path to the PFX file.
 */
export function nativeProxy(req: ProxyRequest, pfxPath: string, passphrase: string): Promise<ProxyResponse> {
  return new Promise((resolve, reject) => {
    const url = new URL(req.url);
    const pfx = readFileSync(pfxPath);

    const options = {
      hostname: url.hostname,
      port: url.port || 443,
      path: url.pathname + url.search,
      method: req.method,
      headers: req.headers,
      pfx,
      passphrase,
      timeout: 120_000,
    };

    const httpReq = httpsRequest(options, (res) => {
      let body = '';
      res.on('data', (chunk: Buffer) => { body += chunk.toString(); });
      res.on('end', () => {
        const headers: Record<string, string> = {};
        for (const [key, value] of Object.entries(res.headers)) {
          if (typeof value === 'string') {
            headers[key] = value;
          } else if (Array.isArray(value)) {
            headers[key] = value.join(', ');
          }
        }

        resolve({
          statusCode: res.statusCode ?? 500,
          headers,
          body,
        });
      });
    });

    httpReq.on('error', (err) => {
      reject(new Error(`HTTPS request failed: ${err.message}`));
    });

    httpReq.on('timeout', () => {
      httpReq.destroy(new Error('Request timed out (120s)'));
    });

    if (req.body) {
      httpReq.write(req.body);
    }
    httpReq.end();
  });
}
