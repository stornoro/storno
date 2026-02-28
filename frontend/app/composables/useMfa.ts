import type { MfaStatus, TotpSetupResponse, TotpEnableResponse } from '~/types'

export function useMfa() {
  const { get, post } = useApi()
  const apiBase = useApiBase()
  const fetchFn = useRequestFetch()

  async function getStatus(): Promise<MfaStatus> {
    return get<MfaStatus>('/v1/me/mfa/status')
  }

  async function setupTotp(): Promise<TotpSetupResponse> {
    return post<TotpSetupResponse>('/v1/me/mfa/totp/setup')
  }

  async function enableTotp(code: string): Promise<TotpEnableResponse> {
    return post<TotpEnableResponse>('/v1/me/mfa/totp/enable', { code })
  }

  async function disableTotp(password: string): Promise<void> {
    await post('/v1/me/mfa/totp/disable', { password })
  }

  async function regenerateBackupCodes(password: string): Promise<{ backupCodes: string[] }> {
    return post<{ backupCodes: string[] }>('/v1/me/mfa/backup-codes/regenerate', { password })
  }

  async function verifyMfaChallenge(mfaToken: string, code: string, type: 'totp' | 'backup'): Promise<{ token: string; refresh_token: string }> {
    return fetchFn<{ token: string; refresh_token: string }>('/auth/mfa/verify', {
      baseURL: apiBase,
      method: 'POST',
      body: { mfaToken, code, type },
    })
  }

  return {
    getStatus,
    setupTotp,
    enableTotp,
    disableTotp,
    regenerateBackupCodes,
    verifyMfaChallenge,
  }
}
