<template>
  <UCard :ui="{ root: 'overflow-visible', body: '!pt-0 !pb-3' }">
    <template #header>
      <div class="flex items-center justify-between gap-2">
        <p class="text-sm font-semibold text-(--ui-text-highlighted) truncate">{{ title }}</p>
        <div class="flex items-center gap-1.5 shrink-0">
          <!-- View toggle -->
          <div class="flex items-center gap-0.5 p-0.5 rounded-md bg-(--ui-bg-elevated) border border-(--ui-border)">
            <button
              class="p-1 rounded transition-all duration-150"
              :class="viewMode === 'list' ? 'bg-(--ui-bg) shadow-sm text-(--ui-text-highlighted)' : 'text-(--ui-text-muted) hover:text-(--ui-text)'"
              @click="viewMode = 'list'"
            >
              <UIcon name="i-lucide-list" class="size-3.5" />
            </button>
            <button
              class="p-1 rounded transition-all duration-150"
              :class="viewMode === 'chart' ? 'bg-(--ui-bg) shadow-sm text-(--ui-text-highlighted)' : 'text-(--ui-text-muted) hover:text-(--ui-text)'"
              @click="viewMode = 'chart'"
            >
              <UIcon name="i-lucide-bar-chart-3" class="size-3.5" />
            </button>
          </div>
          <!-- Limit selector -->
          <UButtonGroup size="xs">
            <UButton
              v-for="opt in limitOptions"
              :key="opt"
              :variant="limit === opt ? 'solid' : 'ghost'"
              @click="limit = opt"
            >
              {{ opt }}
            </UButton>
          </UButtonGroup>
        </div>
      </div>
    </template>

    <ClientOnly>
      <template #fallback>
        <div class="space-y-2.5 py-1">
          <USkeleton v-for="i in 5" :key="i" class="h-10 w-full rounded-lg" />
        </div>
      </template>

      <UEmpty v-if="!slicedData.length" icon="i-lucide-bar-chart-3" :title="$t('reports.noData')" class="h-72" />

      <!-- List view: ranked items with inline progress bars -->
      <div v-else-if="viewMode === 'list'" class="space-y-1.5 py-1">
        <div
          v-for="(item, index) in slicedData"
          :key="item.label"
          class="group relative flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-(--ui-bg-elevated) transition-colors duration-100"
        >
          <!-- Rank badge -->
          <div
            class="size-6 rounded-md flex items-center justify-center text-[10px] font-bold shrink-0 tabular-nums"
            :class="rankClass(index)"
          >
            {{ index + 1 }}
          </div>

          <!-- Label + bar -->
          <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between mb-1 gap-2">
              <span class="text-xs font-medium text-(--ui-text) truncate" :title="item.label">
                {{ truncateLabel(item.label) }}
              </span>
              <div class="flex items-center gap-2 shrink-0">
                <span class="text-[10px] text-(--ui-text-muted) tabular-nums">
                  {{ itemPercent(item.value).toFixed(1) }}%
                </span>
                <span class="text-xs font-semibold tabular-nums text-(--ui-text-highlighted)">
                  {{ formatItemValue(item.value) }}
                </span>
              </div>
            </div>
            <div class="h-1.5 rounded-full bg-(--ui-border) overflow-hidden">
              <div
                class="h-full rounded-full transition-all duration-700"
                :style="{
                  width: `${itemPercent(item.value)}%`,
                  backgroundColor: barColor,
                }"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- Chart view -->
      <div v-else class="h-72">
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

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend)

const props = defineProps<{
  title: string
  data: { label: string; value: number }[]
  color?: string
}>()

const { t: $t } = useI18n()
const { colorMode, chartColors, defaultChartOptions } = useChartColors()

const limitOptions = [5, 10, 50] as const
const limit = ref<5 | 10 | 50>(5)
const viewMode = ref<'list' | 'chart'>('list')

const slicedData = computed(() => props.data.slice(0, limit.value))

const barColor = computed(() => props.color ?? chartColors.primary)

const totalValue = computed(() =>
  slicedData.value.reduce((sum, d) => sum + d.value, 0),
)

function itemPercent(value: number): number {
  if (totalValue.value === 0) return 0
  return (value / totalValue.value) * 100
}

function formatItemValue(value: number): string {
  return new Intl.NumberFormat('ro-RO', {
    style: 'currency',
    currency: 'RON',
    maximumFractionDigits: 0,
  }).format(value)
}

function rankClass(index: number): string {
  if (index === 0) return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
  if (index === 1) return 'bg-(--ui-bg-elevated) text-(--ui-text-muted)'
  if (index === 2) return 'bg-orange-50 text-orange-600 dark:bg-orange-900/20 dark:text-orange-400'
  return 'bg-(--ui-bg-elevated) text-(--ui-text-muted)'
}

function truncateLabel(label: string): string {
  return label.length > 32 ? label.slice(0, 29) + '...' : label
}

const barChartData = computed(() => ({
  labels: slicedData.value.map(d => truncateLabel(d.label)),
  datasets: [{
    label: props.title,
    data: slicedData.value.map(d => d.value),
    backgroundColor: barColor.value,
    borderRadius: 4,
    barThickness: slicedData.value.length > 20 ? 12 : 20,
  }],
}))

const barChartOptions = computed(() => ({
  ...defaultChartOptions.value,
  indexAxis: 'y' as const,
  plugins: {
    ...defaultChartOptions.value.plugins,
    legend: { display: false },
    tooltip: {
      ...defaultChartOptions.value.plugins?.tooltip,
      callbacks: {
        label: (ctx: { parsed: { x: number } }) =>
          `  ${formatItemValue(ctx.parsed.x)}`,
      },
    },
  },
  scales: {
    ...defaultChartOptions.value.scales,
    x: {
      ...defaultChartOptions.value.scales?.x,
      ticks: {
        ...defaultChartOptions.value.scales?.x?.ticks,
        callback: (value: string | number) =>
          new Intl.NumberFormat('ro-RO', {
            notation: 'compact',
            compactDisplay: 'short',
          }).format(Number(value)),
      },
    },
  },
}))
</script>
