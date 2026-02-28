import { defineStore } from 'pinia'
import type { Client, Invoice, DeliveryNote, Receipt } from '~/types'
import { PAGINATION } from '~/utils/constants'

interface ClientPaginatedResponse {
  data: Client[]
  total: number
  page: number
  limit: number
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
  const page = ref(1)
  const limit = ref(PAGINATION.DEFAULT_LIMIT)
  const total = ref(0)

  // ── Getters ────────────────────────────────────────────────────────
  const totalPages = computed(() => Math.ceil(total.value / limit.value) || 1)
  const hasNextPage = computed(() => page.value < totalPages.value)
  const hasPreviousPage = computed(() => page.value > 1)
  const isEmpty = computed(() => !loading.value && items.value.length === 0)

  // ── Actions ────────────────────────────────────────────────────────
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

      const response = await get<ClientPaginatedResponse>('/v1/clients', params)

      items.value = response.data
      total.value = response.total
      page.value = response.page
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca clientii.'
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
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca clientul.'
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

  async function createClient(payload: Record<string, any>): Promise<Client | null> {
    const { post } = useApi()
    try {
      const res = await post<{ client: Client }>('/v1/clients', payload)
      return res.client
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea clientul.'
      return null
    }
  }

  async function updateClient(uuid: string, payload: Record<string, any>): Promise<Client | null> {
    const { patch } = useApi()
    try {
      const res = await patch<{ client: Client }>(`/v1/clients/${uuid}`, payload)
      return res.client
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza clientul.'
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
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge clientul.'
      return false
    }
  }

  async function createClientFromRegistry(cui: string, name: string): Promise<Client | null> {
    const { post } = useApi()
    try {
      const res = await post<{ client: Client, existing?: boolean, anafValidated?: boolean }>('/v1/clients/from-registry', { cui, name })
      return res.client
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea clientul din registru.'
      return null
    }
  }

  async function bulkDelete(ids: string[]): Promise<{ deleted: number, errors: Array<{ id: string, error: string }> } | null> {
    const { post } = useApi()
    try {
      return await post<{ deleted: number, errors: Array<{ id: string, error: string }> }>('/v1/clients/bulk-delete', { ids })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut sterge clientii in masa.'
      return null
    }
  }

  function $reset() {
    items.value = []
    loading.value = false
    error.value = null
    search.value = ''
    page.value = 1
    total.value = 0
  }

  return {
    // State
    items,
    loading,
    error,
    search,
    page,
    limit,
    total,

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
