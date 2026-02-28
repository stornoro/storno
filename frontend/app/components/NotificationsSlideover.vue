<script setup lang="ts">
import type { BackendNotification } from '~/composables/useExportNotifications'

const { t: $t } = useI18n()
const { isNotificationsSlideoverOpen } = useDashboard()
const authStore = useAuthStore()
const { notifications, unreadCount, markAllRead, downloadExport, start, stop } = useNotifications()

let started = false

if (import.meta.client) {
  watchEffect(() => {
    const userId = authStore.user?.id
    if (userId && !started) {
      start()
      started = true
    }
  })
}

onUnmounted(() => {
  if (started) {
    stop()
    started = false
  }
})

function channelIcon(notification: BackendNotification): string {
  const typeMap: Record<string, string> = {
    'export_ready': 'i-lucide-archive',
    'sync.error': 'i-lucide-alert-circle',
    'sync.completed': 'i-lucide-refresh-cw',
    'efactura.new_documents': 'i-lucide-cloud-download',
    'invoice.validated': 'i-lucide-check-circle',
    'invoice.rejected': 'i-lucide-x-circle',
    'invoice.overdue': 'i-lucide-alert-triangle',
    'invoice.due_today': 'i-lucide-calendar-clock',
    'invoice.due_soon': 'i-lucide-calendar-clock',
    'token.expiring_soon': 'i-lucide-clock',
    'token.refresh_failed': 'i-lucide-key-round',
    'payment.received': 'i-lucide-banknote',
    'proforma.expiring_soon': 'i-lucide-clock',
    'proforma.expired': 'i-lucide-calendar-x',
  }
  return typeMap[notification.type] || 'i-lucide-bell'
}

function iconColor(notification: BackendNotification): string {
  if (notification.isRead) return 'text-(--ui-text-muted)'
  const errorTypes = ['sync.error', 'invoice.rejected', 'token.refresh_failed']
  const warnTypes = ['invoice.overdue', 'invoice.due_today', 'invoice.due_soon', 'token.expiring_soon', 'proforma.expiring_soon', 'proforma.expired']
  const successTypes = ['invoice.validated', 'payment.received', 'efactura.new_documents', 'sync.completed']
  if (errorTypes.includes(notification.type)) return 'text-error'
  if (warnTypes.includes(notification.type)) return 'text-warning'
  if (successTypes.includes(notification.type)) return 'text-success'
  return 'text-(--ui-text-highlighted)'
}

function formatDate(dateStr: string): string {
  if (!dateStr) return ''
  const now = new Date()
  const date = new Date(dateStr)
  const diffMs = now.getTime() - date.getTime()
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMs / 3600000)
  const diffDays = Math.floor(diffMs / 86400000)

  if (diffMins < 1) return 'Acum'
  if (diffMins < 60) return `Acum ${diffMins} min`
  if (diffHours < 24) return `Acum ${diffHours} ${diffHours === 1 ? 'ora' : 'ore'}`
  if (diffDays < 7) return `Acum ${diffDays} ${diffDays === 1 ? 'zi' : 'zile'}`
  return date.toLocaleDateString('ro-RO', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function notificationLink(n: BackendNotification): string | null {
  const invoiceId = n.data?.invoiceId as string | undefined
  if (invoiceId && n.type.startsWith('invoice.')) return `/invoices/${invoiceId}`
  if (n.type === 'efactura.new_documents') return '/efactura'
  if (n.type === 'sync.error' || n.type === 'sync.completed') return '/efactura'
  if (n.type === 'token.expiring_soon' || n.type === 'token.refresh_failed') return '/efactura'
  if (n.type === 'payment.received' && invoiceId) return `/invoices/${invoiceId}`
  return n.link ?? null
}

function onDownload(notification: BackendNotification) {
  const url = notification.data?.downloadUrl
  const filename = notification.data?.filename || 'export.zip'
  downloadExport(url, filename)
}
</script>

<template>
  <USlideover
    v-model:open="isNotificationsSlideoverOpen"
    :title="$t('notifications.title')"
  >
    <template #header>
      <div class="flex items-center justify-between w-full">
        <span class="text-lg font-semibold">{{ $t('notifications.title') }}</span>
        <UButton
          v-if="unreadCount > 0"
          variant="link"
          size="xs"
          @click="markAllRead"
        >
          {{ $t('notifications.markAllRead') }}
        </UButton>
      </div>
    </template>

    <template #body>
      <div v-if="notifications.length === 0" class="text-center py-12 text-sm text-(--ui-text-muted)">
        {{ $t('notifications.empty') }}
      </div>

      <div
        v-for="n in notifications"
        :key="n.id"
        class="px-3 py-2.5 rounded-md hover:bg-elevated/50 flex items-start gap-3 -mx-3 first:-mt-3 last:-mb-3"
      >
        <div class="mt-0.5 shrink-0">
          <UIcon :name="channelIcon(n)" class="size-5" :class="iconColor(n)" />
        </div>

        <div class="text-sm flex-1 min-w-0">
          <p class="flex items-center justify-between gap-2">
            <span class="font-medium truncate" :class="n.isRead ? 'text-(--ui-text-muted)' : 'text-(--ui-text-highlighted)'">
              {{ n.title }}
            </span>
            <time :datetime="n.sentAt" class="text-(--ui-text-dimmed) text-xs shrink-0">
              {{ formatDate(n.sentAt) }}
            </time>
          </p>
          <p class="text-(--ui-text-dimmed) mt-0.5">{{ n.message }}</p>
          <div class="flex items-center gap-2 mt-1.5">
            <UButton
              v-if="n.type === 'export_ready' && n.data?.downloadUrl"
              size="xs"
              variant="soft"
              icon="i-lucide-download"
              @click="onDownload(n)"
            >
              {{ $t('invoices.exportZipDownload') }}
            </UButton>
            <NuxtLink
              v-if="notificationLink(n)"
              :to="notificationLink(n)!"
              class="text-xs text-primary font-medium hover:underline"
              @click="isNotificationsSlideoverOpen = false"
            >
              {{ $t('notifications.seeDetails') }}
            </NuxtLink>
          </div>
        </div>
      </div>
    </template>
  </USlideover>
</template>
