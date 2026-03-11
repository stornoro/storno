<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const intlLocale = useIntlLocale()
useHead({ title: $t('clients.title') })
const router = useRouter()
const clientsStore = useClientStore()
const companyStore = useCompanyStore()

const { can } = usePermissions()
const toast = useToast()

const { visibility: columnVisibility, toggle: toggleColumn, filterColumns, toggleableColumns } = useColumnVisibility('storno:clients:columns', [
  { key: 'email', label: $t('common.email'), default: false },
  { key: 'phone', label: $t('common.phone'), default: false },
  { key: 'vatCode', label: $t('clients.vatCode'), default: false },
  { key: 'county', label: $t('common.county'), default: false },
])

const createModalOpen = ref(false)
const page = ref(1)
const limit = ref(PAGINATION.DEFAULT_LIMIT)
const search = ref('')

// ── Selection ──────────────────────────────────────────────────────
const { selectedIds, allSelected, toggle, isSelected, clear: clearSelection, count: selectionCount } = useTableSelection(
  computed(() => clientsStore.items),
)
const bulkLoading = ref(false)
const deleteConfirmOpen = ref(false)

async function handleBulkDelete() {
  deleteConfirmOpen.value = false
  bulkLoading.value = true
  const result = await clientsStore.bulkDelete(selectedIds.value)
  bulkLoading.value = false
  if (result) {
    if (result.errors.length > 0) {
      toast.add({ title: $t('bulk.deletePartial', { deleted: result.deleted, errors: result.errors.length }), color: 'warning' })
    }
    else {
      toast.add({ title: $t('bulk.deleteSuccess', { count: result.deleted }), color: 'success' })
    }
    clearSelection()
    await fetchClients()
  }
  else {
    toast.add({ title: clientsStore.error || $t('common.error'), color: 'error' })
  }
}

const loading = computed(() => clientsStore.loading)
const clients = computed(() => clientsStore.items)
const total = computed(() => clientsStore.total)

const EU_COUNTRY_CODES = [
  'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES',
  'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
  'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
]

const allColumnDefs = [
  { id: 'select', header: '', accessorKey: 'id', size: 40, _always: true },
  { id: 'client', header: $t('clients.name'), accessorFn: (row: any) => row.name, _always: true },
  { accessorKey: 'cui', header: 'CIF / CNP', _always: true },
  { accessorKey: 'vatCode', header: $t('clients.vatCode'), _toggle: 'vatCode' },
  { accessorKey: 'email', header: $t('common.email'), _toggle: 'email' },
  { accessorKey: 'phone', header: $t('common.phone'), _toggle: 'phone' },
  { accessorKey: 'invoiceCount', header: $t('clients.invoiceCount'), _always: true },
  { accessorKey: 'invoiceTotal', header: $t('clients.invoiceTotal'), _always: true },
  { accessorKey: 'city', header: $t('common.city'), _always: true },
  { accessorKey: 'county', header: $t('common.county'), _toggle: 'county' },
]

const columns = computed(() => filterColumns(allColumnDefs))

function getInitials(name: string): string {
  return name
    .split(' ')
    .slice(0, 2)
    .map(w => w[0])
    .join('')
    .toUpperCase()
}

function formatMoney(amount: number, cur?: string) {
  return new Intl.NumberFormat(intlLocale, {
    style: 'currency',
    currency: cur || clientsStore.currency || 'RON',
    minimumFractionDigits: 2,
  }).format(amount)
}

const onSearchInput = useDebounceFn(() => {
  page.value = 1
  fetchClients()
}, 300)

function onRowClick(_e: Event, row: any) {
  router.push(`/clients/${row.original.id}`)
}

async function fetchClients() {
  clientsStore.search = search.value
  clientsStore.page = page.value
  await clientsStore.fetchClients()
}

function onClientSaved() {
  createModalOpen.value = false
  fetchClients()
}

watch([page], () => fetchClients())

watch(() => companyStore.currentCompanyId, () => {
  page.value = 1
  search.value = ''
  fetchClients()
})

onMounted(() => fetchClients())
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('clients.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UPopover>
            <UButton icon="i-lucide-columns-3" color="neutral" variant="outline" />
            <template #content>
              <div class="p-2 min-w-48">
                <p class="text-xs font-semibold text-muted px-2 pb-1.5">{{ $t('invoices.toggleColumns') }}</p>
                <label
                  v-for="col in toggleableColumns"
                  :key="col.key"
                  class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-elevated cursor-pointer"
                >
                  <input
                    type="checkbox"
                    :checked="columnVisibility[col.key]"
                    class="accent-primary"
                    @change="toggleColumn(col.key)"
                  >
                  <span class="text-sm">{{ col.label }}</span>
                </label>
              </div>
            </template>
          </UPopover>
          <UButton
            v-if="can(P.CLIENT_CREATE)"
            :label="$t('clients.addClient')"
            icon="i-lucide-plus"
            @click="createModalOpen = true"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-wrap items-center justify-between gap-1.5">
        <UInput v-model="search" :placeholder="$t('common.search')" icon="i-lucide-search" class="max-w-sm" @update:model-value="onSearchInput" />
      </div>

      <SharedTableBulkBar :count="selectionCount" :loading="bulkLoading" @clear="clearSelection">
        <template #actions>
          <UButton :label="$t('bulk.delete')" icon="i-lucide-trash-2" color="error" variant="soft" size="sm" @click="deleteConfirmOpen = true" />
        </template>
      </SharedTableBulkBar>

      <UTable
        :data="clients"
        :columns="columns"
        :loading="loading"
        class="shrink-0"
        :ui="{
          base: 'table-fixed',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
          td: 'border-b border-default',
        }"
        @select="onRowClick"
      >
        <template #select-header>
          <input v-model="allSelected" type="checkbox" class="accent-primary">
        </template>
        <template #select-cell="{ row }">
          <input :checked="isSelected(row.original.id)" type="checkbox" class="accent-primary" @click.stop @change="toggle(row.original.id)">
        </template>
        <template #client-cell="{ row }">
          <div class="flex items-center gap-3">
            <UAvatar :text="getInitials(row.original.name)" size="sm" />
            <div>
              <div class="flex items-center gap-1.5">
                <p class="font-medium text-highlighted">{{ row.original.name }}</p>
                <UBadge v-if="row.original.country && row.original.country !== 'RO'" variant="subtle" size="xs" color="neutral">
                  {{ row.original.country }}
                </UBadge>
              </div>
              <p v-if="row.original.city" class="text-xs text-muted">{{ row.original.city }}</p>
            </div>
          </div>
        </template>
        <template #cui-cell="{ row }">
          <div class="flex items-center gap-2">
            <span class="font-mono text-sm">{{ row.original.cui || row.original.cnp || '-' }}</span>
            <UBadge v-if="row.original.type" :color="row.original.type === 'company' ? 'info' : 'warning'" variant="subtle" size="xs">
              {{ row.original.type === 'company' ? 'PJ' : 'PF' }}
            </UBadge>
            <UBadge
              v-if="row.original.country && row.original.country !== 'RO' && EU_COUNTRY_CODES.includes(row.original.country) && row.original.viesValid !== null && row.original.viesValid !== undefined"
              :color="row.original.viesValid ? 'success' : 'error'"
              variant="subtle"
              size="xs"
            >
              {{ row.original.viesValid ? 'VIES ✓' : 'VIES ✗' }}
            </UBadge>
          </div>
        </template>
        <template #invoiceCount-cell="{ row }">
          <span class="text-sm tabular-nums">{{ row.original.invoiceCount ?? 0 }}</span>
        </template>
        <template #invoiceTotal-header>
          <div class="flex items-center gap-1">
            <span>{{ $t('clients.invoiceTotal') }}</span>
            <UTooltip v-if="clientsStore.hasForeignCurrencies" :text="$t('clients.invoiceTotalConverted', { currency: clientsStore.currency })">
              <UIcon name="i-lucide-info" class="w-3.5 h-3.5 text-amber-500" />
            </UTooltip>
          </div>
        </template>
        <template #invoiceTotal-cell="{ row }">
          <span class="text-sm font-medium tabular-nums">
            {{ formatMoney(row.original.invoiceTotal ?? 0) }}
          </span>
        </template>
        <template #vatCode-cell="{ row }">
          <span class="font-mono text-sm">{{ row.original.vatCode || '-' }}</span>
        </template>
        <template #email-cell="{ row }">
          <span class="text-sm">{{ row.original.email || '-' }}</span>
        </template>
        <template #phone-cell="{ row }">
          <span class="text-sm">{{ row.original.phone || '-' }}</span>
        </template>
        <template #county-cell="{ row }">
          <span class="text-sm">{{ row.original.county || '-' }}</span>
        </template>
      </UTable>

      <UEmpty v-if="!loading && !clients.length" icon="i-lucide-users" :title="$t('clients.noClients')" :description="$t('clients.noClientsDesc')" class="py-12">
        <UButton v-if="can(P.CLIENT_CREATE)" :label="$t('clients.addClient')" icon="i-lucide-plus" @click="createModalOpen = true" />
      </UEmpty>

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <span class="text-sm text-muted">
          {{ $t('common.showing') }} {{ clients.length }} {{ $t('common.of') }} {{ total }}
        </span>
        <UPagination v-model:page="page" :total="total" :items-per-page="limit" />
      </div>

      <!-- Add Client Modal -->
      <SharedClientFormModal v-model:open="createModalOpen" @saved="onClientSaved" />

      <!-- Bulk Delete Confirm -->
      <UModal v-model:open="deleteConfirmOpen">
        <template #header>
          <h3 class="text-lg font-semibold">{{ $t('bulk.deleteConfirmTitle') }}</h3>
        </template>
        <template #body>
          <p class="text-sm">{{ $t('bulk.deleteConfirmDescription', { count: selectionCount }) }}</p>
        </template>
        <template #footer>
          <div class="flex justify-end gap-2">
            <UButton :label="$t('common.cancel')" variant="ghost" @click="deleteConfirmOpen = false" />
            <UButton :label="$t('common.delete')" color="error" :loading="bulkLoading" @click="handleBulkDelete" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
