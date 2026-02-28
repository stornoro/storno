<template>
  <div>
    <UDashboardToolbar class="mb-4">
      <div class="flex flex-wrap items-center gap-2 w-full">
        <UInput v-model="search" :placeholder="$t('common.search')" icon="i-lucide-search" class="w-full sm:w-64 mr-auto" @update:model-value="onSearchInput" />
        <USelectMenu v-model="typeFilter" :items="typeOptions" value-key="value" :placeholder="$t('spvMessages.filterByType')" class="w-full sm:w-52" />
        <USelectMenu v-model="statusFilter" :items="statusOptions" value-key="value" :placeholder="$t('spvMessages.filterByStatus')" class="w-full sm:w-44" />
      </div>
    </UDashboardToolbar>

    <UTable
      :data="messages"
      :columns="columns"
      :loading="loading"
    >
      <template #createdAt-cell="{ row }">
        {{ formatDateTime(row.original.createdAt) }}
      </template>
      <template #messageType-cell="{ row }">
        <UBadge :color="messageTypeColor(row.original.messageType)" variant="subtle" size="sm">
          {{ messageTypeLabel(row.original.messageType) }}
        </UBadge>
      </template>
      <template #status-cell="{ row }">
        <UBadge :color="statusColor(row.original.status)" variant="subtle" size="sm">
          {{ statusLabel(row.original.status) }}
        </UBadge>
      </template>
      <template #invoice-cell="{ row }">
        <NuxtLink
          v-if="row.original.invoice?.id"
          :to="`/invoices/${row.original.invoice.id}`"
          class="text-primary hover:underline text-sm font-medium"
        >
          #{{ row.original.invoice.number }}
        </NuxtLink>
        <span v-else-if="row.original.uploadId" class="text-xs text-(--ui-text-muted)">
          ID: {{ row.original.uploadId }}
        </span>
        <span v-else class="text-(--ui-text-muted)">-</span>
      </template>
      <template #errorMessage-cell="{ row }">
        <div v-if="row.original.errorMessage" class="max-w-md">
          <div class="flex items-start gap-1.5 rounded-md bg-error/5 border border-error/20 px-2.5 py-1.5">
            <UIcon name="i-lucide-alert-circle" class="text-error shrink-0 mt-0.5 size-3.5" />
            <span class="text-error text-xs whitespace-pre-line break-words">{{ row.original.errorMessage }}</span>
          </div>
        </div>
        <span v-else class="text-(--ui-text-muted)">-</span>
      </template>
    </UTable>

    <UEmpty v-if="!loading && !messages.length" icon="i-lucide-mail" :title="$t('spvMessages.noMessages')" :description="$t('spvMessages.noMessagesDesc')" class="py-12" />

    <div class="flex items-center justify-between mt-4">
      <span class="text-sm text-(--ui-text-muted)">
        {{ $t('common.showing') }} {{ messages.length }} {{ $t('common.of') }} {{ total }}
      </span>
      <UPagination v-model:page="page" :total="total" :items-per-page="limit" />
    </div>
  </div>
</template>

<script setup lang="ts">
const { t: $t } = useI18n()
const store = useEFacturaMessageStore()
const companyStore = useCompanyStore()
const { formatDateTime } = useDate()

const page = ref(1)
const limit = ref(PAGINATION.DEFAULT_LIMIT)
const search = ref('')
const typeFilter = ref('all')
const statusFilter = ref('all')

const loading = computed(() => store.loading)
const messages = computed(() => store.items)
const total = computed(() => store.total)

const typeOptions = computed(() => [
  { label: $t('common.all'), value: 'all' },
  { label: $t('spvMessages.typeReceived'), value: 'FACTURA PRIMITA' },
  { label: $t('spvMessages.typeSent'), value: 'FACTURA TRIMISA' },
  { label: $t('spvMessages.typeError'), value: 'ERORI FACTURA' },
])

const statusOptions = computed(() => [
  { label: $t('common.all'), value: 'all' },
  { label: $t('spvMessages.statusProcessed'), value: 'processed' },
  { label: $t('spvMessages.statusError'), value: 'error' },
  { label: $t('spvMessages.statusReceived'), value: 'received' },
])

const columns = [
  { accessorKey: 'createdAt', header: $t('spvMessages.createdAt') },
  { accessorKey: 'messageType', header: $t('spvMessages.messageType') },
  { accessorKey: 'cif', header: 'CIF' },
  { accessorKey: 'invoice', header: $t('spvMessages.invoice') },
  { accessorKey: 'status', header: $t('spvMessages.status') },
  { accessorKey: 'errorMessage', header: $t('spvMessages.errorMessage') },
]

function messageTypeColor(type: string) {
  if (type.includes('PRIMITA')) return 'info'
  if (type.includes('TRIMISA')) return 'success'
  if (type.includes('ERORI')) return 'error'
  return 'neutral'
}

function messageTypeLabel(type: string) {
  switch (type) {
    case 'FACTURA PRIMITA': return $t('spvMessages.typeReceived')
    case 'FACTURA TRIMISA': return $t('spvMessages.typeSent')
    case 'ERORI FACTURA': return $t('spvMessages.typeError')
    default: return type
  }
}

function statusColor(status: string) {
  switch (status) {
    case 'processed': return 'success'
    case 'error': return 'error'
    default: return 'neutral'
  }
}

function statusLabel(status: string) {
  switch (status) {
    case 'processed': return $t('spvMessages.statusProcessed')
    case 'error': return $t('spvMessages.statusError')
    case 'received': return $t('spvMessages.statusReceived')
    default: return status
  }
}

const onSearchInput = useDebounceFn(() => {
  page.value = 1
  fetchMessages()
}, 300)

async function fetchMessages() {
  store.search = search.value
  store.messageType = typeFilter.value !== 'all' ? typeFilter.value : null
  store.status = statusFilter.value !== 'all' ? statusFilter.value : null
  store.page = page.value
  store.limit = limit.value
  await store.fetchMessages()
}

watch([page, typeFilter, statusFilter], () => fetchMessages())

watch(() => companyStore.currentCompanyId, () => {
  page.value = 1
  search.value = ''
  typeFilter.value = 'all'
  statusFilter.value = 'all'
  fetchMessages()
})

onMounted(() => fetchMessages())
</script>
