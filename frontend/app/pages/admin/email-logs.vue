<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()

const logs = ref<any[]>([])
const loading = ref(true)
const search = ref('')
const categoryFilter = ref('all')
const statusFilter = ref('all')
const page = ref(1)
const total = ref(0)
const limit = ref(PAGINATION.DEFAULT_LIMIT)
const selectedLog = ref<any>(null)
const detailLoading = ref(false)

const showModal = computed({
  get: () => !!selectedLog.value,
  set: (v: boolean) => { if (!v) selectedLog.value = null },
})

const columns = [
  { accessorKey: 'sentAt', header: $t('admin.emailSentAt') },
  { accessorKey: 'toEmail', header: $t('admin.emailTo') },
  { accessorKey: 'subject', header: $t('admin.emailSubject') },
  { accessorKey: 'category', header: $t('admin.emailCategory') },
  { accessorKey: 'status', header: $t('admin.emailStatus') },
  { accessorKey: 'actions', header: '' },
]

const categoryOptions = computed(() => [
  { label: $t('admin.emailAllCategories'), value: 'all' },
  { label: $t('admin.emailCategoryInvoice'), value: 'invoice' },
  { label: $t('admin.emailCategoryReceipt'), value: 'receipt' },
  { label: $t('admin.emailCategoryDeliveryNote'), value: 'delivery_note' },
  { label: $t('admin.emailCategoryWelcome'), value: 'welcome' },
  { label: $t('admin.emailCategoryEmailConfirmation'), value: 'email_confirmation' },
  { label: $t('admin.emailCategoryPasswordReset'), value: 'password_reset' },
  { label: $t('admin.emailCategoryInvitation'), value: 'invitation' },
  { label: $t('admin.emailCategoryDunning'), value: 'dunning' },
  { label: $t('admin.emailCategoryTrialExpiration'), value: 'trial_expiration' },
  { label: $t('admin.emailCategoryReEngagement'), value: 're_engagement' },
  { label: $t('admin.emailCategoryNotification'), value: 'notification' },
  { label: $t('admin.emailCategoryContact'), value: 'contact' },
])

const statusOptions = computed(() => [
  { label: $t('admin.emailAllStatuses'), value: 'all' },
  { label: $t('emailStatus.sent'), value: 'sent' },
  { label: $t('emailStatus.delivered'), value: 'delivered' },
  { label: $t('emailStatus.bounced'), value: 'bounced' },
  { label: $t('emailStatus.failed'), value: 'failed' },
])

function statusColor(status: string): string {
  switch (status) {
    case 'sent': return 'info'
    case 'delivered': return 'success'
    case 'bounced': return 'warning'
    case 'failed': return 'error'
    default: return 'neutral'
  }
}

function categoryColor(category: string | null): string {
  if (!category) return 'neutral'
  switch (category) {
    case 'invoice':
    case 'receipt':
    case 'delivery_note':
      return 'info'
    case 'welcome':
    case 'email_confirmation':
      return 'success'
    case 'password_reset':
      return 'warning'
    case 'dunning':
    case 'trial_expiration':
      return 'error'
    case 'invitation':
    case 're_engagement':
    case 'notification':
      return 'neutral'
    case 'contact':
      return 'info'
    default:
      return 'neutral'
  }
}

const categoryLabelMap: Record<string, string> = {
  invoice: 'emailCategoryInvoice',
  receipt: 'emailCategoryReceipt',
  delivery_note: 'emailCategoryDeliveryNote',
  welcome: 'emailCategoryWelcome',
  email_confirmation: 'emailCategoryEmailConfirmation',
  password_reset: 'emailCategoryPasswordReset',
  invitation: 'emailCategoryInvitation',
  dunning: 'emailCategoryDunning',
  trial_expiration: 'emailCategoryTrialExpiration',
  re_engagement: 'emailCategoryReEngagement',
  notification: 'emailCategoryNotification',
  contact: 'emailCategoryContact',
}

function categoryLabel(category: string | null): string {
  if (!category) return '-'
  const key = categoryLabelMap[category]
  return key ? $t(`admin.${key}`) : category
}

function formatDate(iso: string | null): string {
  if (!iso) return '-'
  return new Date(iso).toLocaleString('ro-RO', { dateStyle: 'short', timeStyle: 'short' })
}

function eventTypeColor(type: string): string {
  switch (type) {
    case 'send': return 'info'
    case 'delivery': return 'success'
    case 'bounce': return 'warning'
    case 'complaint': return 'error'
    case 'reject': return 'error'
    case 'open': return 'neutral'
    case 'click': return 'neutral'
    default: return 'neutral'
  }
}

async function fetchLogs() {
  loading.value = true
  try {
    const { get } = useApi()
    const params: Record<string, any> = {
      page: page.value,
      limit: limit.value,
    }
    if (search.value) params.search = search.value
    if (categoryFilter.value !== 'all') params.category = categoryFilter.value
    if (statusFilter.value !== 'all') params.status = statusFilter.value

    const data = await get<any>('/v1/admin/email-logs', params)
    logs.value = data.data || []
    total.value = data.total || 0
  } catch {
    // Not authorized or error
  } finally {
    loading.value = false
  }
}

async function openDetail(log: any) {
  detailLoading.value = true
  selectedLog.value = log
  try {
    const { get } = useApi()
    const detail = await get<any>(`/v1/admin/email-logs/${log.id}`)
    selectedLog.value = detail
  } catch {
    // Keep list data
  } finally {
    detailLoading.value = false
  }
}

const onSearchInput = useDebounceFn(() => {
  page.value = 1
  fetchLogs()
}, 300)

watch(categoryFilter, () => {
  page.value = 1
  fetchLogs()
})

watch(statusFilter, () => {
  page.value = 1
  fetchLogs()
})

watch(page, () => fetchLogs())

onMounted(() => fetchLogs())
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
    <UPageHeader :title="$t('admin.emailLogs')" :description="$t('admin.emailLogsDescription')" />

    <div class="flex items-center gap-3 mb-6">
      <UButton icon="i-lucide-arrow-left" variant="ghost" to="/admin" />
      <h1 class="text-2xl font-bold">{{ $t('admin.emailLogs') }}</h1>
    </div>

    <UDashboardToolbar class="mb-4">
      <template #right>
        <USelectMenu v-model="categoryFilter" :items="categoryOptions" value-key="value" :placeholder="$t('admin.emailCategory')" class="w-44" />
        <USelectMenu v-model="statusFilter" :items="statusOptions" value-key="value" :placeholder="$t('admin.emailStatus')" class="w-36" />
        <UInput v-model="search" :placeholder="$t('common.search')" icon="i-lucide-search" class="w-64" @update:model-value="onSearchInput" />
      </template>
    </UDashboardToolbar>

    <UTable :data="logs" :columns="columns" :loading="loading">
      <template #sentAt-cell="{ row }">
        <span class="text-sm text-muted">{{ formatDate(row.original.sentAt) }}</span>
      </template>

      <template #toEmail-cell="{ row }">
        <span class="text-sm">{{ row.original.toEmail }}</span>
      </template>

      <template #subject-cell="{ row }">
        <span class="text-sm truncate max-w-64 inline-block">{{ row.original.subject }}</span>
      </template>

      <template #category-cell="{ row }">
        <UBadge v-if="row.original.category" :color="categoryColor(row.original.category)" variant="subtle" size="sm">
          {{ categoryLabel(row.original.category) }}
        </UBadge>
        <span v-else class="text-muted text-sm">-</span>
      </template>

      <template #status-cell="{ row }">
        <UBadge :color="statusColor(row.original.status)" variant="subtle" size="sm">
          {{ $t(`emailStatus.${row.original.status}`) }}
        </UBadge>
      </template>

      <template #actions-cell="{ row }">
        <UButton
          icon="i-lucide-eye"
          variant="ghost"
          size="sm"
          @click="openDetail(row.original)"
        />
      </template>
    </UTable>

    <div v-if="!loading && !logs.length" class="text-center py-8 text-muted">
      {{ $t('common.noData') }}
    </div>

    <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
      <span class="text-sm text-muted">
        {{ $t('common.showing') }} {{ logs.length }} {{ $t('common.of') }} {{ total }}
      </span>
      <UPagination v-model:page="page" :total="total" :items-per-page="limit" />
    </div>

    <UModal v-model:open="showModal">
      <template #content>
        <UCard>
          <template #header>
            <div class="flex items-center justify-between">
              <h3 class="text-lg font-semibold">{{ $t('admin.emailSubject') }}</h3>
              <UButton icon="i-lucide-x" variant="ghost" size="sm" @click="selectedLog = null" />
            </div>
          </template>

          <div v-if="selectedLog" class="space-y-4">
            <div class="grid grid-cols-2 gap-2 text-sm">
              <div class="text-muted">{{ $t('admin.emailTo') }}</div>
              <div>{{ selectedLog.toEmail }}</div>

              <div class="text-muted">{{ $t('admin.emailSubject') }}</div>
              <div>{{ selectedLog.subject }}</div>

              <div class="text-muted">{{ $t('admin.emailFrom') }}</div>
              <div>{{ selectedLog.fromName ? `${selectedLog.fromName} <${selectedLog.fromEmail}>` : selectedLog.fromEmail || '-' }}</div>

              <div class="text-muted">{{ $t('admin.emailCategory') }}</div>
              <div>
                <UBadge v-if="selectedLog.category" :color="categoryColor(selectedLog.category)" variant="subtle" size="sm">
                  {{ categoryLabel(selectedLog.category) }}
                </UBadge>
                <span v-else>-</span>
              </div>

              <div class="text-muted">{{ $t('admin.emailStatus') }}</div>
              <div>
                <UBadge :color="statusColor(selectedLog.status)" variant="subtle" size="sm">
                  {{ $t(`emailStatus.${selectedLog.status}`) }}
                </UBadge>
              </div>

              <div class="text-muted">{{ $t('admin.emailSentAt') }}</div>
              <div>{{ formatDate(selectedLog.sentAt) }}</div>

              <template v-if="selectedLog.ccEmails?.length">
                <div class="text-muted">CC</div>
                <div>{{ selectedLog.ccEmails.join(', ') }}</div>
              </template>

              <template v-if="selectedLog.bccEmails?.length">
                <div class="text-muted">BCC</div>
                <div>{{ selectedLog.bccEmails.join(', ') }}</div>
              </template>

              <template v-if="selectedLog.sesMessageId">
                <div class="text-muted">{{ $t('admin.emailSesMessageId') }}</div>
                <div class="font-mono text-xs break-all">{{ selectedLog.sesMessageId }}</div>
              </template>
            </div>

            <div v-if="selectedLog.events?.length">
              <div class="text-sm font-medium mb-2">{{ $t('admin.emailEvents') }}</div>
              <div class="space-y-2">
                <div v-for="event in selectedLog.events" :key="event.id" class="flex items-center gap-2 text-sm border border-default rounded-lg p-2">
                  <UBadge :color="eventTypeColor(event.eventType)" variant="subtle" size="sm">
                    {{ $t(`emailEventType.${event.eventType}`) }}
                  </UBadge>
                  <span class="text-muted text-xs">{{ formatDate(event.timestamp) }}</span>
                  <span v-if="event.bounceType" class="text-xs text-error">{{ event.bounceType }} / {{ event.bounceSubType }}</span>
                  <span v-if="event.eventDetail" class="text-xs text-muted truncate">{{ event.eventDetail }}</span>
                </div>
              </div>
            </div>

            <div v-if="detailLoading" class="text-center py-4 text-muted text-sm">
              {{ $t('common.loading') }}
            </div>
          </div>
        </UCard>
      </template>
    </UModal>

    </template>
  </UDashboardPanel>
</template>
