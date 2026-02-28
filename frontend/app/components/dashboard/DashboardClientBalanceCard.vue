<script setup lang="ts">
import type { RecentActivityItem } from '~/stores/dashboard'

const props = defineProps<{
  recentActivity: RecentActivityItem[]
  loading?: boolean
}>()

const { t: $t } = useI18n()

// Group by counterparty and sum amounts (top 5 clients)
const topClients = computed(() => {
  const clientMap = new Map<string, number>()

  for (const item of props.recentActivity) {
    if (item.direction !== 'outgoing') continue
    const name = item.receiverName || item.senderName || $t('common.unknown')
    const current = clientMap.get(name) || 0
    clientMap.set(name, current + Number(item.total || 0))
  }

  const sorted = [...clientMap.entries()]
    .sort((a, b) => b[1] - a[1])
    .slice(0, 5)

  const maxVal = sorted.length > 0 ? (sorted[0]?.[1] ?? 1) : 1

  return sorted.map(([name, amount]) => ({
    name,
    amount,
    percent: Math.round((amount / maxVal) * 100),
  }))
})

function formatMoney(amount: number) {
  return new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 }).format(amount)
}
</script>

<template>
  <div class="rounded-lg border border-(--ui-border) bg-(--ui-bg) overflow-hidden flex flex-col h-full">
    <div class="px-5 pt-4 pb-2">
      <h3 class="text-sm font-bold tracking-wide text-(--ui-text) uppercase">
        {{ $t('dashboard.cards.clientBalance') }}
      </h3>
    </div>

    <div class="px-5 pb-5 flex-1 flex flex-col">
      <template v-if="loading">
        <div v-for="i in 4" :key="i" class="mb-3">
          <USkeleton class="w-full h-4 mb-1" />
          <USkeleton class="w-3/4 h-2" />
        </div>
      </template>
      <template v-else>
        <!-- Client list with bars -->
        <div v-if="topClients.length" class="space-y-3 flex-1">
          <div v-for="client in topClients" :key="client.name" class="space-y-1">
            <div class="flex items-center justify-between text-sm">
              <span class="text-(--ui-text-muted) truncate mr-2">{{ client.name }}</span>
              <span class="font-semibold text-(--ui-text) tabular-nums whitespace-nowrap">{{ formatMoney(client.amount) }} RON</span>
            </div>
            <div class="h-1.5 bg-(--ui-bg-elevated) rounded-full overflow-hidden">
              <div
                class="h-full bg-primary rounded-full transition-all"
                :style="{ width: `${client.percent}%` }"
              />
            </div>
          </div>
        </div>

        <!-- Empty state -->
        <div v-else class="flex-1 flex flex-col items-center justify-center text-center py-4">
          <UIcon name="i-lucide-users" class="size-10 text-(--ui-text-muted) mb-3" />
          <p class="text-sm text-(--ui-text-muted) mb-4">{{ $t('dashboard.cards.noPeriodData') }}</p>
          <UButton to="/invoices?create=true" color="primary" size="sm">
            {{ $t('dashboard.cards.issueInvoice') }}
          </UButton>
        </div>
      </template>
    </div>
  </div>
</template>
