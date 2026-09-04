/**
 * Secret storage for unattended operation (token PIN, Storno API key).
 *
 *  - macOS:   login Keychain via `security` (generic password, service storno-agent)
 *  - Windows: DPAPI through PowerShell (ConvertFrom-SecureString), user-scoped,
 *             ciphertext kept in ~/.storno-agent/secrets.json
 *  - Linux:   libsecret via `secret-tool` when available, otherwise
 *             ~/.storno-agent/secrets.json with mode 0600 (documented fallback)
 *
 * Values are passed through environment variables / stdin, never on the
 * command line, so they do not show up in process listings.
 */

import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync, mkdirSync, chmodSync } from 'node:fs';
import { join } from 'node:path';
import { platform } from 'node:os';
import { getConfigDir } from '../config.js';

const SERVICE = 'storno-agent';

function fallbackFile(): string {
  return join(getConfigDir(), 'secrets.json');
}

function readFallback(): Record<string, string> {
  try {
    const f = fallbackFile();
    if (!existsSync(f)) return {};
    return JSON.parse(readFileSync(f, 'utf-8')) as Record<string, string>;
  } catch {
    return {};
  }
}

function writeFallback(data: Record<string, string>): void {
  mkdirSync(getConfigDir(), { recursive: true });
  const f = fallbackFile();
  writeFileSync(f, JSON.stringify(data, null, 2) + '\n', { mode: 0o600 });
  try { chmodSync(f, 0o600); } catch { /* windows */ }
}

function hasSecretTool(): boolean {
  const r = spawnSync('secret-tool', ['--version'], { stdio: 'ignore' });
  return !r.error;
}

/** Which backend is in use; reported in /monitor status so users know where the PIN lives. */
export function secretStoreName(): string {
  switch (platform()) {
    case 'darwin': return 'macOS Keychain';
    case 'win32': return 'Windows DPAPI';
    default: return hasSecretTool() ? 'libsecret (secret-tool)' : 'file ~/.storno-agent/secrets.json (0600)';
  }
}

export function setSecret(account: string, value: string): void {
  const os = platform();
  if (os === 'darwin') {
    // -U updates an existing item instead of failing
    execFileSync('security', ['add-generic-password', '-s', SERVICE, '-a', account, '-w', value, '-U'], { stdio: 'ignore' });
    return;
  }
  if (os === 'win32') {
    const r = spawnSync('powershell', [
      '-NoProfile', '-NonInteractive', '-Command',
      'ConvertTo-SecureString -String $env:STORNO_SECRET -AsPlainText -Force | ConvertFrom-SecureString',
    ], { encoding: 'utf-8', env: { ...process.env, STORNO_SECRET: value } });
    if (r.status !== 0 || !r.stdout.trim()) throw new Error('DPAPI encryption failed: ' + (r.stderr || '').trim());
    const data = readFallback();
    data[account] = r.stdout.trim();
    writeFallback(data);
    return;
  }
  if (hasSecretTool()) {
    const r = spawnSync('secret-tool', ['store', '--label=Storno Agent ' + account, 'service', SERVICE, 'account', account], { input: value, encoding: 'utf-8' });
    if (r.status === 0) return;
  }
  const data = readFallback();
  data[account] = value;
  writeFallback(data);
}

export function getSecret(account: string): string | null {
  const os = platform();
  if (os === 'darwin') {
    const r = spawnSync('security', ['find-generic-password', '-s', SERVICE, '-a', account, '-w'], { encoding: 'utf-8' });
    if (r.status !== 0) return null;
    return r.stdout.replace(/\r?\n$/, '');
  }
  if (os === 'win32') {
    const enc = readFallback()[account];
    if (!enc) return null;
    const r = spawnSync('powershell', [
      '-NoProfile', '-NonInteractive', '-Command',
      '$s = ConvertTo-SecureString -String $env:STORNO_ENC; [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))',
    ], { encoding: 'utf-8', env: { ...process.env, STORNO_ENC: enc } });
    if (r.status !== 0) return null;
    return r.stdout.replace(/\r?\n$/, '');
  }
  if (hasSecretTool()) {
    const r = spawnSync('secret-tool', ['lookup', 'service', SERVICE, 'account', account], { encoding: 'utf-8' });
    if (r.status === 0 && r.stdout !== '') return r.stdout.replace(/\r?\n$/, '');
  }
  return readFallback()[account] ?? null;
}

export function deleteSecret(account: string): void {
  const os = platform();
  if (os === 'darwin') {
    spawnSync('security', ['delete-generic-password', '-s', SERVICE, '-a', account], { stdio: 'ignore' });
    return;
  }
  if (os !== 'win32' && hasSecretTool()) {
    spawnSync('secret-tool', ['clear', 'service', SERVICE, 'account', account], { stdio: 'ignore' });
  }
  const data = readFallback();
  if (account in data) {
    delete data[account];
    writeFallback(data);
  }
}
