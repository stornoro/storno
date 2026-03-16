import { createWriteStream, renameSync, chmodSync, unlinkSync } from 'node:fs';
import { get as httpsGet } from 'node:https';
import { pipeline } from 'node:stream/promises';
import { Readable } from 'node:stream';
import { spawn } from 'node:child_process';

const REPO = 'stornoro/storno';
const RELEASE_TAG_PREFIX = 'agent-v';

export interface UpdateInfo {
  updateAvailable: boolean;
  currentVersion: string;
  latestVersion: string | null;
  downloadUrl: string | null;
}

/** Cached update info — refreshed every 30 minutes. */
let cachedUpdate: UpdateInfo | null = null;
let lastCheck = 0;
const CHECK_INTERVAL = 30 * 60 * 1000; // 30 min

function binaryAssetName(): string {
  const platform = process.platform;
  const arch = process.arch;

  if (platform === 'win32') return 'storno-agent-win-x64.exe';
  if (platform === 'linux') return 'storno-agent-linux-x64';
  if (platform === 'darwin' && arch === 'arm64') return 'storno-agent-macos-arm64';
  if (platform === 'darwin') return 'storno-agent-macos-x64';

  return 'storno-agent-linux-x64'; // fallback
}

async function fetchLatestRelease(): Promise<{ version: string; downloadUrl: string } | null> {
  try {
    const res = await fetch(`https://api.github.com/repos/${REPO}/releases?per_page=10`, {
      headers: { 'User-Agent': 'storno-agent', Accept: 'application/vnd.github+json' },
      signal: AbortSignal.timeout(10_000),
    });

    if (!res.ok) return null;

    const releases = await res.json() as Array<{
      tag_name: string;
      assets: Array<{ name: string; browser_download_url: string }>;
    }>;

    // Find latest agent release
    const agentRelease = releases.find(r => r.tag_name.startsWith(RELEASE_TAG_PREFIX));
    if (!agentRelease) return null;

    const version = agentRelease.tag_name.replace(RELEASE_TAG_PREFIX, '');
    const assetName = binaryAssetName();
    const asset = agentRelease.assets.find(a => a.name === assetName);

    return {
      version,
      downloadUrl: asset?.browser_download_url ?? null as any,
    };
  } catch {
    return null;
  }
}

function compareVersions(a: string, b: string): number {
  const pa = a.split('.').map(Number);
  const pb = b.split('.').map(Number);
  for (let i = 0; i < 3; i++) {
    const diff = (pa[i] ?? 0) - (pb[i] ?? 0);
    if (diff !== 0) return diff;
  }
  return 0;
}

export async function checkForUpdate(currentVersion: string): Promise<UpdateInfo> {
  const now = Date.now();
  if (cachedUpdate && (now - lastCheck) < CHECK_INTERVAL) {
    return cachedUpdate;
  }

  const latest = await fetchLatestRelease();
  const info: UpdateInfo = {
    updateAvailable: false,
    currentVersion,
    latestVersion: latest?.version ?? null,
    downloadUrl: latest?.downloadUrl ?? null,
  };

  if (latest && compareVersions(latest.version, currentVersion) > 0) {
    info.updateAvailable = true;
  }

  cachedUpdate = info;
  lastCheck = now;
  return info;
}

/**
 * Download the latest binary from GitHub, replace the current one, and restart.
 */
export async function applyUpdate(currentVersion: string): Promise<{ success: boolean; message: string }> {
  const info = await checkForUpdate(currentVersion);

  if (!info.updateAvailable || !info.downloadUrl) {
    return { success: false, message: 'No update available.' };
  }

  const binaryPath = process.execPath;
  const tempPath = binaryPath + '.update';
  const backupPath = binaryPath + '.backup';

  try {
    // Download the new binary, following redirects
    await downloadFile(info.downloadUrl, tempPath);

    // Swap binaries
    try { unlinkSync(backupPath); } catch { /* may not exist */ }
    renameSync(binaryPath, backupPath);
    renameSync(tempPath, binaryPath);

    // Ensure executable permission on unix
    if (process.platform !== 'win32') {
      chmodSync(binaryPath, 0o755);
    }

    // Restart: spawn the new binary and exit
    const child = spawn(binaryPath, process.argv.slice(1), {
      detached: true,
      stdio: 'ignore',
    });
    child.unref();

    // Give the new process a moment to start, then exit
    setTimeout(() => process.exit(0), 500);

    return { success: true, message: `Updated to v${info.latestVersion}. Restarting...` };
  } catch (err) {
    // Rollback
    try {
      unlinkSync(tempPath);
    } catch { /* cleanup */ }
    try {
      renameSync(backupPath, binaryPath);
    } catch { /* may not exist */ }

    return { success: false, message: `Update failed: ${(err as Error).message}` };
  }
}

function downloadFile(url: string, dest: string): Promise<void> {
  return new Promise((resolve, reject) => {
    const doRequest = (requestUrl: string, redirects = 0) => {
      if (redirects > 5) {
        reject(new Error('Too many redirects'));
        return;
      }

      const parsedUrl = new URL(requestUrl);
      const mod = parsedUrl.protocol === 'https:' ? httpsGet : require('node:http').get;

      mod(requestUrl, { headers: { 'User-Agent': 'storno-agent' } }, (res: any) => {
        // Follow redirects (GitHub releases redirect to S3)
        if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
          doRequest(res.headers.location, redirects + 1);
          return;
        }

        if (res.statusCode !== 200) {
          reject(new Error(`Download failed: HTTP ${res.statusCode}`));
          return;
        }

        const file = createWriteStream(dest);
        pipeline(Readable.from(res), file).then(resolve).catch(reject);
      }).on('error', reject);
    };

    doRequest(url);
  });
}
