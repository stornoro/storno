<script setup lang="ts">
import type { RecentActivityItem } from '~/stores/dashboard'

const props = defineProps<{
  outstandingCount: number
  outstandingAmount: string | number
  overdueCount: number
  overdueAmount: string | number
  recentActivity: RecentActivityItem[]
  loading?: boolean
}>()

const { t: $t } = useI18n()

function formatMoney(amount: string | number, currency = 'RON') {
  const num = Number(amount || 0)
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency, maximumFractionDigits: 2 }).format(num)
}

function formatDate(dateStr: string | null) {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleDateString('ro-RO', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

const hasData = computed(() => props.outstandingCount > 0 || props.overdueCount > 0)

// Get unpaid invoices from recent activity (outgoing invoices that are not paid)
const unpaidInvoices = computed(() => {
  return props.recentActivity
    .filter(item => item.direction === 'outgoing' && !item.paidAt && item.status !== 'cancelled')
    .slice(0, 5)
})

const totalUnpaidAmount = computed(() => Number(props.outstandingAmount || 0))
</script>

<template>
  <div class="rounded-lg border border-(--ui-border) bg-(--ui-bg) overflow-hidden flex flex-col h-full">
    <div class="px-5 pt-4 pb-2">
      <h3 class="text-sm font-bold tracking-wide text-(--ui-text) uppercase">
        {{ $t('dashboard.cards.unpaidInvoices') }}
      </h3>
    </div>

    <div class="px-5 pb-5 flex-1 flex flex-col">
      <template v-if="loading">
        <USkeleton class="w-32 h-8 mb-3" />
        <USkeleton class="w-full h-4 mb-2" />
        <USkeleton class="w-full h-4 mb-2" />
        <USkeleton class="w-full h-4" />
      </template>
      <template v-else>
        <div v-if="hasData" class="flex-1">
          <!-- Period label -->
          <div class="text-xs text-(--ui-text-muted) mb-1">{{ $t('dashboard.last12Months') }}</div>

          <!-- Big amount -->
          <div class="flex items-baseline gap-1.5 mb-4">
            <span class="text-3xl font-semibold text-(--ui-text) tabular-nums">
              {{ formatMoney(totalUnpaidAmount).replace(/\s*RON/, '') }}
            </span>
            <span class="text-sm text-(--ui-text-muted)">RON</span>
          </div>

          <!-- Mini table header -->
          <div v-if="unpaidInvoices.length" class="overflow-x-auto -mx-1">
            <table class="w-full text-xs">
              <thead>
                <tr class="border-b border-(--ui-border)">
                  <th class="text-left py-1.5 px-1 font-medium text-(--ui-text-muted)" />
                  <th class="text-right py-1.5 px-1 font-medium text-(--ui-text-muted)">{{ $t('dashboard.cards.invoiceTotal') }}</th>
                  <th class="text-right py-1.5 px-1 font-medium text-(--ui-text-muted)">{{ $t('dashboard.cards.toCollect') }}</th>
                  <th class="text-right py-1.5 px-1 font-medium text-(--ui-text-muted)">
                    {{ $t('invoices.dueDate') }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="item in unpaidInvoices"
                  :key="item.id"
                  class="border-b border-(--ui-border)/50 last:border-0"
                >
                  <td class="py-2 px-1">
                    <div class="font-medium text-(--ui-text) truncate max-w-32">
                      {{ item.receiverName || item.senderName || '-' }}
                    </div>
                    <div class="text-(--ui-text-muted)">
                      {{ formatDate(item.issueDate) }}
                      <NuxtLink :to="`/invoices/${item.id}`" class="text-primary hover:underline ml-1">
                        {{ item.number }}
                      </NuxtLink>
                    </div>
                  </td>
                  <td class="py-2 px-1 text-right tabular-nums whitespace-nowrap text-(--ui-text)">
                    {{ formatMoney(item.total, item.currency) }}
                  </td>
                  <td class="py-2 px-1 text-right tabular-nums whitespace-nowrap text-(--ui-text)">
                    {{ formatMoney(item.total, item.currency) }}
                  </td>
                  <td class="py-2 px-1 text-right tabular-nums whitespace-nowrap text-(--ui-text-muted)" />
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Empty state with CTA -->
        <div v-else class="flex-1 flex flex-col items-center justify-center text-center py-4">
          <UIcon name="i-lucide-circle-check" class="size-10 text-(--ui-text-muted) mb-3" />
          <p class="text-sm text-(--ui-text-muted) mb-4">{{ $t('dashboard.cards.noPeriodData') }}</p>
          <UButton to="/invoices?create=true" color="primary" size="sm">
            {{ $t('dashboard.cards.issueInvoice') }}
          </UButton>
        </div>
      </template>
    </div>
  </div>
</template>
