<script setup lang="ts">
const props = defineProps<{
  overdueAmount: string | number
  outstandingAmount: string | number
  loading?: boolean
}>()

const { t: $t } = useI18n()

function formatMoney(amount: string | number) {
  const num = Number(amount || 0)
  return new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 }).format(num)
}
</script>

<template>
  <div class="rounded-lg border border-(--ui-border) bg-(--ui-bg) overflow-hidden">
    <div class="px-5 pt-4 pb-2">
      <h3 class="text-sm font-bold tracking-wide text-(--ui-text) uppercase">
        {{ $t('dashboard.cards.totalDue') }}
      </h3>
    </div>

    <div class="px-5 pb-5">
      <template v-if="loading">
        <div class="flex gap-6">
          <USkeleton class="w-20 h-8" />
          <USkeleton class="w-20 h-8" />
        </div>
      </template>
      <template v-else>
        <div class="flex items-start gap-8">
          <!-- Overdue (today) -->
          <div>
            <span class="text-xs text-(--ui-text-muted) block mb-1">{{ $t('dashboard.cards.today') }}</span>
            <div class="flex items-baseline gap-1">
              <span class="text-2xl font-semibold text-(--ui-text) tabular-nums">
                {{ formatMoney(overdueAmount) }}
              </span>
              <span class="text-sm text-(--ui-text-muted)">RON</span>
            </div>
          </div>

          <!-- Outstanding (7 days) -->
          <div>
            <span class="text-xs text-(--ui-text-muted) block mb-1">{{ $t('dashboard.cards.in7Days') }}</span>
            <div class="flex items-baseline gap-1">
              <span class="text-2xl font-semibold text-(--ui-text) tabular-nums">
                {{ formatMoney(outstandingAmount) }}
              </span>
              <span class="text-sm text-(--ui-text-muted)">RON</span>
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
