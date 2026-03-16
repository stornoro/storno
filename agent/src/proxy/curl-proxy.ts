import { spawn } from 'node:child_process';
import { platform } from 'node:os';
import type { AgentConfig } from '../config.js';

export interface ProxyRequest {
  url: string;
  method: string;
  headers: Record<string, string>;
  body: string;
  certificateId: string;
}

export interface ProxyResponse {
  statusCode: number;
  headers: Record<string, string>;
  body: string;
}

/**
 * Proxy an mTLS request to ANAF using curl.
 *
 * Uses platform-specific certificate access:
 * - macOS: SecureTransport backend sees Keychain + USB tokens
 * - Windows: SChannel backend uses Windows Certificate Store
 * - Linux: PKCS#11 engine for hardware tokens
 */
export function curlProxy(req: ProxyRequest, config: AgentConfig): Promise<ProxyResponse> {
  return new Promise((resolve, reject) => {
    const args = buildCurlArgs(req, config);

    const child = spawn(config.curlPath, args, {
      stdio: ['pipe', 'pipe', 'pipe'],
      timeout: 120_000,
    });

    let stdout = '';
    let stderr = '';

    child.stdout.on('data', (chunk: Buffer) => { stdout += chunk.toString(); });
    child.stderr.on('data', (chunk: Buffer) => { stderr += chunk.toString(); });

    // Pipe XML body via stdin (never as CLI arg)
    if (req.body) {
      child.stdin.write(req.body);
    }
    child.stdin.end();

    child.on('close', (code) => {
      if (code !== 0 && !stdout) {
        reject(new Error(`curl exited with code ${code}: ${stderr}`));
        return;
      }

      try {
        const result = parseResponse(stdout);
        resolve(result);
      } catch (err) {
        reject(new Error(`Failed to parse curl response: ${(err as Error).message}`));
      }
    });

    child.on('error', (err) => {
      reject(new Error(`Failed to spawn curl: ${err.message}`));
    });
  });
}

function buildCurlArgs(req: ProxyRequest, config: AgentConfig): string[] {
  const args: string[] = [
    '-s',                  // Silent mode
    '-S',                  // Show errors
    '-D', '-',             // Dump headers to stdout
    '-X', req.method,
    '--max-time', '120',
    '-d', '@-',            // Read body from stdin
  ];

  // Add request headers
  for (const [key, value] of Object.entries(req.headers)) {
    args.push('-H', `${key}: ${value}`);
  }

  // Platform-specific certificate selection
  const os = platform();
  if (os === 'darwin') {
    // macOS: SecureTransport sees Keychain entries (including USB token certs)
    args.push('--cert', req.certificateId);
  } else if (os === 'win32') {
    // Windows: SChannel auto-selects from Windows Certificate Store
    // The certificate is identified by subject name
    args.push('--cert', req.certificateId);
  } else if (os === 'linux' && config.pkcs11Module) {
    // Linux: Use PKCS#11 engine for hardware token access
    args.push(
      '--engine', 'pkcs11',
      '--cert-type', 'ENG',
      '--cert', `pkcs11:id=%${req.certificateId}`,
    );
  }

  args.push(req.url);

  return args;
}

function parseResponse(raw: string): ProxyResponse {
  // curl -D - outputs headers then blank line then body
  const headerEnd = raw.indexOf('\r\n\r\n');
  if (headerEnd === -1) {
    // No headers separator — treat everything as body
    return { statusCode: 200, headers: {}, body: raw };
  }

  const headerSection = raw.substring(0, headerEnd);
  const body = raw.substring(headerEnd + 4);

  const headerLines = headerSection.split('\r\n');
  const headers: Record<string, string> = {};
  let statusCode = 200;

  for (const line of headerLines) {
    // Status line: HTTP/1.1 200 OK
    const statusMatch = line.match(/^HTTP\/[\d.]+ (\d+)/);
    if (statusMatch) {
      statusCode = parseInt(statusMatch[1], 10);
      continue;
    }

    const colonIdx = line.indexOf(':');
    if (colonIdx > 0) {
      const key = line.substring(0, colonIdx).trim().toLowerCase();
      const value = line.substring(colonIdx + 1).trim();
      headers[key] = value;
    }
  }

  return { statusCode, headers, body };
}
