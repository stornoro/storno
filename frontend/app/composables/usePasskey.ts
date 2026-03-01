import { bufferToBase64url, base64urlToBuffer } from '~/utils/webauthn'

export function usePasskey() {
  const authStore = useAuthStore()
  const { post, get, del } = useApi()
  const isLoading = ref(false)

  const isSupported = computed(() => {
    if (import.meta.server) return false
    return !!window.PublicKeyCredential
  })

  async function register(name?: string) {
    isLoading.value = true
    try {
      // 1. Get creation options from server
      const options = await post<any>('/v1/passkey/register/options', {})

      // 2. Decode challenge and user.id for the browser API
      const publicKeyOptions: PublicKeyCredentialCreationOptions = {
        challenge: base64urlToBuffer(options.challenge),
        rp: {
          name: options.rp.name,
          id: options.rp.id,
        },
        user: {
          id: base64urlToBuffer(options.user.id),
          name: options.user.name,
          displayName: options.user.displayName,
        },
        pubKeyCredParams: options.pubKeyCredParams.map((p: any) => ({
          type: p.type,
          alg: p.alg,
        })),
        timeout: options.timeout,
        attestation: options.attestation || 'none',
        authenticatorSelection: options.authenticatorSelection,
        excludeCredentials: (options.excludeCredentials || []).map((c: any) => ({
          type: c.type,
          id: base64urlToBuffer(c.id),
          transports: c.transports,
        })),
      }

      // 3. Create credential using browser WebAuthn API
      const credential = await navigator.credentials.create({
        publicKey: publicKeyOptions,
      }) as PublicKeyCredential

      if (!credential) throw new Error('Credential creation cancelled')

      const attestationResponse = credential.response as AuthenticatorAttestationResponse

      // 4. Encode response for server (must use base64url for WebAuthn JSON format)
      const credentialData = {
        id: bufferToBase64url(credential.rawId),
        rawId: bufferToBase64url(credential.rawId),
        type: credential.type,
        response: {
          clientDataJSON: bufferToBase64url(attestationResponse.clientDataJSON),
          attestationObject: bufferToBase64url(attestationResponse.attestationObject),
        },
      }

      // 5. Send attestation to server
      const result = await post<any>('/v1/passkey/register', {
        credential: credentialData,
        name,
      })

      return result
    } finally {
      isLoading.value = false
    }
  }

  async function authenticate() {
    isLoading.value = true
    try {
      // 1. Get request options from server (public endpoint)
      const apiBase = useApiBase()
      const fetchFn = useRequestFetch()
      const options = await fetchFn<any>('/auth/passkey/login/options', {
        baseURL: apiBase,
        method: 'POST',
      })

      const sessionId = options.sessionId

      // 2. Decode challenge for the browser API
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

      // 3. Get assertion from browser
      const credential = await navigator.credentials.get({
        publicKey: publicKeyOptions,
      }) as PublicKeyCredential

      if (!credential) throw new Error('Authentication cancelled')

      const assertionResponse = credential.response as AuthenticatorAssertionResponse

      // 4. Encode response for server (must use base64url for WebAuthn JSON format)
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

      // 5. Verify with server (public endpoint)
      const result = await fetchFn<{ token: string }>(
        '/auth/passkey/login',
        {
          baseURL: apiBase,
          method: 'POST',
          body: { credential: credentialData, sessionId },
        },
      )

      // 6. Store token
      authStore.token = result.token
      await authStore.fetchUser()

      return true
    } catch (err: any) {
      console.error('Passkey authentication failed:', err)
      return false
    } finally {
      isLoading.value = false
    }
  }

  async function listPasskeys() {
    return await get<any[]>('/v1/me/passkeys')
  }

  async function deletePasskey(id: string) {
    await del(`/v1/me/passkeys/${id}`)
  }

  return {
    isSupported,
    isLoading,
    register,
    authenticate,
    listPasskeys,
    deletePasskey,
  }
}
