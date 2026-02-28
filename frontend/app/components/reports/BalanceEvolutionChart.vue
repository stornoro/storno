<template>
  <UCard :ui="{ root: 'overflow-visible', body: '!pt-0 !pb-3' }">
    <template #header>
      <div class="flex items-center justify-between">
        <div>
          <p class="text-xs text-muted uppercase mb-1.5">{{ $t('reports.balanceAnalysis.evolution') }}</p>
          <p class="text-3xl text-highlighted font-semibold tabular-nums">
            {{ formattedTotal }}
          </p>
        </div>
        <UTabs v-model="activeTab" :items="tabItems" :content="false" size="xs" />
      </div>
    </template>

    <ClientOnly>
      <template #fallback>
        <USkeleton class="w-full h-80" />
      </template>
      <UEmpty v-if="!hasData" icon="i-lucide-bar-chart-3" :title="$t('reports.noData')" class="h-80" />
      <div v-else class="h-80">
        <Line v-if="activeTab === 'chart'" :key="colorMode.value" :data="lineChartData" :options="chartOptions" />
        <div v-else class="overflow-auto h-full">
          <UTable :data="tableData" :columns="tableColumns" />
        </div>
      </div>
    </ClientOnly>
  </UCard>
</template>

<script setup lang="ts">
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Filler,
  Tooltip,
  Legend,
} from 'chart.js'
import { Line } from 'vue-chartjs'
import type { BalanceMonthlyEvolution } from '~/types'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Filler, Tooltip, Legend)

const props = defineProps<{
  data: BalanceMonthlyEvolution[]
}>()

const { t: $t } = useI18n()
const { formatMoney } = useMoney()
const { colorMode, chartColors, defaultChartOptions } = useChartColors()

const activeTab = ref('chart')

const tabItems = computed(() => [
  { label: $t('reports.balanceAnalysis.chart'), value: 'chart' },
  { label: $t('reports.balanceAnalysis.table'), value: 'table' },
])

const hasData = computed(() => props.data.length > 0)

const formattedTotal = computed(() => {
  const total = props.data.reduce((sum, d) => sum + (parseFloat(d.revenue) || 0), 0)
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency: 'RON', maximumFractionDigits: 0 }).format(total)
})

const labels = computed(() =>
  props.data.map(d => $t(`reports.months.${d.month}`)),
)

const lineChartData = computed(() => ({
  labels: labels.value,
  datasets: [
    {
      label: $t('reports.balanceAnalysis.revenue'),
      data: props.data.map(d => Number(d.revenue)),
      borderColor: chartColors.primary,
      backgroundColor: `color-mix(in srgb, ${chartColors.primary} 20%, transparent)`,
      fill: true,
      tension: 0.3,
      pointRadius: 4,
      pointHoverRadius: 6,
    },
    {
      label: $t('reports.balanceAnalysis.expenses'),
      data: props.data.map(d => Number(d.expenses)),
      borderColor: chartColors.error,
      backgroundColor: `color-mix(in srgb, ${chartColors.error} 20%, transparent)`,
      fill: true,
      tension: 0.3,
      pointRadius: 4,
      pointHoverRadius: 6,
    },
    {
      label: $t('reports.balanceAnalysis.netProfit'),
      data: props.data.map(d => Number(d.profit)),
      borderColor: chartColors.success,
      backgroundColor: `color-mix(in srgb, ${chartColors.success} 20%, transparent)`,
      fill: true,
      tension: 0.3,
      pointRadius: 4,
      pointHoverRadius: 6,
    },
  ],
}))

const chartOptions = computed(() => ({
  ...defaultChartOptions.value,
  interaction: { intersect: false, mode: 'index' as const },
}))

const tableColumns = computed(() => [
  { accessorKey: 'month', header: $t('reports.month') },
  { accessorKey: 'revenue', header: $t('reports.balanceAnalysis.revenue') },
  { accessorKey: 'expenses', header: $t('reports.balanceAnalysis.expenses') },
  { accessorKey: 'profit', header: $t('reports.balanceAnalysis.netProfit') },
])

const tableData = computed(() =>
  props.data.map(d => ({
    month: $t(`reports.months.${d.month}`),
    revenue: formatMoney(d.revenue),
    expenses: formatMoney(d.expenses),
    profit: formatMoney(d.profit),
  })),
)
</script>
