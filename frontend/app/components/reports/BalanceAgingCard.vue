<template>
  <ReportsBalanceSectionCard
    icon="i-lucide-clock"
    :title="$t('reports.balanceAnalysis.sections.aging')"
    :subtitle="$t('reports.balanceAnalysis.aging.subtitle')"
  >
    <div class="space-y-4">
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
        <div
          v-for="bucket in data.buckets"
          :key="bucket.range"
          class="flex flex-col gap-1 p-3 rounded-lg bg-(--ui-bg-elevated)/50"
        >
          <span class="text-xs text-(--ui-text-muted)">{{ bucket.range }} {{ $t('common.days') }}</span>
          <span class="text-lg font-semibold tabular-nums text-(--ui-text-highlighted)">
            {{ formatMoney(bucket.amount) }}
          </span>
          <div class="h-1 rounded-full bg-(--ui-bg-elevated) overflow-hidden mt-1">
            <div
              class="h-full rounded-full transition-all"
              :class="bucketBarClass(bucket.range)"
              :style="{ width: `${bucketShare(bucket.amount)}%` }"
            />
          </div>
        </div>
      </div>

      <div v-if="data.buckets.length > 0" class="flex gap-1 h-3 rounded-full overflow-hidden">
        <div
          v-for="bucket in data.buckets"
          :key="bucket.range + '-bar'"
          class="h-full transition-all"
          :class="bucketBarClass(bucket.range)"
          :style="{ width: `${bucketShare(bucket.amount)}%` }"
        />
      </div>

      <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
        <div class="flex flex-col gap-1 p-3 rounded-lg bg-(--ui-bg-elevated)/50">
          <span class="text-xs text-(--ui-text-muted)">{{ $t('reports.balanceAnalysis.aging.totalUnpaid') }}</span>
          <span class="text-xl font-semibold tabular-nums text-(--ui-text-highlighted)">{{ formatMoney(data.totalUnpaid) }}</span>
        </div>
        <div class="flex flex-col gap-1 p-3 rounded-lg bg-(--ui-bg-elevated)/50">
          <span class="text-xs text-(--ui-text-muted)">{{ $t('reports.balanceAnalysis.aging.percentOver90') }}</span>
          <div class="flex items-baseline gap-1.5">
            <span
              class="text-xl font-semibold tabular-nums"
              :class="overdueTextClass"
            >
              {{ data.percentOver90.toFixed(1) }}%
            </span>
            <span
              v-if="data.overdueStatus !== 'na'"
              class="ml-auto size-2 rounded-full flex-shrink-0"
              :class="overdueDotClass"
            />
          </div>
        </div>
        <div class="flex flex-col gap-1 p-3 rounded-lg bg-(--ui-bg-elevated)/50">
          <div class="flex items-center gap-1">
            <span class="text-xs text-(--ui-text-muted)">{{ $t('reports.balanceAnalysis.aging.estimatedProvision') }}</span>
            <UTooltip :text="$t('reports.balanceAnalysis.aging.provisionHint')" :ui="{ content: 'max-w-64 text-xs' }">
              <UIcon name="i-lucide-info" class="size-3 text-(--ui-text-dimmed) cursor-help" />
            </UTooltip>
          </div>
          <span class="text-xl font-semibold tabular-nums text-(--ui-text-highlighted)">{{ formatMoney(data.estimatedProvision) }}</span>
        </div>
      </div>

      <div v-if="data.countOver90 > 0" class="flex items-center gap-2 text-xs text-warning">
        <UIcon name="i-lucide-alert-triangle" class="size-3.5 flex-shrink-0" />
        <span>{{ data.countOver90 }} {{ $t('reports.balanceAnalysis.aging.countOver90').toLowerCase() }}</span>
      </div>
    </div>
  </ReportsBalanceSectionCard>
</template>

<script setup lang="ts">
import type { BalanceAging } from '~/types'

const props = defineProps<{
  data: BalanceAging
}>()

const { t: $t } = useI18n()
const { formatMoney } = useMoney()

const totalUnpaid = computed(() => parseFloat(props.data.totalUnpaid) || 0)

function bucketShare(amount: string): number {
  const total = totalUnpaid.value
  if (total === 0) return 0
  return Math.min(100, (parseFloat(amount) / total) * 100)
}

function bucketBarClass(range: string): string {
  switch (range) {
    case '0-30': return 'bg-success'
    case '31-60': return 'bg-warning/70'
    case '61-90': return 'bg-warning'
    case '90+': return 'bg-error'
    default: return 'bg-(--ui-text-dimmed)'
  }
}

const overdueTextClass = computed(() => {
  switch (props.data.overdueStatus) {
    case 'normal': return 'text-success'
    case 'warning': return 'text-warning'
    case 'critical': return 'text-error'
    default: return 'text-(--ui-text-muted)'
  }
})

const overdueDotClass = computed(() => {
  switch (props.data.overdueStatus) {
    case 'normal': return 'bg-success'
    case 'warning': return 'bg-warning'
    case 'critical': return 'bg-error'
    default: return 'bg-(--ui-text-dimmed)'
  }
})
</script>
