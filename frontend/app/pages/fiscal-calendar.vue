<script setup lang="ts">
import type { FiscalCalendarItem, FiscalCalendarResponse } from '~/types'

/**
 * The next 90 days of filing deadlines, grouped by month, for the current company or — for an
 * accountant with several companies — across all of them. Each row says why the deadline
 * applies, its status (to file / overdue / filed) and links to create the declaration.
 */
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const { get } = useApi()
const companyStore = useCompanyStore()
const intlLocale = useIntlLocale()

useHead({ title: $t('fiscalCalendar.title') })

const DAYS = 90
const loading = ref(true)
const allCompanies = ref(false)
const items = ref<FiscalCalendarItem[]>([])
const counts = ref({ due: 0, overdue: 0, filed: 0 })
const hasManyCompanies = computed(() => companyStore.companies.length > 1)

async function load() {
  loading.value = true
  try {
    const res = await get<FiscalCalendarResponse>(allCompanies.value ? '/v1/fiscal-calendar/all' : '/v1/fiscal-calendar', { days: DAYS })
    items.value = res.data
    counts.value = res.counts
  } catch {
    items.value = []
    counts.value = { due: 0, overdue: 0, filed: 0 }
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(allCompanies, load)
watch(() => companyStore.currentCompanyId, () => { if (!allCompanies.value) load() })

const months = computed(() => {
  const groups = new Map<string, FiscalCalendarItem[]>()
  for (const item of items.value) {
    const key = item.dueDate.slice(0, 7)
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key)!.push(item)
  }
  return [...groups.entries()].map(([key, list]) => ({ key, label: new Date(key + '-01T00:00:00').toLocaleDateString(intlLocale, { month: 'long', year: 'numeric' }), items: list }))
})

function formatDate(d: string): string {
  return new Date(d + 'T00:00:00').toLocaleDateString(intlLocale, { weekday: 'short', day: 'numeric', month: 'long' })
}
function periodLabel(item: FiscalCalendarItem): string {
  const p = item.period
  if (p.month) return new Date(p.year, p.month - 1, 1).toLocaleDateString(intlLocale, { month: 'long', year: 'numeric' })
  if (p.quarter) return $t('fiscalCalendar.quarter', { quarter: p.quarter, year: p.year })
  return $t('fiscalCalendar.year', { year: p.year })
}
function codeLabel(item: FiscalCalendarItem): string {
  const key = `fiscalCalendar.codes.${item.code}`
  const translated = $t(key)
  return translated === key ? item.label : translated
}
function daysLabel(item: FiscalCalendarItem): string {
  return item.daysLeft < 0 ? $t('fiscalCalendar.daysOverdue', { count: -item.daysLeft }, -item.daysLeft) : $t('fiscalCalendar.daysLeft', { count: item.daysLeft }, item.daysLeft)
}
const statusColor: Record<FiscalCalendarItem['status'], 'error' | 'warning' | 'success'> = { overdue: 'error', due: 'warning', filed: 'success' }

/** Link that opens the declarations page with the create dialog pre-filled for this deadline. */
function createLink(item: FiscalCalendarItem): string {
  const p = item.period
  const query = new URLSearchParams({ create: item.declarationType ?? '', year: String(p.year) })
  if (p.quarter) {
    query.set('month', String(p.quarter * 3))
    query.set('periodType', 'quarterly')
  } else if (p.month) {
    query.set('month', String(p.month))
    query.set('periodType', 'monthly')
  } else {
    query.set('month', '12')
    query.set('periodType', 'annual')
  }
  if (item.company) query.set('company', item.company.id)
  return `/declarations?${query.toString()}`
}
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('fiscalCalendar.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton to="/companies" icon="i-lucide-settings-2" color="neutral" variant="ghost" size="sm">
            {{ $t('fiscalCalendar.openSettings') }}
          </UButton>
          <UButton to="/declarations" icon="i-lucide-file-badge" color="neutral" variant="outline" size="sm">
            {{ $t('fiscalCalendar.openDeclarations') }}
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="p-4 space-y-6">
        <p class="text-sm text-muted">{{ $t('fiscalCalendar.subtitle') }} {{ $t('fiscalCalendar.settingsHint') }}</p>

        <div class="flex flex-wrap items-center gap-3">
          <UBadge color="error" variant="subtle">{{ $t('fiscalCalendar.counts.overdue', { count: counts.overdue }) }}</UBadge>
          <UBadge color="warning" variant="subtle">{{ $t('fiscalCalendar.counts.due', { count: counts.due }) }}</UBadge>
          <UBadge color="success" variant="subtle">{{ $t('fiscalCalendar.counts.filed', { count: counts.filed }) }}</UBadge>
          <span class="text-xs text-(--ui-text-muted)">{{ $t('fiscalCalendar.nextDays', { days: DAYS }) }}</span>
          <div v-if="hasManyCompanies" class="ml-auto flex items-center gap-2 text-sm">
            <span>{{ $t('fiscalCalendar.allCompanies') }}</span>
            <USwitch v-model="allCompanies" />
          </div>
        </div>

        <div v-if="loading" class="space-y-3">
          <USkeleton class="h-6 w-40" />
          <USkeleton class="h-16 w-full" />
          <USkeleton class="h-16 w-full" />
        </div>

        <p v-else-if="!months.length" class="text-sm text-(--ui-text-muted)">{{ $t('fiscalCalendar.empty', { days: DAYS }) }}</p>

        <section v-for="month in months" v-else :key="month.key" class="space-y-2">
          <h2 class="text-sm font-semibold capitalize text-(--ui-text-highlighted)">{{ month.label }}</h2>
          <ul class="divide-y divide-(--ui-border) rounded-lg border border-(--ui-border) bg-(--ui-bg)">
            <li v-for="item in month.items" :key="(item.company?.id ?? '') + item.code + item.dueDate" class="flex flex-wrap items-start gap-3 p-3">
              <div class="w-28 shrink-0">
                <div class="text-sm font-medium">{{ formatDate(item.dueDate) }}</div>
                <div class="text-xs" :class="item.status === 'overdue' ? 'text-error' : 'text-(--ui-text-muted)'">{{ daysLabel(item) }}</div>
              </div>
              <div class="min-w-0 flex-1 space-y-0.5">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-semibold">{{ item.code }}</span>
                  <span class="text-sm">{{ codeLabel(item) }}</span>
                  <UBadge :color="statusColor[item.status]" variant="subtle" size="sm">{{ $t(`fiscalCalendar.status.${item.status}`) }}</UBadge>
                  <UBadge v-if="item.company" color="neutral" variant="outline" size="sm">{{ item.company.name }}</UBadge>
                </div>
                <div class="text-xs text-(--ui-text-muted)">
                  {{ $t('fiscalCalendar.period') }}: {{ periodLabel(item) }} · {{ $t(`fiscalCalendar.because.${item.appliesBecause}`) }}
                  <template v-if="item.nominalDueDate !== item.dueDate"> · {{ $t('fiscalCalendar.shifted', { date: formatDate(item.nominalDueDate) }) }}</template>
                </div>
              </div>
              <div class="shrink-0">
                <UButton v-if="item.declarationType && item.status !== 'filed'" :to="createLink(item)" size="xs" variant="soft" icon="i-lucide-file-plus">
                  {{ $t('fiscalCalendar.createDeclaration') }}
                </UButton>
                <span v-else-if="!item.declarationType" class="text-xs text-(--ui-text-muted)">{{ $t('fiscalCalendar.notInStorno') }}</span>
              </div>
            </li>
          </ul>
        </section>
      </div>
    </template>
  </UDashboardPanel>
</template>
