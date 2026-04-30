<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('efactura.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <template v-if="activeTab === 'sync'">
            <UButton
              v-if="showActivateSync"
              icon="i-lucide-power"
              color="warning"
              variant="soft"
              :loading="activatingSync"
              @click="activateSync"
            >
              {{ $t('efactura.activateSync') }}
            </UButton>
            <UButton icon="i-lucide-refresh-cw" :loading="syncing" :disabled="!syncStore.canSync" @click="triggerManualSync">
              {{ $t('efactura.syncNow') }}
            </UButton>
          </template>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="pt-4 px-4">
        <UTabs v-model="activeTab" :items="tabItems" />
      </div>

      <div v-if="activeTab === 'sync'" class="py-6 space-y-6">
      <!-- ANAF platform stats -->
      <EfacturaAnafStats />

      <!-- Sync progress with live stats -->
      <UCard v-if="syncStore.syncProgress">
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="font-semibold">{{ $t('efactura.syncProgressTitle') }}</h3>
            <span class="text-sm text-muted">{{ syncStore.syncProgress.processed }} / {{ syncStore.syncProgress.total }}</span>
          </div>
          <UProgress
            :value="syncStore.syncProgress.processed"
            :max="syncStore.syncProgress.total"
            size="sm"
          />
          <div v-if="syncStore.syncProgress.stats" class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-1">
            <div class="text-center">
              <div class="text-lg font-bold">{{ syncStore.syncProgress.stats.newInvoices }}</div>
              <div class="text-xs text-muted">{{ $t('efactura.newInvoices') }}</div>
            </div>
            <div class="text-center">
              <div class="text-lg font-bold">{{ syncStore.syncProgress.stats.newClients }}</div>
              <div class="text-xs text-muted">{{ $t('efactura.newClients') }}</div>
            </div>
            <div class="text-center">
              <div class="text-lg font-bold">{{ syncStore.syncProgress.stats.skippedDuplicates }}</div>
              <div class="text-xs text-muted">{{ $t('efactura.skippedDuplicates') }}</div>
            </div>
            <div class="text-center">
              <div class="text-lg font-bold">{{ syncStore.syncProgress.stats.newProducts }}</div>
              <div class="text-xs text-muted">{{ $t('efactura.newProducts') }}</div>
            </div>
          </div>
        </div>
      </UCard>
      <!-- Persistent error banners -->
      <UAlert
        v-if="syncStore.lastSyncError"
        icon="i-lucide-alert-circle"
        color="error"
        :title="$t('efactura.syncError')"
        :description="syncStore.lastSyncError.message"
        close
        @update:open="syncStore.clearSyncError()"
      />
      <UAlert
        v-else-if="!syncStore.hasValidToken && syncStore.tokenError"
        icon="i-lucide-key-round"
        color="warning"
        :title="$t('efactura.tokenInvalid')"
        :description="syncStore.tokenError"
      />
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <template v-if="syncStore.loading && !syncStore.syncStatus">
          <UCard v-for="i in 3" :key="i">
            <USkeleton class="w-24 h-4 mb-2" />
            <USkeleton class="w-32 h-6" />
          </UCard>
        </template>
        <template v-else>
          <UCard>
            <div class="text-sm text-muted">{{ $t('efactura.lastSync') }}</div>
            <div class="text-xl font-bold mt-1">
              {{ formatDate(syncStore.lastSyncedAt) }}
            </div>
            <div v-if="syncStore.nextSyncAt" class="text-xs text-muted mt-2 flex items-center gap-1">
              <UIcon name="i-lucide-clock" class="size-3" />
              {{ $t('efactura.nextSync') }}: {{ formatRelativeTime(syncStore.nextSyncAt) }}
            </div>
          </UCard>
          <UCard>
            <div class="text-sm text-muted">{{ $t('efactura.syncStatus') }}</div>
            <div class="mt-2">
              <UBadge :color="syncStore.isSyncEnabled ? 'success' : 'neutral'" variant="subtle">
                {{ syncStore.isSyncEnabled ? $t('common.enabled') : $t('common.disabled') }}
              </UBadge>
            </div>
          </UCard>
          <UCard>
            <div class="text-sm text-muted">{{ $t('efactura.tokenStatus') }}</div>
            <div class="mt-2 flex items-center justify-between gap-2">
              <UBadge :color="syncStore.hasValidToken ? 'success' : 'error'" variant="subtle">
                {{ syncStore.hasValidToken ? $t('common.valid') : $t('common.invalid') }}
              </UBadge>
              <UButton
                v-if="!syncStore.hasValidToken && companyStore.currentCompanyId"
                icon="i-lucide-link"
                size="xs"
                color="error"
                variant="soft"
                :to="`/companies/${companyStore.currentCompanyId}/anaf`"
              >
                {{ $t('companies.connectAnaf') }}
              </UButton>
            </div>
            <p v-if="syncStore.tokenError" class="text-xs text-error mt-2">
              {{ syncStore.tokenError }}
            </p>
          </UCard>
        </template>
      </div>

      <UCard v-if="syncStore.lastSyncResult">
        <template #header>
          <h3 class="font-semibold">{{ $t('efactura.lastSyncResult') }}</h3>
        </template>
        <div class="space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-sm text-muted">{{ $t('efactura.newInvoices') }}</span>
            <span class="font-medium">{{ syncStore.lastSyncResult.stats.newInvoices }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-muted">{{ $t('efactura.newClients') }}</span>
            <span class="font-medium">{{ syncStore.lastSyncResult.stats.newClients }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-muted">{{ $t('efactura.skippedDuplicates') }}</span>
            <span class="font-medium">{{ syncStore.lastSyncResult.stats.skippedDuplicates }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-muted">{{ $t('efactura.newProducts') }}</span>
            <span class="font-medium">{{ syncStore.lastSyncResult.stats.newProducts }}</span>
          </div>
          <div v-if="syncStore.lastSyncHadErrors" class="flex items-center justify-between">
            <span class="text-sm text-error">{{ $t('efactura.errorsLabel') }}</span>
            <span class="font-medium text-error">{{ syncStore.lastSyncResult.stats.errors.length }}</span>
          </div>
        </div>
      </UCard>

      <UCard>
        <template #header>
          <div class="flex items-center justify-between gap-2 flex-wrap">
            <h3 class="font-semibold">{{ $t('efactura.syncLog') }}</h3>
            <div class="flex items-center gap-1.5 flex-wrap">
              <UInput
                v-model="syncStore.syncLogSearch"
                :placeholder="$t('common.search')"
                icon="i-lucide-search"
                size="sm"
                class="w-48"
                @update:model-value="onLogSearch"
              />

              <UPopover>
                <UButton
                  icon="i-lucide-filter"
                  color="neutral"
                  variant="outline"
                  size="sm"
                  :label="logFilterCount ? $t('suppliers.filters.title') + ' · ' + logFilterCount : $t('suppliers.filters.title')"
                />
                <template #content>
                  <div class="p-3 min-w-64 space-y-3">
                    <div>
                      <p class="text-xs font-semibold text-muted mb-1.5">{{ $t('invoices.direction') }}</p>
                      <USelectMenu v-model="logDirectionSel" :items="logDirectionOptions" value-key="value" class="w-full" @update:model-value="onLogFilterChange" />
                    </div>
                    <div>
                      <p class="text-xs font-semibold text-muted mb-1.5">{{ $t('common.status') }}</p>
                      <USelectMenu v-model="logStatusSel" :items="logStatusOptions" value-key="value" class="w-full" @update:model-value="onLogFilterChange" />
                    </div>
                    <div v-if="logFilterCount" class="pt-1 border-t border-default">
                      <UButton block variant="ghost" size="xs" icon="i-lucide-x" :label="$t('suppliers.filters.clear')" @click="clearLogFilters" />
                    </div>
                  </div>
                </template>
              </UPopover>

              <UButton variant="ghost" size="sm" icon="i-lucide-alert-circle" @click="openErrors">
                {{ $t('efactura.viewErrors') }}
              </UButton>
            </div>
          </div>
        </template>
        <div class="space-y-4">
          <template v-if="syncStore.loading">
            <div v-for="i in 5" :key="i" class="flex items-start gap-4 pb-4 border-b last:border-b-0">
              <USkeleton class="w-2 h-2 rounded-full mt-1 shrink-0" />
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                  <USkeleton class="w-24 h-4" />
                  <USkeleton class="w-6 h-4 rounded" />
                  <USkeleton class="w-14 h-4 rounded" />
                </div>
                <USkeleton class="w-40 h-4 mt-1" />
                <USkeleton class="w-28 h-3 mt-1" />
              </div>
              <USkeleton class="w-20 h-4 shrink-0" />
            </div>
          </template>
          <template v-else>
            <div
              v-for="entry in syncStore.syncLog"
              :key="entry.id"
              class="flex items-start gap-4 pb-4 border-b last:border-b-0"
            >
              <div class="flex-shrink-0 mt-1">
                <div class="w-2 h-2 rounded-full" :class="statusDotColor(entry.status)" />
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                  <NuxtLink
                    :to="`/invoices/${entry.id}`"
                    class="font-medium hover:text-primary"
                  >
                    {{ entry.number }}
                  </NuxtLink>
                  <UBadge
                    :color="entry.direction === 'incoming' ? 'blue' : 'green'"
                    variant="subtle"
                    size="xs"
                  >
                    {{ entry.direction === 'incoming' ? '↓' : '↑' }}
                  </UBadge>
                  <UBadge :color="statusColor(entry.status)" variant="subtle" size="xs">
                    {{ $t(`documentStatus.${entry.status}`) }}
                  </UBadge>
                </div>
                <div class="text-sm text-muted">
                  {{ entry.senderName || entry.receiverName }}
                </div>
                <div class="text-xs text-muted mt-1">
                  {{ formatDate(entry.syncedAt) }}
                </div>
              </div>
              <div class="flex-shrink-0 font-medium">
                {{ formatMoney(entry.total, entry.currency) }}
              </div>
            </div>
            <div v-if="syncStore.hasMoreLog" ref="scrollSentinel" class="flex justify-center py-4">
              <UButton variant="ghost" size="sm" :loading="syncStore.syncLogLoading" @click="syncStore.fetchMoreLog()">
                {{ $t('common.loadMore') }}
              </UButton>
            </div>
            <div v-if="!syncStore.syncLog.length" class="text-muted text-center py-8">
              {{ $t('common.noData') }}
            </div>
          </template>
        </div>
      </UCard>
      </div>

      <div v-if="activeTab === 'messages'" class="py-6">
        <EfacturaSpvMessages />
      </div>
    </template>
  </UDashboardPanel>

  <!-- Rejected invoices slideover -->
  <USlideover v-model:open="errorsOpen" :ui="{ content: 'sm:max-w-2xl' }">
    <template #header>
      <h3 class="font-semibold text-lg">{{ $t('efactura.errors.title') }}</h3>
    </template>
    <template #body>
      <div v-if="errorsLoading" class="flex items-center justify-center py-12">
        <UIcon name="i-lucide-loader-2" class="animate-spin text-2xl text-(--ui-text-muted)" />
      </div>
      <div v-else-if="rejectedInvoices.length === 0" class="text-center py-12">
        <UIcon name="i-lucide-check-circle" class="text-4xl text-success mb-3" />
        <p class="text-muted">{{ $t('efactura.errors.noErrors') }}</p>
      </div>
      <div v-else class="space-y-3">
        <div
          v-for="invoice in rejectedInvoices"
          :key="invoice.id"
          class="rounded-lg border border-error/20 bg-error/5 p-4"
        >
          <div class="flex items-center justify-between mb-2">
            <NuxtLink
              :to="`/invoices/${invoice.id}`"
              class="font-medium hover:text-primary"
              @click="errorsOpen = false"
            >
              {{ invoice.number }}
            </NuxtLink>
            <span class="text-xs text-muted">{{ formatDate(invoice.issueDate) }}</span>
          </div>
          <div class="text-sm text-muted mb-2">
            {{ invoice.senderName || invoice.receiverName || '-' }}
          </div>
          <div class="text-sm text-error flex items-start gap-2">
            <UIcon name="i-lucide-alert-circle" class="shrink-0 mt-0.5" />
            <span class="whitespace-pre-line">{{ invoice.anafErrorMessage || '-' }}</span>
          </div>
        </div>
      </div>
    </template>
  </USlideover>
</template>

<script setup lang="ts">
import { storeToRefs } from 'pinia'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('efactura.title') })
const route = useRoute()
const syncStore = useSyncStore()
const companyStore = useCompanyStore()
const { syncing } = storeToRefs(syncStore)

const { isModuleEnabled, MODULE_KEYS } = useModules()
const activeTab = ref(route.query.tab === 'messages' ? 'messages' : 'sync')
const tabItems = computed(() => {
  const items = [
    { label: $t('efactura.tabSync'), value: 'sync' },
  ]
  if (isModuleEnabled(MODULE_KEYS.SPV_MESSAGES)) {
    items.push({ label: $t('efactura.tabMessages'), value: 'messages' })
  }
  return items
})

const intlLocale = useIntlLocale()

function formatDate(date: string | null): string {
  if (!date) return $t('common.never')
  return new Intl.DateTimeFormat(intlLocale, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(date))
}

function formatRelativeTime(dateStr: string): string {
  const target = new Date(dateStr)
  const now = new Date()
  const diff = target.getTime() - now.getTime()

  if (diff <= 0) return $t('efactura.syncAvailableNow')

  const minutes = Math.ceil(diff / 60000)
  if (minutes < 60) return `${minutes} min`
  const hours = Math.floor(minutes / 60)
  const remainingMinutes = minutes % 60
  if (remainingMinutes === 0) return `${hours}h`
  return `${hours}h ${remainingMinutes}min`
}

function formatMoney(amount: string | number, currency = 'RON'): string {
  const num = Number(amount || 0)
  return new Intl.NumberFormat(intlLocale, { style: 'currency', currency }).format(num)
}

function statusColor(status: string): string {
  const colors: Record<string, string> = {
    synced: 'info',
    validated: 'success',
    rejected: 'error',
    draft: 'neutral',
    issued: 'info',
    sent_to_provider: 'warning',
    paid: 'success',
    overdue: 'error',
    cancelled: 'neutral',
  }
  return colors[status] || 'neutral'
}

function statusDotColor(status: string): string {
  const colors: Record<string, string> = {
    synced: 'bg-info',
    validated: 'bg-success',
    rejected: 'bg-error',
    draft: 'bg-neutral',
    issued: 'bg-info',
    sent_to_provider: 'bg-warning',
    paid: 'bg-success',
    overdue: 'bg-error',
    cancelled: 'bg-neutral',
  }
  return colors[status] || 'bg-neutral'
}

async function triggerManualSync() {
  await syncStore.triggerSync()
}

const activatingSync = ref(false)
const showActivateSync = computed(() =>
  !syncStore.loading && syncStore.syncStatus !== null && !syncStore.isSyncEnabled,
)

async function activateSync() {
  const companyId = companyStore.currentCompanyId
  if (!companyId) return
  activatingSync.value = true
  const { post } = useApi()
  try {
    const response = await post<{ syncEnabled: boolean }>(`/v1/companies/${companyId}/toggle-sync`)
    const company = companyStore.companies.find(c => c.id === companyId)
    if (company) company.syncEnabled = response.syncEnabled
    if (response.syncEnabled) {
      useToast().add({ title: $t('companies.syncEnabledSuccess'), color: 'success' })
      await syncStore.fetchStatus()
    }
  }
  catch (err: any) {
    const messageKey = err?.data?.messageKey
    const message = err?.data?.error
      ? translateApiError(err.data.error)
      : $t('companies.toggleSyncError')
    if (messageKey === 'error.sync.enable_no_token') {
      useToast().add({
        title: message,
        color: 'error',
        actions: [
          {
            label: $t('companies.connectAnaf'),
            color: 'error',
            variant: 'solid',
            onClick: () => navigateTo(`/companies/${companyId}/anaf`),
          },
        ],
      })
    }
    else {
      useToast().add({ title: message, color: 'error' })
    }
  }
  finally {
    activatingSync.value = false
  }
}

// Rejected invoices slideover
const errorsOpen = ref(false)
const errorsLoading = ref(false)
const rejectedInvoices = ref<any[]>([])

async function fetchRejectedInvoices() {
  const { get } = useApi()
  errorsLoading.value = true
  try {
    const response = await get<{ data: any[] }>('/v1/invoices', { status: 'rejected' })
    rejectedInvoices.value = response.data
  } catch {
    useToast().add({ title: $t('efactura.errors.fetchError'), color: 'error' })
  } finally {
    errorsLoading.value = false
  }
}

function openErrors() {
  errorsOpen.value = true
  fetchRejectedInvoices()
}

// ── Sync log filters ───────────────────────────────────────────────
const logDirectionOptions = computed(() => [
  { value: 'all', label: $t('efactura.filters.directionAll') },
  { value: 'incoming', label: $t('common.incoming') },
  { value: 'outgoing', label: $t('common.outgoing') },
])

const logStatusOptions = computed(() => [
  { value: 'all', label: $t('efactura.filters.statusAll') },
  { value: 'synced', label: $t('documentStatus.synced') },
  { value: 'validated', label: $t('documentStatus.validated') },
  { value: 'rejected', label: $t('documentStatus.rejected') },
  { value: 'paid', label: $t('documentStatus.paid') },
])

const logDirectionSel = computed({
  get: () => syncStore.syncLogDirection || 'all',
  set: (v: string) => { syncStore.syncLogDirection = v === 'all' ? '' : v as any },
})
const logStatusSel = computed({
  get: () => syncStore.syncLogStatus || 'all',
  set: (v: string) => { syncStore.syncLogStatus = v === 'all' ? '' : v },
})

const logFilterCount = computed(() =>
  [syncStore.syncLogDirection, syncStore.syncLogStatus].filter(Boolean).length,
)

function onLogFilterChange() {
  syncStore.fetchLog()
}

function clearLogFilters() {
  syncStore.syncLogDirection = ''
  syncStore.syncLogStatus = ''
  onLogFilterChange()
}

const onLogSearch = useDebounceFn(() => {
  syncStore.fetchLog()
}, 300)

const syncRealtime = useSyncRealtime()

// Infinite scroll for sync log
const scrollSentinel = ref<HTMLElement | null>(null)
let observer: IntersectionObserver | null = null

onMounted(() => {
  syncStore.fetchStatus()
  syncStore.fetchLog()
  syncRealtime.start()

  observer = new IntersectionObserver((entries) => {
    if (entries[0]?.isIntersecting) {
      syncStore.fetchMoreLog()
    }
  }, { rootMargin: '100px' })

  watch(scrollSentinel, (el) => {
    observer?.disconnect()
    if (el) observer?.observe(el)
  })
})

onUnmounted(() => {
  observer?.disconnect()
  syncRealtime.stop()
})

watch(() => companyStore.currentCompanyId, () => {
  syncStore.fetchStatus()
  syncStore.fetchLog()
  syncRealtime.stop()
  syncRealtime.start()
})
</script>
