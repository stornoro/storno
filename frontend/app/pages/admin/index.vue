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
        <div class="text-muted text-center py-4">{{ $t('admin.manageUsers') }}</div>
      </UCard>
      <UCard>
        <template #header>
          <div class="flex items-center justify-between">
            <h3 class="font-semibold">{{ $t('admin.organizations') }}</h3>
            <UButton variant="ghost" size="sm" to="/admin/organizations">{{ $t('common.viewAll') }}</UButton>
          </div>
        </template>
        <div class="text-muted text-center py-4">{{ $t('admin.manageOrganizations') }}</div>
      </UCard>
    </div>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()

const stats = ref({ users: 0, organizations: 0, companies: 0 })

onMounted(async () => {
  try {
    const { get } = useApi()
    const data = await get<any>('/v1/admin/stats')
    stats.value = data
  } catch {
    // Admin stats not available
  }
})
</script>
