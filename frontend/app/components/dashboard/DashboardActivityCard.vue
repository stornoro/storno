<script setup lang="ts">
import type { RecentActivityItem } from '~/stores/dashboard'

const props = defineProps<{
  data: RecentActivityItem[]
  loading?: boolean
}>()

const { t: $t } = useI18n()
const activities = computed(() => props.data.slice(0, 8))

function formatDate(dateStr: string | null) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  const now = new Date()
  const isToday = date.toDateString() === now.toDateString()

  const time = date.toLocaleTimeString('ro-RO', { hour: '2-digit', minute: '2-digit' })
  const dateFormatted = date.toLocaleDateString('ro-RO', { day: 'numeric', month: 'short' })

  if (isToday) {
    return `azi, ${dateFormatted}, ora ${time}`
  }
  return `${dateFormatted}, ora ${time}`
}

function getCompanyName(item: RecentActivityItem) {
  if (item.direction === 'incoming') return item.senderName || '-'
  return item.receiverName || '-'
}

function getActivityIcon(item: RecentActivityItem) {
  if (item.paidAt) return 'i-lucide-circle-check'
  if (item.direction === 'incoming') return 'i-lucide-arrow-down-left'
  return 'i-lucide-arrow-up-right'
}

function getActivityIconColor(item: RecentActivityItem) {
  if (item.paidAt) return 'text-success'
  if (item.direction === 'incoming') return 'text-orange-500'
  return 'text-emerald-500'
}

function getDirectionBadge(item: RecentActivityItem) {
  if (item.direction === 'incoming') return $t('invoiceDirection.incoming')
  return $t('invoiceDirection.outgoing')
}

function getActivityLabel(item: RecentActivityItem) {
  if (item.paidAt) {
    return `${$t('dashboard.cards.paymentOf')} ${formatMoney(item.total, item.currency)}`
  }
  return item.number || '-'
}

function formatMoney(amount: string | number, currency = 'RON') {
  const num = Number(amount || 0)
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency }).format(num)
}
</script>

<template>
  <div class="rounded-lg border border-(--ui-border) bg-(--ui-bg) overflow-hidden flex flex-col h-full">
    <div class="px-5 pt-4 pb-2">
      <h3 class="text-sm font-bold tracking-wide text-(--ui-text) uppercase">
        {{ $t('dashboard.cards.activity') }}
      </h3>
    </div>

    <div class="px-5 pb-5 flex-1 flex flex-col overflow-hidden">
      <template v-if="loading">
        <div v-for="i in 4" :key="i" class="flex gap-3 mb-4">
          <USkeleton class="w-6 h-6 rounded shrink-0" />
          <div class="flex-1">
            <USkeleton class="w-3/4 h-4 mb-1" />
            <USkeleton class="w-1/2 h-3" />
          </div>
        </div>
      </template>
      <template v-else>
        <div v-if="activities.length" class="flex-1 overflow-y-auto">
          <NuxtLink
            v-for="item in activities"
            :key="item.id"
            :to="`/invoices/${item.id}`"
            class="flex gap-3 py-2.5 group hover:bg-(--ui-bg-elevated)/50 -mx-2 px-2 rounded-lg transition-colors"
          >
            <UIcon :name="getActivityIcon(item)" class="size-5 shrink-0 mt-0.5" :class="getActivityIconColor(item)" />
            <div class="flex-1 min-w-0">
              <div class="flex items-center justify-between gap-1.5">
                <p class="text-sm text-(--ui-text) group-hover:text-primary transition-colors truncate">
                  {{ getActivityLabel(item) }}
                </p>
                <span
                  class="shrink-0 text-[10px] font-medium px-1.5 py-0.5 rounded-full leading-none"
                  :class="item.direction === 'incoming'
                    ? 'bg-orange-500/10 text-orange-500'
                    : 'bg-emerald-500/10 text-emerald-500'"
                >
                  {{ getDirectionBadge(item) }}
                </span>
              </div>
              <p class="text-xs text-(--ui-text-muted) mt-0.5 truncate">
                {{ getCompanyName(item) }} &middot; {{ formatDate(item.syncedAt || item.issueDate) }}
              </p>
            </div>
          </NuxtLink>
        </div>

        <div v-else class="flex-1 flex items-center justify-center">
          <div class="text-center">
            <UIcon name="i-lucide-activity" class="size-10 text-(--ui-text-muted) mb-3" />
            <p class="text-sm text-(--ui-text-muted)">{{ $t('dashboard.cards.noActivityPeriod') }}</p>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
