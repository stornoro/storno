#!/usr/bin/env node

import { startServer } from './server.js';
import { loadConfig, saveConfig } from './config.js';
import { discoverCertificates } from './certificates/discovery.js';
import { registerProtocol } from './protocol.js';
import { resolve } from 'node:path';

/** Resolve how to launch this binary. */
function resolveBinaryCommand(): { isPkg: boolean; execPath: string } {
  // pkg bundles set process.pkg; the real executable is process.execPath
  if ((process as any).pkg) {
    return { isPkg: true, execPath: process.execPath };
  }
  // Running via node — need "node <absolute-script-path>"
  return { isPkg: false, execPath: resolve(process.argv[1]) };
}

async function main() {
  const args = process.argv.slice(2);
  const command = args[0];

  switch (command) {
    case 'start':
      try {
        registerProtocol(resolveBinaryCommand());
      } catch {
        // Best-effort — don't block startup
      }
      startServer();
      break;

    case 'install': {
      const cmd = resolveBinaryCommand();
      try {
        registerProtocol(cmd);
        console.log('Protocol handler registered successfully.');
        console.log(`  Scheme:  storno-agent://`);
        console.log(`  Binary:  ${cmd.execPath}`);
      } catch (err) {
        console.error('Failed to register protocol handler:', (err as Error).message);
        process.exit(1);
      }
      break;
    }

    case 'status': {
      const config = loadConfig();
      try {
        const res = await fetch(`https://agent.storno.ro:${config.port}/health`, {
          signal: AbortSignal.timeout(2000),
        });
        const data = await res.json() as Record<string, unknown>;
        console.log('Agent is running');
        console.log(`  Version:  ${data.version}`);
        console.log(`  Platform: ${data.platform}`);
        console.log(`  Port:     ${config.port}`);
      } catch {
        console.log('Agent is not running');
        process.exit(1);
      }
      break;
    }

    case 'certificates': {
      const config = loadConfig();
      const certs = discoverCertificates(config.pkcs11Module);
      if (certs.length === 0) {
        console.log('No certificates found.');
        console.log('');
        console.log('Tips:');
        console.log('  - Make sure your hardware token is plugged in');
        console.log('  - On Linux, configure the PKCS#11 module:');
        console.log('    storno-agent config --pkcs11-module /path/to/pkcs11.so');
      } else {
        console.log(`Found ${certs.length} certificate(s):\n`);
        for (const cert of certs) {
          console.log(`  ID:      ${cert.id}`);
          console.log(`  Subject: ${cert.subject}`);
          console.log(`  Source:  ${cert.source}`);
          if (cert.issuer) console.log(`  Issuer:  ${cert.issuer}`);
          if (cert.notAfter) console.log(`  Expires: ${cert.notAfter}`);
          console.log('');
        }
      }
      break;
    }

    case 'config': {
      const flag = args[1];
      const value = args[2];

      if (flag === '--pkcs11-module' && value) {
        const config = saveConfig({ pkcs11Module: value });
        console.log(`PKCS#11 module set to: ${config.pkcs11Module}`);
      } else if (flag === '--port' && value) {
        const port = parseInt(value, 10);
        if (isNaN(port) || port < 1024 || port > 65535) {
          console.error('Port must be between 1024 and 65535');
          process.exit(1);
        }
        const config = saveConfig({ port });
        console.log(`Port set to: ${config.port}`);
      } else if (flag === '--show') {
        const config = loadConfig();
        console.log(JSON.stringify(config, null, 2));
      } else {
        console.log('Usage:');
        console.log('  storno-agent config --pkcs11-module <path>');
        console.log('  storno-agent config --port <number>');
        console.log('  storno-agent config --show');
      }
      break;
    }

    case 'help':
    case '--help':
    case '-h':
      console.log('Storno ANAF Agent — Local mTLS proxy for hardware USB tokens\n');
      console.log('Usage:');
      console.log('  storno-agent                Start the agent server (default)');
      console.log('  storno-agent start          Start the agent server');
      console.log('  storno-agent status         Check if the agent is running');
      console.log('  storno-agent install        Register storno-agent:// URL protocol');
      console.log('  storno-agent certificates   List available certificates');
      console.log('  storno-agent config         Manage configuration');
      console.log('');
      console.log('Options:');
      console.log('  storno-agent config --pkcs11-module <path>  Set PKCS#11 module (Linux)');
      console.log('  storno-agent config --port <number>         Set server port');
      console.log('  storno-agent config --show                  Show current config');
      break;

    default:
      // No command, URL scheme arg (storno-agent://...), or unknown → start server
      try {
        registerProtocol(resolveBinaryCommand());
      } catch {
        // Best-effort
      }
      startServer();
      break;
  }
}

main();
