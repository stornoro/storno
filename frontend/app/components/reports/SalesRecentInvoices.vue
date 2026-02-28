<template>
  <UCard :ui="{ body: '!p-0' }">
    <template #header>
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <div class="size-7 rounded-md bg-(--ui-primary)/10 flex items-center justify-center">
            <UIcon name="i-lucide-receipt" class="size-3.5 text-(--ui-primary)" />
          </div>
          <p class="text-sm font-semibold text-(--ui-text-highlighted)">
            {{ $t('reports.salesAnalysis.recentInvoices') }}
          </p>
        </div>
        <UButton variant="ghost" size="xs" to="/invoices" trailing-icon="i-lucide-arrow-right">
          {{ $t('reports.salesAnalysis.viewAll') }}
        </UButton>
      </div>
    </template>

    <div v-if="data.length" class="divide-y divide-(--ui-border)">
      <div
        v-for="invoice in data"
        :key="invoice.id"
        class="flex items-center gap-3 px-4 py-2.5 hover:bg-(--ui-bg-elevated) transition-colors duration-100"
      >
        <!-- Status dot -->
        <div
          class="size-2 rounded-full shrink-0 mt-0.5"
          :class="statusDotClass(invoice.status)"
        />

        <!-- Invoice details -->
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-1.5 mb-0.5">
            <NuxtLink
              :to="`/invoices/${invoice.id}`"
              class="text-xs font-semibold text-(--ui-primary) hover:underline truncate"
            >
              {{ invoice.number || $t('reports.salesAnalysis.noInvoice') }}
            </NuxtLink>
            <UBadge :color="statusColor(invoice.status)" variant="subtle" size="xs" class="shrink-0">
              {{ $t(`documentStatus.${invoice.status}`) }}
            </UBadge>
          </div>
          <div class="text-xs text-(--ui-text-muted) truncate">
            {{ invoice.clientName || '-' }}
          </div>
          <div class="flex items-center gap-1.5 mt-0.5">
            <span class="text-[10px] text-(--ui-text-muted)">{{ invoice.issueDate }}</span>
            <span class="text-[10px] text-(--ui-text-muted)">·</span>
            <span class="text-[10px] text-(--ui-text-muted)">{{ relativeDate(invoice.issueDate) }}</span>
            <template v-if="invoice.status === 'paid' && invoice.paidAt">
              <span class="text-[10px] text-(--ui-text-muted)">·</span>
              <UIcon name="i-lucide-circle-check" class="size-3 text-(--ui-success) shrink-0" />
              <span class="text-[10px] text-(--ui-success)">{{ relativeDate(invoice.paidAt) }}</span>
            </template>
          </div>
        </div>

        <!-- Amount -->
        <div class="text-xs font-semibold tabular-nums text-(--ui-text-highlighted) shrink-0">
          {{ formatMoney(invoice.total, invoice.currency) }}
        </div>
      </div>
    </div>

    <UEmpty v-else icon="i-lucide-file-text" :title="$t('reports.noData')" class="py-10" />
  </UCard>
</template>

<script setup lang="ts">
import type { SalesRecentInvoice } from '~/types'

defineProps<{
  data: SalesRecentInvoice[]
}>()

const { t: $t } = useI18n()
const { formatMoney } = useMoney()

type BadgeColor = 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'error' | 'neutral'

function statusColor(status: string): BadgeColor {
  const map: Record<string, BadgeColor> = {
    draft: 'neutral',
    issued: 'info',
    synced: 'info',
    validated: 'success',
    rejected: 'error',
    sent_to_provider: 'warning',
    cancelled: 'neutral',
    paid: 'success',
  }
  return map[status] ?? 'neutral'
}

function statusDotClass(status: string): string {
  const map: Record<string, string> = {
    draft: 'bg-(--ui-text-muted)',
    issued: 'bg-(--ui-info)',
    synced: 'bg-(--ui-info)',
    validated: 'bg-(--ui-success)',
    rejected: 'bg-(--ui-error)',
    sent_to_provider: 'bg-(--ui-warning)',
    cancelled: 'bg-(--ui-text-muted)',
    paid: 'bg-(--ui-success)',
  }
  return map[status] ?? 'bg-(--ui-text-muted)'
}

function relativeDate(dateStr: string): string {
  const now = new Date()
  const date = new Date(dateStr)
  const diffMs = now.getTime() - date.getTime()
  const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))

  if (diffDays === 0) return 'azi'
  if (diffDays === 1) return 'ieri'
  if (diffDays < 7) return `acum ${diffDays} zile`
  if (diffDays < 30) return `acum ${Math.floor(diffDays / 7)} săpt.`
  if (diffDays < 365) return `acum ${Math.floor(diffDays / 30)} luni`
  return `acum ${Math.floor(diffDays / 365)} ani`
}
</script>
