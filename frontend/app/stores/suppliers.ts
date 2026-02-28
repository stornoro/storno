import { defineStore } from 'pinia'
import type { Supplier } from '~/types'
import { PAGINATION } from '~/utils/constants'

interface SupplierPaginatedResponse {
  data: Supplier[]
  total: number
  page: number
  limit: number
}

export interface SupplierDetailResponse {
  supplier: Supplier
  invoiceHistory: any[]
  invoiceCount: number
}

export const useSupplierStore = defineStore('suppliers', () => {
  const items = ref<Supplier[]>([])
  const loading = ref(true)
  const error = ref<string | null>(null)

  const search = ref('')
  const page = ref(1)
  const limit = ref(PAGINATION.DEFAULT_LIMIT)
  const total = ref(0)

  const totalPages = computed(() => Math.ceil(total.value / limit.value) || 1)
  const hasNextPage = computed(() => page.value < totalPages.value)
  const hasPreviousPage = computed(() => page.value > 1)
  const isEmpty = computed(() => !loading.value && items.value.length === 0)

  async function fetchSuppliers(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const params: Record<string, any> = {
        page: page.value,
        limit: limit.value,
      }
      if (search.value) params.search = search.value

      const response = await get<SupplierPaginatedResponse>('/v1/suppliers', params)
      items.value = response.data
      total.value = response.total
      page.value = response.page
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca furnizorii.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchSupplier(uuid: string): Promise<SupplierDetailResponse | null> {
    const { get } = useApi()
    try {
      return await get<SupplierDetailResponse>(`/v1/suppliers/${uuid}`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca furnizorul.'
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

  async function createSupplier(payload: Record<string, any>): Promise<Supplier | null> {
    const { post } = useApi()
    try {
      const res = await post<{ supplier: Supplier }>('/v1/suppliers', payload)
      return res.supplier
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea furnizorul.'
      return null
    }
  }

  async function updateSupplier(uuid: string, payload: Record<string, any>): Promise<Supplier | null> {
    const { patch } = useApi()
    try {
      const res = await patch<{ supplier: Supplier }>(`/v1/suppliers/${uuid}`, payload)
      return res.supplier
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza furnizorul.'
      return null
    }
  }

  async function deleteSupplier(uuid: string): Promise<boolean> {
    const { del } = useApi()
    try {
      await del(`/v1/suppliers/${uuid}`)
      items.value = items.value.filter(i => i.id !== uuid)
      total.value = Math.max(0, total.value - 1)
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge furnizorul.'
      return false
    }
  }

  async function bulkDelete(ids: string[]): Promise<{ deleted: number, errors: Array<{ id: string, error: string }> } | null> {
    const { post } = useApi()
    try {
      return await post<{ deleted: number, errors: Array<{ id: string, error: string }> }>('/v1/suppliers/bulk-delete', { ids })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut sterge furnizorii in masa.'
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
    items, loading, error, search, page, limit, total,
    totalPages, hasNextPage, hasPreviousPage, isEmpty,
    fetchSuppliers, fetchSupplier, createSupplier, updateSupplier, deleteSupplier, bulkDelete, setSearch, setPage, $reset,
  }
})
