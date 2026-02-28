<script setup lang="ts">
import { storeToRefs } from 'pinia'
import type { DropdownMenuItem } from '@nuxt/ui'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('dashboard.title') })
const { isNotificationsSlideoverOpen } = useDashboard()
const dashboardStore = useDashboardStore()
const syncStore = useSyncStore()
const companyStore = useCompanyStore()
const {
  loading,
  recentActivity,
  totalInvoices,
  incomingInvoices,
  outgoingInvoices,
  totalAmount,
  totalVat,
  clientCount,
  productCount,
  isSyncEnabled,
  lastSyncedAt,
  invoicesByStatus,
  monthlyTotals,
  incomingAmount,
  outgoingAmount,
  outstandingCount,
  outstandingAmount,
  overdueCount,
  overdueAmount,
} = storeToRefs(dashboardStore)

const { syncing } = storeToRefs(syncStore)

// ── Period selector ───────────────────────────────────────────────
const {
  selectedPreset,
  customDateFrom,
  customDateTo,
  presets,
  resolvedRange,
  isCustom,
  displayLabel,
} = usePeriodSelector('currentMonth')

function fetchWithPeriod() {
  const { dateFrom, dateTo } = resolvedRange.value
  if (dateFrom && dateTo) {
    dashboardStore.fetchStats({ dateFrom, dateTo })
  }
}

// Debounce for custom date inputs
let customDebounceTimer: ReturnType<typeof setTimeout> | null = null
watch([customDateFrom, customDateTo], () => {
  if (!isCustom.value) return
  if (customDebounceTimer) clearTimeout(customDebounceTimer)
  customDebounceTimer = setTimeout(() => fetchWithPeriod(), 500)
})

// Immediate fetch when preset changes (non-custom presets resolve instantly)
watch(selectedPreset, () => {
  if (!isCustom.value) {
    fetchWithPeriod()
  }
})

// Quick actions dropdown
const quickActions = computed<DropdownMenuItem[][]>(() => [[
  {
    label: $t('invoices.newInvoice'),
    icon: 'i-lucide-file-text',
    kbds: ['C', 'I'],
    onSelect: () => navigateTo('/invoices?create=true'),
  },
  {
    label: $t('nav.proformaInvoices'),
    icon: 'i-lucide-file-check',
    kbds: ['C', 'P'],
    to: '/proforma-invoices/new',
  },
], [
  {
    label: $t('dashboard.syncNow'),
    icon: 'i-lucide-refresh-cw',
    onSelect: () => triggerSync(),
  },
]])

async function triggerSync() {
  await syncStore.triggerSync()
}

// Refresh dashboard stats when sync completes
watch(() => syncStore.lastSyncResult, (result) => {
  if (result?.success) {
    fetchWithPeriod()
  }
})

// Refresh dashboard stats on each sync progress update (data changes after batch flushes)
watch(() => syncStore.syncProgress?.processed, () => {
  if (syncStore.syncProgress) {
    fetchWithPeriod()
  }
})

const invoiceRealtime = useInvoiceRealtime(() => fetchWithPeriod())
const syncRealtime = useSyncRealtime()

onMounted(() => {
  fetchWithPeriod()
  syncStore.fetchStatus()
  invoiceRealtime.start()
  syncRealtime.start()
})

onUnmounted(() => {
  invoiceRealtime.stop()
  syncRealtime.stop()
})

watch(() => companyStore.currentCompanyId, () => {
  fetchWithPeriod()
  invoiceRealtime.stop()
  invoiceRealtime.start()
  syncRealtime.stop()
  syncRealtime.start()
})
</script>

<template>
  <UDashboardPanel id="home">
    <template #header>
      <UDashboardNavbar :title="$t('dashboard.title')" :ui="{ right: 'gap-3' }">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UTooltip :text="$t('notifications.title')" :kbds="['N']">
            <UButton
              color="neutral"
              variant="ghost"
              square
              @click="isNotificationsSlideoverOpen = true"
            >
              <UIcon name="i-lucide-bell" class="size-5 shrink-0" />
            </UButton>
          </UTooltip>

          <UDropdownMenu :items="quickActions">
            <UButton icon="i-lucide-plus" size="md" class="rounded-full" />
          </UDropdownMenu>
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <template #left>
          <DashboardPeriodSelector
            v-model:selected-preset="selectedPreset"
            v-model:custom-date-from="customDateFrom"
            v-model:custom-date-to="customDateTo"
            :display-label="displayLabel"
            :is-custom="isCustom"
            :presets="presets"
          />
        </template>
        <template #right>
          <div class="flex items-center gap-2">
            <UBadge v-if="isSyncEnabled" color="success" variant="subtle" size="sm">
              e-Factura {{ $t('common.active').toLowerCase() }}
            </UBadge>
            <UBadge v-else color="neutral" variant="subtle" size="sm">
              e-Factura {{ $t('common.inactive').toLowerCase() }}
            </UBadge>

            <UButton
              icon="i-lucide-refresh-cw"
              color="neutral"
              variant="ghost"
              :loading="syncing"
              @click="triggerSync"
            >
              {{ $t('dashboard.syncNow') }}
            </UButton>
          </div>
        </template>
      </UDashboardToolbar>
    </template>

    <template #body>
      <!-- Update available banner (admins only) -->
      <AppUpdateBanner />

      <!-- Usage nudge banner (non-paid plans at ≥80% usage) -->
      <SharedUsageBanner />

      <!-- Read-only company banner -->
      <SharedCompanyReadOnlyBanner />

      <!-- Sync progress (live during sync) -->
      <UCard v-if="syncStore.syncProgress">
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="font-semibold">{{ $t('efactura.syncProgressTitle') }}</h3>
            <span class="text-sm text-muted">{{ syncStore.syncProgress.processed }} / {{ syncStore.syncProgress.total }}</span>
          </div>
          <UProgress
            :model-value="syncStore.syncProgress.processed"
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

      <!-- Onboarding checklist -->
      <DashboardOnboardingChecklist />

      <!-- Row 1: Sales, Client Balance, Unpaid Invoices -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <DashboardSalesCard
          :amount="outgoingAmount"
          :invoice-count="outgoingInvoices"
          :monthly-data="monthlyTotals"
          :loading="loading"
        />

        <DashboardClientBalanceCard
          :recent-activity="recentActivity"
          :loading="loading"
        />

        <DashboardUnpaidCard
          :outstanding-count="outstandingCount"
          :outstanding-amount="outstandingAmount"
          :overdue-count="overdueCount"
          :overdue-amount="overdueAmount"
          :recent-activity="recentActivity"
          :loading="loading"
        />
      </div>

      <!-- Row 2: Expenses, Amounts to Pay, Activity, Due Today -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
        <DashboardExpensesCard
          :incoming-amount="incomingAmount"
          :incoming-invoices="incomingInvoices"
          :monthly-data="monthlyTotals"
          :invoices-by-status="invoicesByStatus"
          :loading="loading"
        />

        <DashboardAmountsToPayCard
          :outstanding-amount="outstandingAmount"
          :outstanding-count="outstandingCount"
          :overdue-amount="overdueAmount"
          :overdue-count="overdueCount"
          :currency="companyStore.currentCompany?.defaultCurrency"
          :loading="loading"
        />

        <DashboardActivityCard
          :data="recentActivity"
          :loading="loading"
        />

        <!-- Stacked: Due Today -->
        <div class="flex flex-col gap-5">
          <DashboardDueTodayCard
            :overdue-amount="overdueAmount"
            :outstanding-amount="outstandingAmount"
            :loading="loading"
          />
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
