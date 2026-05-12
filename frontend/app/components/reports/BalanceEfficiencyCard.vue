<template>
  <ReportsBalanceSectionCard
    icon="i-lucide-zap"
    :title="$t('reports.balanceAnalysis.sections.efficiency')"
    :subtitle="$t('reports.balanceAnalysis.efficiency.subtitle')"
  >
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.efficiency.assetTurnover')"
        :value="data.assetTurnover?.value ?? null"
        :status="data.assetTurnover?.status ?? 'na'"
        :hint="$t('reports.balanceAnalysis.efficiency.assetTurnoverHint')"
        format="ratio"
        suffix="x"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.efficiency.fixedAssetTurnover')"
        :value="data.fixedAssetTurnover?.value ?? null"
        :status="data.fixedAssetTurnover?.status ?? 'na'"
        format="ratio"
        suffix="x"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.efficiency.inventoryTurnover')"
        :value="data.inventoryTurnover?.value ?? null"
        :status="data.inventoryTurnover?.status ?? 'na'"
        format="ratio"
        suffix="x"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.efficiency.inventoryDays')"
        :value="data.inventoryDays?.value ?? null"
        :status="data.inventoryDays?.status ?? 'na'"
        format="days"
        :suffix="$t('common.days')"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.efficiency.dso')"
        :value="data.dso?.value ?? null"
        :status="data.dso?.status ?? 'na'"
        :hint="$t('reports.balanceAnalysis.efficiency.dsoHint')"
        format="days"
        :suffix="$t('common.days')"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.efficiency.dpo')"
        :value="data.dpo?.value ?? null"
        :status="data.dpo?.status ?? 'na'"
        :hint="$t('reports.balanceAnalysis.efficiency.dpoHint')"
        format="days"
        :suffix="$t('common.days')"
      />
      <ReportsBalanceRatioTile
        :label="$t('reports.balanceAnalysis.efficiency.cashConversionCycle')"
        :value="data.cashConversionCycle?.value ?? null"
        :status="data.cashConversionCycle?.status ?? 'na'"
        :hint="$t('reports.balanceAnalysis.efficiency.cashConversionCycleHint')"
        format="days"
        :suffix="$t('common.days')"
      />
    </div>
  </ReportsBalanceSectionCard>
</template>

<script setup lang="ts">
import type { BalanceEfficiency } from '~/types'

defineProps<{
  data: BalanceEfficiency
}>()

const { t: $t } = useI18n()
</script>
