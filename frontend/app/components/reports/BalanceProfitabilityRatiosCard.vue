<template>
  <ReportsBalanceSectionCard
    icon="i-lucide-trending-up"
    :title="$t('reports.balanceAnalysis.sections.profitabilityRatios')"
    :subtitle="$t('reports.balanceAnalysis.profitabilityRatios.subtitle')"
  >
    <div class="space-y-4">
      <div>
        <p class="text-xs text-(--ui-text-dimmed) uppercase mb-2">Marje</p>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
          <ReportsBalanceRatioTile
            :label="$t('reports.balanceAnalysis.profitabilityRatios.grossMargin')"
            :value="data.grossMargin?.value ?? null"
            :status="data.grossMargin?.status ?? 'na'"
            :hint="$t('reports.balanceAnalysis.profitabilityRatios.grossMarginHint')"
            format="percent"
          />
          <ReportsBalanceRatioTile
            :label="$t('reports.balanceAnalysis.profitabilityRatios.operatingMargin')"
            :value="data.operatingMargin?.value ?? null"
            :status="data.operatingMargin?.status ?? 'na'"
            format="percent"
          />
          <ReportsBalanceRatioTile
            :label="$t('reports.balanceAnalysis.profitabilityRatios.ebitdaMargin')"
            :value="data.ebitdaMargin?.value ?? null"
            :status="data.ebitdaMargin?.status ?? 'na'"
            format="percent"
          />
          <ReportsBalanceRatioTile
            :label="$t('reports.balanceAnalysis.profitabilityRatios.netMargin')"
            :value="data.netMargin?.value ?? null"
            :status="data.netMargin?.status ?? 'na'"
            format="percent"
          />
        </div>
      </div>

      <div>
        <p class="text-xs text-(--ui-text-dimmed) uppercase mb-2">Rentabilitate</p>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
          <ReportsBalanceRatioTile
            :label="$t('reports.balanceAnalysis.profitabilityRatios.returnOnAssets')"
            :value="data.returnOnAssets?.value ?? null"
            :status="data.returnOnAssets?.status ?? 'na'"
            :hint="$t('reports.balanceAnalysis.profitabilityRatios.returnOnAssetsHint')"
            format="percent"
          />
          <ReportsBalanceRatioTile
            :label="$t('reports.balanceAnalysis.profitabilityRatios.returnOnEquity')"
            :value="data.returnOnEquity?.value ?? null"
            :status="data.returnOnEquity?.status ?? 'na'"
            :hint="$t('reports.balanceAnalysis.profitabilityRatios.returnOnEquityHint')"
            format="percent"
          />
          <ReportsBalanceRatioTile
            :label="$t('reports.balanceAnalysis.profitabilityRatios.returnOnCapitalEmployed')"
            :value="data.returnOnCapitalEmployed?.value ?? null"
            :status="data.returnOnCapitalEmployed?.status ?? 'na'"
            :hint="$t('reports.balanceAnalysis.profitabilityRatios.returnOnCapitalEmployedHint')"
            format="percent"
          />
          <ReportsBalanceRatioTile
            :label="$t('reports.balanceAnalysis.profitabilityRatios.ebit')"
            :value="data.ebit ? parseFloat(data.ebit) : null"
            :status="data.ebit ? 'normal' : 'na'"
            format="money"
            suffix="RON"
          />
          <ReportsBalanceRatioTile
            :label="$t('reports.balanceAnalysis.profitabilityRatios.ebitda')"
            :value="data.ebitda ? parseFloat(data.ebitda) : null"
            :status="data.ebitda ? 'normal' : 'na'"
            format="money"
            suffix="RON"
          />
        </div>
      </div>
    </div>
  </ReportsBalanceSectionCard>
</template>

<script setup lang="ts">
import type { BalanceProfitabilityRatios } from '~/types'

defineProps<{
  data: BalanceProfitabilityRatios
}>()

const { t: $t } = useI18n()
</script>
