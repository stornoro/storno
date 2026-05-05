<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('admin.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
    <UPageHeader :title="$t('admin.title')" :description="$t('admin.description')" />

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
      <UCard>
        <div class="text-sm text-muted">{{ $t('admin.totalUsers') }}</div>
        <div class="text-3xl font-bold mt-1">{{ stats.users }}</div>
      </UCard>
      <UCard>
        <div class="text-sm text-muted">{{ $t('admin.totalOrganizations') }}</div>
        <div class="text-3xl font-bold mt-1">{{ stats.organizations }}</div>
      </UCard>
      <UCard>
        <div class="text-sm text-muted">{{ $t('admin.totalCompanies') }}</div>
        <div class="text-3xl font-bold mt-1">{{ stats.companies }}</div>
      </UCard>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <UCard>
        <template #header>
          <div class="flex items-center justify-between">
            <h3 class="font-semibold">{{ $t('admin.users') }}</h3>
            <UButton variant="ghost" size="sm" to="/admin/users">{{ $t('common.viewAll') }}</UButton>
          </div>
        </template>
        <div v-if="usersLoading" class="text-muted text-center py-4">{{ $t('common.loading') }}</div>
        <div v-else-if="!recentUsers.length" class="text-muted text-center py-4">{{ $t('common.noData') }}</div>
        <ul v-else class="divide-y divide-default">
          <li v-for="u in recentUsers" :key="u.id" class="flex items-center gap-3 py-2.5 text-sm">
            <NuxtLink :to="`/admin/users`" class="font-medium truncate text-primary hover:underline">{{ u.email }}</NuxtLink>
            <span v-if="u.firstName || u.lastName" class="text-muted truncate">{{ [u.firstName, u.lastName].filter(Boolean).join(' ') }}</span>
            <UBadge v-if="!u.emailVerified" color="warning" variant="subtle" size="xs">{{ $t('admin.unverified') }}</UBadge>
            <span class="ml-auto text-muted text-xs whitespace-nowrap">{{ formatRelative(u.createdAt) }}</span>
          </li>
        </ul>
      </UCard>
      <UCard>
        <template #header>
          <div class="flex items-center justify-between">
            <h3 class="font-semibold">{{ $t('admin.organizations') }}</h3>
            <UButton variant="ghost" size="sm" to="/admin/organizations">{{ $t('common.viewAll') }}</UButton>
          </div>
        </template>
        <div v-if="orgsLoading" class="text-muted text-center py-4">{{ $t('common.loading') }}</div>
        <div v-else-if="!recentOrgs.length" class="text-muted text-center py-4">{{ $t('common.noData') }}</div>
        <ul v-else class="divide-y divide-default">
          <li v-for="o in recentOrgs" :key="o.id" class="flex items-center gap-3 py-2.5 text-sm">
            <NuxtLink :to="`/admin/organizations/${o.id}`" class="font-medium truncate text-primary hover:underline">{{ o.name }}</NuxtLink>
            <UBadge :color="planColor(o.plan)" variant="subtle" size="xs">{{ o.plan }}</UBadge>
            <span class="ml-auto text-muted text-xs whitespace-nowrap">{{ formatRelative(o.createdAt) }}</span>
          </li>
        </ul>
      </UCard>
    </div>

    <UCard class="mt-4">
      <template #header>
        <div class="flex items-center justify-between">
          <h3 class="font-semibold">{{ $t('admin.recentActivity') }}</h3>
          <UButton variant="ghost" size="sm" to="/admin/audit-logs">{{ $t('common.viewAll') }}</UButton>
        </div>
      </template>
      <div v-if="recentLoading" class="text-muted text-center py-4">{{ $t('common.loading') }}</div>
      <div v-else-if="!recent.length" class="text-muted text-center py-4">{{ $t('common.noData') }}</div>
      <ul v-else class="divide-y divide-default">
        <li v-for="log in recent" :key="log.id" class="flex items-center gap-3 py-3 text-sm">
          <UBadge :color="actionColor(log.action)" variant="subtle" size="xs" class="shrink-0 uppercase">
            {{ log.action }}
          </UBadge>
          <span class="font-medium truncate">{{ shortEntityType(log.entityType) }}</span>
          <span class="text-muted truncate font-mono text-xs">{{ log.entityId }}</span>
          <span class="ml-auto text-muted text-xs whitespace-nowrap">
            {{ log.user?.email || $t('admin.systemActor') }}
            · {{ formatRelative(log.createdAt) }}
          </span>
        </li>
      </ul>
    </UCard>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const intlLocale = useIntlLocale()

const stats = ref({ users: 0, organizations: 0, companies: 0 })
const recent = ref<any[]>([])
const recentLoading = ref(true)
const recentUsers = ref<any[]>([])
const usersLoading = ref(true)
const recentOrgs = ref<any[]>([])
const orgsLoading = ref(true)

function actionColor(action: string): string {
  switch (action) {
    case 'create': return 'success'
    case 'update': return 'info'
    case 'delete': return 'error'
    case 'impersonate': return 'warning'
    default: return 'neutral'
  }
}

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

function shortEntityType(type: string): string {
  const idx = type.lastIndexOf('\\')
  return idx >= 0 ? type.substring(idx + 1) : type
}

function formatRelative(iso: string | null): string {
  if (!iso) return '-'
  const then = new Date(iso).getTime()
  const minutes = Math.round((Date.now() - then) / 60000)
  if (minutes < 1) return $t('admin.justNow')
  if (minutes < 60) return $t('admin.minutesAgo', { n: minutes })
  const hours = Math.round(minutes / 60)
  if (hours < 24) return $t('admin.hoursAgo', { n: hours })
  const days = Math.round(hours / 24)
  if (days < 7) return $t('admin.daysAgo', { n: days })
  return new Date(iso).toLocaleDateString(intlLocale)
}

onMounted(async () => {
  const { get } = useApi()
  // Fire all four admin lookups in parallel — they're independent and the
  // page is already rendered, so blocking one card behind another just adds
  // perceived latency.
  await Promise.all([
    get<any>('/v1/admin/stats').then((d) => { stats.value = d }).catch(() => {}),
    get<any>('/v1/admin/audit-logs', { page: 1, limit: 8 })
      .then((d) => { recent.value = d.data || [] })
      .catch(() => {})
      .finally(() => { recentLoading.value = false }),
    get<any>('/v1/admin/users', { page: 1, limit: 5 })
      .then((d) => { recentUsers.value = d.data || [] })
      .catch(() => {})
      .finally(() => { usersLoading.value = false }),
    get<any>('/v1/admin/organizations', { page: 1, limit: 5 })
      .then((d) => { recentOrgs.value = d.data || [] })
      .catch(() => {})
      .finally(() => { orgsLoading.value = false }),
  ])
})
</script>
