import { spawnSync, execFile } from 'node:child_process';
import { X509Certificate } from 'node:crypto';
import { readFileSync, unlinkSync, mkdtempSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import type { Certificate } from './macos.js';
import { PKCS11_AUTO_ID, type Pkcs11Toolchain } from '../utils/toolchain.js';

/**
 * List certificates from a PKCS#11 token using OpenSC's `pkcs11-tool`.
 *
 * Works on Linux and macOS (with a Homebrew toolchain). Certificates on many
 * tokens (Longmai, some SafeNet profiles) are private objects that only show
 * up after login, and listing runs without a PIN, so when the token is present
 * but exposes nothing we return a single "auto" placeholder: the request path
 * then lets the libp11 engine pick the certificate after the PIN is supplied.
 */
export function listPkcs11Certificates(toolchain: Pkcs11Toolchain | null): Certificate[] {
  if (!toolchain) {
    return [];
  }
  if (!toolchain.pkcs11ToolPath) {
    // No pkcs11-tool: cannot enumerate, but the engine path still works after PIN.
    return [{
      id: PKCS11_AUTO_ID,
      subject: `${toolchain.moduleName} (certificatul apare dupa introducerea PIN-ului)`,
      issuer: toolchain.moduleName,
      notAfter: null,
      source: 'pkcs11',
    }];
  }

  // pkcs11-tool prints slot/token notices ("Using slot 0 with a present
  // token") on stderr, so read both streams.
  const run = (args: string[]): string => {
    const r = spawnSync(toolchain.pkcs11ToolPath as string, ['--module', toolchain.module, ...args], {
      encoding: 'utf-8',
      timeout: 20_000,
      env: toolchain.env,
    });
    if (r.error) throw r.error;
    return `${r.stdout ?? ''}\n${r.stderr ?? ''}`;
  };

  let certs: Certificate[] = [];
  let tokenPresent = false;
  try {
    const output = run(['-O', '--type', 'cert']);
    certs = parseCertificateObjects(output);
    // pkcs11-tool prints this before listing objects when a token is inserted
    tokenPresent = /present token/i.test(output);
  } catch {
    certs = [];
  }
  if (certs.length > 0) {
    const dir = mkdtempSync(join(tmpdir(), 'storno-p11-'));
    try {
      const map = new Map<string, Buffer | null>();
      for (const c of certs) {
        const file = join(dir, `${c.id.slice(0, 16)}.der`);
        const r = spawnSync(toolchain.pkcs11ToolPath as string, readObjectArgs(toolchain, c.id, file), { env: toolchain.env, timeout: 20_000 });
        let der: Buffer | null = null;
        try { if (r.status === 0) der = readFileSync(file); } catch { der = null; }
        try { unlinkSync(file); } catch { /* ignore */ }
        map.set(c.id, der);
      }
      return enrichSync(certs, map);
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  }

  // Nothing public: is a token present at all? Some middleware (Longmai)
  // answers C_GetTokenInfo with CKR_CANCEL, so try -T but do not rely on it.
  let label = '';
  // -L can take ~10s with some middleware (empty virtual slots are polled), so
  // only fall back to it when -O did not already prove a token is present.
  if (!tokenPresent) try {
    const slots = run(['-L']);
    label = slots.match(/token label\s*:\s*(.+)/i)?.[1]?.trim() ?? '';
    // A slot that is not reported "(empty)" holds a token, even when the
    // middleware refuses C_GetTokenInfo (Longmai answers CKR_CANCEL).
    const occupied = /^Slot \d+ \([^)]*\):.*\n(?!\s*\(empty\))/m.test(slots);
    if (label || occupied || /present token/i.test(slots)) tokenPresent = true;
  } catch {
    // ignore, tokenPresent may already be true from -O
  }

  if (tokenPresent) {
    return [{
      id: PKCS11_AUTO_ID,
      subject: `${label || toolchain.moduleName} (certificatul apare dupa introducerea PIN-ului)`,
      issuer: toolchain.moduleName,
      notAfter: null,
      source: 'pkcs11',
    }];
  }

  return [];
}

export function parseCertificateObjects(output: string): Certificate[] {
  const certs: Certificate[] = [];
  const blocks = output.split(/Certificate Object/i).slice(1);

  for (const block of blocks) {
    const labelMatch = block.match(/label:\s+(.+)/i);
    const subjectMatch = block.match(/subject:\s+(?:DN:\s*)?(.+)/i);
    // pkcs11-tool prints the ID as colon-separated bytes, wrapped over
    // continuation lines that contain only hex pairs/colons/spaces.
    const idMatch = block.match(/\bID:\s+([0-9a-f]{2}(?::[0-9a-f]{2})*(?:\s*\n\s+[0-9a-f]{2}(?::[0-9a-f]{2})*)*)/i);
    if (!idMatch) continue;
    const id = idMatch[1].replace(/[^0-9a-fA-F]/g, '').toLowerCase();
    if (!id) continue;

    certs.push({
      id,
      subject: (subjectMatch?.[1] ?? labelMatch?.[1] ?? id).trim(),
      issuer: '',
      notAfter: null,
      source: 'pkcs11',
    });
  }

  return certs;
}

/**
 * Read each certificate's DER and enrich it: subject CN, issuer, expiry.
 * Drops CA certificates and expired ones so the user only sees identities
 * that can actually authenticate. Falls back to the raw entry on any error.
 */
export async function enrichPkcs11Certificates(
  toolchain: Pkcs11Toolchain,
  certs: Certificate[],
  read: (id: string) => Promise<Buffer | null>,
): Promise<Certificate[]> {
  const out: Certificate[] = [];
  for (const c of certs) {
    try {
      const der = await read(c.id);
      if (!der) { out.push(c); continue; }
      const x = new X509Certificate(der);
      if (x.ca) continue;
      const notAfter = new Date(x.validTo);
      if (notAfter.getTime() < Date.now()) continue;
      const cn = x.subject.split('\n').find((l) => l.startsWith('CN='))?.slice(3) ?? c.subject;
      const org = x.subject.split('\n').find((l) => l.startsWith('O='))?.slice(2);
      const issuerCn = x.issuer.split('\n').find((l) => l.startsWith('CN='))?.slice(3) ?? '';
      out.push({
        ...c,
        subject: org ? `${cn} (${org})` : cn,
        issuer: issuerCn,
        notAfter: notAfter.toISOString().slice(0, 10),
      });
    } catch {
      out.push(c);
    }
  }
  // If everything got filtered (unexpected), show the raw list rather than nothing.
  return out.length ? out : certs;
}

function enrichSync(certs: Certificate[], ders: Map<string, Buffer | null>): Certificate[] {
  const out: Certificate[] = [];
  for (const c of certs) {
    const der = ders.get(c.id) ?? null;
    if (!der) { out.push(c); continue; }
    try {
      const x = new X509Certificate(der);
      if (x.ca) continue;
      const notAfter = new Date(x.validTo);
      if (notAfter.getTime() < Date.now()) continue;
      const cn = x.subject.split('\n').find((l) => l.startsWith('CN='))?.slice(3) ?? c.subject;
      const org = x.subject.split('\n').find((l) => l.startsWith('O='))?.slice(2);
      const issuerCn = x.issuer.split('\n').find((l) => l.startsWith('CN='))?.slice(3) ?? '';
      out.push({ ...c, subject: org ? `${cn} (${org})` : cn, issuer: issuerCn, notAfter: notAfter.toISOString().slice(0, 10) });
    } catch {
      out.push(c);
    }
  }
  return out.length ? out : certs;
}

function readObjectArgs(toolchain: Pkcs11Toolchain, id: string, file: string): string[] {
  return ['--module', toolchain.module, '--read-object', '--type', 'cert', '--id', id, '--output-file', file];
}

/**
 * Async variant used by the server cache so a slow middleware (Longmai needs
 * ~12s to initialise) never blocks the event loop.
 */
export async function listPkcs11CertificatesAsync(toolchain: Pkcs11Toolchain | null): Promise<Certificate[]> {
  if (!toolchain) return [];
  if (!toolchain.pkcs11ToolPath) return listPkcs11Certificates(toolchain);

  const run = (args: string[]): Promise<string> => new Promise((resolve, reject) => {
    execFile(toolchain.pkcs11ToolPath as string, ['--module', toolchain.module, ...args], {
      encoding: 'utf-8',
      timeout: 30_000,
      env: toolchain.env,
    }, (err, stdout, stderr) => {
      if (err && !stdout && !stderr) { reject(err); return; }
      resolve(`${stdout ?? ''}\n${stderr ?? ''}`);
    });
  });

  let certs: Certificate[] = [];
  let tokenPresent = false;
  try {
    const output = await run(['-O', '--type', 'cert']);
    certs = parseCertificateObjects(output);
    tokenPresent = /present token/i.test(output);
  } catch {
    certs = [];
  }
  if (certs.length > 0) {
    const dir = mkdtempSync(join(tmpdir(), 'storno-p11-'));
    try {
      return await enrichPkcs11Certificates(toolchain, certs, (id) => new Promise((resolve) => {
        const file = join(dir, `${id.slice(0, 16)}.der`);
        execFile(toolchain.pkcs11ToolPath as string, readObjectArgs(toolchain, id, file), { env: toolchain.env, timeout: 20_000 }, (err) => {
          let der: Buffer | null = null;
          try { if (!err) der = readFileSync(file); } catch { der = null; }
          try { unlinkSync(file); } catch { /* ignore */ }
          resolve(der);
        });
      }));
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  }

  let label = '';
  if (!tokenPresent) try {
    const slots = await run(['-L']);
    label = slots.match(/token label\s*:\s*(.+)/i)?.[1]?.trim() ?? '';
    const occupied = /^Slot \d+ \([^)]*\):.*\n(?!\s*\(empty\))/m.test(slots);
    if (label || occupied || /present token/i.test(slots)) tokenPresent = true;
  } catch {
    // ignore
  }

  if (tokenPresent) {
    return [{
      id: PKCS11_AUTO_ID,
      subject: `${label || toolchain.moduleName} (certificatul apare dupa introducerea PIN-ului)`,
      issuer: toolchain.moduleName,
      notAfter: null,
      source: 'pkcs11',
    }];
  }
  return [];
}

/** @deprecated use listPkcs11Certificates(toolchain) */
export function listLinuxCertificates(toolchain: Pkcs11Toolchain | null): Certificate[] {
  return listPkcs11Certificates(toolchain);
}
