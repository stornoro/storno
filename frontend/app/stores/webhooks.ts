import { defineStore } from 'pinia'
import type { WebhookEndpoint, WebhookEvent, WebhookDelivery } from '~/types'

export const useWebhookStore = defineStore('webhooks', () => {
  const items = ref<WebhookEndpoint[]>([])
  const availableEvents = ref<WebhookEvent[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchWebhooks(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const response = await get<{ data: WebhookEndpoint[] }>('/v1/webhooks')
      items.value = response.data
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca webhook-urile.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchAvailableEvents(): Promise<void> {
    const { get } = useApi()
    try {
      const response = await get<{ data: WebhookEvent[] }>('/v1/webhooks/events')
      availableEvents.value = response.data
    }
    catch {
      availableEvents.value = []
    }
  }

  async function createWebhook(data: { url: string, events: string[], description?: string, isActive?: boolean }): Promise<WebhookEndpoint | null> {
    const { post } = useApi()
    error.value = null
    try {
      const result = await post<{ data: WebhookEndpoint }>('/v1/webhooks', data)
      await fetchWebhooks()
      return result.data
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea webhook-ul.'
      return null
    }
  }

  async function updateWebhook(id: string, data: Partial<{ url: string, events: string[], description: string | null, isActive: boolean }>): Promise<boolean> {
    const { patch } = useApi()
    error.value = null
    try {
      await patch(`/v1/webhooks/${id}`, data)
      await fetchWebhooks()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza webhook-ul.'
      return false
    }
  }

  async function deleteWebhook(id: string): Promise<boolean> {
    const { del } = useApi()
    error.value = null
    try {
      await del(`/v1/webhooks/${id}`)
      await fetchWebhooks()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge webhook-ul.'
      return false
    }
  }

  async function testWebhook(id: string): Promise<{ success: boolean, statusCode: number | null, durationMs: number | null, error: string | null } | null> {
    const { post } = useApi()
    error.value = null
    try {
      return await post(`/v1/webhooks/${id}/test`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut testa webhook-ul.'
      return null
    }
  }

  async function regenerateSecret(id: string): Promise<string | null> {
    const { post } = useApi()
    error.value = null
    try {
      const result = await post<{ secret: string }>(`/v1/webhooks/${id}/regenerate-secret`)
      await fetchWebhooks()
      return result.secret
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut regenera secretul.'
      return null
    }
  }

  async function fetchDeliveries(id: string, page = 1, limit = 20): Promise<{ data: WebhookDelivery[], meta: { page: number, limit: number, total: number } } | null> {
    const { get } = useApi()
    try {
      return await get(`/v1/webhooks/${id}/deliveries`, { page, limit })
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca livrarile.'
      return null
    }
  }

  async function fetchDeliveryDetail(webhookId: string, deliveryId: string): Promise<WebhookDelivery | null> {
    const { get } = useApi()
    try {
      const result = await get<{ data: WebhookDelivery }>(`/v1/webhooks/${webhookId}/deliveries/${deliveryId}`)
      return result.data
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca detaliul livrarii.'
      return null
    }
  }

  function $reset() {
    items.value = []
    availableEvents.value = []
    loading.value = false
    error.value = null
  }

  return {
    items, availableEvents, loading, error,
    fetchWebhooks, fetchAvailableEvents, createWebhook, updateWebhook, deleteWebhook,
    testWebhook, regenerateSecret, fetchDeliveries, fetchDeliveryDetail, $reset,
  }
})
