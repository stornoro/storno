import { defineStore } from 'pinia'
import type { Invoice, Payment, EmailLog, CreateInvoicePayload, UpdateInvoicePayload, ValidationResponse } from '~/types'
import type { InvoiceDirection, DocumentStatus, DocumentType } from '~/types/enums'
import { PAGINATION } from '~/utils/constants'

export interface InvoiceFilters {
  direction: InvoiceDirection | null
  status: DocumentStatus | null
  type: DocumentType | null
  search: string
  dateFrom: string | null
  dateTo: string | null
  isDuplicate: boolean | null
  isLateSubmission: boolean | null
  isPaid: boolean | null
  supplierId: string | null
}

interface InvoiceTotals {
  subtotal: string
  vatTotal: string
  total: string
  receivable: string
  payable: string
}

interface InvoicePaginatedResponse {
  data: Invoice[]
  total: number
  page: number
  limit: number
  totals: InvoiceTotals
}

export const useInvoiceStore = defineStore('invoices', () => {
  // ── State ──────────────────────────────────────────────────────────
  const items = ref<Invoice[]>([])
  const loading = ref(true)
  const error = ref<string | null>(null)

  const filters = ref<InvoiceFilters>({
    direction: null,
    status: null,
    type: null,
    search: '',
    dateFrom: null,
    dateTo: null,
    isDuplicate: null,
    isLateSubmission: null,
    isPaid: null,
    supplierId: null,
  })

  const page = ref(1)
  const limit = ref(PAGINATION.DEFAULT_LIMIT)
  const total = ref(0)
  const sort = ref<string | null>('issueDate')
  const order = ref<'asc' | 'desc' | null>('desc')
  const totals = ref<InvoiceTotals>({ subtotal: '0.00', vatTotal: '0.00', total: '0.00', receivable: '0.00', payable: '0.00' })

  // ── Getters ────────────────────────────────────────────────────────
  const totalPages = computed(() => Math.ceil(total.value / limit.value) || 1)

  const hasNextPage = computed(() => page.value < totalPages.value)
  const hasPreviousPage = computed(() => page.value > 1)

  const isEmpty = computed(() => !loading.value && items.value.length === 0)

  const activeFilterCount = computed(() => {
    let count = 0
    if (filters.value.direction) count++
    if (filters.value.status) count++
    if (filters.value.type) count++
    if (filters.value.search) count++
    if (filters.value.dateFrom || filters.value.dateTo) count++
    return count
  })

  // ── Actions ────────────────────────────────────────────────────────
  async function fetchInvoices(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const params: Record<string, any> = {
        page: page.value,
        limit: limit.value,
      }

      if (filters.value.direction) params.direction = filters.value.direction
      if (filters.value.status) params.status = filters.value.status
      if (filters.value.type) params.type = filters.value.type
      if (filters.value.search) params.search = filters.value.search
      if (filters.value.dateFrom) params.dateFrom = filters.value.dateFrom
      if (filters.value.dateTo) params.dateTo = filters.value.dateTo
      if (filters.value.isDuplicate !== null) params.isDuplicate = filters.value.isDuplicate
      if (filters.value.isLateSubmission !== null) params.isLateSubmission = filters.value.isLateSubmission
      if (filters.value.isPaid !== null) params.isPaid = filters.value.isPaid
      if (filters.value.supplierId) params.supplierId = filters.value.supplierId
      if (sort.value) params.sort = sort.value
      if (order.value) params.order = order.value

      const response = await get<InvoicePaginatedResponse>('/v1/invoices', params)

      items.value = response.data
      total.value = response.total
      page.value = response.page
      totals.value = response.totals ?? { subtotal: '0.00', vatTotal: '0.00', total: '0.00', receivable: '0.00', payable: '0.00' }
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca facturile.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchInvoice(uuid: string): Promise<Invoice | null> {
    const { get } = useApi()

    try {
      return await get<Invoice>(`/v1/invoices/${uuid}`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca factura.'
      return null
    }
  }

  async function fetchInvoiceEvents(uuid: string): Promise<any[]> {
    const { get } = useApi()

    try {
      return await get<any[]>(`/v1/invoices/${uuid}/events`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca evenimentele.'
      return []
    }
  }

  function setFilters(newFilters: Partial<InvoiceFilters>) {
    filters.value = { ...filters.value, ...newFilters }
    page.value = 1 // Reset to first page when filters change
  }

  function clearFilters() {
    filters.value = {
      direction: null,
      status: null,
      type: null,
      search: '',
      dateFrom: null,
      dateTo: null,
      isDuplicate: null,
      isLateSubmission: null,
      isPaid: null,
      supplierId: null,
    }
    page.value = 1
  }

  function setPage(newPage: number) {
    page.value = newPage
  }

  function nextPage() {
    if (hasNextPage.value) {
      page.value++
    }
  }

  function previousPage() {
    if (hasPreviousPage.value) {
      page.value--
    }
  }

  async function exportCsv(): Promise<Blob | null> {
    const { apiFetch } = useApi()
    error.value = null
    try {
      const params: Record<string, any> = {}
      if (filters.value.direction) params.direction = filters.value.direction
      if (filters.value.status) params.status = filters.value.status
      if (filters.value.dateFrom) params.dateFrom = filters.value.dateFrom
      if (filters.value.dateTo) params.dateTo = filters.value.dateTo

      return await apiFetch<Blob>('/v1/invoices/export/csv', {
        method: 'GET',
        params,
        responseType: 'blob',
      })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut exporta CSV-ul.'
      return null
    }
  }

  async function exportZip(invoiceIds: string[]): Promise<boolean> {
    const { post } = useApi()
    error.value = null
    try {
      await post('/v1/invoices/export/zip', { ids: invoiceIds })
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut exporta arhiva ZIP.'
      return false
    }
  }

  async function deleteAllPayments(uuid: string): Promise<boolean> {
    const { del } = useApi()
    error.value = null
    try {
      const payments = await fetchPayments(uuid)
      for (const payment of payments) {
        await del(`/v1/invoices/${uuid}/payments/${payment.id}`)
      }
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut sterge platile.'
      return false
    }
  }

  async function fetchPayments(uuid: string): Promise<Payment[]> {
    const { get } = useApi()
    try {
      return await get<Payment[]>(`/v1/invoices/${uuid}/payments`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca platile.'
      return []
    }
  }

  async function recordPayment(uuid: string, payload: { amount: string; paymentMethod: string; paymentDate?: string; reference?: string; notes?: string }): Promise<Payment | null> {
    const { post } = useApi()
    try {
      return await post<Payment>(`/v1/invoices/${uuid}/payments`, payload)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut inregistra plata.'
      return null
    }
  }

  async function deletePayment(uuid: string, paymentId: string): Promise<boolean> {
    const { del } = useApi()
    error.value = null
    try {
      await del(`/v1/invoices/${uuid}/payments/${paymentId}`)
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge plata.'
      return false
    }
  }

  async function sendEmail(uuid: string, params: { to: string, subject?: string, body?: string, cc?: string[], bcc?: string[], templateId?: string }): Promise<EmailLog | null> {
    const { post } = useApi()
    error.value = null
    try {
      const payload: Record<string, any> = { to: params.to }
      if (params.subject) payload.subject = params.subject
      if (params.body) payload.body = params.body
      if (params.cc?.length) payload.cc = params.cc
      if (params.bcc?.length) payload.bcc = params.bcc
      if (params.templateId) payload.templateId = params.templateId
      return await post<EmailLog>(`/v1/invoices/${uuid}/email`, payload)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut trimite emailul.'
      return null
    }
  }

  async function fetchEmailLogs(uuid: string): Promise<EmailLog[]> {
    const { get } = useApi()
    try {
      return await get<EmailLog[]>(`/v1/invoices/${uuid}/emails`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca emailurile.'
      return []
    }
  }

  async function fetchEmailDefaults(uuid: string): Promise<{ to: string | null, subject: string | null, body: string | null, templateId: string | null }> {
    const { get } = useApi()
    try {
      return await get<{ to: string | null, subject: string | null, body: string | null, templateId: string | null }>(`/v1/invoices/${uuid}/email-defaults`)
    }
    catch {
      return { to: null, subject: null, body: null, templateId: null }
    }
  }

  async function createInvoice(payload: CreateInvoicePayload): Promise<{ invoice: Invoice, validation: ValidationResponse } | null> {
    const { post } = useApi()
    try {
      return await post<{ invoice: Invoice, validation: ValidationResponse }>('/v1/invoices', payload)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea factura.'
      return null
    }
  }

  async function updateInvoice(uuid: string, payload: UpdateInvoicePayload): Promise<{ invoice: Invoice, validation: ValidationResponse } | null> {
    const { put } = useApi()
    try {
      return await put<{ invoice: Invoice, validation: ValidationResponse }>(`/v1/invoices/${uuid}`, payload)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza factura.'
      return null
    }
  }

  async function deleteInvoice(uuid: string): Promise<boolean> {
    const { del } = useApi()
    try {
      await del(`/v1/invoices/${uuid}`)
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge factura.'
      return false
    }
  }

  async function issueInvoice(uuid: string): Promise<{ status: string; number: string; efacturaDelayHours: number | null; scheduledSendAt: string | null } | null> {
    const { post } = useApi()
    try {
      return await post<{ status: string; number: string; efacturaDelayHours: number | null; scheduledSendAt: string | null }>(`/v1/invoices/${uuid}/issue`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut emite factura.'
      return null
    }
  }

  async function submitInvoice(uuid: string): Promise<{ message: string; status: string } | null> {
    const { post } = useApi()
    try {
      return await post<{ message: string; status: string }>(`/v1/invoices/${uuid}/submit`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut trimite factura.'
      return null
    }
  }

  async function cancelInvoice(uuid: string, reason?: string): Promise<Invoice | null> {
    const { post } = useApi()
    try {
      return await post<Invoice>(`/v1/invoices/${uuid}/cancel`, { reason })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut anula factura.'
      return null
    }
  }

  async function validateInvoice(uuid: string, mode: 'quick' | 'full' = 'quick'): Promise<ValidationResponse | null> {
    const { post } = useApi()
    try {
      return await post<ValidationResponse>(`/v1/invoices/${uuid}/validate`, { mode })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Validarea a esuat.'
      return null
    }
  }

  async function bulkMarkPaid(ids: string[], paymentMethod = 'bank_transfer'): Promise<{ marked: number, errors: Array<{ id: string, error: string }> } | null> {
    const { post } = useApi()
    try {
      return await post<{ marked: number, errors: Array<{ id: string, error: string }> }>('/v1/invoices/bulk-mark-paid', { ids, paymentMethod })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut marca facturile ca platite.'
      return null
    }
  }

  async function bulkDelete(ids: string[]): Promise<{ deleted: number, errors: Array<{ id: string, error: string }> } | null> {
    const { post } = useApi()
    try {
      return await post<{ deleted: number, errors: Array<{ id: string, error: string }> }>('/v1/invoices/bulk-delete', { ids })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut sterge facturile in masa.'
      return null
    }
  }

  async function bulkCancel(ids: string[], reason?: string): Promise<{ cancelled: number, errors: Array<{ id: string, error: string }> } | null> {
    const { post } = useApi()
    try {
      return await post<{ cancelled: number, errors: Array<{ id: string, error: string }> }>('/v1/invoices/bulk-cancel', { ids, reason })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut anula facturile in masa.'
      return null
    }
  }

  async function bulkMarkUnpaid(ids: string[]): Promise<{ unmarked: number, errors: Array<{ id: string, error: string }> } | null> {
    const { post } = useApi()
    try {
      return await post<{ unmarked: number, errors: Array<{ id: string, error: string }> }>('/v1/invoices/bulk-mark-unpaid', { ids })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut marca facturile ca neplatite.'
      return null
    }
  }

  async function bulkStorno(ids: string[]): Promise<{ created: number, errors: Array<{ id: string, error: string }> } | null> {
    const { post } = useApi()
    try {
      return await post<{ created: number, errors: Array<{ id: string, error: string }> }>('/v1/invoices/bulk-storno', { ids })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut storna facturile in masa.'
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
    totals,

    // Getters
    totalPages,
    hasNextPage,
    hasPreviousPage,
    isEmpty,
    activeFilterCount,

    // Actions
    fetchInvoices,
    fetchInvoice,
    fetchInvoiceEvents,
    setFilters,
    clearFilters,
    setPage,
    nextPage,
    previousPage,
    exportCsv,
    exportZip,
    deleteAllPayments,
    fetchPayments,
    recordPayment,
    deletePayment,
    sendEmail,
    fetchEmailLogs,
    fetchEmailDefaults,
    createInvoice,
    updateInvoice,
    deleteInvoice,
    issueInvoice,
    submitInvoice,
    cancelInvoice,
    validateInvoice,
    bulkMarkPaid,
    bulkMarkUnpaid,
    bulkDelete,
    bulkCancel,
    bulkStorno,
    $reset,
  }
})
