<template>
  <ReportsBalanceSectionCard
    icon="i-lucide-receipt"
    :title="$t('reports.balanceAnalysis.sections.fiscal')"
    :subtitle="$t('reports.balanceAnalysis.fiscal.subtitle')"
  >
    <div class="space-y-5">
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
        <ReportsBalanceRatioTile
          :label="$t('reports.balanceAnalysis.fiscal.vatPayable')"
          :value="data.vatPayable?.value ? parseFloat(data.vatPayable.value) : null"
          :status="data.vatPayable?.status ?? 'na'"
          format="money"
          suffix="RON"
        />
        <ReportsBalanceRatioTile
          :label="$t('reports.balanceAnalysis.fiscal.salaryDebts')"
          :value="data.salaryDebts?.value ? parseFloat(data.salaryDebts.value) : null"
          :status="data.salaryDebts?.status ?? 'na'"
          format="money"
          suffix="RON"
        />
        <ReportsBalanceRatioTile
          :label="$t('reports.balanceAnalysis.fiscal.stateTaxDebts')"
          :value="data.stateTaxDebts?.value ? parseFloat(data.stateTaxDebts.value) : null"
          :status="data.stateTaxDebts?.status ?? 'na'"
          format="money"
          suffix="RON"
        />
      </div>

      <div v-if="data.microThreshold" class="space-y-3">
        <div class="flex items-center justify-between gap-2">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ $t('reports.balanceAnalysis.fiscal.microThreshold') }}</span>
            <UTooltip :text="$t('reports.balanceAnalysis.fiscal.microThresholdHint')" :ui="{ content: 'max-w-64 text-xs' }">
              <UIcon name="i-lucide-info" class="size-3.5 text-(--ui-text-dimmed) cursor-help" />
            </UTooltip>
          </div>
          <div class="flex items-center gap-2">
            <UBadge
              v-if="data.microThreshold.isMicro"
              color="success"
              variant="subtle"
              size="sm"
            >
              {{ $t('reports.balanceAnalysis.fiscal.microRegime') }}
            </UBadge>
            <UBadge
              v-else
              color="neutral"
              variant="subtle"
              size="sm"
            >
              {{ $t('reports.balanceAnalysis.fiscal.profitRegime') }}
            </UBadge>
          </div>
        </div>

        <div
          v-if="data.microThreshold.isMicro"
          class="rounded-lg p-3 space-y-2"
          :class="microThresholdBgClass"
        >
          <div class="flex items-center justify-between text-xs gap-2">
            <span class="text-(--ui-text-muted)">{{ data.microThreshold.revenueEur }} € / {{ data.microThreshold.plafonEur.toLocaleString() }} €</span>
            <span class="font-semibold tabular-nums" :class="microThresholdTextClass">{{ data.microThreshold.usagePercent.toFixed(1) }}%</span>
          </div>
          <UProgress
            :value="Math.min(data.microThreshold.usagePercent, 100)"
            :color="progressColor(data.microThreshold.status)"
            size="sm"
          />
          <div class="flex justify-between text-xs text-(--ui-text-muted)">
            <span>{{ $t('reports.balanceAnalysis.fiscal.usage') }}: {{ data.microThreshold.revenueEur }} €</span>
            <span>{{ $t('reports.balanceAnalysis.fiscal.remaining') }}: {{ remainingEur }} €</span>
          </div>
        </div>
        <div v-else class="text-xs text-(--ui-text-muted) rounded-lg p-3 bg-(--ui-bg-elevated)/50">
          {{ $t('reports.balanceAnalysis.fiscal.profitRegime') }} — {{ $t('reports.balanceAnalysis.fiscal.microThreshold') }} N/A
        </div>
      </div>

      <div v-if="data.vatThreshold" class="space-y-3">
        <div class="flex items-center justify-between gap-2">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ $t('reports.balanceAnalysis.fiscal.vatThreshold') }}</span>
            <UTooltip :text="$t('reports.balanceAnalysis.fiscal.vatThresholdHint')" :ui="{ content: 'max-w-64 text-xs' }">
              <UIcon name="i-lucide-info" class="size-3.5 text-(--ui-text-dimmed) cursor-help" />
            </UTooltip>
          </div>
          <div class="flex items-center gap-2">
            <UBadge
              v-if="data.vatThreshold.isVatPayer"
              color="info"
              variant="subtle"
              size="sm"
            >
              {{ $t('reports.balanceAnalysis.fiscal.vatPayer') }}
            </UBadge>
            <UBadge
              v-else
              color="neutral"
              variant="subtle"
              size="sm"
            >
              {{ $t('reports.balanceAnalysis.fiscal.notVatPayer') }}
            </UBadge>
          </div>
        </div>

        <div v-if="!data.vatThreshold.isVatPayer" class="rounded-lg p-3 space-y-2" :class="vatThresholdBgClass">
          <div class="flex items-center justify-between text-xs gap-2">
            <span class="text-(--ui-text-muted)">
              {{ formatMoney(data.vatThreshold.plafonRon ? parseFloat(data.vatThreshold.plafonRon) * (data.vatThreshold.usagePercent / 100) : 0) }}
              /
              {{ formatMoney(data.vatThreshold.plafonRon) }}
            </span>
            <span class="font-semibold tabular-nums" :class="vatThresholdTextClass">{{ data.vatThreshold.usagePercent.toFixed(1) }}%</span>
          </div>
          <UProgress
            :value="Math.min(data.vatThreshold.usagePercent, 100)"
            :color="progressColor(data.vatThreshold.status)"
            size="sm"
          />
          <div class="flex justify-between text-xs text-(--ui-text-muted)">
            <span>{{ $t('reports.balanceAnalysis.fiscal.usage') }}: {{ data.vatThreshold.usagePercent.toFixed(1) }}%</span>
            <span>{{ $t('reports.balanceAnalysis.fiscal.remaining') }}: {{ (100 - data.vatThreshold.usagePercent).toFixed(1) }}%</span>
          </div>
        </div>
        <div v-else class="text-xs text-(--ui-text-muted) rounded-lg p-3 bg-(--ui-bg-elevated)/50">
          {{ $t('reports.balanceAnalysis.fiscal.vatPayer') }} — {{ $t('reports.balanceAnalysis.fiscal.vatThreshold') }} N/A
        </div>
      </div>
    </div>
  </ReportsBalanceSectionCard>
</template>

<script setup lang="ts">
import type { BalanceFiscal, RatioStatus } from '~/types'

const props = defineProps<{
  data: BalanceFiscal
}>()

const { t: $t } = useI18n()
const { formatMoney } = useMoney()

const remainingEur = computed(() => {
  if (!props.data.microThreshold) return '0'
  const rem = props.data.microThreshold.plafonEur - parseFloat(props.data.microThreshold.revenueEur)
  return Math.max(0, rem).toLocaleString()
})

function progressColor(status: RatioStatus): 'success' | 'warning' | 'error' | 'neutral' {
  switch (status) {
    case 'normal': return 'success'
    case 'warning': return 'warning'
    case 'critical': return 'error'
    default: return 'neutral'
  }
}

const microThresholdBgClass = computed(() => {
  switch (props.data.microThreshold?.status) {
    case 'warning': return 'bg-warning/5'
    case 'critical': return 'bg-error/5'
    default: return 'bg-(--ui-bg-elevated)/50'
  }
})

const microThresholdTextClass = computed(() => {
  switch (props.data.microThreshold?.status) {
    case 'warning': return 'text-warning'
    case 'critical': return 'text-error'
    default: return 'text-(--ui-text-highlighted)'
  }
})

const vatThresholdBgClass = computed(() => {
  switch (props.data.vatThreshold?.status) {
    case 'warning': return 'bg-warning/5'
    case 'critical': return 'bg-error/5'
    default: return 'bg-(--ui-bg-elevated)/50'
  }
})

const vatThresholdTextClass = computed(() => {
  switch (props.data.vatThreshold?.status) {
    case 'warning': return 'text-warning'
    case 'critical': return 'text-error'
    default: return 'text-(--ui-text-highlighted)'
  }
})
</script>
