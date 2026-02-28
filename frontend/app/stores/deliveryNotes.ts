import { defineStore } from 'pinia'
import type { DeliveryNote, CreateDeliveryNotePayload, UpdateDeliveryNotePayload, Invoice, ValidationResponse } from '~/types'
import type { DeliveryNoteStatus } from '~/types/enums'
import { PAGINATION } from '~/utils/constants'

export interface DeliveryNoteFilters {
  status: DeliveryNoteStatus | null
  search: string
  dateFrom: string | null
  dateTo: string | null
}

interface DeliveryNotePaginatedResponse {
  data: DeliveryNote[]
  total: number
  page: number
  limit: number
}

export const useDeliveryNoteStore = defineStore('deliveryNotes', () => {
  // ── State ──────────────────────────────────────────────────────────
  const items = ref<DeliveryNote[]>([])
  const loading = ref(true)
  const error = ref<string | null>(null)

  const filters = ref<DeliveryNoteFilters>({
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
  async function fetchDeliveryNotes(): Promise<void> {
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

      const response = await get<DeliveryNotePaginatedResponse>('/v1/delivery-notes', params)

      items.value = response.data
      total.value = response.total
      page.value = response.page
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca avizele.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchDeliveryNote(uuid: string): Promise<DeliveryNote | null> {
    const { get } = useApi()

    try {
      return await get<DeliveryNote>(`/v1/delivery-notes/${uuid}`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca avizul.'
      return null
    }
  }

  async function createDeliveryNote(payload: CreateDeliveryNotePayload): Promise<{ deliveryNote: DeliveryNote, validation: ValidationResponse } | null> {
    const { post } = useApi()
    try {
      return await post<{ deliveryNote: DeliveryNote, validation: ValidationResponse }>('/v1/delivery-notes', payload)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea avizul.'
      return null
    }
  }

  async function updateDeliveryNote(uuid: string, payload: UpdateDeliveryNotePayload): Promise<{ deliveryNote: DeliveryNote, validation: ValidationResponse } | null> {
    const { put } = useApi()
    try {
      return await put<{ deliveryNote: DeliveryNote, validation: ValidationResponse }>(`/v1/delivery-notes/${uuid}`, payload)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza avizul.'
      return null
    }
  }

  async function deleteDeliveryNote(uuid: string): Promise<boolean> {
    const { del } = useApi()
    try {
      await del(`/v1/delivery-notes/${uuid}`)
      items.value = items.value.filter(i => i.id !== uuid)
      total.value = Math.max(0, total.value - 1)
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge avizul.'
      return false
    }
  }

  async function issueDeliveryNote(uuid: string): Promise<DeliveryNote | null> {
    const { post } = useApi()
    try {
      return await post<DeliveryNote>(`/v1/delivery-notes/${uuid}/issue`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut emite avizul.'
      return null
    }
  }

  async function cancelDeliveryNote(uuid: string): Promise<DeliveryNote | null> {
    const { post } = useApi()
    try {
      return await post<DeliveryNote>(`/v1/delivery-notes/${uuid}/cancel`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut anula avizul.'
      return null
    }
  }

  async function restoreDeliveryNote(uuid: string): Promise<DeliveryNote | null> {
    const { post } = useApi()
    try {
      return await post<DeliveryNote>(`/v1/delivery-notes/${uuid}/restore`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut restaura avizul.'
      return null
    }
  }

  async function convertToInvoice(uuid: string): Promise<Invoice | null> {
    const { post } = useApi()
    try {
      return await post<Invoice>(`/v1/delivery-notes/${uuid}/convert`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut converti avizul.'
      return null
    }
  }

  async function stornoDeliveryNote(uuid: string): Promise<DeliveryNote | null> {
    const { post } = useApi()
    try {
      return await post<DeliveryNote>(`/v1/delivery-notes/${uuid}/storno`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut storna avizul.'
      return null
    }
  }

  async function bulkConvert(ids: string[]): Promise<Invoice | null> {
    const { post } = useApi()
    try {
      return await post<Invoice>('/v1/delivery-notes/bulk-convert', { ids })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut factura avizele.'
      return null
    }
  }

  async function createFromProforma(proformaId: string): Promise<DeliveryNote | null> {
    const { post } = useApi()
    try {
      return await post<DeliveryNote>('/v1/delivery-notes/from-proforma', { proformaId })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea avizul din proforma.'
      return null
    }
  }

  async function sendEmail(uuid: string, payload: { to: string, subject?: string, body?: string, cc?: string[], bcc?: string[] }): Promise<any> {
    const { post } = useApi()
    try {
      return await post(`/v1/delivery-notes/${uuid}/email`, payload)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut trimite emailul.'
      return null
    }
  }

  async function fetchEmailDefaults(uuid: string): Promise<{ to?: string, subject?: string, body?: string }> {
    const { get } = useApi()
    try {
      return await get(`/v1/delivery-notes/${uuid}/email-defaults`)
    }
    catch {
      return {}
    }
  }

  async function fetchEmails(uuid: string): Promise<any[]> {
    const { get } = useApi()
    try {
      return await get(`/v1/delivery-notes/${uuid}/emails`)
    }
    catch {
      return []
    }
  }

  function setFilters(newFilters: Partial<DeliveryNoteFilters>) {
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

  async function submitETransport(uuid: string): Promise<DeliveryNote | null> {
    const { post } = useApi()
    try {
      return await post<DeliveryNote>(`/v1/delivery-notes/${uuid}/submit-etransport`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut trimite la e-Transport.'
      return null
    }
  }

  async function bulkDelete(ids: string[]): Promise<{ deleted: number, errors: Array<{ id: string, error: string }> } | null> {
    const { post } = useApi()
    try {
      return await post<{ deleted: number, errors: Array<{ id: string, error: string }> }>('/v1/delivery-notes/bulk-delete', { ids })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut sterge avizele in masa.'
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
    fetchDeliveryNotes,
    fetchDeliveryNote,
    createDeliveryNote,
    updateDeliveryNote,
    deleteDeliveryNote,
    bulkDelete,
    issueDeliveryNote,
    cancelDeliveryNote,
    restoreDeliveryNote,
    convertToInvoice,
    stornoDeliveryNote,
    submitETransport,
    bulkConvert,
    createFromProforma,
    sendEmail,
    fetchEmailDefaults,
    fetchEmails,
    setFilters,
    clearFilters,
    setPage,
    $reset,
  }
})
