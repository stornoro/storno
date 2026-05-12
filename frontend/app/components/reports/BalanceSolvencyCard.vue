<template>
  <ReportsBalanceSectionCard
    icon="i-lucide-shield"
    :title="$t('reports.balanceAnalysis.sections.solvency')"
    :subtitle="$t('reports.balanceAnalysis.solvency.subtitle')"
  >
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.solvency.debtToEquity')"
        :value="data.debtToEquity?.value ?? null"
        :status="data.debtToEquity?.status ?? 'na'"
        :hint="$t('reports.balanceAnalysis.solvency.debtToEquityHint')"
        format="ratio"
        :suffix="$t('reports.balanceAnalysis.targetRange') + ' < 1.0'"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.solvency.financialAutonomy')"
        :value="data.financialAutonomy?.value ?? null"
        :status="data.financialAutonomy?.status ?? 'na'"
        :hint="$t('reports.balanceAnalysis.solvency.financialAutonomyHint')"
        format="percent"
        :suffix="$t('reports.balanceAnalysis.targetRange') + ' > 33%'"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.solvency.debtRatio')"
        :value="data.debtRatio?.value ?? null"
        :status="data.debtRatio?.status ?? 'na'"
        :hint="$t('reports.balanceAnalysis.solvency.debtRatioHint')"
        format="percent"
        :suffix="$t('reports.balanceAnalysis.targetRange') + ' < 67%'"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.solvency.generalSolvency')"
        :value="data.generalSolvency?.value ?? null"
        :status="data.generalSolvency?.status ?? 'na'"
        :hint="$t('reports.balanceAnalysis.solvency.generalSolvencyHint')"
        format="ratio"
        :suffix="$t('reports.balanceAnalysis.targetRange') + ' > 1.5'"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.solvency.interestCoverage')"
        :value="data.interestCoverage?.value ?? null"
        :status="data.interestCoverage?.status ?? 'na'"
        :hint="$t('reports.balanceAnalysis.solvency.interestCoverageHint')"
        format="ratio"
        suffix="x"
      />
    </div>
  </ReportsBalanceSectionCard>
</template>

<script setup lang="ts">
import type { BalanceSolvency } from '~/types'

defineProps<{
  data: BalanceSolvency
}>()

const { t: $t } = useI18n()
</script>
