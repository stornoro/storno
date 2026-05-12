<template>
  <ReportsBalanceSectionCard
    icon="i-lucide-users"
    :title="$t('reports.balanceAnalysis.sections.concentration')"
    :subtitle="$t('reports.balanceAnalysis.concentration.subtitle')"
  >
    <div class="space-y-4">
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
        <ReportsBalanceRatioTile
          :label="$t('reports.balanceAnalysis.concentration.top5Share')"
          :value="data.top5SharePercent"
          :status="data.top5Status"
          format="percent"
        />
        <ReportsBalanceRatioTile
          :label="$t('reports.balanceAnalysis.concentration.top10Share')"
          :value="data.top10SharePercent"
          :status="'normal'"
          format="percent"
        />
        <div class="flex flex-col gap-1 p-3 rounded-lg bg-(--ui-bg-elevated)/50">
          <span class="text-xs text-(--ui-text-muted)">{{ $t('reports.balanceAnalysis.concentration.totalRevenue') }}</span>
          <span class="text-xl font-semibold tabular-nums text-(--ui-text-highlighted)">{{ formatMoney(data.totalRevenue) }}</span>
        </div>
      </div>

      <div v-if="data.topClients.length > 0">
        <p class="text-xs text-(--ui-text-dimmed) uppercase mb-2">{{ $t('reports.balanceAnalysis.concentration.topClients') }}</p>
        <div class="space-y-1.5">
          <div
            v-for="(client, index) in data.topClients"
            :key="index"
            class="flex items-center gap-3 py-2 px-3 rounded-lg hover:bg-(--ui-bg-elevated)/50 transition-colors"
          >
            <span class="text-xs text-(--ui-text-dimmed) w-4 flex-shrink-0 tabular-nums">{{ index + 1 }}</span>
            <span class="text-sm text-(--ui-text) truncate flex-1 min-w-0">{{ client.name || '—' }}</span>
            <span class="text-sm font-medium tabular-nums text-(--ui-text-highlighted) flex-shrink-0">{{ formatMoney(client.revenue) }}</span>
            <div class="w-20 flex items-center gap-1.5 flex-shrink-0">
              <div class="flex-1 h-1.5 rounded-full bg-(--ui-bg-elevated) overflow-hidden">
                <div
                  class="h-full rounded-full bg-primary transition-all"
                  :style="{ width: `${Math.min(client.percent, 100)}%` }"
                />
              </div>
              <span class="text-xs text-(--ui-text-muted) tabular-nums w-10 text-right">{{ client.percent.toFixed(1) }}%</span>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="text-sm text-(--ui-text-muted) py-2">—</div>
    </div>
  </ReportsBalanceSectionCard>
</template>

<script setup lang="ts">
import type { BalanceClientConcentration } from '~/types'

defineProps<{
  data: BalanceClientConcentration
}>()

const { t: $t } = useI18n()
const { formatMoney } = useMoney()
</script>
