<script setup lang="ts">
import { storeToRefs } from 'pinia'
import type { RecentActivityItem, MonthlyTotal } from '~/stores/dashboard'

const { t: $t } = useI18n()

const props = defineProps<{
  id: string
  editMode?: boolean
  dateFrom?: string
  dateTo?: string
}>()

const emit = defineEmits<{
  hide: [id: string]
}>()

const dashboardStore = useDashboardStore()
const {
  loading,
  recentActivity,
  outgoingInvoices,
  outgoingAmount,
  incomingAmount,
  incomingInvoices,
  monthlyTotals,
  invoicesByStatus,
  clientCount,
  outstandingCount,
  outstandingAmount,
  overdueCount,
  overdueAmount,
  currency,
  isSyncEnabled,
  lastSyncedAt,
  outgoingAmountDelta,
  incomingAmountDelta,
  clientCountDelta,
} = storeToRefs(dashboardStore)

const companyStore = useCompanyStore()
</script>

<template>
  <div class="relative group/widget h-full">
    <!-- Edit mode overlay handles -->
    <template v-if="editMode">
      <!-- Drag handle top-left -->
      <div
        class="dashboard-drag-handle absolute top-2 left-2 z-10 opacity-0 group-hover/widget:opacity-100 transition-opacity cursor-grab active:cursor-grabbing bg-(--ui-bg)/80 backdrop-blur-sm rounded-md p-1 shadow-sm"
        title="Trage pentru a reordona"
      >
        <UIcon name="i-lucide-grip-vertical" class="size-4 text-(--ui-text-muted)" />
      </div>

      <!-- Hide button top-right -->
      <button
        class="absolute top-2 right-2 z-10 opacity-0 group-hover/widget:opacity-100 transition-opacity bg-(--ui-bg)/80 backdrop-blur-sm rounded-md p-1 shadow-sm hover:text-error"
        :title="$t('dashboard.edit.hideWidget')"
        @click="emit('hide', id)"
      >
        <UIcon name="i-lucide-eye-off" class="size-4 text-(--ui-text-muted)" />
      </button>

      <!-- Edit mode border highlight -->
      <div class="absolute inset-0 rounded-lg ring-2 ring-primary/30 ring-dashed pointer-events-none z-[5]" />
    </template>

    <!-- Sales Card -->
    <DashboardSalesCard
      v-if="id === 'sales-card'"
      :amount="outgoingAmount"
      :invoice-count="outgoingInvoices"
      :monthly-data="monthlyTotals as MonthlyTotal[]"
      :currency="currency"
      :loading="loading"
      :delta="outgoingAmountDelta"
    />

    <!-- Expenses Card -->
    <DashboardExpensesCard
      v-else-if="id === 'expenses-card'"
      :incoming-amount="incomingAmount"
      :incoming-invoices="incomingInvoices"
      :monthly-data="monthlyTotals as MonthlyTotal[]"
      :invoices-by-status="invoicesByStatus"
      :currency="currency"
      :loading="loading"
      :delta="incomingAmountDelta"
    />

    <!-- Client Balance Card -->
    <DashboardClientBalanceCard
      v-else-if="id === 'client-balance-card'"
      :recent-activity="recentActivity as RecentActivityItem[]"
      :currency="currency"
      :loading="loading"
      :delta="clientCountDelta"
    />

    <!-- Unpaid Card -->
    <DashboardUnpaidCard
      v-else-if="id === 'unpaid-card'"
      :outstanding-count="outstandingCount"
      :outstanding-amount="outstandingAmount"
      :overdue-count="overdueCount"
      :overdue-amount="overdueAmount"
      :recent-activity="recentActivity as RecentActivityItem[]"
      :currency="currency"
      :loading="loading"
    />

    <!-- Amounts to Pay Card -->
    <DashboardAmountsToPayCard
      v-else-if="id === 'amounts-to-pay-card'"
      :outstanding-amount="outstandingAmount"
      :outstanding-count="outstandingCount"
      :overdue-amount="overdueAmount"
      :overdue-count="overdueCount"
      :currency="companyStore.currentCompany?.defaultCurrency"
      :loading="loading"
    />

    <!-- Activity Card -->
    <DashboardActivityCard
      v-else-if="id === 'activity-card'"
      :data="recentActivity as RecentActivityItem[]"
      :loading="loading"
    />

    <!-- Due Today Card -->
    <DashboardDueTodayCard
      v-else-if="id === 'due-today-card'"
      :overdue-amount="overdueAmount"
      :outstanding-amount="outstandingAmount"
      :currency="currency"
      :loading="loading"
    />

    <!-- Cash Balance Card -->
    <DashboardCashBalanceCard
      v-else-if="id === 'cash-balance-card'"
    />

    <!-- Recent Invoices Table -->
    <DashboardRecentInvoicesTable
      v-else-if="id === 'recent-invoices-table'"
      :data="recentActivity as RecentActivityItem[]"
      :loading="loading"
    />

    <!-- Status Breakdown Chart -->
    <DashboardStatusDoughnutChart
      v-else-if="id === 'status-breakdown-chart'"
      :data="invoicesByStatus"
    />

    <!-- Monthly Charts -->
    <DashboardChartsCard
      v-else-if="id === 'monthly-charts'"
      :data="monthlyTotals as MonthlyTotal[]"
      :currency="currency"
      :delta="outgoingAmountDelta"
    />

    <!-- Sync Status -->
    <DashboardSyncStatus
      v-else-if="id === 'sync-status'"
      :sync-enabled="isSyncEnabled"
      :last-synced-at="lastSyncedAt"
    />

    <!-- Top Clients Revenue -->
    <DashboardTopClientsCard
      v-else-if="id === 'top-clients-revenue'"
      :date-from="dateFrom"
      :date-to="dateTo"
      :loading="loading"
    />

    <!-- Top Products Revenue -->
    <DashboardTopProductsCard
      v-else-if="id === 'top-products-revenue'"
      :date-from="dateFrom"
      :date-to="dateTo"
      :loading="loading"
    />

    <!-- Top Outstanding Clients -->
    <DashboardTopOutstandingClientsCard
      v-else-if="id === 'top-outstanding-clients'"
      :loading="loading"
    />

    <!-- Unknown widget fallback -->
    <div
      v-else
      class="rounded-lg border border-(--ui-border) bg-(--ui-bg) flex items-center justify-center p-8 h-full"
    >
      <span class="text-sm text-(--ui-text-muted)">{{ id }}</span>
    </div>
  </div>
</template>
