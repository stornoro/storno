import type { AgentConfig } from '../config.js';

/**
 * What protects the private key, which decides how the agent authenticates:
 *
 * - `token`: a smart card / USB token (PKCS#11, Keychain-backed or a Windows
 *   smart-card provider). Needs the PIN; the agent never touches it without one.
 * - `cloud`: a remote (cloud / "virtual token") qualified certificate — Trans Sped
 *   EasySign, certSIGN cloud, DigiSign cloud and similar — exposed on Windows by
 *   the vendor's key storage provider. There is no PIN: the vendor's driver asks
 *   the user to approve each operation (app confirmation, OTP or its own dialog).
 * - `software`: a key stored by Windows itself (imported .pfx, TPM). No PIN.
 */
export type CertificateKind = 'token' | 'cloud' | 'software';

const CLOUD_PROVIDER_PATTERNS = [
  /easy\s*sign/i,
  /trans\s*sped/i,
  /cloud/i,
  /remote/i,
  /paperless/i,
  /virtual/i,
  /\bcsc\b/i,
  /sign(ing)?\s*service/i,
  /namirial/i,
  /d-?trust/i,
  /trust\s*sign/i,
];

const SOFTWARE_PROVIDER_PATTERNS = [
  /^microsoft software key storage provider$/i,
  /^microsoft platform crypto provider$/i,
  /^microsoft (enhanced|strong|base|rsa|dh) /i,
];

/** Classify a Windows key provider name (CSP / KSP). Unknown providers count as tokens, the safe default. */
export function classifyWindowsProvider(provider: string, config?: Pick<AgentConfig, 'cloudCertificateProviders'>): CertificateKind {
  const name = provider.trim();
  if (!name) return 'token';
  const extra = (config?.cloudCertificateProviders ?? []).map((p) => p.toLowerCase()).filter(Boolean);
  if (extra.some((p) => name.toLowerCase().includes(p))) return 'cloud';
  if (CLOUD_PROVIDER_PATTERNS.some((re) => re.test(name))) return 'cloud';
  if (SOFTWARE_PROVIDER_PATTERNS.some((re) => re.test(name))) return 'software';
  return 'token';
}

/** Certificates that never take a PIN: the vendor (cloud) or Windows itself guards the key. */
export function isPinlessKind(kind: CertificateKind | undefined): boolean {
  return kind === 'cloud' || kind === 'software';
}

/** Certificate ids the user forced to "cloud" in config.json (detection missed the provider). */
export function isConfiguredCloudId(certificateId: string, config?: Pick<AgentConfig, 'cloudCertificateIds'>): boolean {
  const id = certificateId.trim().toUpperCase();
  return (config?.cloudCertificateIds ?? []).some((c) => c.trim().toUpperCase() === id);
}
