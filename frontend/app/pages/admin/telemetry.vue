<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('admin.telemetry')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
    <UPageHeader :title="$t('admin.telemetry')" :description="$t('admin.telemetryDescription')" />

    <div v-if="loading" class="flex justify-center py-12">
      <UIcon name="i-lucide-loader-2" class="animate-spin text-2xl" />
    </div>

    <div v-else class="space-y-6">
      <!-- Stat Cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <UCard>
          <div class="text-sm text-muted">{{ $t('admin.telemetryTotalEvents') }}</div>
          <div class="text-3xl font-bold mt-1">{{ stats.totalEvents.toLocaleString(intlLocale) }}</div>
        </UCard>
        <UCard>
          <div class="text-sm text-muted">{{ $t('admin.telemetryToday') }}</div>
          <div class="text-3xl font-bold mt-1">{{ stats.todayEvents.toLocaleString(intlLocale) }}</div>
        </UCard>
        <UCard>
          <div class="text-sm text-muted">{{ $t('admin.telemetryThisWeek') }}</div>
          <div class="text-3xl font-bold mt-1">{{ stats.weekEvents.toLocaleString(intlLocale) }}</div>
        </UCard>
        <UCard>
          <div class="text-sm text-muted">{{ $t('admin.telemetryActiveUsers') }}</div>
          <div class="text-3xl font-bold mt-1">{{ stats.uniqueUsers.toLocaleString(intlLocale) }}</div>
        </UCard>
      </div>

      <!-- Daily Trend Chart -->
      <UCard>
        <template #header>
          <h3 class="font-semibold">{{ $t('admin.telemetryDailyTrend') }}</h3>
        </template>
        <ClientOnly>
          <template #fallback>
            <USkeleton class="w-full h-72" />
          </template>
          <div class="h-72">
            <Bar
              v-if="stats.dailyTrend.length"
              :key="colorMode.value"
              :data="chartData"
              :options="chartOptions"
            />
            <div v-else class="flex items-center justify-center h-full text-muted">
              {{ $t('common.noData') }}
            </div>
          </div>
        </ClientOnly>
      </UCard>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Top Events -->
        <UCard>
          <template #header>
            <h3 class="font-semibold">{{ $t('admin.telemetryTopEvents') }}</h3>
          </template>
          <UTable :data="stats.topEvents" :columns="topEventsColumns">
            <template #count-cell="{ row }">
              <span class="font-semibold tabular-nums">{{ row.original.count.toLocaleString(intlLocale) }}</span>
            </template>
          </UTable>
          <div v-if="!stats.topEvents.length" class="text-muted text-center py-4">
            {{ $t('common.noData') }}
          </div>
        </UCard>

        <!-- Platform Breakdown -->
        <UCard>
          <template #header>
            <h3 class="font-semibold">{{ $t('admin.telemetryPlatform') }}</h3>
          </template>
          <div class="flex flex-wrap gap-3">
            <div
              v-for="p in stats.platformBreakdown"
              :key="p.platform"
              class="flex items-center gap-2 px-3 py-2 rounded-lg bg-elevated"
            >
              <UBadge :color="platformColor(p.platform)" variant="subtle">{{ p.platform }}</UBadge>
              <span class="text-lg font-semibold tabular-nums">{{ p.count.toLocaleString(intlLocale) }}</span>
            </div>
          </div>
          <div v-if="!stats.platformBreakdown.length" class="text-muted text-center py-4">
            {{ $t('common.noData') }}
          </div>
        </UCard>
      </div>

      <!-- Event Log -->
      <UCard>
        <template #header>
          <div class="flex items-center justify-between gap-4 flex-wrap">
            <h3 class="font-semibold">{{ $t('admin.telemetryEventLog') }}</h3>
            <div class="flex items-center gap-2">
              <USelectMenu
                v-model="filterEvent"
                :items="eventFilterOptions"
                value-key="value"
                :placeholder="$t('admin.telemetryAllEvents')"
                class="w-48"
              />
              <USelectMenu
                v-model="filterPlatform"
                :items="platformFilterOptions"
                value-key="value"
                :placeholder="$t('admin.telemetryAllPlatforms')"
                class="w-40"
              />
            </div>
          </div>
        </template>
        <UTable :data="events.items" :columns="eventColumns">
          <template #userId-cell="{ row }">
            <span v-if="row.original.userEmail" class="text-sm" :title="row.original.userId">
              {{ row.original.userEmail }}<span v-if="row.original.userName" class="text-muted"> · {{ row.original.userName }}</span>
            </span>
            <span v-else-if="row.original.userId" class="text-xs text-muted font-mono" :title="row.original.userId">
              {{ row.original.userId.slice(0, 8) }}…
            </span>
            <span v-else class="text-muted">-</span>
          </template>
          <template #properties-cell="{ row }">
            <span class="text-xs text-muted truncate max-w-48 block">
              {{ row.original.properties ? JSON.stringify(row.original.properties) : '-' }}
            </span>
          </template>
          <template #createdAt-cell="{ row }">
            <span class="text-sm text-muted">{{ formatDate(row.original.createdAt) }}</span>
          </template>
        </UTable>
        <div v-if="!events.items.length" class="text-muted text-center py-4">
          {{ $t('common.noData') }}
        </div>
        <template v-if="events.total > eventsLimit" #footer>
          <div class="flex justify-center">
            <UPagination
              v-model="eventsPage"
              :total="events.total"
              :items-per-page="eventsLimit"
            />
          </div>
        </template>
      </UCard>
    </div>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Tooltip,
  Legend,
} from 'chart.js'
import { Bar } from 'vue-chartjs'

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend)

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const intlLocale = useIntlLocale()
const { colorMode, chartColors, defaultChartOptions } = useChartColors()

const loading = ref(true)

const stats = ref<{
  totalEvents: number
  todayEvents: number
  weekEvents: number
  uniqueUsers: number
  topEvents: { event: string; count: number }[]
  platformBreakdown: { platform: string; count: number }[]
  dailyTrend: { date: string; count: number }[]
}>({
  totalEvents: 0,
  todayEvents: 0,
  weekEvents: 0,
  uniqueUsers: 0,
  topEvents: [],
  platformBreakdown: [],
  dailyTrend: [],
})

const events = ref<{
  items: any[]
  total: number
}>({ items: [], total: 0 })

const eventsPage = ref(1)
const eventsLimit = 20
const filterEvent = ref('all')
const filterPlatform = ref('all')

const eventFilterOptions = computed(() => {
  const options = [{ label: $t('admin.telemetryAllEvents'), value: 'all' }]
  for (const e of stats.value.topEvents) {
    options.push({ label: e.event, value: e.event })
  }
  return options
})

const platformFilterOptions = computed(() => {
  const options = [{ label: $t('admin.telemetryAllPlatforms'), value: 'all' }]
  for (const p of stats.value.platformBreakdown) {
    options.push({ label: p.platform, value: p.platform })
  }
  return options
})

const topEventsColumns = [
  { accessorKey: 'event', header: $t('admin.telemetryEventName') },
  { accessorKey: 'count', header: $t('common.total') },
]

const eventColumns = [
  { accessorKey: 'event', header: $t('admin.telemetryEventName') },
  { accessorKey: 'platform', header: $t('admin.telemetryPlatform') },
  { accessorKey: 'appVersion', header: $t('admin.telemetryAppVersion') },
  { accessorKey: 'userId', header: $t('admin.users') },
  { accessorKey: 'properties', header: $t('common.details') },
  { accessorKey: 'createdAt', header: $t('common.date') },
]

const chartData = computed(() => ({
  labels: stats.value.dailyTrend.map(d => {
    const parts = d.date.split('-')
    return `${parts[2]}/${parts[1]}`
  }),
  datasets: [{
    label: $t('admin.telemetryEventName'),
    data: stats.value.dailyTrend.map(d => d.count),
    backgroundColor: `color-mix(in srgb, ${chartColors.primary} 70%, transparent)`,
    borderColor: chartColors.primary,
    borderWidth: 1,
    borderRadius: 4,
  }],
}))

const chartOptions = computed(() => ({
  ...defaultChartOptions.value,
  plugins: {
    ...defaultChartOptions.value.plugins,
    legend: { display: false },
  },
}))

type BadgeColor = 'error' | 'neutral' | 'info' | 'primary' | 'warning' | 'success' | 'secondary'

function platformColor(platform: string): BadgeColor {
  const colors: Record<string, BadgeColor> = {
    web: 'info',
    ios: 'neutral',
    android: 'success',
    mobile: 'warning',
  }
  return colors[platform] || 'neutral'
}

function formatDate(iso: string | null): string {
  if (!iso) return '-'
  return new Date(iso).toLocaleString(intlLocale)
}

const { get } = useApi()

async function loadStats() {
  try {
    stats.value = await get<any>('/v1/admin/telemetry/stats')
  } catch {
    // Not authorized or error
  }
}

async function loadEvents() {
  try {
    const params = new URLSearchParams()
    params.set('page', String(eventsPage.value))
    params.set('limit', String(eventsLimit))
    if (filterEvent.value !== 'all') params.set('event', filterEvent.value)
    if (filterPlatform.value !== 'all') params.set('platform', filterPlatform.value)

    events.value = await get<any>(`/v1/admin/telemetry/events?${params}`)
  } catch {
    // Not authorized or error
  }
}

watch(eventsPage, () => loadEvents())
watch([filterEvent, filterPlatform], () => {
  eventsPage.value = 1
  loadEvents()
})

onMounted(async () => {
  try {
    await Promise.all([loadStats(), loadEvents()])
  } finally {
    loading.value = false
  }
})
</script>
