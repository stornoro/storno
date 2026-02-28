<template>
  <UCard variant="outline">
    <template #header>
      <h3 class="font-semibold text-(--ui-text)">{{ $t('dashboard.charts.statusDistribution') }}</h3>
    </template>

    <ClientOnly>
      <template #fallback>
        <USkeleton class="w-full h-56" />
      </template>
      <div v-if="hasData" class="relative h-56 flex items-center justify-center">
        <Doughnut :key="colorMode.value" :data="chartData" :options="chartOptions" />
        <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
          <span class="text-2xl font-bold text-(--ui-text)">{{ total }}</span>
          <span class="text-xs text-(--ui-text-muted)">{{ $t('common.total') }}</span>
        </div>
      </div>
      <UEmpty v-else icon="i-lucide-pie-chart" :title="$t('dashboard.noChartData')" class="h-56" />
    </ClientOnly>
  </UCard>
</template>

<script setup lang="ts">
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js'
import { Doughnut } from 'vue-chartjs'

ChartJS.register(ArcElement, Tooltip, Legend)

const props = defineProps<{
  data: Record<string, number>
}>()

const { t: $t } = useI18n()
const { colorMode, textColor, bgColor, borderColor, chartColors } = useChartColors()

const statusColorMap: Record<string, string> = {
  synced: chartColors.info,
  validated: chartColors.success,
  rejected: chartColors.error,
  draft: chartColors.neutral,
  issued: chartColors.info,
  sent_to_provider: chartColors.warning,
  paid: chartColors.success,
  overdue: chartColors.error,
  cancelled: chartColors.neutral,
  partially_paid: chartColors.warning,
  converted: chartColors.primary,
  refund: chartColors.warning,
  refunded: chartColors.warning,
}

const entries = computed(() => Object.entries(props.data).filter(([_, v]) => v > 0))
const hasData = computed(() => entries.value.length > 0)
const total = computed(() => entries.value.reduce((sum, [_, v]) => sum + v, 0))

const chartData = computed(() => ({
  labels: entries.value.map(([status]) => $t(`documentStatus.${status}`, status)),
  datasets: [{
    data: entries.value.map(([_, v]) => v),
    backgroundColor: entries.value.map(([status]) => statusColorMap[status] || chartColors.neutral),
    borderWidth: 0,
    hoverOffset: 8,
  }],
}))

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  cutout: '65%',
  plugins: {
    legend: {
      position: 'bottom' as const,
      labels: {
        color: textColor.value,
        usePointStyle: true,
        pointStyle: 'circle' as const,
        padding: 12,
        font: { size: 11 },
      },
    },
    tooltip: {
      backgroundColor: bgColor.value,
      titleColor: textColor.value,
      bodyColor: textColor.value,
      borderColor: borderColor.value,
      borderWidth: 1,
    },
  },
}))
</script>
