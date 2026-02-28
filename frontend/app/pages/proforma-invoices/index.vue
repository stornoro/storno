<script setup lang="ts">
import type { SortingState } from '@tanstack/vue-table'
import type { ProformaStatus } from '~/types/enums'
import type { ProformaInvoice } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('proformaInvoices.title') })
const { can } = usePermissions()
const router = useRouter()
const route = useRoute()
const toast = useToast()
const proformaStore = useProformaInvoiceStore()
const companyStore = useCompanyStore()

const page = ref(1)
const limit = ref(PAGINATION.DEFAULT_LIMIT)
const search = ref('')
const statusFilter = ref('all')
const sorting = ref<SortingState>([])

// Create/Edit/Copy slideover state
const formSlideoverOpen = ref(false)
const formSlideoverTitle = ref('')
const formEditProforma = ref<ProformaInvoice | null>(null)
const formCopyOf = ref<string | undefined>(undefined)

function openCreateSlideover() {
  formEditProforma.value = null
  formCopyOf.value = undefined
  formSlideoverTitle.value = $t('proformaInvoices.newProforma')
  formSlideoverOpen.value = true
}

async function openEditSlideover(uuid: string) {
  const proforma = await proformaStore.fetchProforma(uuid)
  if (!proforma) {
    toast.add({ title: $t('common.error'), color: 'error' })
    return
  }
  if (proforma.status !== 'draft') {
    toast.add({ title: $t('proformaInvoices.notEditable'), color: 'warning' })
    router.replace(`/proforma-invoices/${uuid}`)
    return
  }
  formEditProforma.value = proforma
  formCopyOf.value = undefined
  formSlideoverTitle.value = $t('proformaInvoices.editProforma')
  formSlideoverOpen.value = true
}

function openCopySlideover(uuid: string) {
  formEditProforma.value = null
  formCopyOf.value = uuid
  formSlideoverTitle.value = $t('proformaInvoices.copyProforma')
  formSlideoverOpen.value = true
}

function onFormSaved(proforma: ProformaInvoice) {
  formSlideoverOpen.value = false
  toast.add({
    title: formEditProforma.value ? $t('proformaInvoices.updateSuccess') : $t('proformaInvoices.createSuccess'),
    color: 'success',
    icon: 'i-lucide-check',
  })
  fetchProformas()
  router.push(`/proforma-invoices/${proforma.id}`)
}

function checkSlideoverQueryParams() {
  const copyOfParam = route.query.copyOf as string | undefined
  const editParam = route.query.edit as string | undefined
  if (copyOfParam) {
    openCopySlideover(copyOfParam)
  }
  else if (editParam) {
    openEditSlideover(editParam)
  }
  else if (route.query.create) {
    openCreateSlideover()
  }
}

// ── Row actions ────────────────────────────────────────────────────
function getRowActions(proforma: any) {
  const group: any[] = []

  // Copy — requires INVOICE_CREATE permission
  if (can(P.INVOICE_CREATE)) {
    group.push({
      label: $t('common.copy'),
      icon: 'i-lucide-copy',
      onSelect: () => openCopySlideover(proforma.id),
    })
  }

  // Edit only for drafts — requires INVOICE_EDIT permission
  if (can(P.INVOICE_EDIT) && proforma.status === 'draft') {
    group.push({
      label: $t('common.edit'),
      icon: 'i-lucide-pencil',
      onSelect: () => openEditSlideover(proforma.id),
    })
  }

  return [group]
}

// ── Selection ──────────────────────────────────────────────────────
const { selectedIds, allSelected, toggle, isSelected, clear: clearSelection, count: selectionCount } = useTableSelection(
  computed(() => proformaStore.items),
)
const bulkLoading = ref(false)
const deleteConfirmOpen = ref(false)

const eligibleForDelete = computed(() =>
  proformaStore.items.filter(i => selectedIds.value.includes(i.id) && ['draft', 'cancelled'].includes(i.status)),
)

async function handleBulkDelete() {
  deleteConfirmOpen.value = false
  if (!eligibleForDelete.value.length) {
    toast.add({ title: $t('bulk.noneEligible'), color: 'warning' })
    return
  }
  bulkLoading.value = true
  const result = await proformaStore.bulkDelete(eligibleForDelete.value.map(i => i.id))
  bulkLoading.value = false
  if (result) {
    if (result.errors.length > 0) {
      toast.add({ title: $t('bulk.deletePartial', { deleted: result.deleted, errors: result.errors.length }), color: 'warning' })
    }
    else {
      toast.add({ title: $t('bulk.deleteSuccess', { count: result.deleted }), color: 'success' })
    }
    clearSelection()
    await fetchProformas()
  }
  else {
    toast.add({ title: proformaStore.error || $t('common.error'), color: 'error' })
  }
}

const loading = computed(() => proformaStore.loading)
const proformas = computed(() => proformaStore.items)
const total = computed(() => proformaStore.total)

const statusOptions = computed(() => [
  { label: $t('common.all'), value: 'all' },
  { label: $t('proformaStatus.draft'), value: 'draft' },
  { label: $t('proformaStatus.sent'), value: 'sent' },
  { label: $t('proformaStatus.accepted'), value: 'accepted' },
  { label: $t('proformaStatus.rejected'), value: 'rejected' },
  { label: $t('proformaStatus.converted'), value: 'converted' },
  { label: $t('proformaStatus.cancelled'), value: 'cancelled' },
  { label: $t('proformaStatus.expired'), value: 'expired' },
])

const columns = [
  { id: 'select', header: '', accessorKey: 'id', size: 40, enableSorting: false },
  { accessorKey: 'number', header: $t('proformaInvoices.number'), enableSorting: true },
  { accessorKey: 'clientName', header: $t('proformaInvoices.client'), enableSorting: false },
  { accessorKey: 'issueDate', header: $t('proformaInvoices.issueDate'), enableSorting: true },
  { accessorKey: 'validUntil', header: $t('proformaInvoices.validUntil'), enableSorting: true },
  { accessorKey: 'total', header: $t('proformaInvoices.total'), enableSorting: true },
  { accessorKey: 'status', header: $t('proformaInvoices.status'), enableSorting: true },
  { id: 'actions', header: '', accessorKey: 'id', size: 50, enableSorting: false },
]

function formatMoney(amount?: string | number, currency = 'RON') {
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency }).format(Number(amount || 0))
}

type BadgeColor = 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'error' | 'neutral'

function statusColor(status: string): BadgeColor {
  const map: Record<string, BadgeColor> = { draft: 'neutral', sent: 'primary', accepted: 'success', rejected: 'error', converted: 'info', cancelled: 'neutral', expired: 'warning' }
  return map[status] || 'neutral'
}

const onSearchInput = useDebounceFn(() => {
  page.value = 1
  fetchProformas()
}, 300)

function onSortChange(newSorting: SortingState | undefined) {
  sorting.value = newSorting ?? []
  applySortToStore()
  fetchProformas()
}

function applySortToStore() {
  const firstSort = sorting.value[0]
  if (firstSort) {
    proformaStore.sort = firstSort.id
    proformaStore.order = firstSort.desc ? 'desc' : 'asc'
  }
  else {
    proformaStore.sort = null
    proformaStore.order = null
  }
}

function onRowClick(_e: Event, row: any) {
  router.push(`/proforma-invoices/${row.original.id}`)
}

async function fetchProformas() {
  proformaStore.setFilters({
    status: statusFilter.value !== 'all' ? statusFilter.value as ProformaStatus : null,
    search: search.value,
  })
  proformaStore.page = page.value
  proformaStore.limit = limit.value
  applySortToStore()
  await proformaStore.fetchProformas()
}

watch([page, statusFilter], () => fetchProformas())

watch(() => companyStore.currentCompanyId, () => {
  page.value = 1
  search.value = ''
  statusFilter.value = 'all'
  sorting.value = []
  fetchProformas()
})

onMounted(() => {
  fetchProformas()
  checkSlideoverQueryParams()
})
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('proformaInvoices.title')" :ui="{ right: 'gap-1.5' }">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UTooltip :kbds="['C', 'P']">
            <UButton v-if="can(P.INVOICE_CREATE)" icon="i-lucide-plus" @click="openCreateSlideover">
              {{ $t('proformaInvoices.newProforma') }}
            </UButton>
          </UTooltip>
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <div class="flex flex-wrap items-center gap-2 w-full">
          <UInput v-model="search" :placeholder="$t('common.search')" icon="i-lucide-search" class="w-full sm:w-56" @update:model-value="onSearchInput" />
          <USelectMenu v-model="statusFilter" :items="statusOptions" value-key="value" :placeholder="$t('proformaInvoices.status')" class="w-full sm:w-44" />
        </div>
      </UDashboardToolbar>
    </template>

    <template #body>
      <SharedTableBulkBar :count="selectionCount" :loading="bulkLoading" @clear="clearSelection">
        <template #actions>
          <UButton v-if="can(P.INVOICE_DELETE) && eligibleForDelete.length > 0" :label="`${$t('bulk.delete')} (${eligibleForDelete.length})`" icon="i-lucide-trash-2" color="error" variant="soft" size="sm" @click="deleteConfirmOpen = true" />
        </template>
      </SharedTableBulkBar>

      <UTable
        :data="proformas"
        :columns="columns"
        :loading="loading"
        :sorting="sorting"
        class="shrink-0"
        :ui="{
          base: 'table-fixed',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
          td: 'border-b border-default',
        }"
        @select="onRowClick"
        @update:sorting="onSortChange"
      >
        <template #select-header>
          <input v-model="allSelected" type="checkbox" class="accent-primary">
        </template>
        <template #select-cell="{ row }">
          <input :checked="isSelected(row.original.id)" type="checkbox" class="accent-primary" @click.stop @change="toggle(row.original.id)">
        </template>
        <template #issueDate-cell="{ row }">
          {{ row.original.issueDate ? new Date(row.original.issueDate).toLocaleDateString('ro-RO') : '-' }}
        </template>
        <template #validUntil-cell="{ row }">
          {{ row.original.validUntil ? new Date(row.original.validUntil).toLocaleDateString('ro-RO') : '-' }}
        </template>
        <template #clientName-cell="{ row }">
          {{ row.original.clientName || '-' }}
        </template>
        <template #total-cell="{ row }">
          <span class="font-medium tabular-nums">
            {{ formatMoney(row.original.total, row.original.currency) }}
          </span>
        </template>
        <template #status-cell="{ row }">
          <UBadge :color="statusColor(row.original.status)" variant="subtle" size="sm">
            {{ $t(`proformaStatus.${row.original.status}`) }}
          </UBadge>
        </template>
        <template #actions-cell="{ row }">
          <UDropdownMenu :items="getRowActions(row.original)">
            <UButton icon="i-lucide-ellipsis-vertical" variant="ghost" size="xs" @click.stop />
          </UDropdownMenu>
        </template>
      </UTable>

      <UEmpty v-if="!loading && !proformas.length" icon="i-lucide-file-check" :title="$t('proformaInvoices.noProformas')" :description="$t('proformaInvoices.noProformasDesc')" class="py-12" />

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <span class="text-sm text-muted">
          {{ $t('common.showing') }} {{ proformas.length }} {{ $t('common.of') }} {{ total }}
        </span>
        <UPagination v-model:page="page" :total="total" :items-per-page="limit" />
      </div>

      <!-- Bulk Delete Confirm -->
      <UModal v-model:open="deleteConfirmOpen">
        <template #header>
          <h3 class="text-lg font-semibold">{{ $t('bulk.deleteConfirmTitle') }}</h3>
        </template>
        <template #body>
          <p class="text-sm">{{ $t('bulk.deleteConfirmDescription', { count: eligibleForDelete.length }) }}</p>
          <p v-if="eligibleForDelete.length < selectionCount" class="text-sm text-(--ui-text-muted) mt-2">
            {{ $t('bulk.onlyDraftsCanBeDeleted') }}
          </p>
        </template>
        <template #footer>
          <div class="flex justify-end gap-2">
            <UButton :label="$t('common.cancel')" variant="ghost" @click="deleteConfirmOpen = false" />
            <UButton :label="$t('common.delete')" color="error" :loading="bulkLoading" @click="handleBulkDelete" />
          </div>
        </template>
      </UModal>

      <!-- Create / Edit Proforma Slideover -->
      <USlideover
        v-model:open="formSlideoverOpen"
        :ui="{ content: 'sm:max-w-2xl' }"
      >
        <template #header>
          <span class="text-lg font-semibold">{{ formSlideoverTitle }}</span>
        </template>
        <template #body>
          <ProformaInvoicesProformaInvoiceForm
            v-if="formSlideoverOpen"
            :proforma="formEditProforma"
            :copy-of="formCopyOf"
            @saved="onFormSaved"
            @cancel="formSlideoverOpen = false"
          />
        </template>
      </USlideover>
    </template>
  </UDashboardPanel>
</template>
