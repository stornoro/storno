import { defineStore } from 'pinia'
import type { EFacturaMessage } from '~/types'
import { PAGINATION } from '~/utils/constants'

interface MessagePaginatedResponse {
  data: EFacturaMessage[]
  total: number
  page: number
  limit: number
}

export const useEFacturaMessageStore = defineStore('efacturaMessages', () => {
  const items = ref<EFacturaMessage[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  const search = ref('')
  const messageType = ref<string | null>(null)
  const status = ref<string | null>(null)
  const page = ref(1)
  const limit = ref(PAGINATION.DEFAULT_LIMIT)
  const total = ref(0)

  const totalPages = computed(() => Math.ceil(total.value / limit.value) || 1)
  const isEmpty = computed(() => !loading.value && items.value.length === 0)

  async function fetchMessages(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const params: Record<string, any> = {
        page: page.value,
        limit: limit.value,
      }
      if (search.value) params.search = search.value
      if (messageType.value) params.messageType = messageType.value
      if (status.value) params.status = status.value

      const response = await get<MessagePaginatedResponse>('/v1/efactura-messages', params)
      items.value = response.data
      total.value = response.total
      page.value = response.page
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca mesajele.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchMessage(uuid: string): Promise<EFacturaMessage | null> {
    const { get } = useApi()
    try {
      return await get<EFacturaMessage>(`/v1/efactura-messages/${uuid}`)
    }
    catch {
      return null
    }
  }

  function setPage(newPage: number) {
    page.value = newPage
  }

  function $reset() {
    items.value = []
    loading.value = false
    error.value = null
    search.value = ''
    messageType.value = null
    status.value = null
    page.value = 1
    total.value = 0
  }

  return {
    items, loading, error, search, messageType, status, page, limit, total,
    totalPages, isEmpty,
    fetchMessages, fetchMessage, setPage, $reset,
  }
})
