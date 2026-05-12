<template>
  <ReportsBalanceSectionCard
    icon="i-lucide-waves"
    :title="$t('reports.balanceAnalysis.sections.cashflow')"
    :subtitle="$t('reports.balanceAnalysis.cashflow.subtitle')"
  >
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-3 gap-2">
      <div class="col-span-2 sm:col-span-1 p-3 rounded-lg bg-(--ui-bg-elevated)/50">
        <div class="flex items-center justify-between gap-1 mb-1">
          <span class="text-xs text-(--ui-text-muted) truncate leading-tight">{{ $t('reports.balanceAnalysis.cashflow.cashRunwayMonths') }}</span>
          <UTooltip :text="$t('reports.balanceAnalysis.cashflow.cashRunwayHint')" :ui="{ content: 'max-w-64 text-xs' }">
            <UIcon name="i-lucide-info" class="size-3 text-(--ui-text-dimmed) flex-shrink-0 cursor-help" />
          </UTooltip>
        </div>
        <div class="flex items-baseline gap-1.5">
          <span
            class="text-3xl font-bold tabular-nums leading-tight"
            :class="runwayTextClass"
          >
            {{ runwayDisplay }}
          </span>
          <span v-if="data.cashRunwayMonths?.status !== 'na' && data.cashRunwayMonths?.value !== null" class="text-sm text-(--ui-text-muted)">
            {{ $t('common.months') }}
          </span>
          <span
            v-if="data.cashRunwayMonths?.status !== 'na'"
            class="ml-auto size-2 rounded-full flex-shrink-0"
            :class="runwayDotClass"
          />
        </div>
      </div>

      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.cashflow.monthlyBurnRate')"
        :value="data.monthlyBurnRate?.value ? parseFloat(data.monthlyBurnRate.value) : null"
        :status="data.monthlyBurnRate?.status ?? 'na'"
        format="money"
        suffix="RON"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.cashflow.breakEvenRevenue')"
        :value="data.breakEvenRevenue?.value ? parseFloat(data.breakEvenRevenue.value) : null"
        :status="data.breakEvenRevenue?.status ?? 'na'"
        :hint="$t('reports.balanceAnalysis.cashflow.breakEvenRevenueHint')"
        format="money"
        suffix="RON"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.cashflow.breakEvenMonths')"
        :value="data.breakEvenMonths?.value ?? null"
        :status="data.breakEvenMonths?.status ?? 'na'"
        format="days"
        :suffix="$t('common.months')"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.cashflow.contributionRate')"
        :value="data.contributionRatePercent !== undefined ? data.contributionRatePercent : null"
        :status="data.contributionRatePercent !== undefined ? 'normal' : 'na'"
        format="percent"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.cashflow.operatingLeverage')"
        :value="data.operatingLeverage?.value ?? null"
        :status="data.operatingLeverage?.status ?? 'na'"
        format="ratio"
        suffix="x"
      />
    </div>
  </ReportsBalanceSectionCard>
</template>

<script setup lang="ts">
import type { BalanceCashflow } from '~/types'

const props = defineProps<{
  data: BalanceCashflow
}>()

const { t: $t } = useI18n()

const runwayDisplay = computed(() => {
  const v = props.data.cashRunwayMonths?.value
  if (props.data.cashRunwayMonths?.status === 'na' || v == null) return '—'
  return Math.round(v).toString()
})

const runwayTextClass = computed(() => {
  switch (props.data.cashRunwayMonths?.status) {
    case 'normal': return 'text-success'
    case 'warning': return 'text-warning'
    case 'critical': return 'text-error'
    default: return 'text-(--ui-text-muted)'
  }
})

const runwayDotClass = computed(() => {
  switch (props.data.cashRunwayMonths?.status) {
    case 'normal': return 'bg-success'
    case 'warning': return 'bg-warning'
    case 'critical': return 'bg-error'
    default: return 'bg-(--ui-text-dimmed)'
  }
})
</script>
