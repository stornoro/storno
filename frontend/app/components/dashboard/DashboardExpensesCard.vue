<script setup lang="ts">
import type { MonthlyTotal } from '~/stores/dashboard'

const props = defineProps<{
  incomingAmount: string | number
  incomingInvoices: number
  monthlyData: MonthlyTotal[]
  invoicesByStatus: Record<string, number>
  loading?: boolean
}>()

const { t: $t } = useI18n()

const currentMonthAmount = computed(() => {
  if (!props.monthlyData.length) return 0
  const last = props.monthlyData[props.monthlyData.length - 1]
  return Number(last?.incoming ?? 0)
})

// Calculate month-over-month change %
const monthChange = computed(() => {
  if (props.monthlyData.length < 2) return null
  const current = Number(props.monthlyData[props.monthlyData.length - 1]?.incoming ?? 0)
  const prev = Number(props.monthlyData[props.monthlyData.length - 2]?.incoming ?? 0)
  if (prev === 0) return null
  return Math.round(((current - prev) / prev) * 100)
})

// Status breakdown as expense categories
const statusEntries = computed(() => {
  const entries = Object.entries(props.invoicesByStatus)
    .filter(([_, count]) => count > 0)
    .sort((a, b) => b[1] - a[1])
    .slice(0, 5)

  const total = entries.reduce((sum, [_, count]) => sum + count, 0)

  return entries.map(([status, count]) => ({
    status,
    count,
    percent: total > 0 ? Math.round((count / total) * 100) : 0,
  }))
})

function formatMoney(amount: number | string) {
  const num = Number(amount || 0)
  return new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 }).format(num)
}

const statusColorMap: Record<string, string> = {
  synced: 'bg-info',
  validated: 'bg-success',
  rejected: 'bg-error',
  draft: 'bg-neutral',
  issued: 'bg-info',
  sent_to_provider: 'bg-warning',
  paid: 'bg-success',
  overdue: 'bg-error',
  cancelled: 'bg-neutral',
  partially_paid: 'bg-warning',
  converted: 'bg-info',
  refund: 'bg-warning',
  refunded: 'bg-warning',
}

function getBarColor(status: string): string {
  return statusColorMap[status] || 'bg-neutral'
}
</script>

<template>
  <div class="rounded-lg border border-(--ui-border) bg-(--ui-bg) overflow-hidden flex flex-col h-full">
    <div class="px-5 pt-4 pb-2">
      <h3 class="text-sm font-bold tracking-wide text-(--ui-text) uppercase">
        {{ $t('dashboard.cards.expenses') }}
      </h3>
    </div>

    <div class="px-5 pb-5 flex-1 flex flex-col">
      <template v-if="loading">
        <USkeleton class="w-32 h-8 mb-2" />
        <USkeleton class="w-48 h-4 mb-1" />
        <USkeleton class="w-full h-4" />
      </template>
      <template v-else>
        <!-- Current month indicator -->
        <div class="flex items-center gap-1.5 mb-1">
          <span class="w-2 h-2 rounded-full bg-success" />
          <span class="text-xs text-(--ui-text-muted)">{{ $t('dashboard.cards.currentMonth') }}</span>
        </div>

        <!-- Big amount + change -->
        <div class="flex items-baseline gap-2 mb-1">
          <span class="text-3xl font-semibold text-(--ui-text) tabular-nums">
            {{ formatMoney(currentMonthAmount) }}
          </span>
          <span class="text-sm text-(--ui-text-muted)">RON</span>
          <UBadge v-if="monthChange !== null" :color="monthChange > 0 ? 'error' : 'success'" variant="subtle" size="xs">
            <UIcon :name="monthChange > 0 ? 'i-lucide-trending-up' : 'i-lucide-trending-down'" class="size-3 mr-0.5" />
            {{ Math.abs(monthChange) }}%
          </UBadge>
        </div>

        <!-- Total label -->
        <div class="flex items-center gap-1.5 mb-3">
          <span class="text-xs text-(--ui-text-muted)">{{ $t('common.total') }}: {{ formatMoney(incomingAmount) }} RON</span>
        </div>

        <!-- Description -->
        <p class="text-xs text-(--ui-text-muted) mb-3">
          {{ $t('dashboard.cards.expensesDesc') }}
        </p>

        <!-- Status breakdown -->
        <div v-if="statusEntries.length" class="space-y-2 mt-auto">
          <div v-for="entry in statusEntries" :key="entry.status">
            <div class="flex items-center justify-between text-xs mb-0.5">
              <span class="text-(--ui-text)">{{ $t(`documentStatus.${entry.status}`, entry.status) }}</span>
              <div class="flex items-center gap-2 tabular-nums">
                <span class="text-(--ui-text) font-medium">{{ entry.count }}</span>
                <span class="text-(--ui-text-muted) w-8 text-right">{{ entry.percent }}%</span>
              </div>
            </div>
            <div class="h-1.5 bg-(--ui-bg-elevated) rounded-full overflow-hidden">
              <div
                class="h-full rounded-full transition-all"
                :class="getBarColor(entry.status)"
                :style="{ width: `${entry.percent}%` }"
              />
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
