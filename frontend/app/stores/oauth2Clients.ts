import { defineStore } from 'pinia'
import type { OAuth2Client, OAuth2ClientWithSecret, ApiKeyScope } from '~/types'

export const useOAuth2ClientStore = defineStore('oauth2Clients', () => {
  const items = ref<OAuth2Client[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const availableScopes = ref<ApiKeyScope[]>([])

  async function fetchClients(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const response = await get<{ data: OAuth2Client[] }>('/v1/oauth2/clients')
      items.value = response.data
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca aplicatiile OAuth2.'
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

  async function createClient(data: {
    name: string
    description?: string | null
    clientType: 'confidential' | 'public'
    redirectUris: string[]
    scopes: string[]
    websiteUrl?: string | null
    logoUrl?: string | null
  }): Promise<OAuth2ClientWithSecret | null> {
    const { post } = useApi()
    error.value = null
    try {
      const result = await post<OAuth2ClientWithSecret>('/v1/oauth2/clients', data)
      await fetchClients()
      return result
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea aplicatia OAuth2.'
      return null
    }
  }

  async function updateClient(id: string, data: {
    name?: string
    description?: string | null
    redirectUris?: string[]
    scopes?: string[]
    isActive?: boolean
    websiteUrl?: string | null
    logoUrl?: string | null
  }): Promise<boolean> {
    const { patch } = useApi()
    error.value = null
    try {
      await patch(`/v1/oauth2/clients/${id}`, data)
      await fetchClients()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza aplicatia OAuth2.'
      return false
    }
  }

  async function revokeClient(id: string): Promise<boolean> {
    const { del } = useApi()
    error.value = null
    try {
      await del(`/v1/oauth2/clients/${id}`)
      await fetchClients()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut revoca aplicatia OAuth2.'
      return false
    }
  }

  async function rotateSecret(id: string): Promise<OAuth2ClientWithSecret | null> {
    const { post } = useApi()
    error.value = null
    try {
      const result = await post<OAuth2ClientWithSecret>(`/v1/oauth2/clients/${id}/rotate-secret`)
      await fetchClients()
      return result
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut rota secretul.'
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
    fetchClients, fetchAvailableScopes, createClient, updateClient, revokeClient, rotateSecret, $reset,
  }
})
