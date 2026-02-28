<template>
  <UCard>
    <template #header>
      <p class="text-sm font-semibold">{{ $t('reports.balanceAnalysis.profitability') }}</p>
    </template>

    <ClientOnly>
      <template #fallback>
        <USkeleton class="w-full h-80" />
      </template>
      <div class="h-80">
        <Doughnut :key="colorMode.value" :data="doughnutChartData" :options="doughnutChartOptions" />
      </div>
    </ClientOnly>

    <div class="flex flex-wrap justify-center gap-4 mt-4">
      <div class="flex items-center gap-2">
        <span class="inline-block size-3 rounded-full" :style="{ backgroundColor: chartColors.success }" />
        <span class="text-xs text-(--ui-text-muted)">{{ $t('reports.balanceAnalysis.profitMargin') }}</span>
        <span class="text-xs font-semibold tabular-nums">{{ data.profitMargin.toFixed(1) }}%</span>
      </div>
      <div class="flex items-center gap-2">
        <span class="inline-block size-3 rounded-full" :style="{ backgroundColor: chartColors.error }" />
        <span class="text-xs text-(--ui-text-muted)">{{ $t('reports.balanceAnalysis.expenseRatio') }}</span>
        <span class="text-xs font-semibold tabular-nums">{{ data.expenseRatio.toFixed(1) }}%</span>
      </div>
      <div class="flex items-center gap-2">
        <span class="inline-block size-3 rounded-full" :style="{ backgroundColor: chartColors.warning }" />
        <span class="text-xs text-(--ui-text-muted)">{{ $t('reports.balanceAnalysis.salaryRatio') }}</span>
        <span class="text-xs font-semibold tabular-nums">{{ data.salaryRatio.toFixed(1) }}%</span>
      </div>
    </div>
  </UCard>
</template>

<script setup lang="ts">
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js'
import { Doughnut } from 'vue-chartjs'
import type { BalanceProfitability } from '~/types'

ChartJS.register(ArcElement, Tooltip, Legend)

const props = defineProps<{
  data: BalanceProfitability
}>()

const { t: $t } = useI18n()
const { colorMode, chartColors, defaultChartOptions } = useChartColors()

const doughnutChartData = computed(() => ({
  labels: [
    $t('reports.balanceAnalysis.profitMargin'),
    $t('reports.balanceAnalysis.expenseRatio'),
    $t('reports.balanceAnalysis.salaryRatio'),
  ],
  datasets: [
    {
      data: [props.data.profitMargin, props.data.expenseRatio, props.data.salaryRatio],
      backgroundColor: [chartColors.success, chartColors.error, chartColors.warning],
      borderWidth: 2,
      hoverOffset: 6,
    },
  ],
}))

const doughnutChartOptions = computed(() => ({
  ...defaultChartOptions.value,
  plugins: {
    ...defaultChartOptions.value.plugins,
    legend: {
      display: false,
    },
    tooltip: {
      ...defaultChartOptions.value.plugins.tooltip,
      callbacks: {
        label: (ctx: { dataset: { data: number[] }; dataIndex: number; label: string }) => {
          const value = ctx.dataset.data[ctx.dataIndex]
          return ` ${ctx.label}: ${typeof value === 'number' ? value.toFixed(1) : '0.0'}%`
        },
      },
    },
  },
  scales: {},
}))
</script>
