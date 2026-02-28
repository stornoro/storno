<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()

const logs = ref<any[]>([])
const loading = ref(true)
const search = ref('')
const actionFilter = ref('all')
const page = ref(1)
const total = ref(0)
const limit = ref(PAGINATION.DEFAULT_LIMIT)
const selectedLog = ref<any>(null)

const showModal = computed({
  get: () => !!selectedLog.value,
  set: (v: boolean) => { if (!v) selectedLog.value = null },
})

const columns = [
  { accessorKey: 'user', header: $t('admin.auditUser') },
  { accessorKey: 'action', header: $t('admin.auditAction') },
  { accessorKey: 'entityType', header: $t('admin.auditEntityType') },
  { accessorKey: 'entityId', header: $t('admin.auditEntityId') },
  { accessorKey: 'ipAddress', header: $t('admin.auditIpAddress') },
  { accessorKey: 'createdAt', header: $t('common.createdAt') },
  { accessorKey: 'actions', header: '' },
]

const actionOptions = computed(() => [
  { label: $t('common.all'), value: 'all' },
  { label: 'Create', value: 'create' },
  { label: 'Update', value: 'update' },
  { label: 'Delete', value: 'delete' },
  { label: 'Impersonate', value: 'impersonate' },
])

function actionColor(action: string): string {
  switch (action) {
    case 'create': return 'success'
    case 'update': return 'info'
    case 'delete': return 'error'
    case 'impersonate': return 'warning'
    default: return 'neutral'
  }
}

function formatSource(userAgent: string | null): string {
  if (!userAgent) return 'System'
  if (userAgent.startsWith('system:cli:')) return `CLI: ${userAgent.replace('system:cli:', '')}`
  if (userAgent === 'system:worker') return 'Worker'
  if (userAgent.startsWith('system:webhook:')) return 'Webhook'
  if (userAgent === 'system:http') return 'System (HTTP)'
  return 'System'
}

// Strip FQCN prefix from old audit rows (e.g. "App\Entity\Invoice" → "Invoice")
function shortEntityType(type: string): string {
  const idx = type.lastIndexOf('\\')
  return idx >= 0 ? type.substring(idx + 1) : type
}

function formatDate(iso: string | null): string {
  if (!iso) return '-'
  return new Date(iso).toLocaleDateString('ro-RO')
}

async function fetchLogs() {
  loading.value = true
  try {
    const { get } = useApi()
    const params: Record<string, any> = {
      page: page.value,
      limit: limit.value,
    }
    if (search.value) params.search = search.value
    if (actionFilter.value !== 'all') params.action = actionFilter.value

    const data = await get<any>('/v1/admin/audit-logs', params)
    logs.value = data.data || []
    total.value = data.total || 0
  } catch {
    // Not authorized or error
  } finally {
    loading.value = false
  }
}

const onSearchInput = useDebounceFn(() => {
  page.value = 1
  fetchLogs()
}, 300)

watch(actionFilter, () => {
  page.value = 1
  fetchLogs()
})

watch(page, () => fetchLogs())

onMounted(() => fetchLogs())
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
    <UPageHeader :title="$t('admin.auditLogs')" :description="$t('admin.auditLogsDescription')" />

    <div class="flex items-center gap-3 mb-6">
      <UButton icon="i-lucide-arrow-left" variant="ghost" to="/admin" />
      <h1 class="text-2xl font-bold">{{ $t('admin.auditLogs') }}</h1>
    </div>

    <UDashboardToolbar class="mb-4">
      <template #right>
        <USelectMenu v-model="actionFilter" :items="actionOptions" value-key="value" :placeholder="$t('admin.auditAction')" class="w-40" />
        <UInput v-model="search" :placeholder="$t('common.search')" icon="i-lucide-search" class="w-64" @update:model-value="onSearchInput" />
      </template>
    </UDashboardToolbar>

    <UTable :data="logs" :columns="columns" :loading="loading">
      <template #user-cell="{ row }">
        <span v-if="row.original.user" class="text-sm">{{ row.original.user.email }}</span>
        <UBadge v-else color="neutral" variant="subtle" size="sm">
          {{ formatSource(row.original.userAgent) }}
        </UBadge>
      </template>

      <template #action-cell="{ row }">
        <UBadge :color="actionColor(row.original.action)" variant="subtle" size="sm">
          {{ row.original.action }}
        </UBadge>
      </template>

      <template #entityType-cell="{ row }">
        <span class="text-sm">{{ shortEntityType(row.original.entityType) }}</span>
      </template>

      <template #entityId-cell="{ row }">
        <span class="text-xs font-mono text-muted truncate max-w-32 inline-block">{{ row.original.entityId }}</span>
      </template>

      <template #ipAddress-cell="{ row }">
        <span class="text-xs font-mono text-muted">{{ row.original.ipAddress || '-' }}</span>
      </template>

      <template #createdAt-cell="{ row }">
        <span class="text-sm text-muted">{{ formatDate(row.original.createdAt) }}</span>
      </template>

      <template #actions-cell="{ row }">
        <UButton
          icon="i-lucide-eye"
          variant="ghost"
          size="sm"
          @click="selectedLog = row.original"
        />
      </template>
    </UTable>

    <div v-if="!loading && !logs.length" class="text-center py-8 text-muted">
      {{ $t('common.noData') }}
    </div>

    <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
      <span class="text-sm text-muted">
        {{ $t('common.showing') }} {{ logs.length }} {{ $t('common.of') }} {{ total }}
      </span>
      <UPagination v-model:page="page" :total="total" :items-per-page="limit" />
    </div>

    <UModal v-model:open="showModal">
      <template #content>
        <UCard>
          <template #header>
            <div class="flex items-center justify-between">
              <h3 class="text-lg font-semibold">{{ $t('admin.auditChanges') }}</h3>
              <UButton icon="i-lucide-x" variant="ghost" size="sm" @click="selectedLog = null" />
            </div>
          </template>

          <div v-if="selectedLog" class="space-y-3">
            <div class="grid grid-cols-2 gap-2 text-sm">
              <div class="text-muted">{{ $t('admin.auditAction') }}</div>
              <div>
                <UBadge :color="actionColor(selectedLog.action)" variant="subtle" size="sm">
                  {{ selectedLog.action }}
                </UBadge>
              </div>
              <div class="text-muted">{{ $t('admin.auditEntityType') }}</div>
              <div>{{ shortEntityType(selectedLog.entityType) }}</div>
              <div class="text-muted">{{ $t('admin.auditEntityId') }}</div>
              <div class="font-mono text-xs break-all">{{ selectedLog.entityId }}</div>
              <div class="text-muted">{{ $t('admin.auditUser') }}</div>
              <div>{{ selectedLog.user?.email || '-' }}</div>
              <div class="text-muted">{{ $t('admin.auditIpAddress') }}</div>
              <div class="font-mono text-xs">{{ selectedLog.ipAddress || '-' }}</div>
            </div>
            <div>
              <div class="text-sm text-muted mb-1">{{ $t('admin.auditChanges') }}</div>
              <pre class="text-xs bg-elevated p-3 rounded-lg overflow-auto max-h-64">{{ JSON.stringify(selectedLog.changes, null, 2) }}</pre>
            </div>
          </div>
        </UCard>
      </template>
    </UModal>

    </template>
  </UDashboardPanel>
</template>
