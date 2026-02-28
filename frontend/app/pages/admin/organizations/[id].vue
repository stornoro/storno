<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="org?.name ?? $t('admin.organizationDetail')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
    <div class="flex items-center gap-3 mb-6">
      <UButton icon="i-lucide-arrow-left" variant="ghost" to="/admin/organizations" />
      <h1 class="text-2xl font-bold">{{ $t('admin.organizationDetail') }}</h1>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <UIcon name="i-lucide-loader-2" class="animate-spin text-2xl" />
    </div>

    <div v-else-if="org" class="space-y-6">
      <!-- Organization Info -->
      <UCard>
        <template #header>
          <div class="flex items-center justify-between">
            <h3 class="font-semibold">{{ org.name }}</h3>
            <div class="flex items-center gap-2">
              <UBadge :color="org.isActive ? 'success' : 'error'" variant="subtle">
                {{ org.isActive ? $t('common.active') : $t('admin.suspended') }}
              </UBadge>
              <UButton
                :color="org.isActive ? 'error' : 'success'"
                variant="soft"
                size="sm"
                :loading="toggling"
                @click="toggleActive"
              >
                {{ org.isActive ? $t('admin.suspendOrg') : $t('admin.reactivateOrg') }}
              </UButton>
            </div>
          </div>
        </template>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
          <div>
            <span class="text-muted">Slug</span>
            <p class="font-medium">{{ org.slug }}</p>
          </div>
          <div>
            <span class="text-muted">{{ $t('common.createdAt') }}</span>
            <p class="font-medium">{{ formatDate(org.createdAt) }}</p>
          </div>
          <div>
            <span class="text-muted">{{ $t('admin.trialCount') }}</span>
            <p class="font-medium">{{ org.trialEndsAt ? formatDate(org.trialEndsAt) : '-' }}</p>
          </div>
        </div>
      </UCard>

      <!-- Subscription Info -->
      <UCard>
        <template #header>
          <h3 class="font-semibold">{{ $t('admin.subscription') }}</h3>
        </template>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
          <div>
            <span class="text-muted">Plan</span>
            <p><UBadge :color="planColor(org.plan)" variant="subtle">{{ org.plan }}</UBadge></p>
          </div>
          <div>
            <span class="text-muted">{{ $t('admin.subscriptionStatus') }}</span>
            <p class="font-medium">{{ org.subscriptionStatus ?? '-' }}</p>
          </div>
          <div>
            <span class="text-muted">{{ $t('admin.stripeCustomerId') }}</span>
            <p class="font-mono text-xs break-all">{{ org.stripeCustomerId ?? '-' }}</p>
          </div>
          <div>
            <span class="text-muted">{{ $t('admin.stripeSubscriptionId') }}</span>
            <p class="font-mono text-xs break-all">{{ org.stripeSubscriptionId ?? '-' }}</p>
          </div>
          <div>
            <span class="text-muted">{{ $t('admin.stripePriceId') }}</span>
            <p class="font-mono text-xs break-all">{{ org.stripePriceId ?? '-' }}</p>
          </div>
          <div>
            <span class="text-muted">{{ $t('admin.currentPeriodEnd') }}</span>
            <p class="font-medium">{{ org.currentPeriodEnd ? formatDate(org.currentPeriodEnd) : '-' }}</p>
          </div>
          <div>
            <span class="text-muted">{{ $t('admin.cancelAtPeriodEnd') }}</span>
            <p class="font-medium">{{ org.cancelAtPeriodEnd ? $t('common.yes') : $t('common.no') }}</p>
          </div>
        </div>
      </UCard>

      <!-- Plan Override -->
      <UCard>
        <template #header>
          <h3 class="font-semibold">{{ $t('admin.planOverride') }}</h3>
        </template>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label class="text-sm text-muted mb-1 block">{{ $t('admin.selectPlan') }}</label>
            <USelectMenu
              v-model="form.plan"
              :items="planOptions"
              value-key="value"
            />
          </div>
          <div>
            <label class="text-sm text-muted mb-1 block">{{ $t('admin.maxUsers') }}</label>
            <UInput v-model.number="form.maxUsers" type="number" :min="1" />
          </div>
          <div>
            <label class="text-sm text-muted mb-1 block">{{ $t('admin.maxCompanies') }}</label>
            <UInput v-model.number="form.maxCompanies" type="number" :min="1" />
          </div>
        </div>
        <div class="mt-4 flex justify-end">
          <UButton :loading="saving" @click="savePlan">
            {{ $t('admin.updatePlan') }}
          </UButton>
        </div>
      </UCard>

      <!-- Members Table -->
      <UCard>
        <template #header>
          <h3 class="font-semibold">{{ $t('admin.membersList') }} ({{ org.members.length }})</h3>
        </template>
        <UTable :data="org.members" :columns="memberColumns">
          <template #isActive-cell="{ row }">
            <UBadge :color="row.original.isActive ? 'success' : 'error'" variant="subtle" size="sm">
              {{ row.original.isActive ? $t('common.active') : $t('common.inactive') }}
            </UBadge>
          </template>
          <template #joinedAt-cell="{ row }">
            <span class="text-sm text-muted">{{ formatDate(row.original.joinedAt) }}</span>
          </template>
        </UTable>
      </UCard>

      <!-- Companies Table -->
      <UCard>
        <template #header>
          <h3 class="font-semibold">{{ $t('admin.companiesList') }} ({{ org.companies.length }})</h3>
        </template>
        <UTable :data="org.companies" :columns="companyColumns">
          <template #createdAt-cell="{ row }">
            <span class="text-sm text-muted">{{ formatDate(row.original.createdAt) }}</span>
          </template>
        </UTable>
      </UCard>
    </div>

    <div v-else class="text-center py-12 text-muted">
      {{ $t('common.noData') }}
    </div>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const route = useRoute()
const toast = useToast()

const org = ref<any>(null)
const loading = ref(true)
const saving = ref(false)
const toggling = ref(false)

const form = reactive({
  plan: null as string | null,
  maxUsers: 1,
  maxCompanies: 1,
})

const planOptions = [
  { label: 'Free', value: 'free' },
  { label: 'Starter', value: 'starter' },
  { label: 'Professional', value: 'professional' },
  { label: 'Business', value: 'business' },
]

const memberColumns = [
  { accessorKey: 'email', header: $t('admin.memberEmail') },
  { accessorKey: 'fullName', header: $t('common.name') },
  { accessorKey: 'role', header: $t('admin.memberRole') },
  { accessorKey: 'isActive', header: $t('common.status') },
  { accessorKey: 'joinedAt', header: $t('admin.memberJoinedAt') },
]

const companyColumns = [
  { accessorKey: 'name', header: $t('admin.companyName') },
  { accessorKey: 'cif', header: $t('admin.companyCif') },
  { accessorKey: 'city', header: $t('common.city') },
  { accessorKey: 'createdAt', header: $t('common.createdAt') },
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

async function savePlan() {
  saving.value = true
  const { patch } = useApi()
  try {
    const result = await patch<any>(`/v1/admin/organizations/${route.params.id}`, {
      plan: form.plan,
      maxUsers: form.maxUsers,
      maxCompanies: form.maxCompanies,
    })
    org.value.plan = result.plan
    org.value.maxUsers = result.maxUsers
    org.value.maxCompanies = result.maxCompanies
    toast.add({ title: $t('admin.planUpdated'), color: 'success' })
  } catch {
    toast.add({ title: $t('error.generic'), color: 'error' })
  } finally {
    saving.value = false
  }
}

async function toggleActive() {
  toggling.value = true
  const { post } = useApi()
  try {
    const result = await post<any>(`/v1/admin/organizations/${route.params.id}/toggle-active`)
    org.value.isActive = result.isActive
    toast.add({
      title: result.isActive ? $t('admin.orgReactivated') : $t('admin.orgSuspended'),
      color: 'success',
    })
  } catch {
    toast.add({ title: $t('error.generic'), color: 'error' })
  } finally {
    toggling.value = false
  }
}

onMounted(async () => {
  try {
    const { get } = useApi()
    org.value = await get<any>(`/v1/admin/organizations/${route.params.id}`)
    form.plan = org.value.plan
    form.maxUsers = org.value.maxUsers
    form.maxCompanies = org.value.maxCompanies
  } catch {
    // Not found or unauthorized
  } finally {
    loading.value = false
  }
})
</script>
