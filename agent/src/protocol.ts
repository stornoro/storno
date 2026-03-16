import { execSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { homedir } from 'node:os';

const SCHEME = 'storno-agent';

function currentDir(): string {
  // ESM (tsc output)
  try {
    if (import.meta.url) return dirname(fileURLToPath(import.meta.url));
  } catch { /* CJS bundle — fall through */ }
  // CJS bundle (esbuild) / pkg snapshot
  return __dirname;
}

function resolveIconsDir(): string {
  // Relative to this file (works for tsc, esbuild CJS, and pkg snapshot)
  const localDir = join(currentDir(), 'icons');
  if (existsSync(localDir)) return localDir;

  // Fallback: next to the executable (for bundled builds where icons/ is alongside the binary)
  const exeDir = join(dirname(process.execPath), 'icons');
  if (existsSync(exeDir)) return exeDir;

  throw new Error('Icons directory not found');
}

export interface BinaryCommand {
  /** true when running as a pkg-compiled standalone binary */
  isPkg: boolean;
  /** Absolute path to the binary (pkg) or script (node) */
  execPath: string;
}

/** Build the shell exec line: standalone binary or "node <script>" */
function execLine(cmd: BinaryCommand, args: string): string {
  if (cmd.isPkg) {
    return `"${cmd.execPath}" ${args}`;
  }
  return `"${process.execPath}" "${cmd.execPath}" ${args}`;
}

function registerMacOS(cmd: BinaryCommand): void {
  const appDir = join(homedir(), '.storno-agent', 'Storno Agent.app');
  const contentsDir = join(appDir, 'Contents');
  const macosDir = join(contentsDir, 'MacOS');
  const resourcesDir = join(contentsDir, 'Resources');

  mkdirSync(macosDir, { recursive: true });
  mkdirSync(resourcesDir, { recursive: true });

  // Copy .icns icon into Resources/
  copyFileSync(join(resolveIconsDir(), 'icon.icns'), join(resourcesDir, 'icon.icns'));

  writeFileSync(join(contentsDir, 'Info.plist'), `<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>CFBundleIdentifier</key>
  <string>ro.storno.agent</string>
  <key>CFBundleName</key>
  <string>Storno Agent</string>
  <key>CFBundleExecutable</key>
  <string>storno-agent</string>
  <key>CFBundleIconFile</key>
  <string>icon</string>
  <key>CFBundleURLTypes</key>
  <array>
    <dict>
      <key>CFBundleURLName</key>
      <string>Storno Agent URL</string>
      <key>CFBundleURLSchemes</key>
      <array>
        <string>${SCHEME}</string>
      </array>
    </dict>
  </array>
</dict>
</plist>
`);

  writeFileSync(join(macosDir, 'storno-agent'), `#!/bin/sh\nexec ${execLine(cmd, 'start')}\n`);
  execSync(`chmod +x "${join(macosDir, 'storno-agent')}"`);

  // Register with LaunchServices
  const lsregister = '/System/Library/Frameworks/CoreServices.framework/Versions/A/Frameworks/LaunchServices.framework/Versions/A/Support/lsregister';
  // -f forces update of cached registration (icon, URL scheme, etc.)
  execSync(`"${lsregister}" -f "${appDir}"`);
}

function registerWindows(cmd: BinaryCommand): void {
  // Copy .ico to a stable location
  const iconDir = join(homedir(), '.storno-agent');
  mkdirSync(iconDir, { recursive: true });
  const iconPath = join(iconDir, 'icon.ico');
  copyFileSync(join(resolveIconsDir(), 'icon.ico'), iconPath);

  // Build the command value for the registry
  let commandValue: string;
  if (cmd.isPkg) {
    const escaped = cmd.execPath.replace(/\\/g, '\\\\');
    commandValue = `\\"${escaped}\\" start`;
  } else {
    const nodeEscaped = process.execPath.replace(/\\/g, '\\\\');
    const scriptEscaped = cmd.execPath.replace(/\\/g, '\\\\');
    commandValue = `\\"${nodeEscaped}\\" \\"${scriptEscaped}\\" start`;
  }

  const iconEscaped = iconPath.replace(/\\/g, '\\\\');
  const regCmds = [
    `reg add "HKCU\\Software\\Classes\\${SCHEME}" /ve /d "URL:Storno Agent" /f`,
    `reg add "HKCU\\Software\\Classes\\${SCHEME}" /v "URL Protocol" /d "" /f`,
    `reg add "HKCU\\Software\\Classes\\${SCHEME}\\DefaultIcon" /ve /d "${iconEscaped}" /f`,
    `reg add "HKCU\\Software\\Classes\\${SCHEME}\\shell\\open\\command" /ve /d "${commandValue}" /f`,
  ];
  for (const c of regCmds) {
    execSync(c);
  }
}

function registerLinux(cmd: BinaryCommand): void {
  // Copy icon to standard location
  const iconDir = join(homedir(), '.local', 'share', 'icons', 'hicolor', '256x256', 'apps');
  mkdirSync(iconDir, { recursive: true });
  copyFileSync(join(resolveIconsDir(), 'icon.png'), join(iconDir, `${SCHEME}.png`));

  const appsDir = join(homedir(), '.local', 'share', 'applications');
  mkdirSync(appsDir, { recursive: true });

  const desktopFile = join(appsDir, `${SCHEME}.desktop`);
  writeFileSync(desktopFile, `[Desktop Entry]
Type=Application
Name=Storno Agent
Exec=${execLine(cmd, 'start')}
Icon=${SCHEME}
MimeType=x-scheme-handler/${SCHEME}
NoDisplay=true
`);

  execSync(`xdg-mime default ${SCHEME}.desktop x-scheme-handler/${SCHEME}`);
}

export function registerProtocol(cmd: BinaryCommand): void {
  const platform = process.platform;

  if (platform === 'darwin') {
    registerMacOS(cmd);
  } else if (platform === 'win32') {
    registerWindows(cmd);
  } else if (platform === 'linux') {
    registerLinux(cmd);
  }
}
