import { defineStore } from 'pinia'
import type { Client, Invoice, DeliveryNote, Receipt } from '~/types'
import { PAGINATION } from '~/utils/constants'

interface ClientPaginatedResponse {
  data: Client[]
  total: number
  page: number
  limit: number
  currency: string
  hasForeignCurrencies: boolean
  distinctCountries: string[]
}

interface ClientDetailResponse {
  client: Client
  invoiceHistory: Invoice[]
  invoiceTotal: number
  invoiceCount: number
  deliveryNoteHistory: DeliveryNote[]
  deliveryNoteCount: number
  receiptHistory: Receipt[]
  receiptCount: number
}

export const useClientStore = defineStore('clients', () => {
  // ── State ──────────────────────────────────────────────────────────
  const items = ref<Client[]>([])
  const loading = ref(true)
  const error = ref<string | null>(null)

  const search = ref('')
  const country = ref<string | null>(null)
  const page = ref(1)
  const limit = ref(PAGINATION.DEFAULT_LIMIT)
  const total = ref(0)
  const currency = ref('RON')
  const hasForeignCurrencies = ref(false)
  const distinctCountries = ref<string[]>([])
  const sort = ref<'recent' | 'mostInvoiced' | 'mostInvoices' | 'recentActivity' | 'name'>('recent')
  const vatPayerFilter = ref<'' | 'yes' | 'no'>('')
  const hasInvoicesFilter = ref<'' | 'active' | 'dormant'>('')
  const sourceFilter = ref<'' | 'anaf_sync' | 'manual'>('')

  // ── Getters ────────────────────────────────────────────────────────
  const totalPages = computed(() => Math.ceil(total.value / limit.value) || 1)
  const hasNextPage = computed(() => page.value < totalPages.value)
  const hasPreviousPage = computed(() => page.value > 1)
  const isEmpty = computed(() => !loading.value && items.value.length === 0)

  // ── Actions ────────────────────────────────────────────────────────
  function tt(key: string): string {
    return useNuxtApp().$i18n.t(key) as string
  }

  async function fetchClients(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const params: Record<string, any> = {
        page: page.value,
        limit: limit.value,
      }

      if (search.value) {
        params.search = search.value
      }

      if (country.value) {
        params.country = country.value
      }

      params.sort = sort.value
      if (vatPayerFilter.value) params.vatPayer = vatPayerFilter.value
      if (hasInvoicesFilter.value) params.hasInvoices = hasInvoicesFilter.value
      if (sourceFilter.value) params.source = sourceFilter.value

      const response = await get<ClientPaginatedResponse>('/v1/clients', params)

      items.value = response.data
      total.value = response.total
      page.value = response.page
      currency.value = response.currency ?? 'RON'
      hasForeignCurrencies.value = response.hasForeignCurrencies ?? false
      distinctCountries.value = response.distinctCountries ?? []
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : tt('clients.errors.loadList')
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchClient(uuid: string): Promise<ClientDetailResponse | null> {
    const { get } = useApi()

    try {
      return await get<ClientDetailResponse>(`/v1/clients/${uuid}`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : tt('clients.errors.loadOne')
      return null
    }
  }

  function setSearch(query: string) {
    search.value = query
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

  function upsertItem(client: Client) {
    const idx = items.value.findIndex(c => c.id === client.id)
    if (idx >= 0) {
      items.value[idx] = client
    }
    else {
      items.value = [client, ...items.value]
      total.value++
    }
  }

  async function createClient(payload: Record<string, any>): Promise<Client | null> {
    const { post } = useApi()
    try {
      const res = await post<{ client: Client }>('/v1/clients', payload)
      upsertItem(res.client)
      return res.client
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : tt('clients.errors.create')
      return null
    }
  }

  async function updateClient(uuid: string, payload: Record<string, any>): Promise<Client | null> {
    const { patch } = useApi()
    try {
      const res = await patch<{ client: Client }>(`/v1/clients/${uuid}`, payload)
      upsertItem(res.client)
      return res.client
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : tt('clients.errors.update')
      return null
    }
  }

  async function deleteClient(uuid: string): Promise<boolean> {
    const { del } = useApi()
    try {
      await del(`/v1/clients/${uuid}`)
      items.value = items.value.filter(i => i.id !== uuid)
      total.value = Math.max(0, total.value - 1)
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : tt('clients.errors.delete')
      return false
    }
  }

  async function createClientFromRegistry(cui: string, name: string): Promise<Client | null> {
    const { post } = useApi()
    try {
      const res = await post<{ client: Client, existing?: boolean, anafValidated?: boolean }>('/v1/clients/from-registry', { cui, name })
      upsertItem(res.client)
      return res.client
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : tt('clients.errors.createFromRegistry')
      return null
    }
  }

  async function bulkDelete(ids: string[]): Promise<{ deleted: number, errors: Array<{ id: string, error: string }> } | null> {
    const { post } = useApi()
    try {
      const res = await post<{ deleted: number, errors: Array<{ id: string, error: string }> }>('/v1/clients/bulk-delete', { ids })
      const failedIds = new Set(res.errors.map(e => e.id))
      const removedIds = new Set(ids.filter(id => !failedIds.has(id)))
      items.value = items.value.filter(i => !removedIds.has(i.id))
      total.value = Math.max(0, total.value - removedIds.size)
      return res
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : tt('clients.errors.bulkDelete')
      return null
    }
  }

  function $reset() {
    items.value = []
    loading.value = false
    error.value = null
    search.value = ''
    country.value = null
    page.value = 1
    total.value = 0
    distinctCountries.value = []
    sort.value = 'recent'
    vatPayerFilter.value = ''
    hasInvoicesFilter.value = ''
    sourceFilter.value = ''
  }

  return {
    // State
    items,
    loading,
    error,
    search,
    country,
    page,
    limit,
    total,
    currency,
    hasForeignCurrencies,
    distinctCountries,
    sort,
    vatPayerFilter,
    hasInvoicesFilter,
    sourceFilter,

    // Getters
    totalPages,
    hasNextPage,
    hasPreviousPage,
    isEmpty,

    // Actions
    fetchClients,
    fetchClient,
    createClient,
    updateClient,
    deleteClient,
    bulkDelete,
    createClientFromRegistry,
    setSearch,
    setPage,
    nextPage,
    previousPage,
    $reset,
  }
})
