import { createSharedComposable } from '@vueuse/core'

export interface BackendNotification {
  id: string
  type: string
  channel: string
  title: string
  message: string
  from: string | null
  link: string | null
  sentAt: string
  isRead: boolean
  data: Record<string, any>
}

/**
 * Manages notifications via Centrifugo real-time push.
 * Loads existing notifications on start, then receives new ones via WebSocket.
 * Shared composable — single instance across all components.
 */
const _useNotifications = () => {
  const { get, post, apiFetch } = useApi()
  const { subscribe, unsubscribe } = useCentrifugo()
  const authStore = useAuthStore()
  const toast = useToast()
  const { t: $t } = useI18n()

  const notifications = ref<BackendNotification[]>([])
  const loading = ref(false)
  const seenIds = new Set<string>()

  const unreadCount = computed(() =>
    notifications.value.filter(n => !n.isRead).length,
  )

  async function fetchNotifications() {
    if (!authStore.token) return
    loading.value = true
    try {
      const res = await get<{ data: BackendNotification[], total: number }>('/v1/notifications')
      const items = res?.data ?? []
      notifications.value = items

      for (const n of items) {
        seenIds.add(n.id)
      }
    }
    catch {
      // silent
    }
    finally {
      loading.value = false
    }
  }

  function handleRealtimeNotification(data: any) {
    if (!data.id || seenIds.has(data.id)) return

    seenIds.add(data.id)

    const notification: BackendNotification = {
      id: data.id,
      type: data.type ?? '',
      channel: data.channel ?? 'in_app',
      title: data.title ?? '',
      message: data.message ?? '',
      from: data.from ?? null,
      link: data.link ?? null,
      sentAt: data.sentAt ?? new Date().toISOString(),
      isRead: false,
      data: data.data ?? {},
    }

    notifications.value = [notification, ...notifications.value]
    showNotificationToast(notification)
  }

  function showNotificationToast(n: BackendNotification) {
    if (n.type === 'export_ready' && n.data?.downloadUrl) {
      const downloadUrl = n.data.downloadUrl
      const filename = n.data.filename || 'export.zip'
      toast.add({
        title: $t('invoices.exportZipReady'),
        description: n.message,
        icon: 'i-lucide-archive',
        color: 'success',
        actions: [
          {
            label: $t('invoices.exportZipDownload'),
            onClick: () => downloadExport(downloadUrl, filename),
          },
        ],
      })
      return
    }

    const iconMap: Record<string, string> = {
      'invoice.validated': 'i-lucide-check-circle',
      'invoice.rejected': 'i-lucide-x-circle',
      'invoice.overdue': 'i-lucide-alert-triangle',
      'invoice.due_today': 'i-lucide-calendar-clock',
      'invoice.due_soon': 'i-lucide-calendar-clock',
      'payment.received': 'i-lucide-banknote',
      'sync.completed': 'i-lucide-refresh-cw',
      'sync.error': 'i-lucide-alert-circle',
      'efactura.new_documents': 'i-lucide-cloud-download',
      'token.expiring_soon': 'i-lucide-clock',
      'token.refresh_failed': 'i-lucide-key-round',
      'proforma.expiring_soon': 'i-lucide-clock',
      'proforma.expired': 'i-lucide-calendar-x',
    }
    const colorMap: Record<string, string> = {
      'invoice.validated': 'success',
      'invoice.rejected': 'error',
      'invoice.overdue': 'warning',
      'invoice.due_today': 'warning',
      'invoice.due_soon': 'warning',
      'payment.received': 'success',
      'sync.completed': 'info',
      'sync.error': 'error',
      'efactura.new_documents': 'success',
      'token.expiring_soon': 'warning',
      'token.refresh_failed': 'error',
      'proforma.expiring_soon': 'warning',
      'proforma.expired': 'warning',
    }

    toast.add({
      title: n.title,
      description: n.message,
      icon: iconMap[n.type] || 'i-lucide-bell',
      color: (colorMap[n.type] || 'info') as any,
    })
  }

  async function downloadExport(url: string, filename: string) {
    try {
      const blob = await apiFetch<Blob>(url, { responseType: 'blob' })
      const objectUrl = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = objectUrl
      a.download = filename
      a.click()
      URL.revokeObjectURL(objectUrl)
    }
    catch {
      toast.add({
        title: $t('invoices.exportError'),
        color: 'error',
        icon: 'i-lucide-alert-circle',
      })
    }
  }

  async function markAllRead() {
    try {
      await post('/v1/notifications/read-all')
      notifications.value = notifications.value.map(n => ({ ...n, isRead: true }))
    }
    catch {
      // silent
    }
  }

  function start() {
    if (!authStore.user?.id) return

    fetchNotifications()

    const channel = `notifications:user_${authStore.user.id}`
    subscribe(channel, handleRealtimeNotification, { recover: true })
  }

  function stop() {
    if (authStore.user?.id) {
      unsubscribe(`notifications:user_${authStore.user.id}`)
    }
  }

  function refresh() {
    fetchNotifications()
  }

  return {
    notifications,
    unreadCount,
    loading,
    refresh,
    markAllRead,
    downloadExport,
    start,
    stop,
  }
}

export const useNotifications = createSharedComposable(_useNotifications)
