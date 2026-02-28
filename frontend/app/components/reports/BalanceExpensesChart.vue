<template>
  <UCard :ui="{ root: 'overflow-visible', body: '!pt-0 !pb-3' }">
    <template #header>
      <div class="flex items-center justify-between">
        <p class="text-sm font-semibold">{{ $t('reports.balanceAnalysis.topExpenses') }}</p>
        <UButtonGroup size="xs">
          <UButton
            v-for="opt in limitOptions"
            :key="opt"
            :variant="limit === opt ? 'solid' : 'outline'"
            @click="limit = opt"
          >
            {{ opt }}
          </UButton>
        </UButtonGroup>
      </div>
    </template>

    <ClientOnly>
      <template #fallback>
        <USkeleton class="w-full h-80" />
      </template>
      <UEmpty v-if="!slicedData.length" icon="i-lucide-bar-chart-3" :title="$t('reports.noData')" class="h-80" />
      <div v-else class="h-80">
        <Bar :key="colorMode.value" :data="barChartData" :options="barChartOptions" />
      </div>
    </ClientOnly>
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
import type { BalanceTopExpense } from '~/types'

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend)

const props = defineProps<{
  data: BalanceTopExpense[]
}>()

const { t: $t } = useI18n()
const { colorMode, chartColors, defaultChartOptions } = useChartColors()

const limitOptions = [5, 10] as const
const limit = ref<5 | 10>(5)

const slicedData = computed(() => props.data.slice(0, limit.value))

function truncateLabel(label: string): string {
  return label.length > 30 ? label.slice(0, 27) + '...' : label
}

const barChartData = computed(() => ({
  labels: slicedData.value.map(d => truncateLabel(`${d.accountCode} - ${d.accountName}`)),
  datasets: [
    {
      label: $t('reports.balanceAnalysis.topExpenses'),
      data: slicedData.value.map(d => Number(d.amount)),
      backgroundColor: chartColors.warning,
      borderRadius: 4,
      barThickness: slicedData.value.length > 5 ? 12 : 20,
    },
  ],
}))

const barChartOptions = computed(() => ({
  ...defaultChartOptions.value,
  indexAxis: 'y' as const,
  plugins: {
    ...defaultChartOptions.value.plugins,
    legend: { display: false },
  },
}))
</script>
