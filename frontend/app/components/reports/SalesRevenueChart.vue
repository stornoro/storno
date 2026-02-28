<template>
  <UCard :ui="{ root: 'overflow-visible', body: '!pt-0 !pb-4' }">
    <template #header>
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-[11px] font-medium text-(--ui-text-muted) uppercase tracking-wide mb-1">
            {{ $t('reports.salesAnalysis.monthlyRevenue') }}
          </p>
          <p class="text-2xl font-bold tabular-nums text-(--ui-text-highlighted)">
            {{ formattedTotal }}
          </p>
        </div>
        <!-- Pill-style toggle -->
        <div class="flex items-center gap-1 p-0.5 rounded-lg bg-(--ui-bg-elevated) border border-(--ui-border) shrink-0">
          <button
            v-for="tab in tabItems"
            :key="tab.value"
            class="flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium transition-all duration-150"
            :class="activeTab === tab.value
              ? 'bg-(--ui-bg) text-(--ui-text-highlighted) shadow-sm border border-(--ui-border)'
              : 'text-(--ui-text-muted) hover:text-(--ui-text)'"
            @click="activeTab = tab.value"
          >
            <UIcon :name="tab.icon" class="size-3.5" />
            {{ tab.label }}
          </button>
        </div>
      </div>
    </template>

    <ClientOnly>
      <template #fallback>
        <USkeleton class="w-full h-72" />
      </template>
      <UEmpty v-if="!hasData" icon="i-lucide-bar-chart-3" :title="$t('reports.noData')" class="h-72" />
      <div v-else>
        <div class="h-72">
          <Line
            v-if="activeTab === 'chart'"
            :key="colorMode.value"
            :data="lineChartData"
            :options="chartOptions"
          />
          <div v-else class="overflow-auto h-full">
            <UTable :data="tableData" :columns="tableColumns" />
          </div>
        </div>

        <!-- Summary row below chart -->
        <div v-if="activeTab === 'chart'" class="flex items-center justify-center gap-6 mt-4 pt-3 border-t border-(--ui-border)">
          <div class="flex items-center gap-2">
            <span class="size-2.5 rounded-full shrink-0" :style="{ backgroundColor: chartColors.primary }" />
            <span class="text-xs text-(--ui-text-muted)">{{ $t('reports.salesAnalysis.invoiced') }}</span>
            <span class="text-xs font-semibold tabular-nums text-(--ui-text-highlighted)">{{ totalInvoiced }}</span>
          </div>
          <div class="w-px h-4 bg-(--ui-border)" />
          <div class="flex items-center gap-2">
            <span class="size-2.5 rounded-full shrink-0" :style="{ backgroundColor: chartColors.success }" />
            <span class="text-xs text-(--ui-text-muted)">{{ $t('reports.salesAnalysis.collected') }}</span>
            <span class="text-xs font-semibold tabular-nums text-(--ui-success)">{{ totalCollected }}</span>
          </div>
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
import type { MonthlyRevenueItem } from '~/types'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Filler, Tooltip, Legend)

const props = defineProps<{
  data: MonthlyRevenueItem[]
}>()

const { t: $t } = useI18n()
const { formatMoney } = useMoney()
const { colorMode, chartColors, defaultChartOptions } = useChartColors()

const activeTab = ref('chart')

const tabItems = computed(() => [
  { label: $t('reports.salesAnalysis.chart'), value: 'chart', icon: 'i-lucide-chart-line' },
  { label: $t('reports.salesAnalysis.table'), value: 'table', icon: 'i-lucide-table-2' },
])

const hasData = computed(() => props.data.length > 0)

const formattedTotal = computed(() => {
  const total = props.data.reduce((sum, d) => sum + (parseFloat(d.invoiced) || 0), 0)
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency: 'RON', maximumFractionDigits: 0 }).format(total)
})

const totalInvoiced = computed(() => {
  const total = props.data.reduce((sum, d) => sum + (parseFloat(d.invoiced) || 0), 0)
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency: 'RON', maximumFractionDigits: 0 }).format(total)
})

const totalCollected = computed(() => {
  const total = props.data.reduce((sum, d) => sum + (parseFloat(d.collected) || 0), 0)
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency: 'RON', maximumFractionDigits: 0 }).format(total)
})

function formatMonth(ym: string): string {
  const parts = ym.split('-')
  const year = parts[0] ?? ''
  const month = parts[1] ?? '1'
  const date = new Date(Number(year), Number(month) - 1)
  const short = new Intl.DateTimeFormat('ro-RO', { month: 'short' }).format(date)
  return `${short} ${year.slice(2)}`
}

const labels = computed(() => props.data.map(d => formatMonth(d.month)))

const lineChartData = computed(() => ({
  labels: labels.value,
  datasets: [
    {
      label: $t('reports.salesAnalysis.invoiced'),
      data: props.data.map(d => Number(d.invoiced)),
      borderColor: chartColors.primary,
      backgroundColor: `color-mix(in srgb, ${chartColors.primary} 15%, transparent)`,
      fill: true,
      tension: 0.4,
      pointRadius: 3,
      pointHoverRadius: 6,
      pointBackgroundColor: chartColors.primary,
      borderWidth: 2,
    },
    {
      label: $t('reports.salesAnalysis.collected'),
      data: props.data.map(d => Number(d.collected)),
      borderColor: chartColors.success,
      backgroundColor: `color-mix(in srgb, ${chartColors.success} 15%, transparent)`,
      fill: true,
      tension: 0.4,
      pointRadius: 3,
      pointHoverRadius: 6,
      pointBackgroundColor: chartColors.success,
      borderWidth: 2,
    },
  ],
}))

const chartOptions = computed(() => ({
  ...defaultChartOptions.value,
  interaction: { intersect: false, mode: 'index' as const },
  plugins: {
    ...defaultChartOptions.value.plugins,
    tooltip: {
      ...defaultChartOptions.value.plugins?.tooltip,
      callbacks: {
        label: (ctx: { dataset: { label?: string }; parsed: { y: number } }) => {
          const label = ctx.dataset.label ?? ''
          const value = new Intl.NumberFormat('ro-RO', {
            style: 'currency',
            currency: 'RON',
            maximumFractionDigits: 0,
          }).format(ctx.parsed.y)
          return `  ${label}: ${value}`
        },
      },
    },
  },
  scales: {
    ...defaultChartOptions.value.scales,
    y: {
      ...defaultChartOptions.value.scales?.y,
      ticks: {
        ...defaultChartOptions.value.scales?.y?.ticks,
        callback: (value: string | number) =>
          new Intl.NumberFormat('ro-RO', {
            notation: 'compact',
            compactDisplay: 'short',
            currency: 'RON',
            style: 'currency',
            maximumFractionDigits: 0,
          }).format(Number(value)),
      },
    },
  },
}))

const tableColumns = computed(() => [
  { accessorKey: 'month', header: $t('reports.month') },
  { accessorKey: 'invoiced', header: $t('reports.salesAnalysis.invoiced') },
  { accessorKey: 'collected', header: $t('reports.salesAnalysis.collected') },
])

const tableData = computed(() =>
  props.data.map(d => ({
    month: formatMonth(d.month),
    invoiced: formatMoney(d.invoiced),
    collected: formatMoney(d.collected),
  })),
)
</script>
