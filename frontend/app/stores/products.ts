import { defineStore } from 'pinia'
import type { Product } from '~/types'
import { PAGINATION } from '~/utils/constants'

interface ProductPaginatedResponse {
  data: Product[]
  total: number
  page: number
  limit: number
}

export const useProductStore = defineStore('products', () => {
  // ── State ──────────────────────────────────────────────────────────
  const items = ref<Product[]>([])
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
  async function fetchProducts(): Promise<void> {
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

      const response = await get<ProductPaginatedResponse>('/v1/products', params)

      items.value = response.data
      total.value = response.total
      page.value = response.page
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca produsele.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchProduct(uuid: string): Promise<Product | null> {
    const { get } = useApi()

    try {
      return await get<Product>(`/v1/products/${uuid}`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca produsul.'
      return null
    }
  }

  async function createProduct(data: Record<string, any>): Promise<Product | null> {
    const { post } = useApi()
    error.value = null
    try {
      const result = await post<Product>('/v1/products', data)
      await fetchProducts()
      return result
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea produsul.'
      return null
    }
  }

  async function updateProduct(id: string, data: Record<string, any>): Promise<boolean> {
    const { patch } = useApi()
    error.value = null
    try {
      await patch(`/v1/products/${id}`, data)
      await fetchProducts()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza produsul.'
      return false
    }
  }

  async function checkProductUsage(id: string): Promise<{ invoices: number, proformaInvoices: number, recurringInvoices: number, total: number } | null> {
    const { get } = useApi()
    try {
      return await get(`/v1/products/${id}/usage`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut verifica utilizarea produsului.'
      return null
    }
  }

  async function deleteProduct(id: string): Promise<boolean> {
    const { del } = useApi()
    error.value = null
    try {
      await del(`/v1/products/${id}`)
      await fetchProducts()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge produsul.'
      return false
    }
  }

  async function bulkDelete(ids: string[]): Promise<{ deleted: number, errors: Array<{ id: string, error: string }> } | null> {
    const { post } = useApi()
    try {
      return await post<{ deleted: number, errors: Array<{ id: string, error: string }> }>('/v1/products/bulk-delete', { ids })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut sterge produsele in masa.'
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
    fetchProducts,
    fetchProduct,
    createProduct,
    updateProduct,
    checkProductUsage,
    deleteProduct,
    bulkDelete,
    setSearch,
    setPage,
    nextPage,
    previousPage,
    $reset,
  }
})
