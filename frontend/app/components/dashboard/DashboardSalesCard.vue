<script setup lang="ts">
import type { MonthlyTotal } from '~/stores/dashboard'

const props = defineProps<{
  amount: string | number
  invoiceCount: number
  monthlyData: MonthlyTotal[]
  loading?: boolean
}>()

const { t: $t } = useI18n()

const currentMonthAmount = computed(() => {
  if (!props.monthlyData.length) return 0
  const last = props.monthlyData[props.monthlyData.length - 1]
  return Number(last?.outgoing ?? 0)
})

const currentMonthCount = computed(() => props.invoiceCount)

const avgDailyValue = computed(() => {
  const now = new Date()
  const dayOfMonth = now.getDate()
  if (dayOfMonth === 0) return 0
  return Math.round(currentMonthAmount.value / dayOfMonth)
})

function formatMoney(amount: number | string) {
  const num = Number(amount || 0)
  return new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 }).format(num)
}

// SVG sparkline from monthlyData (outgoing amounts)
const sparklinePath = computed(() => {
  const data = props.monthlyData.map(d => Number(d.outgoing))
  if (data.length < 2) return ''

  const w = 300
  const h = 80
  const max = Math.max(...data, 1)
  const min = Math.min(...data, 0)
  const range = max - min || 1

  const points = data.map((val, i) => {
    const x = (i / (data.length - 1)) * w
    const y = h - ((val - min) / range) * (h - 10) - 5
    return `${x},${y}`
  })

  return `M${points.join(' L')}`
})

const sparklineAreaPath = computed(() => {
  const data = props.monthlyData.map(d => Number(d.outgoing))
  if (data.length < 2) return ''

  const w = 300
  const h = 80
  const max = Math.max(...data, 1)
  const min = Math.min(...data, 0)
  const range = max - min || 1

  const points = data.map((val, i) => {
    const x = (i / (data.length - 1)) * w
    const y = h - ((val - min) / range) * (h - 10) - 5
    return `${x},${y}`
  })

  return `M0,${h} L${points.join(' L')} L${w},${h} Z`
})

const sparklineLabels = computed(() => {
  const data = props.monthlyData
  if (data.length < 2) return []

  const formatMonth = (ym: string) => {
    const parts = ym.split('-')
    const month = Number(parts[1] ?? 1)
    const day = 1
    const date = new Date(Number(parts[0]), month - 1, day)
    return new Intl.DateTimeFormat('ro-RO', { day: 'numeric', month: 'short' }).format(date)
  }

  const first = data[0]
  const mid = data[Math.floor(data.length / 2)]
  const last = data[data.length - 1]

  return [
    { label: first ? formatMonth(first.month) : '', x: 0 },
    { label: mid ? formatMonth(mid.month) : '', x: 50 },
    { label: last ? formatMonth(last.month) : '', x: 100 },
  ]
})
</script>

<template>
  <div class="rounded-lg border border-(--ui-border) bg-(--ui-bg) overflow-hidden flex flex-col h-full">
    <div class="px-5 pt-4 pb-2">
      <h3 class="text-sm font-bold tracking-wide text-(--ui-text) uppercase">
        {{ $t('dashboard.cards.sales') }}
      </h3>
    </div>

    <div class="px-5 flex-1">
      <template v-if="loading">
        <USkeleton class="w-32 h-8 mb-2" />
        <USkeleton class="w-48 h-4 mb-1" />
        <USkeleton class="w-full h-4" />
      </template>
      <template v-else>
        <div v-if="invoiceCount > 0" class="flex-1 flex flex-col">
          <!-- Big amount -->
          <div class="flex items-baseline gap-1.5 mb-3">
            <span class="text-3xl font-semibold text-(--ui-text) tabular-nums">
              {{ formatMoney(currentMonthAmount) }}
            </span>
            <span class="text-sm text-(--ui-text-muted)">RON</span>
          </div>

          <!-- Stats row -->
          <div class="flex items-center justify-between text-sm mb-2">
            <div>
              <span class="text-(--ui-text-muted)">{{ $t('dashboard.cards.issued') }}</span>
              <div class="font-semibold text-(--ui-text) tabular-nums">{{ currentMonthCount }}</div>
            </div>
            <div class="text-right">
              <span class="text-(--ui-text-muted)">{{ $t('dashboard.cards.avgDaily') }}</span>
              <div class="font-semibold text-(--ui-text) tabular-nums">{{ formatMoney(avgDailyValue) }} RON</div>
            </div>
          </div>

          <!-- Description -->
          <p class="text-xs text-(--ui-text-muted) mb-3">
            {{ $t('dashboard.cards.salesDesc') }}
          </p>

          <!-- Sparkline chart -->
          <div v-if="monthlyData.length >= 2" class="mt-auto">
            <svg viewBox="0 0 300 80" class="w-full h-20" preserveAspectRatio="none">
              <path :d="sparklineAreaPath" fill="currentColor" class="text-primary/10" />
              <path :d="sparklinePath" fill="none" stroke="currentColor" class="text-primary" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <div class="flex justify-between text-[10px] text-(--ui-text-muted) -mt-1">
              <span v-for="lbl in sparklineLabels" :key="lbl.x">{{ lbl.label }}</span>
            </div>
          </div>
        </div>

        <!-- Empty state -->
        <div v-else class="flex-1 flex flex-col items-center justify-center text-center py-4">
          <UIcon name="i-lucide-file-text" class="size-10 text-(--ui-text-muted) mb-3" />
          <p class="text-sm text-(--ui-text-muted) mb-4">{{ $t('dashboard.cards.noPeriodData') }}</p>
          <UButton to="/invoices?create=true" color="primary" size="sm">
            {{ $t('dashboard.cards.issueInvoice') }}
          </UButton>
        </div>
      </template>
    </div>
  </div>
</template>
