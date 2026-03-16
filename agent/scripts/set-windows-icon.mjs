#!/usr/bin/env node
// Embeds icon.ico into the Windows .exe built by pkg using resedit.
// Usage: node scripts/set-windows-icon.mjs build/storno-agent-win-x64.exe

import * as ResEdit from 'resedit';
import { readFileSync, writeFileSync } from 'fs';
import { basename } from 'path';

const exePath = process.argv[2];
if (!exePath) {
  console.error('Usage: node scripts/set-windows-icon.mjs <exe-path>');
  process.exit(1);
}

const icoPath = 'src/icons/icon.ico';
const version = '1.3.0';

const exeData = readFileSync(exePath);
const exe = ResEdit.NtExecutable.from(exeData);
const res = ResEdit.NtExecutableResource.from(exe);

// Embed icon
const icoData = readFileSync(icoPath);
const iconFile = ResEdit.Data.IconFile.from(icoData);
ResEdit.Resource.IconGroupEntry.replaceIconsForResource(
  res.entries,
  1, // icon group ID
  1033, // en-US
  iconFile.icons.map((icon) => icon.data),
);

// Set version info
const lang = 1033;
const codepage = 1200;
const [major, minor, patch] = version.split('.');

const viList = ResEdit.Resource.VersionInfo.fromEntries(res.entries);
let vi;
if (viList.length > 0) {
  vi = viList[0];
} else {
  vi = ResEdit.Resource.VersionInfo.createEmpty();
}

vi.setFileVersion(Number(major), Number(minor), Number(patch), 0, lang);
vi.setProductVersion(Number(major), Number(minor), Number(patch), 0, lang);
vi.setStringValues(
  { lang, codepage },
  {
    FileDescription: 'Storno ANAF Agent',
    ProductName: 'Storno Agent',
    CompanyName: 'Storno',
    ProductVersion: version,
    FileVersion: version,
    OriginalFilename: basename(exePath),
  },
);

vi.outputToResourceEntries(res.entries);
res.outputResource(exe);

const newBinary = exe.generate();
writeFileSync(exePath, Buffer.from(newBinary));

console.log(`Icon and metadata embedded into ${exePath}`);
