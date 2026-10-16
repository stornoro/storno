/**
 * Mirror console output to ~/.storno-agent/agent.log.
 *
 * The packaged app is launched by Finder / launchd with stdout and stderr on
 * /dev/null, so without this there is nothing to inspect when a sync fails.
 * Each line is timestamped; the file is rotated at 5 MB and one older copy
 * (agent.log.1) is kept. Logging never throws: a failed write is dropped.
 */

import { appendFileSync, existsSync, mkdirSync, renameSync, statSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';
import { format } from 'node:util';

const MAX_BYTES = 5 * 1024 * 1024;

export function logFilePath(configDir: string): string {
  return join(configDir, 'agent.log');
}

function rotateIfNeeded(file: string): void {
  try {
    if (statSync(file).size < MAX_BYTES) return;
    const old = `${file}.1`;
    if (existsSync(old)) unlinkSync(old);
    renameSync(file, old);
  } catch {
    /* no file yet, or not rotatable: keep appending */
  }
}

/** Patch console.log/info/warn/error to also append to the log file. */
export function installFileLog(configDir: string): string {
  const file = logFilePath(configDir);
  try { mkdirSync(configDir, { recursive: true }); } catch { /* best effort */ }

  let bytesSinceCheck = 0;
  const write = (level: string, args: unknown[]) => {
    try {
      const line = `${new Date().toISOString()} ${level} ${format(...args)}\n`;
      bytesSinceCheck += line.length;
      if (bytesSinceCheck > 64 * 1024) { bytesSinceCheck = 0; rotateIfNeeded(file); }
      appendFileSync(file, line, { mode: 0o600 });
    } catch {
      /* logging must never break the agent */
    }
  };

  rotateIfNeeded(file);
  for (const level of ['log', 'info', 'warn', 'error'] as const) {
    const original = console[level].bind(console);
    console[level] = (...args: unknown[]) => {
      original(...args);
      write(level.toUpperCase().padEnd(5), args);
    };
  }
  return file;
}
