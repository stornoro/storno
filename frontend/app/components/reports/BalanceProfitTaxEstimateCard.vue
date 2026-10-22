<template>
  <ReportsBalanceSectionCard
    icon="i-lucide-calculator"
    :title="$t('reports.balanceAnalysis.sections.profitTaxEstimate')"
    :subtitle="$t('reports.balanceAnalysis.profitTaxEstimate.subtitle')"
  >
    <div v-if="!data.hasData" class="text-sm text-(--ui-text-muted)">
      {{ $t('reports.balanceAnalysis.profitTaxEstimate.noData') }}
    </div>
    <div v-else class="space-y-4">
      <UAlert
        v-if="data.missingRates.length"
        color="warning"
        variant="soft"
        icon="i-lucide-alert-triangle"
        :description="$t('reports.balanceAnalysis.profitTaxEstimate.missingRates', { currencies: data.missingRates.join(', ') })"
      />

      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
        <ReportsBalanceRatioTile :label="$t('reports.balanceAnalysis.profitTaxEstimate.revenue')" :value="Number(data.ytd.revenue)" status="normal" format="money" suffix="RON" />
        <ReportsBalanceRatioTile :label="$t('reports.balanceAnalysis.profitTaxEstimate.invoicedExpenses')" :value="Number(data.ytd.invoicedExpenses)" status="normal" format="money" suffix="RON" />
        <ReportsBalanceRatioTile :label="$t('reports.balanceAnalysis.profitTaxEstimate.grossProfit')" :value="Number(data.ytd.grossProfit)" status="normal" format="money" suffix="RON" />
        <ReportsBalanceRatioTile :label="$t('reports.balanceAnalysis.profitTaxEstimate.estimatedProfitTax')" :value="Number(data.ytd.estimatedProfitTax)" status="warning" format="money" suffix="RON" :hint="$t('reports.balanceAnalysis.profitTaxEstimate.ytd', { year: data.year })" />
      </div>

      <UTable :data="rows" :columns="columns" />

      <p v-if="data.ytd.invoicedExpenseShare !== null" class="text-xs text-(--ui-text-muted)">
        {{ $t('reports.balanceAnalysis.profitTaxEstimate.share', { percent: data.ytd.invoicedExpenseShare }) }}
        · {{ $t('reports.balanceAnalysis.profitTaxEstimate.invoices') }}: {{ data.ytd.invoiceCount.outgoing }} / {{ data.ytd.invoiceCount.incoming }}
      </p>

      <div v-if="data.tips?.length" class="space-y-2">
        <p class="text-sm font-semibold">{{ $t('reports.balanceAnalysis.profitTaxEstimate.tipsTitle') }}</p>
        <UAlert
          v-for="tip in data.tips"
          :key="tip.code"
          :color="tip.level === 'action' ? 'primary' : tip.level === 'warning' ? 'warning' : 'neutral'"
          variant="soft"
          :icon="tipIcon(tip.code)"
          :title="$t(`reports.balanceAnalysis.profitTaxEstimate.tips.${tip.code}.title`, tipParams(tip))"
          :description="$t(`reports.balanceAnalysis.profitTaxEstimate.tips.${tip.code}.text`, tipParams(tip))"
        />
      </div>
    </div>
  </ReportsBalanceSectionCard>
</template>

<script setup lang="ts">
import type { BalanceProfitTaxEstimate } from '~/types'

const props = defineProps<{ data: BalanceProfitTaxEstimate }>()
const { t: $t } = useI18n()
const { formatMoney } = useMoney()

const tipIcons: Record<string, string> = {
  sponsorship: 'i-lucide-heart-handshake',
  reinvested_profit: 'i-lucide-server',
  missing_expenses: 'i-lucide-file-x',
  dividends: 'i-lucide-coins',
  employee_benefits: 'i-lucide-users',
  rd_deduction: 'i-lucide-flask-conical',
  advance_payments: 'i-lucide-calendar-clock',
}
function tipIcon(code: string): string {
  return tipIcons[code] ?? 'i-lucide-lightbulb'
}
// Money params come as "1234.56" strings: show them in Romanian format, keep the rest as is
function tipParams(tip: { params: Record<string, string | number> }): Record<string, string | number> {
  const out: Record<string, string | number> = {}
  for (const [k, v] of Object.entries(tip.params)) {
    out[k] = typeof v === 'string' && /^-?\d+\.\d{2}$/.test(v) ? Number(v).toLocaleString('ro-RO', { maximumFractionDigits: 0 }) : v
  }
  return out
}

const columns = [
  { accessorKey: 'period', header: $t('reports.balanceAnalysis.profitTaxEstimate.quarter') },
  { accessorKey: 'revenue', header: $t('reports.balanceAnalysis.profitTaxEstimate.revenue') },
  { accessorKey: 'invoicedExpenses', header: $t('reports.balanceAnalysis.profitTaxEstimate.invoicedExpenses') },
  { accessorKey: 'grossProfit', header: $t('reports.balanceAnalysis.profitTaxEstimate.grossProfit') },
  { accessorKey: 'estimatedProfitTax', header: $t('reports.balanceAnalysis.profitTaxEstimate.estimatedProfitTax') },
  { accessorKey: 'microTax', header: $t('reports.balanceAnalysis.profitTaxEstimate.microTax') },
]

const rows = computed(() => [
  ...props.data.quarters.map(q => ({
    period: `T${q.quarter} ${props.data.year}${q.closed ? '' : ` (${$t('reports.balanceAnalysis.profitTaxEstimate.open')})`}`,
    revenue: formatMoney(q.revenue, 'RON'),
    invoicedExpenses: formatMoney(q.invoicedExpenses, 'RON'),
    grossProfit: formatMoney(q.grossProfit, 'RON'),
    estimatedProfitTax: formatMoney(q.estimatedProfitTax, 'RON'),
    microTax: `${formatMoney(q.microTax1, 'RON')} / ${formatMoney(q.microTax3, 'RON')}`,
  })),
  {
    period: $t('reports.balanceAnalysis.profitTaxEstimate.ytd', { year: props.data.year }),
    revenue: formatMoney(props.data.ytd.revenue, 'RON'),
    invoicedExpenses: formatMoney(props.data.ytd.invoicedExpenses, 'RON'),
    grossProfit: formatMoney(props.data.ytd.grossProfit, 'RON'),
    estimatedProfitTax: formatMoney(props.data.ytd.estimatedProfitTax, 'RON'),
    microTax: `${formatMoney(props.data.ytd.microTax1, 'RON')} / ${formatMoney(props.data.ytd.microTax3, 'RON')}`,
  },
])
</script>
