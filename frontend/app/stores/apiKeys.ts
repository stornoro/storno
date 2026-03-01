import { defineStore } from 'pinia'
import type { ApiKey, ApiKeyScope } from '~/types'

export const useApiKeyStore = defineStore('apiKeys', () => {
  const items = ref<ApiKey[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const availableScopes = ref<ApiKeyScope[]>([])

  async function fetchApiKeys(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const response = await get<{ data: ApiKey[] }>('/v1/api-tokens')
      items.value = response.data
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca cheile API.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchAvailableScopes(): Promise<void> {
    const { get } = useApi()
    try {
      const response = await get<{ scopes: ApiKeyScope[] }>('/v1/api-tokens/scopes')
      availableScopes.value = response.scopes
    }
    catch {
      availableScopes.value = []
    }
  }

  async function createApiKey(data: { name: string, scopes: string[], expiresAt?: string | null }, verificationToken?: string): Promise<ApiKey | null> {
    const { post } = useApi()
    error.value = null
    try {
      const body: any = { ...data }
      if (verificationToken) body.verification_token = verificationToken
      const result = await post<ApiKey>('/v1/api-tokens', body)
      await fetchApiKeys()
      return result
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea cheia API.'
      return null
    }
  }

  async function updateApiKey(id: string, data: { name?: string, scopes?: string[] }): Promise<boolean> {
    const { patch } = useApi()
    error.value = null
    try {
      await patch(`/v1/api-tokens/${id}`, data)
      await fetchApiKeys()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza cheia API.'
      return false
    }
  }

  async function revokeApiKey(id: string): Promise<boolean> {
    const { del } = useApi()
    error.value = null
    try {
      await del(`/v1/api-tokens/${id}`)
      await fetchApiKeys()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut revoca cheia API.'
      return false
    }
  }

  async function rotateApiKey(id: string, verificationToken?: string): Promise<ApiKey | null> {
    const { post } = useApi()
    error.value = null
    try {
      const body: any = {}
      if (verificationToken) body.verification_token = verificationToken
      const result = await post<ApiKey>(`/v1/api-tokens/${id}/rotate`, body)
      await fetchApiKeys()
      return result
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut rota cheia API.'
      return null
    }
  }

  function $reset() {
    items.value = []
    loading.value = false
    error.value = null
    availableScopes.value = []
  }

  return {
    items, loading, error, availableScopes,
    fetchApiKeys, fetchAvailableScopes, createApiKey, updateApiKey, revokeApiKey, rotateApiKey, $reset,
  }
})
