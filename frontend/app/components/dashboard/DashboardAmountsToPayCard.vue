<script setup lang="ts">
const props = defineProps<{
  outstandingAmount: string | number
  outstandingCount: number
  overdueAmount: string | number
  overdueCount: number
  currency?: string
  loading?: boolean
}>()

const { t: $t } = useI18n()

function formatMoney(amount: string | number) {
  const num = Number(amount || 0)
  return new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 }).format(num)
}

// outstanding already includes overdue (overdue is a subset)
const totalAmount = computed(() => Number(props.outstandingAmount || 0))
const overdueNum = computed(() => Number(props.overdueAmount || 0))
const inTermenAmount = computed(() => totalAmount.value - overdueNum.value)

const hasData = computed(() => totalAmount.value > 0)

// Breakdown bars
const bars = computed(() => {
  const total = totalAmount.value || 1
  return [
    {
      label: $t('dashboard.cards.inTermen'),
      amount: inTermenAmount.value,
      percent: Math.round((inTermenAmount.value / total) * 100),
      color: 'bg-primary',
    },
    {
      label: $t('dashboard.cards.overdue'),
      amount: overdueNum.value,
      percent: Math.round((overdueNum.value / total) * 100),
      color: 'bg-error',
    },
  ].filter(b => b.amount > 0)
})
</script>

<template>
  <div class="rounded-lg border border-(--ui-border) bg-(--ui-bg) overflow-hidden flex flex-col h-full">
    <div class="px-5 pt-4 pb-2">
      <h3 class="text-sm font-bold tracking-wide text-(--ui-text) uppercase">
        {{ $t('dashboard.cards.amountsToPay') }}
      </h3>
    </div>

    <div class="px-5 pb-5 flex-1 flex flex-col">
      <template v-if="loading">
        <USkeleton class="w-32 h-8 mb-2" />
        <USkeleton class="w-full h-6 mb-2" />
        <USkeleton class="w-full h-4 mb-1" />
        <USkeleton class="w-3/4 h-4" />
      </template>
      <template v-else>
        <!-- Has unpaid amounts -->
        <div v-if="hasData" class="flex-1">
          <!-- Big total -->
          <div class="flex items-baseline gap-1.5 mb-3">
            <span class="text-3xl font-semibold text-(--ui-text) tabular-nums">
              {{ formatMoney(totalAmount) }}
            </span>
            <span class="text-sm text-(--ui-text-muted)">{{ props.currency || 'RON' }}</span>
          </div>

          <!-- Stacked bar -->
          <div class="mb-4">
            <div class="h-5 bg-(--ui-bg-elevated) rounded-full overflow-hidden flex">
              <div
                v-for="bar in bars"
                :key="bar.label"
                class="h-full transition-all first:rounded-l-full last:rounded-r-full"
                :class="bar.color"
                :style="{ width: `${bar.percent}%` }"
              />
            </div>
            <div class="flex justify-between text-xs font-medium text-(--ui-text) mt-1">
              <span class="tabular-nums text-primary">{{ formatMoney(inTermenAmount) }} {{ $t('dashboard.cards.inTermen').toLowerCase() }}</span>
              <span v-if="overdueNum > 0" class="tabular-nums text-error">{{ formatMoney(overdueNum) }} {{ $t('dashboard.cards.overdue').toLowerCase() }}</span>
            </div>
          </div>

          <!-- Description -->
          <p class="text-xs text-(--ui-text-muted) mb-3">
            {{ $t('dashboard.cards.amountsToPayDesc') }}
          </p>

          <!-- Breakdown rows -->
          <div class="space-y-2">
            <div class="flex items-center justify-between text-sm">
              <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-primary" />
                <span class="text-(--ui-text-muted)">{{ $t('dashboard.cards.inTermen') }}</span>
              </div>
              <span class="font-semibold text-(--ui-text) tabular-nums">{{ formatMoney(inTermenAmount) }}</span>
            </div>
            <div v-if="overdueCount > 0" class="flex items-center justify-between text-sm">
              <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-error" />
                <span class="text-(--ui-text-muted)">{{ $t('dashboard.cards.overdue') }}</span>
              </div>
              <span class="font-semibold text-error tabular-nums">{{ formatMoney(overdueAmount) }}</span>
            </div>
          </div>
        </div>

        <!-- All paid - success state -->
        <div v-else class="flex-1 flex flex-col items-center justify-center text-center py-4">
          <div class="text-5xl mb-3">&#x1F44D;</div>
          <p class="text-sm font-semibold text-(--ui-text) mb-1">{{ $t('dashboard.cards.congratulations') }}</p>
          <p class="text-sm text-(--ui-text-muted)">{{ $t('dashboard.cards.allPaid') }}</p>
        </div>
      </template>
    </div>
  </div>
</template>
