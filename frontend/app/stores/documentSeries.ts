import { defineStore } from 'pinia'
import type { DocumentSeries } from '~/types'

export const useDocumentSeriesStore = defineStore('documentSeries', () => {
  const items = ref<DocumentSeries[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchSeries(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const response = await get<{ data: DocumentSeries[] }>('/v1/document-series')
      items.value = response.data
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca seriile de documente.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function createSeries(data: { prefix: string, type?: string, currentNumber?: number }): Promise<DocumentSeries | null> {
    const { post } = useApi()
    error.value = null
    try {
      const result = await post<DocumentSeries>('/v1/document-series', data)
      await fetchSeries()
      return result
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea seria de documente.'
      return null
    }
  }

  async function updateSeries(id: string, data: Partial<DocumentSeries>): Promise<boolean> {
    const { patch } = useApi()
    error.value = null
    try {
      await patch(`/v1/document-series/${id}`, data)
      await fetchSeries()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza seria de documente.'
      return false
    }
  }

  async function setDefault(id: string): Promise<boolean> {
    const { post } = useApi()
    error.value = null
    try {
      await post(`/v1/document-series/${id}/set-default`)
      await fetchSeries()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut seta seria implicita.'
      return false
    }
  }

  async function deleteSeries(id: string): Promise<boolean> {
    const { del } = useApi()
    error.value = null
    try {
      await del(`/v1/document-series/${id}`)
      await fetchSeries()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge seria de documente.'
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
    fetchSeries, createSeries, updateSeries, setDefault, deleteSeries, $reset,
  }
})
