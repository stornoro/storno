<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('reports.salesAnalysis.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UPageHeader :title="$t('reports.salesAnalysis.title')" :description="$t('reports.salesAnalysis.description')" />

      <UDashboardToolbar class="mb-4">
        <DashboardPeriodSelector
          v-model:selected-preset="selectedPreset"
          v-model:custom-date-from="customDateFrom"
          v-model:custom-date-to="customDateTo"
          :display-label="displayLabel"
          :is-custom="isCustom"
          :presets="presets"
        />
      </UDashboardToolbar>

      <!-- Skeleton loading state -->
      <div v-if="loading" class="space-y-6">
        <!-- KPI skeleton -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <UCard v-for="i in 4" :key="i" :ui="{ body: 'space-y-3' }">
            <USkeleton class="h-3 w-24 rounded" />
            <USkeleton class="h-7 w-36 rounded" />
            <USkeleton class="h-3 w-20 rounded" />
            <USkeleton class="h-1.5 w-full rounded-full" />
          </UCard>
        </div>

        <!-- Chart + recent invoices skeleton -->
        <div class="grid grid-cols-1 xl:grid-cols-5 gap-6">
          <div class="xl:col-span-3">
            <UCard :ui="{ body: 'space-y-4' }">
              <div class="flex items-start justify-between">
                <div class="space-y-2">
                  <USkeleton class="h-3 w-28 rounded" />
                  <USkeleton class="h-7 w-40 rounded" />
                </div>
                <USkeleton class="h-8 w-28 rounded-lg" />
              </div>
              <USkeleton class="h-72 w-full rounded-lg" />
            </UCard>
          </div>
          <div class="xl:col-span-2">
            <UCard :ui="{ body: '!p-0' }">
              <template #header>
                <div class="flex items-center justify-between">
                  <USkeleton class="h-4 w-32 rounded" />
                  <USkeleton class="h-6 w-16 rounded" />
                </div>
              </template>
              <div class="divide-y divide-(--ui-border)">
                <div v-for="i in 6" :key="i" class="flex items-center gap-3 px-4 py-3">
                  <USkeleton class="size-2 rounded-full shrink-0" />
                  <div class="flex-1 space-y-1.5">
                    <USkeleton class="h-3 w-3/4 rounded" />
                    <USkeleton class="h-2.5 w-1/2 rounded" />
                  </div>
                  <USkeleton class="h-3 w-16 rounded" />
                </div>
              </div>
            </UCard>
          </div>
        </div>

        <!-- Top charts skeleton -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <UCard v-for="i in 2" :key="i" :ui="{ body: 'space-y-2.5 !pt-0' }">
            <template #header>
              <div class="flex items-center justify-between">
                <USkeleton class="h-4 w-28 rounded" />
                <USkeleton class="h-7 w-24 rounded-lg" />
              </div>
            </template>
            <div v-for="j in 5" :key="j" class="space-y-1">
              <div class="flex items-center gap-3">
                <USkeleton class="size-6 rounded-md shrink-0" />
                <div class="flex-1 space-y-1">
                  <div class="flex items-center justify-between">
                    <USkeleton class="h-3 rounded" :style="{ width: `${60 - j * 6}%` }" />
                    <USkeleton class="h-3 w-16 rounded" />
                  </div>
                  <USkeleton class="h-1.5 rounded-full" :style="{ width: `${85 - j * 10}%` }" />
                </div>
              </div>
            </div>
          </UCard>
        </div>
      </div>

      <!-- Data loaded: animate in -->
      <Transition name="report-fade">
        <div v-if="!loading && report" class="space-y-6">
          <!-- KPI Cards -->
          <ReportsSalesKpiCards :data="report.kpiSummary" />

          <!-- Revenue Chart + Recent Invoices -->
          <div class="grid grid-cols-1 xl:grid-cols-5 gap-6">
            <div class="xl:col-span-3">
              <ReportsSalesRevenueChart :data="report.monthlyRevenue" />
            </div>
            <div class="xl:col-span-2">
              <ReportsSalesRecentInvoices :data="report.recentInvoices" />
            </div>
          </div>

          <!-- Top Clients + Top Products -->
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <ReportsSalesTopChart
              :title="$t('reports.salesAnalysis.topClients')"
              :data="topClientsData"
              :color="chartColors.primary"
            />
            <ReportsSalesTopChart
              :title="$t('reports.salesAnalysis.topProducts')"
              :data="topProductsData"
              :color="chartColors.success"
            />
          </div>
        </div>
      </Transition>

      <!-- Empty state -->
      <Transition name="report-fade">
        <div v-if="!loading && !report" class="flex flex-col items-center justify-center py-24 gap-4">
          <div class="size-16 rounded-2xl bg-(--ui-bg-elevated) flex items-center justify-center">
            <UIcon name="i-lucide-bar-chart-2" class="size-8 text-(--ui-text-muted)" />
          </div>
          <div class="text-center">
            <p class="text-sm font-semibold text-(--ui-text-highlighted) mb-1">{{ $t('reports.noData') }}</p>
            <p class="text-xs text-(--ui-text-muted) max-w-xs">{{ $t('reports.salesAnalysis.description') }}</p>
          </div>
          <UButton variant="outline" size="sm" icon="i-lucide-refresh-cw" @click="fetchReport">
            {{ $t('common.refresh') }}
          </UButton>
        </div>
      </Transition>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
import type { SalesAnalysisReport } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('reports.salesAnalysis.title') })

const companyStore = useCompanyStore()
const { chartColors } = useChartColors()

const loading = ref(false)
const report = ref<SalesAnalysisReport | null>(null)

// Period selector
const {
  selectedPreset,
  customDateFrom,
  customDateTo,
  presets,
  resolvedRange,
  isCustom,
  displayLabel,
} = usePeriodSelector('currentYear')

async function fetchReport() {
  const { dateFrom, dateTo } = resolvedRange.value
  if (!dateFrom || !dateTo) return

  const { get } = useApi()
  loading.value = true
  try {
    report.value = await get<SalesAnalysisReport>('/v1/reports/sales', { dateFrom, dateTo })
  }
  catch {
    report.value = null
  }
  finally {
    loading.value = false
  }
}

// Debounce custom date inputs
let customDebounceTimer: ReturnType<typeof setTimeout> | null = null
watch([customDateFrom, customDateTo], () => {
  if (!isCustom.value) return
  if (customDebounceTimer) clearTimeout(customDebounceTimer)
  customDebounceTimer = setTimeout(() => fetchReport(), 500)
})

watch(selectedPreset, () => {
  if (!isCustom.value) {
    fetchReport()
  }
})

watch(() => companyStore.currentCompanyId, () => {
  report.value = null
  fetchReport()
})

// Transform top clients/products for the chart component
const topClientsData = computed(() =>
  (report.value?.topClients ?? []).map(c => ({
    label: c.clientName || '-',
    value: parseFloat(c.total) || 0,
  })),
)

const topProductsData = computed(() =>
  (report.value?.topProducts ?? []).map(p => ({
    label: p.description || '-',
    value: parseFloat(p.total) || 0,
  })),
)

onMounted(() => fetchReport())
</script>

<style scoped>
.report-fade-enter-active {
  transition: opacity 0.35s ease, transform 0.35s ease;
}

.report-fade-leave-active {
  transition: opacity 0.2s ease;
}

.report-fade-enter-from {
  opacity: 0;
  transform: translateY(8px);
}

.report-fade-leave-to {
  opacity: 0;
}
</style>
