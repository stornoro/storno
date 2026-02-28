import { defineStore } from 'pinia'
import type { StorageConfig, StorageProvider } from '~/types'

export const useStorageConfigStore = defineStore('storageConfig', () => {
  const config = ref<StorageConfig | null>(null)
  const providers = ref<StorageProvider[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchConfig(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const response = await get<{ data: StorageConfig | null }>('/v1/storage-config')
      config.value = response.data
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca configuratia de stocare.'
      config.value = null
    }
    finally {
      loading.value = false
    }
  }

  async function fetchProviders(): Promise<void> {
    const { get } = useApi()
    try {
      const response = await get<{ data: StorageProvider[] }>('/v1/storage-config/providers')
      providers.value = response.data
    }
    catch {
      providers.value = []
    }
  }

  async function saveConfig(data: Record<string, any>): Promise<boolean> {
    const { put } = useApi()
    try {
      const response = await put<{ data: StorageConfig }>('/v1/storage-config', data)
      config.value = response.data
      return true
    }
    catch {
      return false
    }
  }

  async function deleteConfig(): Promise<boolean> {
    const { del } = useApi()
    try {
      await del('/v1/storage-config')
      config.value = null
      return true
    }
    catch {
      return false
    }
  }

  async function testConnection(data: Record<string, any>): Promise<{ success: boolean, error?: string }> {
    const { post } = useApi()
    try {
      const result = await post<{ success: boolean, error?: string }>('/v1/storage-config/test', data)
      return result
    }
    catch (err: any) {
      return { success: false, error: err?.data?.error || 'Eroare la testarea conexiunii.' }
    }
  }

  function $reset() {
    config.value = null
    providers.value = []
    loading.value = false
    error.value = null
  }

  return {
    config, providers, loading, error,
    fetchConfig, fetchProviders, saveConfig, deleteConfig, testConnection, $reset,
  }
})
