import { defineStore } from 'pinia'
import type { SpvDocument, SpvDocumentStats } from '~/types'
import { PAGINATION } from '~/utils/constants'

interface PaginatedResponse {
  data: SpvDocument[]
  total: number
  page: number
  limit: number
}

/**
 * SPV inbox archive: every ANAF message for the active company, classified
 * (somatii, decizii, notificari, recipise...), with the PDF archived.
 */
export const useSpvDocumentStore = defineStore('spvDocuments', () => {
  const items = ref<SpvDocument[]>([])
  const stats = ref<SpvDocumentStats | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  const search = ref('')
  const category = ref<string | null>(null)
  const severity = ref<string | null>(null)
  const unreadOnly = ref(false)
  const page = ref(1)
  const limit = ref(PAGINATION.DEFAULT_LIMIT)
  const total = ref(0)

  const totalPages = computed(() => Math.ceil(total.value / limit.value) || 1)
  const isEmpty = computed(() => !loading.value && items.value.length === 0)

  async function fetchDocuments(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null
    try {
      const params: Record<string, any> = { page: page.value, limit: limit.value }
      if (search.value) params.search = search.value
      if (category.value) params.category = category.value
      if (severity.value) params.severity = severity.value
      if (unreadOnly.value) params.unread = 1

      const response = await get<PaginatedResponse>('/v1/spv/documents', params)
      items.value = response.data
      total.value = response.total
      page.value = response.page
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca documentele SPV.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchStats(): Promise<void> {
    const { get } = useApi()
    try {
      stats.value = await get<SpvDocumentStats>('/v1/spv/documents/stats')
    }
    catch {
      stats.value = null
    }
  }

  async function fetchDocument(uuid: string): Promise<SpvDocument | null> {
    const { get } = useApi()
    try {
      return await get<SpvDocument>(`/v1/spv/documents/${uuid}`)
    }
    catch {
      return null
    }
  }

  async function markRead(uuid: string): Promise<void> {
    const { patch } = useApi()
    const updated = await patch<SpvDocument>(`/v1/spv/documents/${uuid}/read`, {})
    const idx = items.value.findIndex(d => d.id === uuid)
    if (idx >= 0) items.value[idx] = { ...items.value[idx]!, ...updated }
    if (stats.value && stats.value.unread > 0) stats.value.unread--
  }

  async function markAllRead(): Promise<number> {
    const { post } = useApi()
    const res = await post<{ updated: number }>('/v1/spv/documents/read-all', {})
    items.value = items.value.map(d => ({ ...d, read: true, readAt: d.readAt ?? new Date().toISOString() }))
    if (stats.value) stats.value.unread = 0
    return res.updated
  }

  async function download(doc: SpvDocument): Promise<void> {
    const { apiFetch } = useApi()
    const blob = await apiFetch<Blob>(`/v1/spv/documents/${doc.id}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = doc.fileName ?? `spv_${doc.anafMessageId}.pdf`
    a.click()
    URL.revokeObjectURL(url)
    if (!doc.read) {
      const idx = items.value.findIndex(d => d.id === doc.id)
      if (idx >= 0) items.value[idx] = { ...items.value[idx]!, read: true, readAt: new Date().toISOString() }
      if (stats.value && stats.value.unread > 0) stats.value.unread--
    }
  }

  function setPage(newPage: number) {
    page.value = newPage
  }

  function $reset() {
    items.value = []
    stats.value = null
    loading.value = false
    error.value = null
    search.value = ''
    category.value = null
    severity.value = null
    unreadOnly.value = false
    page.value = 1
    total.value = 0
  }

  return {
    items, stats, loading, error,
    search, category, severity, unreadOnly, page, limit, total,
    totalPages, isEmpty,
    fetchDocuments, fetchStats, fetchDocument, markRead, markAllRead, download, setPage, $reset,
  }
})
