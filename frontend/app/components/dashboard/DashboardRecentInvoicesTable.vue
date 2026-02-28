<template>
  <UCard variant="outline" :ui="{ body: 'p-0' }">
    <template #header>
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <div class="w-8 h-8 rounded-lg bg-info/10 flex items-center justify-center">
            <UIcon name="i-lucide-file-text" class="w-4 h-4 text-info" />
          </div>
          <div>
            <h3 class="font-semibold text-(--ui-text)">{{ $t('dashboard.recentInvoices') }}</h3>
            <p class="text-xs text-(--ui-text-muted)">{{ $t('dashboard.recentActivity') }}</p>
          </div>
        </div>
        <UButton
          to="/invoices"
          variant="link"
          trailing-icon="i-lucide-arrow-right"
          size="sm"
        >
          {{ $t('common.viewAll') }}
        </UButton>
      </div>
    </template>

    <!-- Loading skeleton -->
    <div v-if="loading && !rows.length" class="divide-y divide-default">
      <div v-for="i in 4" :key="i" class="flex items-center gap-4 px-4 py-3">
        <USkeleton class="w-8 h-8 rounded-lg" />
        <div class="flex-1 space-y-1.5">
          <USkeleton class="h-4 w-32" />
          <USkeleton class="h-3 w-20" />
        </div>
        <USkeleton class="h-4 w-24" />
        <USkeleton class="h-4 w-16" />
      </div>
    </div>

    <UTable v-else-if="rows.length" :data="rows" :columns="columns">
      <template #direction-cell="{ row }">
        <div
          class="w-8 h-8 rounded-lg flex items-center justify-center"
          :class="row.original.direction === 'incoming' ? 'bg-info/10' : 'bg-success/10'"
        >
          <UIcon
            :name="row.original.direction === 'incoming' ? 'i-lucide-arrow-down-left' : 'i-lucide-arrow-up-right'"
            class="w-4 h-4"
            :class="row.original.direction === 'incoming' ? 'text-info' : 'text-success'"
          />
        </div>
      </template>

      <template #number-cell="{ row }">
        <NuxtLink :to="`/invoices/${row.original.id}`" class="font-medium hover:text-primary">
          {{ row.original.number }}
        </NuxtLink>
      </template>

      <template #status-cell="{ row }">
        <div class="flex gap-1">
          <UBadge v-if="row.original.paidAt" color="success" variant="subtle" size="xs">
            {{ $t('documentStatus.paid') }}
          </UBadge>
          <UBadge v-else :color="getStatusColor(row.original.status)" variant="subtle" size="xs">
            {{ $t(`documentStatus.${row.original.status}`, row.original.status) }}
          </UBadge>
        </div>
      </template>

      <template #counterparty-cell="{ row }">
        <span class="text-sm text-(--ui-text-muted) truncate">
          {{ row.original.senderName || row.original.receiverName || '-' }}
        </span>
      </template>

      <template #total-cell="{ row }">
        <span class="font-semibold tabular-nums">
          {{ formatMoney(row.original.total, row.original.currency) }}
        </span>
      </template>

      <template #issueDate-cell="{ row }">
        <span class="text-sm text-(--ui-text-muted)">
          {{ row.original.issueDate ? formatDate(row.original.issueDate) : '-' }}
        </span>
      </template>
    </UTable>

    <UEmpty v-else icon="i-lucide-inbox" :title="$t('common.noData')" class="py-12" />

    <template v-if="rows.length" #footer>
      <div class="flex items-center gap-4 text-sm">
        <div class="flex items-center gap-1.5">
          <span class="w-2 h-2 rounded-full bg-info" />
          <span class="text-(--ui-text-muted)">{{ incomingCount }} {{ $t('common.incoming').toLowerCase() }}</span>
        </div>
        <div class="flex items-center gap-1.5">
          <span class="w-2 h-2 rounded-full bg-success" />
          <span class="text-(--ui-text-muted)">{{ outgoingCount }} {{ $t('common.outgoing').toLowerCase() }}</span>
        </div>
      </div>
    </template>
  </UCard>
</template>

<script setup lang="ts">
import type { ColumnDef } from '@tanstack/vue-table'
import type { RecentActivityItem } from '~/stores/dashboard'

const props = defineProps<{
  data: RecentActivityItem[]
  loading?: boolean
}>()

const { t: $t } = useI18n()

const rows = computed(() => props.data.slice(0, 8))

const incomingCount = computed(() => rows.value.filter(r => r.direction === 'incoming').length)
const outgoingCount = computed(() => rows.value.filter(r => r.direction === 'outgoing').length)

const columns: ColumnDef<RecentActivityItem>[] = [
  { accessorKey: 'direction', header: '' },
  { accessorKey: 'number', header: $t('invoices.number') },
  {
    id: 'counterparty',
    header: $t('invoices.counterparty'),
    accessorFn: (row: RecentActivityItem) => row.senderName || row.receiverName || '-',
  },
  { accessorKey: 'total', header: $t('common.total') },
  { accessorKey: 'status', header: $t('invoices.status') },
  { accessorKey: 'issueDate', header: $t('common.date') },
]

function getStatusColor(status: string): 'success' | 'info' | 'warning' | 'error' | 'neutral' {
  const colors: Record<string, 'success' | 'info' | 'warning' | 'error' | 'neutral'> = {
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
  return colors[status] || 'neutral'
}

function formatMoney(amount?: string | number, currency = 'RON') {
  const num = Number(amount || 0)
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency }).format(num)
}

function formatDate(dateStr: string) {
  return new Date(dateStr).toLocaleDateString('ro-RO', {
    day: '2-digit',
    month: 'short',
  })
}
</script>
