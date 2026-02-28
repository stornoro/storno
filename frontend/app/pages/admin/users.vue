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
    <UPageHeader :title="$t('admin.users')" :description="$t('admin.usersDescription')" />

    <div class="flex items-center gap-3 mb-6">
      <UButton icon="i-lucide-arrow-left" variant="ghost" to="/admin" />
      <h1 class="text-2xl font-bold">{{ $t('admin.users') }}</h1>
    </div>

    <UDashboardToolbar class="mb-4">
      <template #right>
        <UInput v-model="search" :placeholder="$t('common.search')" icon="i-lucide-search" class="w-64" />
      </template>
    </UDashboardToolbar>

    <UTable :data="filteredUsers" :columns="columns" :loading="loading">
      <template #active-cell="{ row }">
        <UBadge :color="row.original.active ? 'success' : 'error'" variant="subtle" size="sm">
          {{ row.original.active ? $t('common.active') : $t('common.inactive') }}
        </UBadge>
      </template>

      <template #emailVerified-cell="{ row }">
        <UBadge :color="row.original.emailVerified ? 'success' : 'warning'" variant="subtle" size="sm">
          {{ row.original.emailVerified ? $t('common.valid') : $t('admin.unverified') }}
        </UBadge>
      </template>

      <template #roles-cell="{ row }">
        <span class="text-xs">{{ formatRoles(row.original.roles) }}</span>
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

    <div v-if="!loading && !filteredUsers.length" class="text-center py-8 text-muted">
      {{ $t('common.noData') }}
    </div>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const toast = useToast()
const authStore = useAuthStore()

const users = ref<any[]>([])
const search = ref('')
const loading = ref(true)

const columns = [
  { accessorKey: 'email', header: $t('common.email') },
  { accessorKey: 'firstName', header: $t('common.firstName') },
  { accessorKey: 'lastName', header: $t('common.lastName') },
  { accessorKey: 'roles', header: $t('admin.roles') },
  { accessorKey: 'emailVerified', header: 'Email' },
  { accessorKey: 'active', header: $t('common.status') },
  { accessorKey: 'createdAt', header: $t('common.createdAt') },
  { accessorKey: 'actions', header: $t('common.actions') },
]

const filteredUsers = computed(() => {
  if (!search.value) return users.value
  const q = search.value.toLowerCase()
  return users.value.filter(u =>
    u.email?.toLowerCase().includes(q)
    || u.firstName?.toLowerCase().includes(q)
    || u.lastName?.toLowerCase().includes(q),
  )
})

function formatRoles(roles: string[]): string {
  return roles
    .filter(r => r !== 'ROLE_USER' && r !== 'ROLE_API')
    .map(r => r.replace('ROLE_', ''))
    .join(', ') || 'User'
}

function formatDate(iso: string | null): string {
  if (!iso) return '-'
  return new Date(iso).toLocaleDateString('ro-RO')
}

function getActions(user: any) {
  const items: any[] = []

  items.push({
    label: user.active ? $t('admin.deactivateUser') : $t('admin.activateUser'),
    icon: user.active ? 'i-lucide-user-x' : 'i-lucide-user-check',
    onSelect: () => toggleActive(user),
  })

  if (!user.emailVerified) {
    items.push({
      label: $t('admin.verifyEmailManually'),
      icon: 'i-lucide-mail-check',
      onSelect: () => verifyEmail(user),
    })
    items.push({
      label: $t('admin.resendConfirmationEmail'),
      icon: 'i-lucide-send',
      onSelect: () => resendConfirmation(user),
    })
  }

  // Impersonate — only if not self and not another super admin
  const isSelf = user.id === authStore.user?.id
  const isTargetSuperAdmin = user.roles?.includes('ROLE_SUPER_ADMIN')
  if (!isSelf && !isTargetSuperAdmin) {
    items.push({
      label: $t('admin.impersonateUser'),
      icon: 'i-lucide-eye',
      onSelect: () => impersonateUser(user),
    })
  }

  return [items]
}

async function toggleActive(user: any) {
  const { post } = useApi()
  try {
    const result = await post<any>(`/v1/admin/users/${user.id}/toggle-active`)
    user.active = result.active
    toast.add({ title: result.message, color: 'success' })
  } catch {
    toast.add({ title: $t('error.generic'), color: 'error' })
  }
}

async function verifyEmail(user: any) {
  const { post } = useApi()
  try {
    await post(`/v1/admin/users/${user.id}/verify-email`)
    user.emailVerified = true
    toast.add({ title: $t('admin.emailVerifiedSuccess'), color: 'success' })
  } catch {
    toast.add({ title: $t('error.generic'), color: 'error' })
  }
}

async function resendConfirmation(user: any) {
  const { post } = useApi()
  try {
    await post(`/v1/admin/users/${user.id}/resend-confirmation`)
    toast.add({ title: $t('admin.confirmationSentSuccess'), color: 'success' })
  } catch {
    toast.add({ title: $t('error.generic'), color: 'error' })
  }
}

async function impersonateUser(user: any) {
  const success = await authStore.startImpersonation(user.id)
  if (success) {
    toast.add({ title: $t('admin.impersonationStarted', { name: user.fullName || user.email }), color: 'success' })
    navigateTo('/dashboard')
  }
  else {
    toast.add({ title: $t('error.generic'), color: 'error' })
  }
}

onMounted(async () => {
  try {
    const { get } = useApi()
    const data = await get<any>('/v1/admin/users')
    users.value = data.data || data
  } catch {
    // Not authorized or error
  } finally {
    loading.value = false
  }
})
</script>
