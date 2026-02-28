import { defineStore } from 'pinia'

interface EInvoiceProviderInfo {
  value: string
  label: string
  country: string
}

interface EInvoiceConfigItem {
  id: string
  provider: string
  enabled: boolean
  maskedConfig: Record<string, string>
  createdAt: string
  updatedAt: string
}

export const useEInvoiceConfigStore = defineStore('einvoiceConfig', () => {
  const configs = ref<EInvoiceConfigItem[]>([])
  const providers = ref<EInvoiceProviderInfo[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchConfigs(companyId: string): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const response = await get<EInvoiceConfigItem[]>(`/v1/companies/${companyId}/einvoice-config`)
      configs.value = response
    }
    catch (err: any) {
      error.value = err?.data?.error || 'Failed to load e-invoice configurations.'
      configs.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchProviders(): Promise<void> {
    const { get } = useApi()
    try {
      const response = await get<EInvoiceProviderInfo[]>('/v1/einvoice/providers')
      providers.value = response
    }
    catch {
      providers.value = []
    }
  }

  async function saveConfig(companyId: string, data: Record<string, any>): Promise<boolean> {
    const { post } = useApi()
    try {
      const response = await post<EInvoiceConfigItem>(`/v1/companies/${companyId}/einvoice-config`, data)
      // Update or add in the local list
      const index = configs.value.findIndex(c => c.provider === data.provider)
      if (index >= 0) {
        configs.value[index] = response
      }
      else {
        configs.value.push(response)
      }
      return true
    }
    catch {
      return false
    }
  }

  async function deleteConfig(companyId: string, provider: string): Promise<boolean> {
    const { del } = useApi()
    try {
      await del(`/v1/companies/${companyId}/einvoice-config/${provider}`)
      configs.value = configs.value.filter(c => c.provider !== provider)
      return true
    }
    catch {
      return false
    }
  }

  async function testConnection(companyId: string, data: Record<string, any>): Promise<{ success: boolean, error?: string }> {
    const { post } = useApi()
    try {
      const result = await post<{ success: boolean, error?: string }>(`/v1/companies/${companyId}/einvoice-config/test`, data)
      return result
    }
    catch (err: any) {
      return { success: false, error: err?.data?.error || 'Connection test failed.' }
    }
  }

  function $reset() {
    configs.value = []
    providers.value = []
    loading.value = false
    error.value = null
  }

  return {
    configs, providers, loading, error,
    fetchConfigs, fetchProviders, saveConfig, deleteConfig, testConnection, $reset,
  }
})
