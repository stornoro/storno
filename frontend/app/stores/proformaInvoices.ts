import { defineStore } from 'pinia'
import type { ProformaInvoice, CreateProformaPayload, UpdateProformaPayload, Invoice } from '~/types'
import type { ProformaStatus } from '~/types/enums'
import { PAGINATION } from '~/utils/constants'

export interface ProformaFilters {
  status: ProformaStatus | null
  search: string
  dateFrom: string | null
  dateTo: string | null
}

interface ProformaPaginatedResponse {
  data: ProformaInvoice[]
  total: number
  page: number
  limit: number
}

export const useProformaInvoiceStore = defineStore('proformaInvoices', () => {
  // ── State ──────────────────────────────────────────────────────────
  const items = ref<ProformaInvoice[]>([])
  const loading = ref(true)
  const error = ref<string | null>(null)

  const filters = ref<ProformaFilters>({
    status: null,
    search: '',
    dateFrom: null,
    dateTo: null,
  })

  const page = ref(1)
  const limit = ref(PAGINATION.DEFAULT_LIMIT)
  const total = ref(0)
  const sort = ref<string | null>(null)
  const order = ref<'asc' | 'desc' | null>(null)

  // ── Getters ────────────────────────────────────────────────────────
  const totalPages = computed(() => Math.ceil(total.value / limit.value) || 1)
  const hasNextPage = computed(() => page.value < totalPages.value)
  const hasPreviousPage = computed(() => page.value > 1)
  const isEmpty = computed(() => !loading.value && items.value.length === 0)

  const activeFilterCount = computed(() => {
    let count = 0
    if (filters.value.status) count++
    if (filters.value.search) count++
    if (filters.value.dateFrom || filters.value.dateTo) count++
    return count
  })

  // ── Actions ────────────────────────────────────────────────────────
  async function fetchProformas(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const params: Record<string, any> = {
        page: page.value,
        limit: limit.value,
      }

      if (filters.value.status) params.status = filters.value.status
      if (filters.value.search) params.search = filters.value.search
      if (filters.value.dateFrom) params.dateFrom = filters.value.dateFrom
      if (filters.value.dateTo) params.dateTo = filters.value.dateTo
      if (sort.value) params.sort = sort.value
      if (order.value) params.order = order.value

      const response = await get<ProformaPaginatedResponse>('/v1/proforma-invoices', params)

      items.value = response.data
      total.value = response.total
      page.value = response.page
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca profacturile.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchProforma(uuid: string): Promise<ProformaInvoice | null> {
    const { get } = useApi()

    try {
      return await get<ProformaInvoice>(`/v1/proforma-invoices/${uuid}`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca profactura.'
      return null
    }
  }

  async function createProforma(payload: CreateProformaPayload): Promise<ProformaInvoice | null> {
    const { post } = useApi()
    try {
      return await post<ProformaInvoice>('/v1/proforma-invoices', payload)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea profactura.'
      return null
    }
  }

  async function updateProforma(uuid: string, payload: UpdateProformaPayload): Promise<ProformaInvoice | null> {
    const { put } = useApi()
    try {
      return await put<ProformaInvoice>(`/v1/proforma-invoices/${uuid}`, payload)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza profactura.'
      return null
    }
  }

  async function deleteProforma(uuid: string): Promise<boolean> {
    const { del } = useApi()
    try {
      await del(`/v1/proforma-invoices/${uuid}`)
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge profactura.'
      return false
    }
  }

  async function sendProforma(uuid: string): Promise<ProformaInvoice | null> {
    const { post } = useApi()
    try {
      return await post<ProformaInvoice>(`/v1/proforma-invoices/${uuid}/send`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut trimite profactura.'
      return null
    }
  }

  async function acceptProforma(uuid: string): Promise<ProformaInvoice | null> {
    const { post } = useApi()
    try {
      return await post<ProformaInvoice>(`/v1/proforma-invoices/${uuid}/accept`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut accepta profactura.'
      return null
    }
  }

  async function rejectProforma(uuid: string): Promise<ProformaInvoice | null> {
    const { post } = useApi()
    try {
      return await post<ProformaInvoice>(`/v1/proforma-invoices/${uuid}/reject`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut respinge profactura.'
      return null
    }
  }

  async function cancelProforma(uuid: string): Promise<ProformaInvoice | null> {
    const { post } = useApi()
    try {
      return await post<ProformaInvoice>(`/v1/proforma-invoices/${uuid}/cancel`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut anula profactura.'
      return null
    }
  }

  async function convertToInvoice(uuid: string): Promise<Invoice | null> {
    const { post } = useApi()
    try {
      return await post<Invoice>(`/v1/proforma-invoices/${uuid}/convert`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut converti profactura.'
      return null
    }
  }

  function setFilters(newFilters: Partial<ProformaFilters>) {
    filters.value = { ...filters.value, ...newFilters }
    page.value = 1
  }

  function clearFilters() {
    filters.value = {
      status: null,
      search: '',
      dateFrom: null,
      dateTo: null,
    }
    page.value = 1
  }

  function setPage(newPage: number) {
    page.value = newPage
  }

  async function bulkDelete(ids: string[]): Promise<{ deleted: number, errors: Array<{ id: string, error: string }> } | null> {
    const { post } = useApi()
    try {
      return await post<{ deleted: number, errors: Array<{ id: string, error: string }> }>('/v1/proforma-invoices/bulk-delete', { ids })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut sterge profacturile in masa.'
      return null
    }
  }

  function $reset() {
    items.value = []
    loading.value = false
    error.value = null
    page.value = 1
    total.value = 0
    sort.value = null
    order.value = null
    clearFilters()
  }

  return {
    // State
    items,
    loading,
    error,
    filters,
    page,
    limit,
    total,
    sort,
    order,

    // Getters
    totalPages,
    hasNextPage,
    hasPreviousPage,
    isEmpty,
    activeFilterCount,

    // Actions
    fetchProformas,
    fetchProforma,
    createProforma,
    updateProforma,
    deleteProforma,
    bulkDelete,
    sendProforma,
    acceptProforma,
    rejectProforma,
    cancelProforma,
    convertToInvoice,
    setFilters,
    clearFilters,
    setPage,
    $reset,
  }
})
