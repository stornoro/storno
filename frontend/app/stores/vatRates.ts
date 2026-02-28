import { defineStore } from 'pinia'
import type { VatRate } from '~/types'

export const useVatRateStore = defineStore('vatRates', () => {
  const items = ref<VatRate[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchVatRates(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const response = await get<{ data: VatRate[] }>('/v1/vat-rates')
      items.value = response.data
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca cotele TVA.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function createVatRate(data: { rate: string, label: string, categoryCode?: string, isDefault?: boolean, isActive?: boolean, position?: number }): Promise<VatRate | null> {
    const { post } = useApi()
    error.value = null
    try {
      const result = await post<VatRate>('/v1/vat-rates', data)
      await fetchVatRates()
      return result
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea cota TVA.'
      return null
    }
  }

  async function updateVatRate(id: string, data: Partial<VatRate>): Promise<boolean> {
    const { patch } = useApi()
    error.value = null
    try {
      await patch(`/v1/vat-rates/${id}`, data)
      await fetchVatRates()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza cota TVA.'
      return false
    }
  }

  async function deleteVatRate(id: string): Promise<boolean> {
    const { del } = useApi()
    error.value = null
    try {
      await del(`/v1/vat-rates/${id}`)
      await fetchVatRates()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge cota TVA.'
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
    fetchVatRates, createVatRate, updateVatRate, deleteVatRate, $reset,
  }
})
