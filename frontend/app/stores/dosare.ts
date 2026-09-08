import { defineStore } from 'pinia'
import type { Dosar, DosarActions, DosarCounts, DosarDetail, DosarStats, DosarType } from '~/types'

interface ListResponse {
  data: Dosar[]
  counts: Record<string, DosarCounts>
  total: number
}

/**
 * Dosare: the case files grouping declarations, SPV requests and ANAF messages
 * around a rental contract, a year's Declarația unică or the company's standing.
 */
export const useDosareStore = defineStore('dosare', () => {
  const items = ref<Dosar[]>([])
  const counts = ref<Record<string, DosarCounts>>({})
  const actions = ref<DosarActions | null>(null)
  const stats = ref<DosarStats | null>(null)
  const loading = ref(false)
  const actionsLoading = ref(false)
  const error = ref<string | null>(null)

  const byType = computed(() => {
    const groups: Record<DosarType, Dosar[]> = { rental_contract: [], annual_return: [], periodic: [], fiscal_status: [], generic: [] }
    for (const d of items.value) (groups[d.type] ??= []).push(d)
    return groups
  })

  async function fetchDosare(type?: string, status?: string): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null
    try {
      const res = await get<ListResponse>('/v1/dosare', { type, status })
      items.value = res.data
      counts.value = res.counts
    }
    catch (err: any) {
      error.value = err?.data?.error ?? 'Nu s-au putut incarca dosarele.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchActions(): Promise<void> {
    const { get } = useApi()
    actionsLoading.value = true
    try {
      actions.value = await get<DosarActions>('/v1/dosare/actions')
    }
    catch {
      actions.value = null
    }
    finally {
      actionsLoading.value = false
    }
  }

  async function fetchStats(): Promise<void> {
    const { get } = useApi()
    try {
      stats.value = await get<DosarStats>('/v1/dosare/stats')
    }
    catch {
      stats.value = null
    }
  }

  async function documentPrefill(id: string, type: string): Promise<{ type: string, title: string, required: string[], fields: Record<string, any> }> {
    const { get } = useApi()
    return await get(`/v1/dosare/${id}/document/${type}`)
  }

  async function documentRender(id: string, type: string, fields: Record<string, any>): Promise<{ title: string, fileName: string, pdfBase64: string }> {
    const { post } = useApi()
    return await post(`/v1/dosare/${id}/document/${type}`, fields)
  }

  async function fetchDosar(id: string): Promise<DosarDetail> {
    const { get } = useApi()
    return await get<DosarDetail>(`/v1/dosare/${id}`)
  }

  async function createDosar(payload: Partial<Dosar> & { type: DosarType }): Promise<DosarDetail> {
    const { post } = useApi()
    const detail = await post<DosarDetail>('/v1/dosare', payload)
    await fetchDosare()
    return detail
  }

  async function updateDosar(id: string, payload: Partial<Dosar>): Promise<DosarDetail> {
    const { patch } = useApi()
    const detail = await patch<DosarDetail>(`/v1/dosare/${id}`, payload)
    const idx = items.value.findIndex(d => d.id === id)
    if (idx >= 0) items.value[idx] = detail.dosar
    return detail
  }

  async function deleteDosar(id: string): Promise<void> {
    const { del } = useApi()
    await del(`/v1/dosare/${id}`)
    items.value = items.value.filter(d => d.id !== id)
  }

  async function attach(id: string, ref: { declarationId?: string, requestId?: string, documentId?: string }): Promise<DosarDetail> {
    const { post } = useApi()
    return await post<DosarDetail>(`/v1/dosare/${id}/attach`, ref)
  }

  async function detach(id: string, ref: { declarationId?: string, requestId?: string, documentId?: string }): Promise<DosarDetail> {
    const { post } = useApi()
    return await post<DosarDetail>(`/v1/dosare/${id}/detach`, ref)
  }

  async function ensureAnnualReturn(an?: number): Promise<DosarDetail> {
    const { post } = useApi()
    const detail = await post<DosarDetail>('/v1/dosare/annual-return', an ? { an } : {})
    await fetchDosare()
    return detail
  }

  async function d212Prefill(id: string): Promise<{ input: Record<string, any>, notes: string[], an: number }> {
    const { get } = useApi()
    return await get(`/v1/dosare/${id}/d212-prefill`)
  }

  async function createD212(id: string, input?: Record<string, any>): Promise<any> {
    const { post } = useApi()
    return await post(`/v1/dosare/${id}/d212`, input ? { input } : {})
  }

  return { items, counts, actions, stats, loading, actionsLoading, error, byType, fetchDosare, fetchActions, fetchStats, documentPrefill, documentRender, fetchDosar, createDosar, updateDosar, deleteDosar, attach, detach, ensureAnnualReturn, d212Prefill, createD212 }
})
