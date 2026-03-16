import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { homedir, platform } from 'node:os';

export interface AgentConfig {
  port: number;
  allowedOrigins: string[];
  pkcs11Module: string | null;
  curlPath: string;
}

const CONFIG_DIR = join(homedir(), '.storno-agent');
const CONFIG_FILE = join(CONFIG_DIR, 'config.json');

const DEFAULT_CONFIG: AgentConfig = {
  port: 17394,
  allowedOrigins: [
    'https://app.storno.ro',
    'https://app.storno.test:3000',
    'http://app.storno.test:3000',
    'https://localhost:3000',
    'http://localhost:3000',
    'http://localhost:3001',
  ],
  pkcs11Module: null,
  // On Windows, use the native System32 curl (Schannel) instead of Git's curl (OpenSSL).
  // Schannel can access certificates from the Windows Certificate Store by thumbprint.
  curlPath: platform() === 'win32' ? 'C:\\Windows\\System32\\curl.exe' : 'curl',
};

export function loadConfig(): AgentConfig {
  if (!existsSync(CONFIG_FILE)) {
    return { ...DEFAULT_CONFIG };
  }

  try {
    const raw = readFileSync(CONFIG_FILE, 'utf-8');
    const parsed = JSON.parse(raw);
    return { ...DEFAULT_CONFIG, ...parsed };
  } catch {
    return { ...DEFAULT_CONFIG };
  }
}

export function saveConfig(config: Partial<AgentConfig>): AgentConfig {
  const current = loadConfig();
  const merged = { ...current, ...config };

  mkdirSync(CONFIG_DIR, { recursive: true });
  writeFileSync(CONFIG_FILE, JSON.stringify(merged, null, 2) + '\n', 'utf-8');

  return merged;
}

export function getConfigDir(): string {
  return CONFIG_DIR;
}
