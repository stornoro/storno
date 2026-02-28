<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('reports.balanceAnalysis.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UPageHeader :title="$t('reports.balanceAnalysis.title')" :description="$t('reports.balanceAnalysis.description')" />

      <UDashboardToolbar class="mb-4">
        <div class="flex flex-wrap items-center gap-2 w-full">
          <USelectMenu
            v-model="selectedYear"
            :items="yearOptions"
            value-key="value"
            class="w-full sm:w-28"
          />
          <UButton
            icon="i-lucide-upload"
            class="w-full sm:w-auto ml-auto"
            @click="showUploadModal = true"
          >
            {{ $t('reports.balanceAnalysis.upload') }}
          </UButton>
        </div>
      </UDashboardToolbar>

      <!-- Upload status grid -->
      <ReportsBalanceUploadStatus
        :year="Number(selectedYear)"
        :balances="report?.balances ?? []"
        @upload="handleUploadMonth"
        @delete="handleDelete"
      />

      <div v-if="loading" class="text-center py-20">
        <UIcon name="i-lucide-loader-2" class="animate-spin h-8 w-8 mx-auto text-(--ui-text-muted)" />
      </div>

      <div v-else-if="report && report.balances.some(b => b.status === 'completed')" class="space-y-6 mt-6">
        <!-- KPI indicators -->
        <ReportsBalanceIndicators :indicators="report.indicators" />

        <!-- Evolution chart -->
        <ReportsBalanceEvolutionChart :data="report.monthlyEvolution" />

        <!-- Profitability + Top Expenses -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <ReportsBalanceProfitabilityChart :data="report.profitability" />
          <ReportsBalanceExpensesChart :data="report.topExpenses" />
        </div>

        <!-- YoY comparison -->
        <ReportsBalanceYoyChart :data="report.yoyComparison" />
      </div>
    </template>
  </UDashboardPanel>

  <!-- Upload modal -->
  <ReportsBalanceUploadModal
    v-model:open="showUploadModal"
    @uploaded="handleUploadComplete"
  />
</template>

<script setup lang="ts">
import type { BalanceAnalysisReport } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('reports.balanceAnalysis.title') })

const companyStore = useCompanyStore()
const { get, del } = useApi()

const now = new Date()
const selectedYear = ref(String(now.getFullYear()))
const loading = ref(false)
const report = ref<BalanceAnalysisReport | null>(null)
const showUploadModal = ref(false)

const yearOptions = computed(() => {
  const currentYear = now.getFullYear()
  return Array.from({ length: currentYear - 2020 + 1 }, (_, i) => ({
    label: String(currentYear - i),
    value: String(currentYear - i),
  }))
})

async function fetchReport() {
  loading.value = true
  try {
    report.value = await get<BalanceAnalysisReport>('/v1/balances/analysis', { year: selectedYear.value })
  }
  catch {
    report.value = null
  }
  finally {
    loading.value = false
  }
}

function handleUploadMonth(_month: number) {
  showUploadModal.value = true
}

async function handleUploadComplete() {
  showUploadModal.value = false
  await fetchReport()
}

async function handleDelete(id: string) {
  try {
    await del(`/v1/balances/${id}`)
    await fetchReport()
  }
  catch {
    // error handled by useApi
  }
}

watch(selectedYear, () => {
  report.value = null
  fetchReport()
})

watch(() => companyStore.currentCompanyId, () => {
  report.value = null
  fetchReport()
})

onMounted(() => fetchReport())
</script>
