import { defineStore } from 'pinia'

interface BorderouTransaction {
  id: string
  transactionDate: string
  clientName: string | null
  clientCif: string | null
  explanation: string | null
  amount: string
  currency: string
  awbNumber: string | null
  bankReference: string | null
  documentType: string | null
  documentNumber: string | null
  sourceType: string
  sourceProvider: string | null
  matchConfidence: string
  matchedInvoiceNumber: string | null
  matchedInvoiceId: string | null
  matchedProformaInvoiceNumber: string | null
  matchedProformaInvoiceId: string | null
  matchedClientName: string | null
  matchedClientId: string | null
  status: string
  importJobId: string | null
  rawData: Record<string, string> | null
  createdAt: string
}

interface BorderouSummary {
  total: number
  certain: number
  attention: number
  noMatch: number
  totalAmount: string
}

interface AvailableInvoice {
  id: string
  type: 'invoice' | 'proforma'
  number: string
  clientName: string | null
  issueDate: string
  dueDate: string | null
  total: string
  amountPaid: string
  balance: string
  currency: string
}

interface BorderouProviders {
  borderou: Array<{ key: string; label: string; formats: string[] }>
  bank_statement: Array<{ key: string; label: string; formats: string[] }>
}

interface SaveResult {
  saved: number
  errors: Array<{ transactionId: string; error: string }>
}

export const useBordereauStore = defineStore('borderou', () => {
  const transactions = ref<BorderouTransaction[]>([])
  const loading = ref(false)
  const uploading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)
  const summary = ref<BorderouSummary>({ total: 0, certain: 0, attention: 0, noMatch: 0, totalAmount: '0.00' })
  const providers = ref<BorderouProviders | null>(null)
  const currentImportJobId = ref<string | null>(null)
  const availableInvoices = ref<AvailableInvoice[]>([])
  const pagination = ref({ total: 0, page: 1, limit: 50 })

  const filters = ref({
    sourceType: undefined as string | undefined,
    provider: undefined as string | undefined,
    status: 'all',
    confidence: 'all',
    dateFrom: undefined as string | undefined,
    dateTo: undefined as string | undefined,
    importJobId: undefined as string | undefined,
  })

  async function fetchProviders(): Promise<void> {
    const { get } = useApi()
    try {
      providers.value = await get<BorderouProviders>('/v1/borderou/providers')
    }
    catch (err: any) {
      console.error('[borderou] fetchProviders failed:', err)
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca furnizorii.'
    }
  }

  async function uploadBorderou(
    file: File,
    sourceType: string,
    provider: string,
    currency: string = 'RON',
    bordereauNumber?: string,
    bankAccountId?: string,
  ): Promise<boolean> {
    const { apiFetch } = useApi()
    uploading.value = true
    error.value = null
    try {
      const formData = new FormData()
      formData.append('file', file)
      formData.append('sourceType', sourceType)
      formData.append('provider', provider)
      formData.append('currency', currency)
      if (bordereauNumber) {
        formData.append('bordereauNumber', bordereauNumber)
      }
      if (bankAccountId) {
        formData.append('bankAccountId', bankAccountId)
      }

      const res = await apiFetch<{
        importJobId: string
        summary: BorderouSummary
        transactions: BorderouTransaction[]
      }>('/v1/borderou/upload', {
        method: 'POST',
        body: formData,
      })

      currentImportJobId.value = res.importJobId
      summary.value = res.summary
      transactions.value = res.transactions
      pagination.value.total = res.summary.total
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Incarcarea a esuat.'
      return false
    }
    finally {
      uploading.value = false
    }
  }

  async function fetchTransactions(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null
    try {
      const params = new URLSearchParams()
      if (filters.value.sourceType) params.set('sourceType', filters.value.sourceType)
      if (filters.value.importJobId) params.set('importJobId', filters.value.importJobId)
      if (filters.value.status && filters.value.status !== 'all') params.set('status', filters.value.status)
      if (filters.value.confidence && filters.value.confidence !== 'all') params.set('confidence', filters.value.confidence)
      if (filters.value.provider) params.set('sourceProvider', filters.value.provider)
      if (filters.value.dateFrom) params.set('dateFrom', filters.value.dateFrom)
      if (filters.value.dateTo) params.set('dateTo', filters.value.dateTo)
      params.set('page', String(pagination.value.page))
      params.set('limit', String(pagination.value.limit))

      const res = await get<{
        data: BorderouTransaction[]
        total: number
        page: number
        limit: number
      }>(`/v1/borderou/transactions?${params.toString()}`)

      transactions.value = res.data
      pagination.value.total = res.total
      pagination.value.page = res.page
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca tranzactiile.'
    }
    finally {
      loading.value = false
    }
  }

  async function updateTransaction(id: string, data: {
    clientId?: string | null
    invoiceId?: string | null
    proformaInvoiceId?: string | null
    amount?: string
    documentType?: string
  }): Promise<BorderouTransaction | null> {
    const { put } = useApi()
    try {
      const res = await put<BorderouTransaction>(`/v1/borderou/transactions/${id}`, data)
      // Update local state
      const idx = transactions.value.findIndex(t => t.id === id)
      if (idx !== -1) {
        transactions.value[idx] = res
      }
      return res
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza tranzactia.'
      return null
    }
  }

  async function fetchAvailableInvoices(transactionId: string, search?: string, type?: string): Promise<void> {
    const { get } = useApi()
    try {
      const params = new URLSearchParams()
      if (search) params.set('search', search)
      if (type) params.set('type', type)
      const qs = params.toString()
      const url = `/v1/borderou/transactions/${transactionId}/available-invoices${qs ? '?' + qs : ''}`
      availableInvoices.value = await get<AvailableInvoice[]>(url)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca facturile.'
      availableInvoices.value = []
    }
  }

  async function saveTransactions(ids: string[]): Promise<SaveResult | null> {
    const { post } = useApi()
    saving.value = true
    error.value = null
    try {
      const res = await post<SaveResult>('/v1/borderou/transactions/save', { transactionIds: ids })
      // Refresh list to show updated statuses
      await fetchTransactions()
      return res
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut salva tranzactiile.'
      return null
    }
    finally {
      saving.value = false
    }
  }

  async function rematchTransactions(ids: string[]): Promise<void> {
    const { post } = useApi()
    loading.value = true
    try {
      await post('/v1/borderou/transactions/re-match', { transactionIds: ids })
      await fetchTransactions()
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut re-asocia tranzactiile.'
    }
    finally {
      loading.value = false
    }
  }

  return {
    transactions,
    loading,
    uploading,
    saving,
    error,
    summary,
    providers,
    currentImportJobId,
    availableInvoices,
    pagination,
    filters,
    fetchProviders,
    uploadBorderou,
    fetchTransactions,
    updateTransaction,
    fetchAvailableInvoices,
    saveTransactions,
    rematchTransactions,
  }
})
