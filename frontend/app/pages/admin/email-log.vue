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
    <UPageHeader :title="$t('admin.emailLog.title')" :description="$t('admin.emailLog.description')" />

    <div class="flex items-center gap-3 mb-6">
      <UButton icon="i-lucide-arrow-left" variant="ghost" to="/admin" />
      <h1 class="text-2xl font-bold">{{ $t('admin.emailLog.title') }}</h1>
    </div>

    <UDashboardToolbar class="mb-4">
      <template #right>
        <UInput
          v-model="recipientEmail"
          :placeholder="$t('admin.emailLog.filters.recipientEmail')"
          icon="i-lucide-mail"
          class="w-56"
          @update:model-value="onSearchInput"
        />
        <USelectMenu
          v-model="categoryFilter"
          :items="categoryOptions"
          value-key="value"
          :placeholder="$t('admin.emailLog.filters.category')"
          class="w-52"
          @update:model-value="onFilterChange"
        />
        <USelectMenu
          v-model="statusFilter"
          :items="statusOptions"
          value-key="value"
          :placeholder="$t('admin.emailLog.filters.status')"
          class="w-44"
          @update:model-value="onFilterChange"
        />
        <UInput
          v-model="dateFrom"
          type="date"
          :placeholder="$t('admin.emailLog.filters.dateFrom')"
          class="w-36"
          @update:model-value="onFilterChange"
        />
        <UInput
          v-model="dateTo"
          type="date"
          :placeholder="$t('admin.emailLog.filters.dateTo')"
          class="w-36"
          @update:model-value="onFilterChange"
        />
        <UButton variant="outline" @click="resetFilters">
          {{ $t('admin.emailLog.filters.reset') }}
        </UButton>
      </template>
    </UDashboardToolbar>

    <UTable :data="logs" :columns="columns" :loading="loading">
      <template #sentAt-cell="{ row }">
        <span class="text-sm text-muted tabular-nums">{{ formatDate(row.original.sentAt) }}</span>
      </template>

      <template #category-cell="{ row }">
        <UBadge :color="categoryColor(row.original.category)" variant="subtle" size="sm">
          {{ $t(`admin.emailLog.categories.${row.original.category}`) }}
        </UBadge>
      </template>

      <template #templateUsed-cell="{ row }">
        <UBadge v-if="row.original.templateUsed === 'skipped_gate'" color="warning" variant="subtle" size="sm">
          {{ row.original.templateUsed }}
        </UBadge>
        <span v-else-if="row.original.templateUsed" class="text-sm font-mono text-muted">{{ row.original.templateUsed }}</span>
        <span v-else class="text-muted text-sm">-</span>
      </template>

      <template #recipientEmail-cell="{ row }">
        <span class="text-sm">{{ row.original.recipientEmail }}</span>
      </template>

      <template #subject-cell="{ row }">
        <UTooltip v-if="row.original.subject" :text="row.original.subject">
          <span class="text-sm truncate max-w-60 inline-block">{{ truncate(row.original.subject, 60) }}</span>
        </UTooltip>
        <span v-else class="text-muted text-sm">-</span>
      </template>

      <template #status-cell="{ row }">
        <UBadge :color="statusColor(row.original.status)" variant="subtle" size="sm">
          {{ $t(`admin.emailLog.statuses.${row.original.status}`) }}
        </UBadge>
      </template>

      <template #errorMessage-cell="{ row }">
        <UTooltip v-if="row.original.errorMessage" :text="row.original.errorMessage">
          <span class="text-sm text-error truncate max-w-48 inline-block cursor-help">{{ row.original.errorMessage }}</span>
        </UTooltip>
        <span v-else class="text-muted text-sm">-</span>
      </template>

      <template #actions-cell="{ row }">
        <UButton
          icon="i-lucide-eye"
          variant="ghost"
          size="sm"
          @click="openSlideover(row.original)"
        />
      </template>
    </UTable>

    <div v-if="!loading && !logs.length" class="flex flex-col items-center justify-center py-12 gap-2 text-muted">
      <UIcon name="i-lucide-mail-x" class="size-8 mb-1" />
      <span class="text-sm">{{ $t('admin.emailLog.empty') }}</span>
    </div>

    <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
      <span class="text-sm text-muted">
        {{ $t('common.showing') }} {{ logs.length }} {{ $t('common.of') }} {{ total }}
      </span>
      <UPagination v-model:page="page" :total="total" :items-per-page="limit" />
    </div>

    <USlideover v-model:open="slideoverOpen">
      <template #header>
        <h3 class="text-base font-semibold">{{ $t('admin.emailLog.slideoverTitle') }}</h3>
      </template>
      <template #body>
        <pre v-if="selectedLog" class="text-xs font-mono whitespace-pre-wrap break-all p-4 bg-elevated rounded-lg">{{ JSON.stringify(selectedLog, null, 2) }}</pre>
      </template>
    </USlideover>

    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

interface EmailLogEntry {
  id: string
  recipientEmail: string
  userId: string | null
  organizationId: string | null
  category: string
  templateUsed: string | null
  subject: string | null
  status: 'queued' | 'sent' | 'failed' | 'skipped_unsubscribed' | 'skipped_pref' | 'skipped_test'
  errorMessage: string | null
  sentAt: string | null
}

interface EmailLogResponse {
  data: EmailLogEntry[]
  total: number
  page: number
  limit: number
}

const { t: $t } = useI18n()
const intlLocale = useIntlLocale()

const logs = ref<EmailLogEntry[]>([])
const loading = ref(true)
const recipientEmail = ref('')
const categoryFilter = ref<string>('all')
const statusFilter = ref<string>('all')
const dateFrom = ref('')
const dateTo = ref('')
const page = ref(1)
const total = ref(0)
const limit = ref(50)
const selectedLog = ref<EmailLogEntry | null>(null)
const slideoverOpen = ref(false)

const columns = [
  { accessorKey: 'sentAt', header: $t('admin.emailLog.columns.sentAt') },
  { accessorKey: 'category', header: $t('admin.emailLog.columns.category') },
  { accessorKey: 'templateUsed', header: $t('admin.emailLog.columns.templateUsed') },
  { accessorKey: 'recipientEmail', header: $t('admin.emailLog.columns.recipientEmail') },
  { accessorKey: 'subject', header: $t('admin.emailLog.columns.subject') },
  { accessorKey: 'status', header: $t('admin.emailLog.columns.status') },
  { accessorKey: 'errorMessage', header: $t('admin.emailLog.columns.errorMessage') },
  { accessorKey: 'actions', header: '' },
]

const categoryOptions = computed(() => [
  { label: $t('admin.emailLog.filters.all'), value: 'all' },
  { label: $t('admin.emailLog.categories.re_engagement'), value: 're_engagement' },
  { label: $t('admin.emailLog.categories.trial_expiration'), value: 'trial_expiration' },
  { label: $t('admin.emailLog.categories.dunning'), value: 'dunning' },
  { label: $t('admin.emailLog.categories.account_without_login'), value: 'account_without_login' },
  { label: $t('admin.emailLog.categories.first_company_created'), value: 'first_company_created' },
  { label: $t('admin.emailLog.categories.first_invoice_created'), value: 'first_invoice_created' },
  { label: $t('admin.emailLog.categories.trial_ended'), value: 'trial_ended' },
  { label: $t('admin.emailLog.categories.feature_drip'), value: 'feature_drip' },
  { label: $t('admin.emailLog.categories.welcome'), value: 'welcome' },
  { label: $t('admin.emailLog.categories.email_confirmation'), value: 'email_confirmation' },
  { label: $t('admin.emailLog.categories.invoice_email'), value: 'invoice_email' },
  { label: $t('admin.emailLog.categories.subscription_confirmation'), value: 'subscription_confirmation' },
  { label: $t('admin.emailLog.categories.subscription_renewal'), value: 'subscription_renewal' },
  { label: $t('admin.emailLog.categories.subscription_cancelled'), value: 'subscription_cancelled' },
])

const statusOptions = computed(() => [
  { label: $t('admin.emailLog.filters.all'), value: 'all' },
  { label: $t('admin.emailLog.statuses.queued'), value: 'queued' },
  { label: $t('admin.emailLog.statuses.sent'), value: 'sent' },
  { label: $t('admin.emailLog.statuses.failed'), value: 'failed' },
  { label: $t('admin.emailLog.statuses.skipped_unsubscribed'), value: 'skipped_unsubscribed' },
  { label: $t('admin.emailLog.statuses.skipped_pref'), value: 'skipped_pref' },
  { label: $t('admin.emailLog.statuses.skipped_test'), value: 'skipped_test' },
])

function statusColor(status: string): string {
  switch (status) {
    case 'sent': return 'success'
    case 'failed': return 'error'
    case 'queued': return 'info'
    case 'skipped_unsubscribed':
    case 'skipped_pref':
    case 'skipped_test': return 'warning'
    default: return 'neutral'
  }
}

function categoryColor(category: string): string {
  switch (category) {
    case 'dunning':
    case 'trial_expiration':
    case 'trial_ended': return 'error'
    case 'welcome':
    case 'first_company_created':
    case 'first_invoice_created': return 'success'
    case 're_engagement':
    case 'account_without_login':
    case 'feature_drip': return 'warning'
    case 'subscription_confirmation':
    case 'subscription_renewal':
    case 'subscription_cancelled': return 'info'
    default: return 'neutral'
  }
}

function formatDate(iso: string | null): string {
  if (!iso) return '-'
  return new Intl.DateTimeFormat(intlLocale, {
    timeZone: 'Europe/Bucharest',
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(new Date(iso))
}

function truncate(text: string, max: number): string {
  return text.length > max ? `${text.slice(0, max)}…` : text
}

function openSlideover(entry: EmailLogEntry) {
  selectedLog.value = entry
  slideoverOpen.value = true
}

async function fetchLogs() {
  loading.value = true
  try {
    const { get } = useApi()
    const params: Record<string, string | number> = {
      page: page.value,
      limit: limit.value,
    }
    if (recipientEmail.value) params.recipientEmail = recipientEmail.value
    if (categoryFilter.value !== 'all') params.category = categoryFilter.value
    if (statusFilter.value !== 'all') params.status = statusFilter.value
    if (dateFrom.value) params.dateFrom = dateFrom.value
    if (dateTo.value) params.dateTo = dateTo.value

    const data = await get<EmailLogResponse>('/v1/admin/email-log', params)
    logs.value = data.data ?? []
    total.value = data.total ?? 0
  }
  catch {
  }
  finally {
    loading.value = false
  }
}

const onSearchInput = useDebounceFn(() => {
  page.value = 1
  fetchLogs()
}, 300)

function onFilterChange() {
  page.value = 1
  fetchLogs()
}

function resetFilters() {
  recipientEmail.value = ''
  categoryFilter.value = 'all'
  statusFilter.value = 'all'
  dateFrom.value = ''
  dateTo.value = ''
  page.value = 1
  fetchLogs()
}

watch(page, () => fetchLogs())

onMounted(() => fetchLogs())
</script>
