<template>
  <ReportsBalanceSectionCard
    icon="i-lucide-droplet"
    :title="$t('reports.balanceAnalysis.sections.liquidity')"
    :subtitle="$t('reports.balanceAnalysis.liquidity.subtitle')"
  >
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.liquidity.currentRatio')"
        :value="data.currentRatio?.value ?? null"
        :status="data.currentRatio?.status ?? 'na'"
        :hint="$t('reports.balanceAnalysis.liquidity.currentRatioHint')"
        format="ratio"
        :suffix="$t('reports.balanceAnalysis.targetRange') + ' ≥ 1.5'"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.liquidity.quickRatio')"
        :value="data.quickRatio?.value ?? null"
        :status="data.quickRatio?.status ?? 'na'"
        :hint="$t('reports.balanceAnalysis.liquidity.quickRatioHint')"
        format="ratio"
        :suffix="$t('reports.balanceAnalysis.targetRange') + ' ≥ 1.0'"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.liquidity.cashRatio')"
        :value="data.cashRatio?.value ?? null"
        :status="data.cashRatio?.status ?? 'na'"
        :hint="$t('reports.balanceAnalysis.liquidity.cashRatioHint')"
        format="ratio"
        :suffix="$t('reports.balanceAnalysis.targetRange') + ' ≥ 0.2'"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.liquidity.workingCapital')"
        :value="data.workingCapital?.value ?? null"
        :status="data.workingCapital?.status ?? 'na'"
        format="money"
        suffix="RON"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.liquidity.workingCapitalLongTerm')"
        :value="data.workingCapitalLongTerm?.value ?? null"
        :status="data.workingCapitalLongTerm?.status ?? 'na'"
        format="money"
        suffix="RON"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.liquidity.workingCapitalRequirement')"
        :value="data.workingCapitalRequirement?.value ?? null"
        :status="data.workingCapitalRequirement?.status ?? 'na'"
        format="money"
        suffix="RON"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.liquidity.netCash')"
        :value="data.netCash?.value ?? null"
        :status="data.netCash?.status ?? 'na'"
        format="money"
        suffix="RON"
      />
    </div>
  </ReportsBalanceSectionCard>
</template>

<script setup lang="ts">
import type { BalanceLiquidity } from '~/types'

defineProps<{
  data: BalanceLiquidity
}>()

const { t: $t } = useI18n()
</script>
