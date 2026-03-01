import { bufferToBase64url, base64urlToBuffer } from '~/utils/webauthn'

export function useStepUpMfa() {
  const { post } = useApi()
  const verificationToken = ref<string | null>(null)
  const challengeToken = ref<string | null>(null)
  const methods = ref<string[]>([])
  const mfaRequired = ref(false)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function requestChallenge(): Promise<boolean> {
    loading.value = true
    error.value = null
    try {
      const result = await post<{
        mfa_required: boolean
        challenge_token?: string
        methods?: string[]
      }>('/v1/mfa/challenge', {})

      mfaRequired.value = result.mfa_required
      if (result.mfa_required && result.challenge_token) {
        challengeToken.value = result.challenge_token
        methods.value = result.methods ?? []
        return true
      }
      return false
    } catch (err: any) {
      error.value = err?.data?.error ?? 'Failed to create MFA challenge.'
      return false
    } finally {
      loading.value = false
    }
  }

  async function getPasskeyOptions(): Promise<any> {
    if (!challengeToken.value) throw new Error('No challenge token')
    const apiBase = useApiBase()
    const fetchFn = useRequestFetch()

    const options = await fetchFn<any>('/v1/mfa/passkey/options', {
      baseURL: apiBase,
      method: 'POST',
      body: { challengeToken: challengeToken.value },
    })
    return options
  }

  async function verifyWithPasskey(): Promise<boolean> {
    loading.value = true
    error.value = null
    try {
      const options = await getPasskeyOptions()

      const publicKeyOptions: PublicKeyCredentialRequestOptions = {
        challenge: base64urlToBuffer(options.challenge),
        rpId: options.rpId,
        timeout: options.timeout,
        userVerification: options.userVerification || 'preferred',
        allowCredentials: (options.allowCredentials || []).map((c: any) => ({
          type: c.type,
          id: base64urlToBuffer(c.id),
          transports: c.transports,
        })),
      }

      const credential = await navigator.credentials.get({
        publicKey: publicKeyOptions,
      }) as PublicKeyCredential

      if (!credential) throw new Error('Authentication cancelled')

      const assertionResponse = credential.response as AuthenticatorAssertionResponse

      const credentialData = {
        id: bufferToBase64url(credential.rawId),
        rawId: bufferToBase64url(credential.rawId),
        type: credential.type,
        response: {
          clientDataJSON: bufferToBase64url(assertionResponse.clientDataJSON),
          authenticatorData: bufferToBase64url(assertionResponse.authenticatorData),
          signature: bufferToBase64url(assertionResponse.signature),
          userHandle: assertionResponse.userHandle
            ? bufferToBase64url(assertionResponse.userHandle)
            : null,
        },
      }

      return await verify('passkey', undefined, credentialData)
    } catch (err: any) {
      error.value = err?.message ?? 'Passkey verification failed.'
      return false
    } finally {
      loading.value = false
    }
  }

  async function verify(type: string, code?: string, credential?: any): Promise<boolean> {
    loading.value = true
    error.value = null
    try {
      const body: any = { challengeToken: challengeToken.value, type }
      if (code) body.code = code
      if (credential) body.credential = credential

      const result = await post<{ verification_token: string }>('/v1/mfa/verify', body)
      verificationToken.value = result.verification_token
      return true
    } catch (err: any) {
      error.value = err?.data?.error ?? 'Verification failed.'
      return false
    } finally {
      loading.value = false
    }
  }

  function reset() {
    verificationToken.value = null
    challengeToken.value = null
    methods.value = []
    mfaRequired.value = false
    loading.value = false
    error.value = null
  }

  return {
    verificationToken,
    challengeToken,
    methods,
    mfaRequired,
    loading,
    error,
    requestChallenge,
    getPasskeyOptions,
    verify,
    verifyWithPasskey,
    reset,
  }
}
