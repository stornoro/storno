import { defineStore } from 'pinia'
import type { Receipt, CreateReceiptPayload, UpdateReceiptPayload, Invoice } from '~/types'
import type { ReceiptStatus } from '~/types/enums'
import { PAGINATION } from '~/utils/constants'

export interface ReceiptFilters {
  status: ReceiptStatus | null
  search: string
  dateFrom: string | null
  dateTo: string | null
}

interface ReceiptPaginatedResponse {
  data: Receipt[]
  total: number
  page: number
  limit: number
}

export const useReceiptStore = defineStore('receipts', () => {
  // ── State ──────────────────────────────────────────────────────────
  const items = ref<Receipt[]>([])
  const loading = ref(true)
  const error = ref<string | null>(null)

  const filters = ref<ReceiptFilters>({
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
  async function fetchReceipts(): Promise<void> {
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

      const response = await get<ReceiptPaginatedResponse>('/v1/receipts', params)

      items.value = response.data
      total.value = response.total
      page.value = response.page
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca bonurile fiscale.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchReceipt(uuid: string): Promise<Receipt | null> {
    const { get } = useApi()

    try {
      return await get<Receipt>(`/v1/receipts/${uuid}`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca bonul fiscal.'
      return null
    }
  }

  async function createReceipt(payload: CreateReceiptPayload): Promise<Receipt | null> {
    const { post } = useApi()
    try {
      return await post<Receipt>('/v1/receipts', payload)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea bonul fiscal.'
      return null
    }
  }

  async function updateReceipt(uuid: string, payload: UpdateReceiptPayload): Promise<Receipt | null> {
    const { put } = useApi()
    try {
      return await put<Receipt>(`/v1/receipts/${uuid}`, payload)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza bonul fiscal.'
      return null
    }
  }

  async function deleteReceipt(uuid: string): Promise<boolean> {
    const { del } = useApi()
    try {
      await del(`/v1/receipts/${uuid}`)
      items.value = items.value.filter(i => i.id !== uuid)
      total.value = Math.max(0, total.value - 1)
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge bonul fiscal.'
      return false
    }
  }

  async function issueReceipt(uuid: string): Promise<Receipt | null> {
    const { post } = useApi()
    try {
      return await post<Receipt>(`/v1/receipts/${uuid}/issue`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut emite bonul fiscal.'
      return null
    }
  }

  async function cancelReceipt(uuid: string): Promise<Receipt | null> {
    const { post } = useApi()
    try {
      return await post<Receipt>(`/v1/receipts/${uuid}/cancel`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut anula bonul fiscal.'
      return null
    }
  }

  async function restoreReceipt(uuid: string): Promise<Receipt | null> {
    const { post } = useApi()
    try {
      return await post<Receipt>(`/v1/receipts/${uuid}/restore`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut restaura bonul fiscal.'
      return null
    }
  }

  async function convertToInvoice(uuid: string): Promise<Invoice | null> {
    const { post } = useApi()
    try {
      return await post<Invoice>(`/v1/receipts/${uuid}/convert`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut converti bonul fiscal.'
      return null
    }
  }

  async function sendEmail(uuid: string, payload: { to: string, subject?: string, body?: string, cc?: string[], bcc?: string[] }): Promise<any> {
    const { post } = useApi()
    try {
      return await post(`/v1/receipts/${uuid}/email`, payload)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut trimite emailul.'
      return null
    }
  }

  async function fetchEmailDefaults(uuid: string): Promise<{ to?: string, subject?: string, body?: string }> {
    const { get } = useApi()
    try {
      return await get(`/v1/receipts/${uuid}/email-defaults`)
    }
    catch {
      return {}
    }
  }

  async function fetchEmails(uuid: string): Promise<any[]> {
    const { get } = useApi()
    try {
      return await get(`/v1/receipts/${uuid}/emails`)
    }
    catch {
      return []
    }
  }

  function setFilters(newFilters: Partial<ReceiptFilters>) {
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
    fetchReceipts,
    fetchReceipt,
    createReceipt,
    updateReceipt,
    deleteReceipt,
    issueReceipt,
    cancelReceipt,
    restoreReceipt,
    convertToInvoice,
    sendEmail,
    fetchEmailDefaults,
    fetchEmails,
    setFilters,
    clearFilters,
    setPage,
    $reset,
  }
})
