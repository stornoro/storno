import { defineStore } from 'pinia'
import type { RecurringInvoice, CreateRecurringInvoicePayload, UpdateRecurringInvoicePayload } from '~/types'
import { PAGINATION } from '~/utils/constants'

export interface RecurringInvoiceFilters {
  search: string
  isActive: boolean | null
  frequency: string | null
}

interface RecurringInvoicePaginatedResponse {
  data: RecurringInvoice[]
  total: number
  page: number
  limit: number
}

export const useRecurringInvoiceStore = defineStore('recurringInvoices', () => {
  const items = ref<RecurringInvoice[]>([])
  const loading = ref(true)
  const error = ref<string | null>(null)

  const filters = ref<RecurringInvoiceFilters>({
    search: '',
    isActive: null,
    frequency: null,
  })

  const page = ref(1)
  const limit = ref(PAGINATION.DEFAULT_LIMIT)
  const total = ref(0)

  const totalPages = computed(() => Math.ceil(total.value / limit.value) || 1)
  const isEmpty = computed(() => !loading.value && items.value.length === 0)

  async function fetchRecurringInvoices(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const params: Record<string, any> = {
        page: page.value,
        limit: limit.value,
      }

      if (filters.value.search) params.search = filters.value.search
      if (filters.value.isActive !== null) params.isActive = filters.value.isActive
      if (filters.value.frequency) params.frequency = filters.value.frequency

      const response = await get<RecurringInvoicePaginatedResponse>('/v1/recurring-invoices', params)

      items.value = response.data
      total.value = response.total
      page.value = response.page
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca facturile recurente.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchRecurringInvoice(uuid: string): Promise<RecurringInvoice | null> {
    const { get } = useApi()

    try {
      return await get<RecurringInvoice>(`/v1/recurring-invoices/${uuid}`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca factura recurenta.'
      return null
    }
  }

  async function createRecurringInvoice(payload: CreateRecurringInvoicePayload): Promise<RecurringInvoice | null> {
    const { post } = useApi()
    try {
      return await post<RecurringInvoice>('/v1/recurring-invoices', payload)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea factura recurenta.'
      return null
    }
  }

  async function updateRecurringInvoice(uuid: string, payload: UpdateRecurringInvoicePayload): Promise<RecurringInvoice | null> {
    const { put } = useApi()
    try {
      return await put<RecurringInvoice>(`/v1/recurring-invoices/${uuid}`, payload)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza factura recurenta.'
      return null
    }
  }

  async function deleteRecurringInvoice(uuid: string): Promise<boolean> {
    const { del } = useApi()
    try {
      await del(`/v1/recurring-invoices/${uuid}`)
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge factura recurenta.'
      return false
    }
  }

  async function issueNow(uuid: string): Promise<{ invoiceId: string, invoiceNumber: string } | null> {
    const { post } = useApi()
    try {
      return await post<{ invoiceId: string, invoiceNumber: string }>(`/v1/recurring-invoices/${uuid}/issue-now`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut emite factura recurenta.'
      return null
    }
  }

  async function toggleRecurringInvoice(uuid: string): Promise<RecurringInvoice | null> {
    const { post } = useApi()
    try {
      return await post<RecurringInvoice>(`/v1/recurring-invoices/${uuid}/toggle`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut comuta factura recurenta.'
      return null
    }
  }

  async function bulkDelete(ids: string[]): Promise<{ deleted: number, errors: Array<{ id: string, error: string }> } | null> {
    const { post } = useApi()
    try {
      return await post<{ deleted: number, errors: Array<{ id: string, error: string }> }>('/v1/recurring-invoices/bulk-delete', { ids })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut sterge facturile recurente in masa.'
      return null
    }
  }

  async function bulkIssueNow(ids: string[]): Promise<{ issued: number, errors: Array<{ id: string, error: string }> } | null> {
    const { post } = useApi()
    try {
      return await post<{ issued: number, errors: Array<{ id: string, error: string }> }>('/v1/recurring-invoices/bulk-issue-now', { ids })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut emite facturile recurente in masa.'
      return null
    }
  }

  async function bulkToggleActive(ids: string[], isActive: boolean): Promise<{ updated: number, errors: Array<{ id: string, error: string }> } | null> {
    const { post } = useApi()
    try {
      return await post<{ updated: number, errors: Array<{ id: string, error: string }> }>('/v1/recurring-invoices/bulk-toggle-active', { ids, isActive })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut comuta facturile recurente in masa.'
      return null
    }
  }

  function setFilters(newFilters: Partial<RecurringInvoiceFilters>) {
    filters.value = { ...filters.value, ...newFilters }
    page.value = 1
  }

  function $reset() {
    items.value = []
    loading.value = false
    error.value = null
    page.value = 1
    total.value = 0
    filters.value = { search: '', isActive: null, frequency: null }
  }

  return {
    items,
    loading,
    error,
    filters,
    page,
    limit,
    total,
    totalPages,
    isEmpty,
    fetchRecurringInvoices,
    fetchRecurringInvoice,
    createRecurringInvoice,
    updateRecurringInvoice,
    deleteRecurringInvoice,
    bulkDelete,
    bulkIssueNow,
    bulkToggleActive,
    issueNow,
    toggleRecurringInvoice,
    setFilters,
    $reset,
  }
})
