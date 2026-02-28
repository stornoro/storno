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
    <UPageHeader :title="$t('admin.organizations')" :description="$t('admin.organizationsDescription')" />

    <div class="flex items-center gap-3 mb-6">
      <UButton icon="i-lucide-arrow-left" variant="ghost" to="/admin" />
      <h1 class="text-2xl font-bold">{{ $t('admin.organizations') }}</h1>
    </div>

    <UDashboardToolbar class="mb-4">
      <template #right>
        <UInput v-model="search" :placeholder="$t('common.search')" icon="i-lucide-search" class="w-64" />
      </template>
    </UDashboardToolbar>

    <UTable :data="filteredOrgs" :columns="columns" :loading="loading">
      <template #name-cell="{ row }">
        <NuxtLink :to="`/admin/organizations/${row.original.id}`" class="text-primary hover:underline font-medium">
          {{ row.original.name }}
        </NuxtLink>
      </template>

      <template #plan-cell="{ row }">
        <UBadge :color="planColor(row.original.plan)" variant="subtle" size="sm">
          {{ row.original.plan }}
        </UBadge>
      </template>

      <template #isActive-cell="{ row }">
        <UBadge :color="row.original.isActive ? 'success' : 'error'" variant="subtle" size="sm">
          {{ row.original.isActive ? $t('common.active') : $t('admin.suspended') }}
        </UBadge>
      </template>

      <template #createdAt-cell="{ row }">
        <span class="text-sm text-muted">{{ formatDate(row.original.createdAt) }}</span>
      </template>

      <template #actions-cell="{ row }">
        <UDropdownMenu :items="getActions(row.original)">
          <UButton icon="i-lucide-ellipsis-vertical" variant="ghost" size="sm" />
        </UDropdownMenu>
      </template>
    </UTable>

    <div v-if="!loading && !filteredOrgs.length" class="text-center py-8 text-muted">
      {{ $t('common.noData') }}
    </div>

    <!-- Suspend / Reactivate confirmation modal -->
    <UModal v-model:open="confirmModalOpen">
      <template #header>
        <h3 class="font-semibold">
          {{ selectedOrg?.isActive ? $t('admin.suspendOrg') : $t('admin.reactivateOrg') }}
        </h3>
      </template>
      <template #body>
        <p class="text-muted">
          {{ selectedOrg?.isActive ? $t('admin.confirmSuspend') : $t('admin.confirmReactivate') }}
        </p>
        <p class="mt-2 font-medium">{{ selectedOrg?.name }}</p>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="outline" @click="confirmModalOpen = false">{{ $t('common.cancel') }}</UButton>
          <UButton
            :color="selectedOrg?.isActive ? 'error' : 'success'"
            :loading="toggling"
            @click="confirmToggleActive"
          >
            {{ selectedOrg?.isActive ? $t('admin.suspendOrg') : $t('admin.reactivateOrg') }}
          </UButton>
        </div>
      </template>
    </UModal>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const toast = useToast()

const organizations = ref<any[]>([])
const search = ref('')
const loading = ref(true)
const confirmModalOpen = ref(false)
const selectedOrg = ref<any>(null)
const toggling = ref(false)

const columns = [
  { accessorKey: 'name', header: $t('common.name') },
  { accessorKey: 'plan', header: 'Plan' },
  { accessorKey: 'companyCount', header: $t('admin.companies') },
  { accessorKey: 'memberCount', header: $t('admin.members') },
  { accessorKey: 'isActive', header: $t('common.status') },
  { accessorKey: 'createdAt', header: $t('common.createdAt') },
  { accessorKey: 'actions', header: $t('common.actions') },
]

function planColor(plan: string): string {
  const colors: Record<string, string> = {
    free: 'neutral',
    starter: 'info',
    professional: 'success',
    business: 'warning',
  }
  return colors[plan] || 'neutral'
}

function formatDate(iso: string | null): string {
  if (!iso) return '-'
  return new Date(iso).toLocaleDateString('ro-RO')
}

const filteredOrgs = computed(() => {
  if (!search.value) return organizations.value
  const q = search.value.toLowerCase()
  return organizations.value.filter(o => o.name?.toLowerCase().includes(q))
})

function getActions(org: any) {
  return [[
    {
      label: $t('admin.viewDetails'),
      icon: 'i-lucide-eye',
      onSelect: () => navigateTo(`/admin/organizations/${org.id}`),
    },
    {
      label: org.isActive ? $t('admin.suspendOrg') : $t('admin.reactivateOrg'),
      icon: org.isActive ? 'i-lucide-ban' : 'i-lucide-check-circle',
      onSelect: () => openToggleModal(org),
    },
  ]]
}

function openToggleModal(org: any) {
  selectedOrg.value = org
  confirmModalOpen.value = true
}

async function confirmToggleActive() {
  if (!selectedOrg.value) return
  toggling.value = true
  const { post } = useApi()
  try {
    const result = await post<any>(`/v1/admin/organizations/${selectedOrg.value.id}/toggle-active`)
    selectedOrg.value.isActive = result.isActive
    toast.add({
      title: result.isActive ? $t('admin.orgReactivated') : $t('admin.orgSuspended'),
      color: 'success',
    })
    confirmModalOpen.value = false
  } catch {
    toast.add({ title: $t('error.generic'), color: 'error' })
  } finally {
    toggling.value = false
  }
}

onMounted(async () => {
  try {
    const { get } = useApi()
    const data = await get<any>('/v1/admin/organizations')
    organizations.value = data.data || data
  } catch {
    // Not authorized or error
  } finally {
    loading.value = false
  }
})
</script>
