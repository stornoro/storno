<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('admin.revenue')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
    <UPageHeader :title="$t('admin.revenue')" :description="$t('admin.revenueDescription')" />

    <div class="flex items-center gap-3 mb-6">
      <UButton icon="i-lucide-arrow-left" variant="ghost" to="/admin" />
      <h1 class="text-2xl font-bold">{{ $t('admin.revenue') }}</h1>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <UIcon name="i-lucide-loader-2" class="animate-spin text-2xl" />
    </div>

    <div v-else class="space-y-6">
      <!-- Stat Cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <UCard>
          <div class="text-sm text-muted">{{ $t('admin.mrr') }}</div>
          <div class="text-3xl font-bold mt-1">{{ formatCurrency(data.mrr) }}</div>
          <div class="text-xs text-muted mt-1">{{ data.currency }}</div>
        </UCard>
        <UCard>
          <div class="text-sm text-muted">{{ $t('admin.arr') }}</div>
          <div class="text-3xl font-bold mt-1">{{ formatCurrency(data.arr) }}</div>
          <div class="text-xs text-muted mt-1">{{ data.currency }}</div>
        </UCard>
        <UCard>
          <div class="text-sm text-muted">{{ $t('admin.activeSubscriptions') }}</div>
          <div class="text-3xl font-bold mt-1">{{ data.activeSubscriptions }}</div>
        </UCard>
        <UCard>
          <div class="text-sm text-muted">{{ $t('admin.trialCount') }}</div>
          <div class="text-3xl font-bold mt-1">{{ data.trialCount }}</div>
        </UCard>
      </div>

      <!-- Plan Distribution -->
      <UCard>
        <template #header>
          <h3 class="font-semibold">{{ $t('admin.planDistribution') }}</h3>
        </template>
        <div class="flex flex-wrap gap-3">
          <div
            v-for="(count, plan) in data.planDistribution"
            :key="plan"
            class="flex items-center gap-2 px-3 py-2 rounded-lg bg-elevated"
          >
            <UBadge :color="planColor(plan as string)" variant="subtle">{{ plan }}</UBadge>
            <span class="text-lg font-semibold">{{ count }}</span>
          </div>
        </div>
        <div v-if="!Object.keys(data.planDistribution).length" class="text-muted text-center py-4">
          {{ $t('common.noData') }}
        </div>
      </UCard>

      <!-- Recent Subscriptions -->
      <UCard>
        <template #header>
          <h3 class="font-semibold">{{ $t('admin.recentSubscriptions') }}</h3>
        </template>
        <UTable :data="data.recentSubscriptions" :columns="columns">
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

          <template #subscriptionStatus-cell="{ row }">
            <UBadge :color="statusColor(row.original.subscriptionStatus)" variant="subtle" size="sm">
              {{ row.original.subscriptionStatus ?? '-' }}
            </UBadge>
          </template>

          <template #createdAt-cell="{ row }">
            <span class="text-sm text-muted">{{ formatDate(row.original.createdAt) }}</span>
          </template>
        </UTable>
        <div v-if="!data.recentSubscriptions.length" class="text-muted text-center py-4">
          {{ $t('common.noData') }}
        </div>
      </UCard>
    </div>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()

const loading = ref(true)
const data = ref<any>({
  mrr: 0,
  arr: 0,
  activeSubscriptions: 0,
  trialCount: 0,
  totalOrgs: 0,
  planDistribution: {},
  recentSubscriptions: [],
  currency: 'RON',
})

const columns = [
  { accessorKey: 'name', header: $t('common.name') },
  { accessorKey: 'plan', header: 'Plan' },
  { accessorKey: 'subscriptionStatus', header: $t('admin.subscriptionStatus') },
  { accessorKey: 'createdAt', header: $t('admin.subscribedAt') },
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

function statusColor(status: string | null): string {
  const colors: Record<string, string> = {
    active: 'success',
    trialing: 'info',
    past_due: 'warning',
    canceled: 'error',
    incomplete: 'neutral',
  }
  return colors[status ?? ''] || 'neutral'
}

function formatCurrency(bani: number): string {
  return (bani / 100).toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function formatDate(iso: string | null): string {
  if (!iso) return '-'
  return new Date(iso).toLocaleDateString('ro-RO')
}

onMounted(async () => {
  try {
    const { get } = useApi()
    data.value = await get<any>('/v1/admin/revenue')
  } catch {
    // Not authorized or error
  } finally {
    loading.value = false
  }
})
</script>
