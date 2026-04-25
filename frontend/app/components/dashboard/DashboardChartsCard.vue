<template>
  <UCard :ui="{ root: 'overflow-visible', body: '!pt-0 !pb-3' }">
    <template #header>
      <div class="flex items-center justify-between">
        <div>
          <p class="text-xs text-muted uppercase mb-1.5">{{ $t('dashboard.charts.monthlyTrends') }}</p>
          <p class="text-3xl text-highlighted font-semibold tabular-nums">
            {{ formattedTotal }}
            <span class="text-sm text-(--ui-text-muted) font-normal">{{ currency ?? 'RON' }}</span>
          </p>
          <div v-if="delta" class="mt-1">
            <DashboardStatDelta :delta="delta" />
          </div>
        </div>
        <UTabs v-model="activeTab" :items="tabItems" :content="false" size="xs" />
      </div>
    </template>

    <ClientOnly>
      <template #fallback>
        <USkeleton class="w-full h-80" />
      </template>
      <UEmpty v-if="!hasData" icon="i-lucide-bar-chart-3" :title="$t('dashboard.noChartData')" class="h-80" />
      <div v-else class="h-80">
        <Line v-if="activeTab === 'trends'" :key="colorMode.value" :data="lineChartData" :options="lineChartOptions" />
        <Bar v-else :key="colorMode.value" :data="barChartData" :options="barChartOptions" />
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
  BarElement,
  Filler,
  Tooltip,
  Legend,
} from 'chart.js'
import { Line, Bar } from 'vue-chartjs'
import type { MonthlyTotal } from '~/stores/dashboard'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Filler, Tooltip, Legend)

import type { DeltaResult } from '~/stores/dashboard'

const props = defineProps<{
  data: MonthlyTotal[]
  currency?: string
  delta?: DeltaResult
}>()

const { t: $t } = useI18n()
const intlLocale = useIntlLocale()
const { colorMode, chartColors, defaultChartOptions } = useChartColors()

const activeTab = ref('trends')

const tabItems = computed(() => [
  { label: $t('dashboard.charts.monthlyTrends'), value: 'trends' },
  { label: $t('dashboard.charts.incomingVsOutgoing'), value: 'comparison' },
])

const hasData = computed(() => props.data.length > 0)

// Match DashboardSalesCard: latest month's outgoing in the company default currency.
const currentMonthAmount = computed(() => {
  if (!props.data.length) return 0
  const last = props.data[props.data.length - 1]
  return Number(last?.outgoing ?? 0)
})

const formattedTotal = computed(() =>
  new Intl.NumberFormat(intlLocale, { maximumFractionDigits: 0 }).format(currentMonthAmount.value),
)

function formatMonth(ym: string): string {
  const parts = ym.split('-')
  const year = parts[0] ?? ''
  const month = parts[1] ?? '1'
  const date = new Date(Number(year), Number(month) - 1)
  const short = new Intl.DateTimeFormat(intlLocale, { month: 'short' }).format(date)
  return `${short} ${year.slice(2)}`
}

const labels = computed(() => props.data.map(d => formatMonth(d.month)))

const lineChartData = computed(() => ({
  labels: labels.value,
  datasets: [
    {
      label: $t('common.incoming'),
      data: props.data.map(d => Number(d.incoming)),
      borderColor: chartColors.incoming,
      backgroundColor: `color-mix(in srgb, ${chartColors.incoming} 20%, transparent)`,
      fill: true,
      tension: 0.3,
      pointRadius: 4,
      pointHoverRadius: 6,
    },
    {
      label: $t('common.outgoing'),
      data: props.data.map(d => Number(d.outgoing)),
      borderColor: chartColors.outgoing,
      backgroundColor: `color-mix(in srgb, ${chartColors.outgoing} 20%, transparent)`,
      fill: true,
      tension: 0.3,
      pointRadius: 4,
      pointHoverRadius: 6,
    },
  ],
}))

const barChartData = computed(() => ({
  labels: labels.value,
  datasets: [
    {
      label: $t('common.incoming'),
      data: props.data.map(d => Number(d.incoming)),
      backgroundColor: chartColors.incoming,
      borderRadius: 4,
    },
    {
      label: $t('common.outgoing'),
      data: props.data.map(d => Number(d.outgoing)),
      backgroundColor: chartColors.outgoing,
      borderRadius: 4,
    },
  ],
}))

const chartInteraction = { intersect: false, mode: 'index' as const }

const lineChartOptions = computed(() => ({
  ...defaultChartOptions.value,
  interaction: chartInteraction,
}))

const barChartOptions = computed(() => ({
  ...defaultChartOptions.value,
  interaction: chartInteraction,
}))
</script>
