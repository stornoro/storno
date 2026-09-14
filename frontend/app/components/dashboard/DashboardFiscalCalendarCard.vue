<script setup lang="ts">
import type { FiscalCalendarItem, FiscalCalendarResponse } from '~/types'

/** The next filing deadlines of the company (30 days), with what is overdue and what is already filed. */
const { t: $t } = useI18n()
const { get } = useApi()
const intlLocale = useIntlLocale()
const loading = ref(true)
const items = ref<FiscalCalendarItem[]>([])
const counts = ref({ due: 0, overdue: 0, filed: 0 })

function formatDate(d: string): string {
  return new Date(d).toLocaleDateString(intlLocale, { day: 'numeric', month: 'short' })
}

onMounted(async () => {
  try {
    const res = await get<FiscalCalendarResponse>('/v1/fiscal-calendar', { days: 30 })
    items.value = res.data
    counts.value = res.counts
  } catch { /* the card shows its empty state */ } finally { loading.value = false }
})

const shown = computed(() => items.value.filter(i => i.status !== 'filed').slice(0, 6))
const dotColor: Record<string, string> = { overdue: 'bg-error', due: 'bg-warning', filed: 'bg-success' }
function codeLabel(item: FiscalCalendarItem): string {
  const key = `fiscalCalendar.codes.${item.code}`
  const translated = $t(key)
  return translated === key ? item.label : translated
}
function daysLabel(item: FiscalCalendarItem): string {
  return item.daysLeft < 0 ? $t('fiscalCalendar.daysOverdue', { count: -item.daysLeft }, -item.daysLeft) : $t('fiscalCalendar.daysLeft', { count: item.daysLeft }, item.daysLeft)
}
</script>

<template>
  <UCard class="h-full" :ui="{ body: 'p-4 sm:p-5' }">
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-calendar-days" class="size-4 text-(--ui-text-muted)" />
        <span class="font-semibold text-sm">{{ $t('dashboard.widgets.fiscalCalendar.name') }}</span>
      </div>
      <UButton to="/fiscal-calendar" variant="ghost" size="xs" trailing-icon="i-lucide-arrow-right">{{ $t('common.viewAll') }}</UButton>
    </div>
    <div v-if="loading" class="space-y-2">
      <USkeleton class="h-4 w-3/4" />
      <USkeleton class="h-4 w-1/2" />
    </div>
    <template v-else>
      <ul v-if="shown.length" class="divide-y divide-(--ui-border)">
        <li v-for="item in shown" :key="item.code + item.dueDate">
          <NuxtLink to="/fiscal-calendar" class="flex items-start gap-2 py-2 -mx-1 px-1 rounded hover:bg-(--ui-bg-elevated) transition-colors">
            <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="dotColor[item.status]" />
            <div class="min-w-0 flex-1">
              <div class="text-sm truncate"><span class="font-medium">{{ item.code }}</span> · {{ codeLabel(item) }}</div>
              <div class="text-xs text-(--ui-text-muted) truncate">{{ formatDate(item.dueDate) }} · {{ daysLabel(item) }}</div>
            </div>
          </NuxtLink>
        </li>
      </ul>
      <p v-else class="text-sm text-(--ui-text-muted) py-2">{{ $t('dashboard.widgets.fiscalCalendar.nothing') }}</p>
      <div class="flex gap-4 text-xs text-(--ui-text-muted) pt-3 mt-1 border-t border-(--ui-border)">
        <span :class="counts.overdue ? 'text-error' : ''">{{ $t('dashboard.widgets.fiscalCalendar.overdue', { count: counts.overdue }) }}</span>
        <span>{{ $t('dashboard.widgets.fiscalCalendar.due', { count: counts.due }) }}</span>
      </div>
    </template>
  </UCard>
</template>
