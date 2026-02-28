<template>
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
    <UCard v-for="indicator in indicatorList" :key="indicator.key">
      <p class="text-xs text-(--ui-text-muted) uppercase mb-1">
        {{ $t(`reports.balanceAnalysis.${indicator.key}`) }}
      </p>
      <p class="text-2xl font-bold tabular-nums" :class="indicator.colorClass">
        {{ formatMoney(props.indicators[indicator.key]) }}
      </p>
    </UCard>
  </div>
</template>

<script setup lang="ts">
import type { BalanceIndicators } from '~/types'

const props = defineProps<{
  indicators: BalanceIndicators
}>()

const { t: $t } = useI18n()
const { formatMoney } = useMoney()

interface IndicatorConfig {
  key: keyof BalanceIndicators
  colorClass: string
}

const indicatorList: IndicatorConfig[] = [
  { key: 'revenue', colorClass: 'text-green-600 dark:text-green-400' },
  { key: 'expenses', colorClass: 'text-red-600 dark:text-red-400' },
  { key: 'netProfit', colorClass: 'text-green-600 dark:text-green-400' },
  { key: 'turnover', colorClass: 'text-green-600 dark:text-green-400' },
  { key: 'salaries', colorClass: 'text-amber-600 dark:text-amber-400' },
  { key: 'profitTax', colorClass: 'text-amber-600 dark:text-amber-400' },
  { key: 'supplierDebts', colorClass: 'text-amber-600 dark:text-amber-400' },
  { key: 'clientReceivables', colorClass: 'text-blue-600 dark:text-blue-400' },
  { key: 'bankBalance', colorClass: 'text-blue-600 dark:text-blue-400' },
  { key: 'cashBalance', colorClass: 'text-blue-600 dark:text-blue-400' },
]
</script>
