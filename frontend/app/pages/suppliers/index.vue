<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('suppliers.title') })
const router = useRouter()
const store = useSupplierStore()
const companyStore = useCompanyStore()

const toast = useToast()

const createModalOpen = ref(false)

const page = ref(1)
const limit = ref(PAGINATION.DEFAULT_LIMIT)
const search = ref('')

// ── Selection ──────────────────────────────────────────────────────
const { selectedIds, allSelected, toggle, isSelected, clear: clearSelection, count: selectionCount } = useTableSelection(
  computed(() => store.items),
)
const bulkLoading = ref(false)
const deleteConfirmOpen = ref(false)

async function handleBulkDelete() {
  deleteConfirmOpen.value = false
  bulkLoading.value = true
  const result = await store.bulkDelete(selectedIds.value)
  bulkLoading.value = false
  if (result) {
    if (result.errors.length > 0) {
      toast.add({ title: $t('bulk.deletePartial', { deleted: result.deleted, errors: result.errors.length }), color: 'warning' })
    }
    else {
      toast.add({ title: $t('bulk.deleteSuccess', { count: result.deleted }), color: 'success' })
    }
    clearSelection()
    await fetchSuppliers()
  }
  else {
    toast.add({ title: store.error || $t('common.error'), color: 'error' })
  }
}

const loading = computed(() => store.loading)
const suppliers = computed(() => store.items)
const total = computed(() => store.total)

const columns = [
  { id: 'select', header: '', accessorKey: 'id', size: 40 },
  {
    id: 'supplier',
    header: $t('suppliers.name'),
    accessorFn: (row: any) => row.name,
  },
  { accessorKey: 'cif', header: $t('suppliers.cif') },
  { accessorKey: 'invoiceCount', header: $t('clients.invoiceCount') },
  { accessorKey: 'invoiceTotal', header: $t('clients.invoiceTotal') },
  { accessorKey: 'city', header: $t('common.city') },
]

function getInitials(name: string): string {
  return name
    .split(' ')
    .slice(0, 2)
    .map(w => w[0])
    .join('')
    .toUpperCase()
}

function formatMoney(amount: number) {
  return new Intl.NumberFormat('ro-RO', {
    style: 'currency',
    currency: 'RON',
    minimumFractionDigits: 2,
  }).format(amount)
}

const onSearchInput = useDebounceFn(() => {
  page.value = 1
  fetchSuppliers()
}, 300)

function onRowClick(_e: Event, row: any) {
  router.push(`/suppliers/${row.original.id}`)
}

async function fetchSuppliers() {
  store.search = search.value
  store.page = page.value
  await store.fetchSuppliers()
}

watch([page], () => fetchSuppliers())

watch(() => companyStore.currentCompanyId, () => {
  page.value = 1
  search.value = ''
  fetchSuppliers()
})

function onSupplierCreated() {
  toast.add({ title: $t('suppliers.supplierCreated'), color: 'success' })
  fetchSuppliers()
}

onMounted(() => fetchSuppliers())
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('suppliers.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-wrap items-center justify-between gap-1.5">
        <UInput v-model="search" :placeholder="$t('common.search')" icon="i-lucide-search" class="max-w-sm" @update:model-value="onSearchInput" />
        <UButton icon="i-lucide-plus" :label="$t('suppliers.addSupplier')" @click="createModalOpen = true" />
      </div>

      <SharedTableBulkBar :count="selectionCount" :loading="bulkLoading" @clear="clearSelection">
        <template #actions>
          <UButton :label="$t('bulk.delete')" icon="i-lucide-trash-2" color="error" variant="soft" size="sm" @click="deleteConfirmOpen = true" />
        </template>
      </SharedTableBulkBar>

      <UTable
        :data="suppliers"
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
        <template #supplier-cell="{ row }">
          <div class="flex items-center gap-3">
            <UAvatar :text="getInitials(row.original.name)" size="sm" />
            <div>
              <p class="font-medium text-highlighted">{{ row.original.name }}</p>
              <p v-if="row.original.city" class="text-xs text-muted">{{ row.original.city }}</p>
            </div>
          </div>
        </template>
        <template #cif-cell="{ row }">
          <span class="font-mono text-sm">{{ row.original.cif || '-' }}</span>
        </template>
        <template #invoiceCount-cell="{ row }">
          <span class="text-sm tabular-nums">{{ (row.original as any).invoiceCount ?? 0 }}</span>
        </template>
        <template #invoiceTotal-cell="{ row }">
          <span class="text-sm font-medium tabular-nums">
            {{ formatMoney((row.original as any).invoiceTotal ?? 0) }}
          </span>
        </template>
      </UTable>

      <UEmpty v-if="!loading && !suppliers.length" icon="i-lucide-truck" :title="$t('suppliers.noSuppliers')" :description="$t('suppliers.noSuppliersDesc')" class="py-12" />

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <span class="text-sm text-muted">
          {{ $t('common.showing') }} {{ suppliers.length }} {{ $t('common.of') }} {{ total }}
        </span>
        <UPagination v-model:page="page" :total="total" :items-per-page="limit" />
      </div>

      <!-- Create Supplier Modal -->
      <SharedSupplierFormModal v-model:open="createModalOpen" @saved="onSupplierCreated" />

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
