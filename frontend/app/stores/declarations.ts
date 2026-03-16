import { defineStore } from 'pinia'
import type { TaxDeclaration, CreateDeclarationPayload } from '~/types'
import type { DeclarationType, DeclarationStatus } from '~/types/enums'
import { PAGINATION } from '~/utils/constants'

export interface DeclarationFilters {
  type: DeclarationType | null
  status: DeclarationStatus | null
  year: number | null
  month: number | null
}

export const useDeclarationStore = defineStore('declarations', () => {
  // ── State ──────────────────────────────────────────────────────────
  const items = ref<TaxDeclaration[]>([])
  const loading = ref(true)
  const error = ref<string | null>(null)

  const filters = ref<DeclarationFilters>({
    type: null,
    status: null,
    year: null,
    month: null,
  })

  const page = ref(1)
  const limit = ref(PAGINATION.DEFAULT_LIMIT)
  const total = ref(0)

  // ── Getters ────────────────────────────────────────────────────────
  const totalPages = computed(() => Math.ceil(total.value / limit.value) || 1)
  const isEmpty = computed(() => !loading.value && items.value.length === 0)

  // ── Actions ────────────────────────────────────────────────────────
  async function fetchDeclarations(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const params: Record<string, any> = {
        page: page.value,
        limit: limit.value,
      }

      if (filters.value.type) params.type = filters.value.type
      if (filters.value.status) params.status = filters.value.status
      if (filters.value.year) params.year = filters.value.year
      if (filters.value.month) params.month = filters.value.month

      const res = await get<{ items: TaxDeclaration[]; total: number; page: number; limit: number }>('/api/v1/declarations', params)
      items.value = res.items ?? []
      total.value = res.total ?? 0
    } catch (e: any) {
      error.value = e?.message ?? 'Failed to load declarations'
    } finally {
      loading.value = false
    }
  }

  async function fetchDeclaration(uuid: string): Promise<TaxDeclaration | null> {
    const { get } = useApi()
    try {
      return await get<TaxDeclaration>(`/api/v1/declarations/${uuid}`)
    } catch {
      return null
    }
  }

  async function createDeclaration(payload: CreateDeclarationPayload): Promise<TaxDeclaration> {
    const { post } = useApi()
    return await post<TaxDeclaration>('/api/v1/declarations', payload)
  }

  async function updateDeclaration(uuid: string, data: Record<string, unknown>): Promise<TaxDeclaration> {
    const { patch } = useApi()
    return await patch<TaxDeclaration>(`/api/v1/declarations/${uuid}`, data)
  }

  async function deleteDeclaration(uuid: string): Promise<void> {
    const { del } = useApi()
    await del(`/api/v1/declarations/${uuid}`)
  }

  async function recalculateDeclaration(uuid: string): Promise<TaxDeclaration> {
    const { post } = useApi()
    return await post<TaxDeclaration>(`/api/v1/declarations/${uuid}/recalculate`)
  }

  async function validateDeclaration(uuid: string): Promise<TaxDeclaration> {
    const { post } = useApi()
    return await post<TaxDeclaration>(`/api/v1/declarations/${uuid}/validate`)
  }

  async function submitDeclaration(uuid: string): Promise<TaxDeclaration> {
    const { post } = useApi()
    return await post<TaxDeclaration>(`/api/v1/declarations/${uuid}/submit`)
  }

  async function uploadXml(file: File): Promise<TaxDeclaration> {
    const { apiFetch } = useApi()
    const formData = new FormData()
    formData.append('file', file)
    return await apiFetch<TaxDeclaration>('/api/v1/declarations/upload', {
      method: 'POST',
      body: formData,
    })
  }

  async function bulkSubmit(ids: string[]): Promise<{ submitted: number }> {
    const { post } = useApi()
    return await post<{ submitted: number }>('/api/v1/declarations/bulk-submit', { ids })
  }

  async function syncFromAnaf(year: number): Promise<void> {
    const { post } = useApi()
    await post('/api/v1/declarations/sync', { year })
  }

  async function refreshStatuses(): Promise<void> {
    const { post } = useApi()
    await post('/api/v1/declarations/refresh-statuses')
  }

  function resetFilters(): void {
    filters.value = { type: null, status: null, year: null, month: null }
    page.value = 1
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
    fetchDeclarations,
    fetchDeclaration,
    createDeclaration,
    updateDeclaration,
    deleteDeclaration,
    recalculateDeclaration,
    validateDeclaration,
    submitDeclaration,
    uploadXml,
    bulkSubmit,
    syncFromAnaf,
    refreshStatuses,
    resetFilters,
  }
})
