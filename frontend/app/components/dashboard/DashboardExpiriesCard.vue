<script setup lang="ts">
import type { ExpiryRow } from '~/types'

/** Vehicle documents and company items expiring in the next 60 days (expired ones first). */
const { t: $t } = useI18n()
const { get } = useApi()
const intlLocale = useIntlLocale()
const loading = ref(true)
const items = ref<ExpiryRow[]>([])
const counts = ref({ total: 0, expired: 0, due: 0, ok: 0 })

function formatDate(d: string): string {
  return new Date(d + 'T00:00:00').toLocaleDateString(intlLocale, { day: 'numeric', month: 'short' })
}

onMounted(async () => {
  try {
    const res = await get<{ data: ExpiryRow[], counts: typeof counts.value }>('/v1/expiries/upcoming', { days: 60 })
    items.value = res.data
    counts.value = res.counts
  } catch { /* the card shows its empty state */ } finally { loading.value = false }
})

const shown = computed(() => items.value.slice(0, 6))
const dotColor: Record<string, string> = { expired: 'bg-error', due: 'bg-warning', ok: 'bg-success' }
function daysLabel(item: ExpiryRow): string {
  return item.daysLeft < 0 ? $t('fleet.daysOverdue', { count: -item.daysLeft }, -item.daysLeft) : $t('fleet.daysLeft', { count: item.daysLeft }, item.daysLeft)
}
</script>

<template>
  <UCard class="h-full" :ui="{ body: 'p-4 sm:p-5' }">
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-car" class="size-4 text-(--ui-text-muted)" />
        <span class="font-semibold text-sm">{{ $t('dashboard.widgets.expiries.name') }}</span>
      </div>
      <UButton to="/expiries" variant="ghost" size="xs" trailing-icon="i-lucide-arrow-right">{{ $t('common.viewAll') }}</UButton>
    </div>
    <div v-if="loading" class="space-y-2">
      <USkeleton class="h-4 w-3/4" />
      <USkeleton class="h-4 w-1/2" />
    </div>
    <template v-else>
      <ul v-if="shown.length" class="divide-y divide-(--ui-border)">
        <li v-for="item in shown" :key="item.id">
          <NuxtLink :to="item.vehicleId ? `/vehicles/${item.vehicleId}` : '/expiries'" class="flex items-start gap-2 py-2 -mx-1 px-1 rounded hover:bg-(--ui-bg-elevated) transition-colors">
            <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="dotColor[item.status]" />
            <div class="min-w-0 flex-1">
              <div class="text-sm truncate"><span class="font-medium">{{ item.label }}</span><span v-if="item.vehicle"> · {{ item.vehicle.displayName || item.vehicle.plate }}</span></div>
              <div class="text-xs text-(--ui-text-muted) truncate">{{ formatDate(item.expiresAt) }} · {{ daysLabel(item) }}</div>
            </div>
          </NuxtLink>
        </li>
      </ul>
      <p v-else class="text-sm text-(--ui-text-muted) py-2">{{ $t('dashboard.widgets.expiries.nothing') }}</p>
      <div class="flex gap-4 text-xs text-(--ui-text-muted) pt-3 mt-1 border-t border-(--ui-border)">
        <span :class="counts.expired ? 'text-error' : ''">{{ $t('dashboard.widgets.expiries.expired', { count: counts.expired }) }}</span>
        <span>{{ $t('dashboard.widgets.expiries.due', { count: counts.due }) }}</span>
      </div>
    </template>
  </UCard>
</template>
