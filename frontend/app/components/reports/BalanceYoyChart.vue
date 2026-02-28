<template>
  <UCard :ui="{ root: 'overflow-visible', body: '!pt-0 !pb-3' }">
    <template #header>
      <p class="text-sm font-semibold">{{ $t('reports.balanceAnalysis.yoyComparison') }}</p>
    </template>

    <ClientOnly>
      <template #fallback>
        <USkeleton class="w-full h-80" />
      </template>
      <div class="h-80">
        <Bar :key="colorMode.value" :data="barChartData" :options="barChartOptions" />
      </div>
    </ClientOnly>

    <div class="grid grid-cols-3 gap-3 mt-4 pb-1">
      <div class="flex flex-col items-center gap-1">
        <span class="text-xs text-(--ui-text-muted)">{{ $t('reports.balanceAnalysis.revenue') }}</span>
        <UBadge :color="data.changes.revenue >= 0 ? 'success' : 'error'" variant="subtle" size="sm">
          <UIcon :name="data.changes.revenue >= 0 ? 'i-lucide-trending-up' : 'i-lucide-trending-down'" class="size-3 mr-0.5" />
          {{ data.changes.revenue >= 0 ? '+' : '' }}{{ data.changes.revenue.toFixed(1) }}%
        </UBadge>
      </div>
      <div class="flex flex-col items-center gap-1">
        <span class="text-xs text-(--ui-text-muted)">{{ $t('reports.balanceAnalysis.expenses') }}</span>
        <UBadge :color="data.changes.expenses <= 0 ? 'success' : 'error'" variant="subtle" size="sm">
          <UIcon :name="data.changes.expenses <= 0 ? 'i-lucide-trending-down' : 'i-lucide-trending-up'" class="size-3 mr-0.5" />
          {{ data.changes.expenses >= 0 ? '+' : '' }}{{ data.changes.expenses.toFixed(1) }}%
        </UBadge>
      </div>
      <div class="flex flex-col items-center gap-1">
        <span class="text-xs text-(--ui-text-muted)">{{ $t('reports.balanceAnalysis.netProfit') }}</span>
        <UBadge :color="data.changes.profit >= 0 ? 'success' : 'error'" variant="subtle" size="sm">
          <UIcon :name="data.changes.profit >= 0 ? 'i-lucide-trending-up' : 'i-lucide-trending-down'" class="size-3 mr-0.5" />
          {{ data.changes.profit >= 0 ? '+' : '' }}{{ data.changes.profit.toFixed(1) }}%
        </UBadge>
      </div>
    </div>
  </UCard>
</template>

<script setup lang="ts">
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Tooltip,
  Legend,
} from 'chart.js'
import { Bar } from 'vue-chartjs'
import type { BalanceYoyComparison } from '~/types'

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend)

const props = defineProps<{
  data: BalanceYoyComparison
}>()

const { t: $t } = useI18n()
const { colorMode, chartColors, defaultChartOptions } = useChartColors()

const barChartData = computed(() => ({
  labels: [
    $t('reports.balanceAnalysis.revenue'),
    $t('reports.balanceAnalysis.expenses'),
    $t('reports.balanceAnalysis.netProfit'),
  ],
  datasets: [
    {
      label: String(props.data.currentYear),
      data: [
        Number(props.data.current.revenue),
        Number(props.data.current.expenses),
        Number(props.data.current.profit),
      ],
      backgroundColor: chartColors.primary,
      borderRadius: 4,
      barThickness: 28,
    },
    {
      label: String(props.data.previousYear),
      data: [
        Number(props.data.previous.revenue),
        Number(props.data.previous.expenses),
        Number(props.data.previous.profit),
      ],
      backgroundColor: chartColors.neutral,
      borderRadius: 4,
      barThickness: 28,
    },
  ],
}))

const barChartOptions = computed(() => ({
  ...defaultChartOptions.value,
  plugins: {
    ...defaultChartOptions.value.plugins,
    legend: {
      ...defaultChartOptions.value.plugins.legend,
      display: true,
    },
  },
}))
</script>
