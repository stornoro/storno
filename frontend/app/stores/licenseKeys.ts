import { defineStore } from 'pinia'
import type { LicenseKey } from '~/types'

export const useLicenseKeyStore = defineStore('licenseKeys', () => {
  const items = ref<LicenseKey[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchLicenseKeys(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const response = await get<{ keys: LicenseKey[] }>('/v1/licensing/keys')
      items.value = response.keys
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca cheile de licenta.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function createLicenseKey(instanceName?: string): Promise<LicenseKey | null> {
    const { post } = useApi()
    error.value = null
    try {
      const result = await post<LicenseKey>('/v1/licensing/keys', {
        ...(instanceName ? { instanceName } : {}),
      })
      await fetchLicenseKeys()
      return result
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : err?.data?.message ?? 'Eroare la crearea cheii de licenta.'
      return null
    }
  }

  async function revokeLicenseKey(id: string): Promise<boolean> {
    const { del } = useApi()
    error.value = null
    try {
      await del(`/v1/licensing/keys/${id}`)
      await fetchLicenseKeys()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : err?.data?.message ?? 'Eroare la revocarea cheii de licenta.'
      return false
    }
  }

  function $reset() {
    items.value = []
    loading.value = false
    error.value = null
  }

  return {
    items, loading, error,
    fetchLicenseKeys, createLicenseKey, revokeLicenseKey, $reset,
  }
})
