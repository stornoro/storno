<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const intlLocale = useIntlLocale()

const rows = ref<any[]>([])
const summary = ref({ activeUsers: 0, invoicesCreated: 0, invoicesIssued: 0 })
const loading = ref(true)
const days = ref(30)
const exclude = useAdminAuditExclude()

const dayOptions = computed(() => [7, 30, 90, 365].map(n => ({ label: $t('admin.activity.lastDays', { n }), value: n })))

const columns = [
  { accessorKey: 'user', header: $t('admin.auditUser') },
  { accessorKey: 'organizations', header: $t('admin.activity.organizations') },
  { accessorKey: 'invoicesIssued', header: $t('admin.activity.invoicesIssued') },
  { accessorKey: 'invoicesCreated', header: $t('admin.activity.invoicesCreated') },
  { accessorKey: 'activeDays', header: $t('admin.activity.activeDays') },
  { accessorKey: 'events', header: $t('admin.activity.events') },
  { accessorKey: 'lastInvoiceAt', header: $t('admin.activity.lastInvoice') },
  { accessorKey: 'lastActiveAt', header: $t('admin.activity.lastActive') },
]

function planColor(plan: string): string {
  const colors: Record<string, string> = {
    freemium: 'neutral',
    free: 'neutral',
    starter: 'info',
    professional: 'success',
    business: 'warning',
  }
  return colors[plan] || 'neutral'
}

function formatDate(iso: string | null): string {
  if (!iso) return '-'
  return new Date(iso).toLocaleDateString(intlLocale, { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function formatRelative(iso: string | null): string {
  if (!iso) return '-'
  const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000)
  if (minutes < 1) return $t('admin.justNow')
  if (minutes < 60) return $t('admin.minutesAgo', { n: minutes })
  const hours = Math.round(minutes / 60)
  if (hours < 24) return $t('admin.hoursAgo', { n: hours })
  const d = Math.round(hours / 24)
  if (d < 7) return $t('admin.daysAgo', { n: d })
  return formatDate(iso)
}

async function fetchActivity() {
  loading.value = true
  try {
    const { get } = useApi()
    const params: Record<string, any> = { days: days.value, limit: 100 }
    if (exclude.value.trim()) params.exclude = exclude.value.trim()
    const data = await get<any>('/v1/admin/activity', params)
    rows.value = data.data || []
    summary.value = data.summary || { activeUsers: 0, invoicesCreated: 0, invoicesIssued: 0 }
  } catch {
    rows.value = []
  } finally {
    loading.value = false
  }
}

const onExcludeInput = useDebounceFn(fetchActivity, 400)
watch(days, fetchActivity)
onMounted(fetchActivity)
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
    <UPageHeader :title="$t('admin.activity.title')" :description="$t('admin.activity.description')" />

    <div class="flex items-center gap-3 mb-6">
      <UButton icon="i-lucide-arrow-left" variant="ghost" to="/admin" />
      <h1 class="text-2xl font-bold">{{ $t('admin.activity.title') }}</h1>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 shrink-0">
      <UCard>
        <div class="text-sm text-muted">{{ $t('admin.activity.activeUsers') }}</div>
        <div class="text-3xl font-bold mt-1">{{ summary.activeUsers }}</div>
      </UCard>
      <UCard>
        <div class="text-sm text-muted">{{ $t('admin.activity.invoicesIssued') }}</div>
        <div class="text-3xl font-bold mt-1">{{ summary.invoicesIssued }}</div>
      </UCard>
      <UCard>
        <div class="text-sm text-muted">{{ $t('admin.activity.invoicesCreated') }}</div>
        <div class="text-3xl font-bold mt-1">{{ summary.invoicesCreated }}</div>
      </UCard>
    </div>

    <UDashboardToolbar class="mb-2">
      <template #left>
        <UInput
          v-model="exclude"
          :placeholder="$t('admin.auditExcludePlaceholder')"
          icon="i-lucide-filter-x"
          class="w-80"
          :title="$t('admin.auditExcludeHint')"
          @update:model-value="onExcludeInput"
        />
      </template>
      <template #right>
        <USelectMenu v-model="days" :items="dayOptions" value-key="value" :placeholder="$t('admin.activity.period')" class="w-44" />
      </template>
    </UDashboardToolbar>
    <p class="text-xs text-muted mb-4">{{ $t('admin.activity.hint') }}</p>

    <UTable :data="rows" :columns="columns" :loading="loading">
      <template #user-cell="{ row }">
        <div class="flex flex-col">
          <span class="text-sm font-medium">{{ row.original.user.email }}</span>
          <span v-if="row.original.user.fullName" class="text-xs text-muted">{{ row.original.user.fullName }}</span>
        </div>
      </template>

      <template #organizations-cell="{ row }">
        <div class="flex flex-wrap gap-1.5">
          <NuxtLink
            v-for="o in row.original.organizations"
            :key="o.id"
            :to="`/admin/organizations/${o.id}`"
            class="inline-flex items-center gap-1 text-xs hover:underline"
          >
            <span class="truncate max-w-44">{{ o.name }}</span>
            <UBadge :color="planColor(o.plan)" variant="subtle" size="xs">{{ o.plan }}</UBadge>
          </NuxtLink>
          <span v-if="!row.original.organizations.length" class="text-xs text-muted">-</span>
        </div>
      </template>

      <template #invoicesIssued-cell="{ row }">
        <UBadge :color="row.original.invoicesIssued ? 'success' : 'neutral'" variant="subtle" size="sm">
          {{ row.original.invoicesIssued }}
        </UBadge>
      </template>

      <template #invoicesCreated-cell="{ row }">
        <span class="text-sm">{{ row.original.invoicesCreated }}</span>
      </template>

      <template #activeDays-cell="{ row }">
        <span class="text-sm">{{ row.original.activeDays }}</span>
      </template>

      <template #events-cell="{ row }">
        <span class="text-sm text-muted">{{ row.original.events }}</span>
      </template>

      <template #lastInvoiceAt-cell="{ row }">
        <span class="text-sm text-muted" :title="row.original.lastInvoiceAt ?? ''">{{ formatRelative(row.original.lastInvoiceAt) }}</span>
      </template>

      <template #lastActiveAt-cell="{ row }">
        <span class="text-sm text-muted" :title="row.original.lastActiveAt ?? ''">{{ formatRelative(row.original.lastActiveAt) }}</span>
      </template>
    </UTable>

    <div v-if="!loading && !rows.length" class="text-center py-8 text-muted">
      {{ $t('common.noData') }}
    </div>
    </template>
  </UDashboardPanel>
</template>
