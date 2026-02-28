<template>
  <UCard variant="outline">
    <template #header>
      <h3 class="font-semibold text-(--ui-text)">{{ $t('dashboard.statusBreakdown') }}</h3>
    </template>

    <div v-if="entries.length" class="space-y-3">
      <div v-for="[status, count] in entries" :key="status" class="space-y-1">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2.5">
            <span class="w-2.5 h-2.5 rounded-full shrink-0" :class="statusDotClass(status)" />
            <span class="text-sm text-(--ui-text)">{{ $t(`documentStatus.${status}`, status) }}</span>
          </div>
          <span class="text-sm font-semibold text-(--ui-text) tabular-nums">{{ count }}</span>
        </div>
        <UProgress v-if="total > 0" :model-value="Math.round((count / total) * 100)" :color="statusProgressColor(status)" size="xs" />
      </div>
    </div>

    <UEmpty v-else icon="i-lucide-bar-chart-3" :title="$t('common.noData')" />
  </UCard>
</template>

<script setup lang="ts">
const props = defineProps<{
  data: Record<string, number>
}>()

const { t: $t } = useI18n()

const entries = computed(() => Object.entries(props.data))

const total = computed(() =>
  Object.values(props.data).reduce((sum, v) => sum + v, 0),
)

type ProgressColor = 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'error' | 'neutral'

const statusColorMap: Record<string, ProgressColor> = {
  synced: 'info',
  validated: 'success',
  rejected: 'error',
  draft: 'neutral',
  issued: 'info',
  sent_to_provider: 'warning',
  paid: 'success',
  overdue: 'error',
  cancelled: 'neutral',
  partially_paid: 'warning',
  converted: 'info',
  refund: 'warning',
  refunded: 'warning',
}

function statusProgressColor(status: string): ProgressColor {
  return statusColorMap[status] || 'neutral'
}

const statusDotBgMap: Record<string, string> = {
  synced: 'bg-info',
  validated: 'bg-success',
  rejected: 'bg-error',
  draft: 'bg-neutral',
  issued: 'bg-info',
  sent_to_provider: 'bg-warning',
  paid: 'bg-success',
  overdue: 'bg-error',
  cancelled: 'bg-neutral',
  partially_paid: 'bg-warning',
  converted: 'bg-info',
}

function statusDotClass(status: string): string {
  return statusDotBgMap[status] || 'bg-neutral'
}
</script>
