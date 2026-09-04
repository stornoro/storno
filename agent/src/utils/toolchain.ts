/**
 * Resolve the PKCS#11 module and the curl / openssl / pkcs11-tool binaries
 * able to load it.
 *
 * The catch on macOS: a PKCS#11 module can only be loaded by a process of the
 * same CPU architecture. Some vendor middleware (Longmai mToken CryptoID) ships
 * x86_64-only dylibs, so on Apple Silicon the whole chain (curl with OpenSSL,
 * the libp11 engine, pkcs11-tool) has to come from an Intel Homebrew under
 * Rosetta (/usr/local) rather than the native one (/opt/homebrew).
 *
 * Apple's system curl/openssl (SecureTransport / LibreSSL) have no PKCS#11
 * engine support at all, so on macOS a Homebrew curl is required either way.
 */

import { existsSync, openSync, readSync, closeSync, writeFileSync, mkdirSync } from 'node:fs';
import { platform, arch, homedir } from 'node:os';
import { join } from 'node:path';
import type { AgentConfig } from '../config.js';
import { detectPkcs11Module, expandHome } from './pkcs11-paths.js';

export type CpuArch = 'x86_64' | 'arm64' | 'unknown';

export interface Pkcs11Toolchain {
  module: string;
  moduleName: string;
  /** Architectures the module binary was built for (macOS only, else ['unknown']). */
  moduleArchs: CpuArch[];
  /** Architecture the tools must have to load the module. */
  requiredArch: CpuArch;
  /** True when requiredArch differs from the host CPU (Rosetta / emulation needed). */
  emulated: boolean;
  curlPath: string | null;
  opensslPath: string | null;
  pkcs11ToolPath: string | null;
  /** OpenSSL engines directory holding libp11's pkcs11 engine, when found. */
  enginesDir: string | null;
  /** Environment to spawn curl/openssl with. */
  env: NodeJS.ProcessEnv;
  /** Human-readable list of what is missing, empty when ready for mTLS. */
  missing: string[];
}

const HOMEBREW_PREFIX: Record<CpuArch, string> = {
  arm64: '/opt/homebrew',
  x86_64: '/usr/local',
  unknown: '/usr/local',
};

function hostArch(): CpuArch {
  const a = arch();
  if (a === 'arm64') return 'arm64';
  if (a === 'x64') return 'x86_64';
  return 'unknown';
}

/** Read Mach-O / fat headers to find which architectures a dylib contains. */
export function machoArchs(path: string): CpuArch[] {
  let fd: number | null = null;
  try {
    fd = openSync(path, 'r');
    const head = Buffer.alloc(4096);
    const n = readSync(fd, head, 0, head.length, 0);
    if (n < 8) return ['unknown'];

    const magic = head.readUInt32BE(0);
    const CPU_X86_64 = 0x01000007;
    const CPU_ARM64 = 0x0100000c;
    const toArch = (cputype: number): CpuArch =>
      cputype === CPU_X86_64 ? 'x86_64' : cputype === CPU_ARM64 ? 'arm64' : 'unknown';

    // Fat (universal) binary: big-endian header + array of fat_arch
    if (magic === 0xcafebabe) {
      const count = head.readUInt32BE(4);
      const archs: CpuArch[] = [];
      for (let i = 0; i < count && 8 + i * 20 + 4 <= n; i++) {
        archs.push(toArch(head.readUInt32BE(8 + i * 20)));
      }
      return archs.length ? archs : ['unknown'];
    }

    // Thin 64-bit Mach-O, little-endian: magic 0xfeedfacf, then cputype
    const magicLE = head.readUInt32LE(0);
    if (magicLE === 0xfeedfacf || magicLE === 0xfeedface) {
      return [toArch(head.readUInt32LE(4))];
    }
    return ['unknown'];
  } catch {
    return ['unknown'];
  } finally {
    if (fd !== null) closeSync(fd);
  }
}

/** ~/.storno-agent/toolchain-<arch> — built by scripts/build-pkcs11-toolchain.sh */
export function privateToolchainDir(a: CpuArch): string {
  return join(homedir(), '.storno-agent', `toolchain-${a}`);
}

function firstExisting(paths: string[]): string | null {
  for (const p of paths) if (existsSync(p)) return p;
  return null;
}

/**
 * Resolve the PKCS#11 toolchain for the current config, or null when no
 * module is configured or detected.
 */
export function resolvePkcs11Toolchain(config: AgentConfig): Pkcs11Toolchain | null {
  const os = platform();
  if (os === 'win32') return null;

  let module = config.pkcs11Module ? expandHome(config.pkcs11Module) : null;
  let moduleName = 'configured';
  if (!module) {
    const detected = detectPkcs11Module();
    if (!detected) return null;
    module = detected.path;
    moduleName = detected.name;
  }
  if (!existsSync(module)) return null;

  const host = hostArch();
  const moduleArchs = os === 'darwin' ? machoArchs(module) : ['unknown' as CpuArch];
  let requiredArch: CpuArch = host;
  if (os === 'darwin' && !moduleArchs.includes(host) && !moduleArchs.includes('unknown')) {
    requiredArch = moduleArchs[0];
  }
  const emulated = requiredArch !== host;

  const missing: string[] = [];
  let curlPath: string | null;
  let opensslPath: string | null;
  let pkcs11ToolPath: string | null;
  let enginesDir: string | null = null;

  if (os === 'darwin') {
    // 1) Self-contained toolchain built by scripts/build-pkcs11-toolchain.sh
    //    (no Homebrew, no sudo, removable by deleting the folder)
    // 2) Homebrew of the matching architecture
    const local = privateToolchainDir(requiredArch);
    const prefix = HOMEBREW_PREFIX[requiredArch];
    curlPath = config.curlPath && config.curlPath !== 'curl' && existsSync(config.curlPath)
      ? config.curlPath
      : firstExisting([`${local}/bin/curl`, `${prefix}/opt/curl/bin/curl`]);
    opensslPath = config.opensslPath && existsSync(config.opensslPath)
      ? config.opensslPath
      : firstExisting([`${local}/bin/openssl`, `${prefix}/opt/openssl@3/bin/openssl`, `${prefix}/bin/openssl`]);
    pkcs11ToolPath = config.pkcs11ToolPath && existsSync(config.pkcs11ToolPath)
      ? config.pkcs11ToolPath
      : firstExisting([`${local}/bin/pkcs11-tool`, `${prefix}/bin/pkcs11-tool`]);
    enginesDir = firstExisting([
      `${local}/lib/engines-3`,
      `${prefix}/lib/engines-3`,
      `${prefix}/opt/libp11/lib/engines-3`,
    ]);
    const engineOk = enginesDir !== null && existsSync(`${enginesDir}/pkcs11.dylib`);

    const hint = `run scripts/build-pkcs11-toolchain.sh ${requiredArch} (self-contained), or `
      + (requiredArch === 'x86_64' ? 'arch -x86_64 /usr/local/bin/brew' : 'brew');
    if (!curlPath) missing.push(`curl with OpenSSL: ${hint} install curl`);
    if (!engineOk) missing.push(`libp11 pkcs11 engine: ${hint} install libp11`);
    if (!opensslPath) missing.push(`openssl: ${hint} install openssl@3`);
    // pkcs11-tool only powers certificate listing; without it the placeholder entry is used
  } else {
    // Linux: distro packages put everything on PATH and the engine in OpenSSL's default dir
    curlPath = config.curlPath || 'curl';
    opensslPath = config.opensslPath || 'openssl';
    pkcs11ToolPath = config.pkcs11ToolPath || 'pkcs11-tool';
  }

  const env: NodeJS.ProcessEnv = {
    ...process.env,
    // libp11's engine reads PKCS11_MODULE_PATH; keep MODULE_PATH for older setups
    PKCS11_MODULE_PATH: module,
    MODULE_PATH: module,
  };
  if (enginesDir) env.OPENSSL_ENGINES = enginesDir;

  // Engine config: FORCE_LOGIN makes libp11 log in (with the pin-value from
  // the URI) BEFORE searching objects. Tokens such as Longmai mToken keep the
  // certificate as a private object, so without it curl fails with
  // "SSL engine cannot load client cert".
  const cnf = writeEngineConfig(module, enginesDir);
  if (cnf) env.OPENSSL_CONF = cnf;

  return {
    module,
    moduleName,
    moduleArchs,
    requiredArch,
    emulated,
    curlPath,
    opensslPath,
    pkcs11ToolPath,
    enginesDir,
    env,
    missing,
  };
}

function writeEngineConfig(module: string, enginesDir: string | null): string | null {
  try {
    const dir = join(homedir(), '.storno-agent');
    mkdirSync(dir, { recursive: true });
    const path = join(dir, 'openssl-pkcs11.cnf');
    const dynamic = enginesDir ? `dynamic_path = ${enginesDir}/pkcs11.${platform() === 'darwin' ? 'dylib' : 'so'}\n` : '';
    writeFileSync(path, `openssl_conf = openssl_init
[openssl_init]
engines = engine_section
[engine_section]
pkcs11 = pkcs11_section
[pkcs11_section]
engine_id = pkcs11
${dynamic}MODULE_PATH = ${module}
FORCE_LOGIN = 1
VERBOSE = 1
init = 0
`);
    return path;
  } catch {
    return null;
  }
}

/** Sentinel certificate id: "whatever certificate/key the token exposes after login". */
export const PKCS11_AUTO_ID = 'pkcs11-auto';

/** RFC 7512 percent-encoding for a PKCS#11 URI attribute value. */
function pctEncode(value: string): string {
  return Array.from(Buffer.from(value, 'utf8'))
    .map((b) => {
      const c = String.fromCharCode(b);
      return /[A-Za-z0-9\-._~]/.test(c) ? c : '%' + b.toString(16).padStart(2, '0');
    })
    .join('');
}

/**
 * Build a PKCS#11 URI for libp11. `id` is the hex CKA_ID from pkcs11-tool,
 * every byte must be percent-escaped (`%a1%b2`, not `%a1b2`).
 */
export function pkcs11Uri(id: string, pin?: string): string {
  const parts: string[] = [];
  if (id && id !== PKCS11_AUTO_ID) {
    const hex = id.replace(/[^0-9a-fA-F]/g, '');
    const bytes = hex.match(/.{2}/g) ?? [];
    if (bytes.length) parts.push('id=' + bytes.map((b) => '%' + b.toLowerCase()).join(''));
  }
  if (pin) parts.push('pin-value=' + pctEncode(pin));
  return 'pkcs11:' + parts.join(';');
}
