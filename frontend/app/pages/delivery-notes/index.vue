<script setup lang="ts">
import type { SortingState } from '@tanstack/vue-table'
import type { DeliveryNoteStatus } from '~/types/enums'
import type { DeliveryNote } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const intlLocale = useIntlLocale()
useHead({ title: $t('deliveryNotes.title') })
const { can } = usePermissions()
const router = useRouter()
const route = useRoute()
const toast = useToast()
const deliveryNoteStore = useDeliveryNoteStore()
const companyStore = useCompanyStore()

const page = ref(1)
const limit = ref(PAGINATION.DEFAULT_LIMIT)
const search = ref('')
const statusFilter = ref('all')
const currencyFilter = ref(companyStore.currentCompany?.defaultCurrency || 'RON')
const sorting = ref<SortingState>([])

// Create/Edit/Copy slideover state
const formSlideoverOpen = ref(false)
const formSlideoverTitle = ref('')
const formEditDeliveryNote = ref<DeliveryNote | null>(null)
const formCopyOf = ref<string | undefined>(undefined)
const formPrefillClientId = ref<string | undefined>(undefined)

function openCreateSlideover(clientId?: string) {
  formEditDeliveryNote.value = null
  formCopyOf.value = undefined
  formPrefillClientId.value = clientId
  formSlideoverTitle.value = $t('deliveryNotes.newDeliveryNote')
  formSlideoverOpen.value = true
}

async function openEditSlideover(uuid: string) {
  const deliveryNote = await deliveryNoteStore.fetchDeliveryNote(uuid)
  if (!deliveryNote) {
    toast.add({ title: $t('common.error'), color: 'error' })
    return
  }
  if (!['draft', 'issued'].includes(deliveryNote.status)) {
    toast.add({ title: $t('deliveryNotes.notEditable'), color: 'warning' })
    router.replace(`/delivery-notes/${uuid}`)
    return
  }
  formEditDeliveryNote.value = deliveryNote
  formCopyOf.value = undefined
  formSlideoverTitle.value = $t('deliveryNotes.editDeliveryNote')
  formSlideoverOpen.value = true
}

function openCopySlideover(uuid: string) {
  formEditDeliveryNote.value = null
  formCopyOf.value = uuid
  formSlideoverTitle.value = $t('deliveryNotes.copyDeliveryNote')
  formSlideoverOpen.value = true
}

function onFormSaved(deliveryNote: DeliveryNote) {
  formSlideoverOpen.value = false
  toast.add({
    title: formEditDeliveryNote.value ? $t('deliveryNotes.updateSuccess') : $t('deliveryNotes.createSuccess'),
    color: 'success',
    icon: 'i-lucide-check',
  })
  fetchDeliveryNotes()
  router.push(`/delivery-notes/${deliveryNote.id}`)
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
    openCreateSlideover(route.query.clientId as string | undefined)
  }
}

// ── Row actions ────────────────────────────────────────────────────
function getRowActions(deliveryNote: any) {
  const group: any[] = []

  if (can(P.INVOICE_CREATE)) {
    group.push({
      label: $t('common.copy'),
      icon: 'i-lucide-copy',
      onSelect: () => openCopySlideover(deliveryNote.id),
    })
  }

  if (can(P.INVOICE_EDIT) && ['draft', 'issued'].includes(deliveryNote.status)) {
    group.push({
      label: $t('common.edit'),
      icon: 'i-lucide-pencil',
      onSelect: () => openEditSlideover(deliveryNote.id),
    })
  }

  if (deliveryNote.status === 'cancelled') {
    group.push({
      label: $t('deliveryNotes.restore'),
      icon: 'i-lucide-rotate-ccw',
      onSelect: () => router.push(`/delivery-notes/${deliveryNote.id}`),
    })
  }

  return [group]
}

// ── Selection ──────────────────────────────────────────────────────
const { selectedIds, allSelected, toggle, isSelected, clear: clearSelection, count: selectionCount } = useTableSelection(
  computed(() => deliveryNoteStore.items),
)
const bulkLoading = ref(false)
const deleteConfirmOpen = ref(false)
const bulkConvertModalOpen = ref(false)

const eligibleForDelete = computed(() =>
  deliveryNoteStore.items.filter(i => selectedIds.value.includes(i.id) && ['draft', 'cancelled'].includes(i.status)),
)

const eligibleForConvert = computed(() =>
  deliveryNoteStore.items.filter(i => selectedIds.value.includes(i.id) && i.status === 'issued'),
)

async function handleBulkDelete() {
  deleteConfirmOpen.value = false
  if (!eligibleForDelete.value.length) {
    toast.add({ title: $t('bulk.noneEligible'), color: 'warning' })
    return
  }
  bulkLoading.value = true
  const result = await deliveryNoteStore.bulkDelete(eligibleForDelete.value.map(i => i.id))
  bulkLoading.value = false
  if (result) {
    if (result.errors.length > 0) {
      toast.add({ title: $t('bulk.deletePartial', { deleted: result.deleted, errors: result.errors.length }), color: 'warning' })
    }
    else {
      toast.add({ title: $t('bulk.deleteSuccess', { count: result.deleted }), color: 'success' })
    }
    clearSelection()
    await fetchDeliveryNotes()
  }
  else {
    toast.add({ title: deliveryNoteStore.error || $t('common.error'), color: 'error' })
  }
}

async function handleBulkConvert() {
  bulkConvertModalOpen.value = false
  if (!eligibleForConvert.value.length) {
    toast.add({ title: $t('bulk.noneEligible'), color: 'warning' })
    return
  }
  bulkLoading.value = true
  const invoice = await deliveryNoteStore.bulkConvert(eligibleForConvert.value.map(i => i.id))
  bulkLoading.value = false
  if (invoice) {
    toast.add({ title: $t('deliveryNotes.bulkConvertSuccess'), color: 'success', icon: 'i-lucide-check' })
    clearSelection()
    router.push(`/invoices/${invoice.id}`)
  }
  else {
    toast.add({ title: $t('deliveryNotes.convertError'), color: 'error', icon: 'i-lucide-x' })
    fetchDeliveryNotes()
  }
}

const loading = computed(() => deliveryNoteStore.loading)
const deliveryNotes = computed(() => deliveryNoteStore.items)
const total = computed(() => deliveryNoteStore.total)

const statusOptions = computed(() => [
  { label: $t('common.all'), value: 'all' },
  { label: $t('deliveryNoteStatus.draft'), value: 'draft' },
  { label: $t('deliveryNoteStatus.issued'), value: 'issued' },
  { label: $t('deliveryNoteStatus.converted'), value: 'converted' },
  { label: $t('deliveryNoteStatus.cancelled'), value: 'cancelled' },
])

const { visibility: columnVisibility, toggle: toggleColumn, filterColumns, toggleableColumns } = useColumnVisibility('storno:delivery-notes:columns', [
  { key: 'subtotal', label: $t('invoices.subtotal'), default: false },
  { key: 'vatTotal', label: $t('invoices.vatLabel'), default: false },
  { key: 'deliveryLocation', label: $t('deliveryNotes.deliveryLocation'), default: false },
])

const allColumnDefs = [
  { id: 'select', header: '', accessorKey: 'id', size: 40, enableSorting: false, _always: true },
  { accessorKey: 'number', header: $t('deliveryNotes.number'), enableSorting: true, _always: true },
  { accessorKey: 'clientName', header: $t('deliveryNotes.client'), enableSorting: false, _always: true },
  { accessorKey: 'issueDate', header: $t('deliveryNotes.issueDate'), enableSorting: true, _always: true },
  { accessorKey: 'subtotal', header: $t('invoices.subtotal'), enableSorting: true, _toggle: 'subtotal' },
  { accessorKey: 'vatTotal', header: $t('invoices.vatLabel'), enableSorting: true, _toggle: 'vatTotal' },
  { accessorKey: 'total', header: $t('deliveryNotes.total'), enableSorting: true, _always: true },
  { accessorKey: 'deliveryLocation', header: $t('deliveryNotes.deliveryLocation'), enableSorting: false, _toggle: 'deliveryLocation' },
  { accessorKey: 'status', header: $t('deliveryNotes.status'), enableSorting: true, _always: true },
  { id: 'actions', header: '', accessorKey: 'id', size: 50, enableSorting: false, _always: true },
]

const columns = computed(() => filterColumns(allColumnDefs))

function formatMoney(amount?: string | number, currency = 'RON') {
  return new Intl.NumberFormat(intlLocale, { style: 'currency', currency }).format(Number(amount || 0))
}

function formatPlainMoney(amount?: string | number) {
  return new Intl.NumberFormat(intlLocale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(amount || 0))
}

type BadgeColor = 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'error' | 'neutral'

function statusColor(status: string): BadgeColor {
  const map: Record<string, BadgeColor> = { draft: 'neutral', issued: 'primary', converted: 'info', cancelled: 'neutral' }
  return map[status] || 'neutral'
}

const onSearchInput = useDebounceFn(() => {
  page.value = 1
  fetchDeliveryNotes()
}, 300)

function onSortChange(newSorting: SortingState | undefined) {
  sorting.value = newSorting ?? []
  applySortToStore()
  fetchDeliveryNotes()
}

function applySortToStore() {
  const firstSort = sorting.value[0]
  if (firstSort) {
    deliveryNoteStore.sort = firstSort.id
    deliveryNoteStore.order = firstSort.desc ? 'desc' : 'asc'
  }
  else {
    deliveryNoteStore.sort = null
    deliveryNoteStore.order = null
  }
}

function onRowClick(_e: Event, row: any) {
  router.push(`/delivery-notes/${row.original.id}`)
}

const dateFrom = ref<string>('')
const dateTo = ref<string>('')

const convertedOptions = computed(() => [
  { value: 'all', label: $t('deliveryNotes.filters.convertedAll') },
  { value: 'yes', label: $t('deliveryNotes.filters.convertedYes') },
  { value: 'no', label: $t('deliveryNotes.filters.convertedNo') },
])

const convertedSel = computed({
  get: () => deliveryNoteStore.filters.convertedToInvoice || 'all',
  set: (v: string) => { deliveryNoteStore.filters.convertedToInvoice = (v === 'all' ? '' : v) as any },
})

const popoverFilterCount = computed(() => [
  deliveryNoteStore.filters.convertedToInvoice,
  deliveryNoteStore.filters.dateFrom,
  deliveryNoteStore.filters.dateTo,
].filter(Boolean).length)

async function fetchDeliveryNotes() {
  deliveryNoteStore.setFilters({
    status: statusFilter.value !== 'all' ? statusFilter.value as DeliveryNoteStatus : null,
    search: search.value,
    currency: currencyFilter.value || null,
    dateFrom: dateFrom.value || null,
    dateTo: dateTo.value || null,
  })
  deliveryNoteStore.page = page.value
  deliveryNoteStore.limit = limit.value
  applySortToStore()
  await deliveryNoteStore.fetchDeliveryNotes()
}

function onPopoverFilterChange() {
  page.value = 1
  fetchDeliveryNotes()
}

function clearAllFilters() {
  dateFrom.value = ''
  dateTo.value = ''
  deliveryNoteStore.filters.convertedToInvoice = ''
  onPopoverFilterChange()
}

watch([page, statusFilter, currencyFilter], () => fetchDeliveryNotes())

watch(() => companyStore.currentCompanyId, () => {
  page.value = 1
  search.value = ''
  statusFilter.value = 'all'
  currencyFilter.value = companyStore.currentCompany?.defaultCurrency || 'RON'
  sorting.value = []
  fetchDeliveryNotes()
})

onMounted(() => {
  fetchDeliveryNotes()
  checkSlideoverQueryParams()
})
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('deliveryNotes.title')" :ui="{ right: 'gap-1.5' }">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UPopover>
            <UButton icon="i-lucide-columns-3" color="neutral" variant="outline" />
            <template #content>
              <div class="p-2 min-w-48">
                <p class="text-xs font-semibold text-muted px-2 pb-1.5">{{ $t('invoices.toggleColumns') }}</p>
                <label v-for="col in toggleableColumns" :key="col.key" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-elevated cursor-pointer">
                  <input type="checkbox" :checked="columnVisibility[col.key]" class="accent-primary" @change="toggleColumn(col.key)">
                  <span class="text-sm">{{ col.label }}</span>
                </label>
              </div>
            </template>
          </UPopover>
          <UTooltip :kbds="['C', 'A']">
            <UButton v-if="can(P.INVOICE_CREATE)" icon="i-lucide-plus" @click="openCreateSlideover">
              {{ $t('deliveryNotes.newDeliveryNote') }}
            </UButton>
          </UTooltip>
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <div class="flex flex-wrap items-center gap-2 w-full">
          <UInput v-model="search" :placeholder="$t('common.search')" icon="i-lucide-search" class="w-full sm:w-56" @update:model-value="onSearchInput" />
          <USelectMenu v-model="statusFilter" :items="statusOptions" value-key="value" :placeholder="$t('deliveryNotes.status')" class="w-full sm:w-44" />

          <UPopover>
            <UButton
              icon="i-lucide-filter"
              color="neutral"
              variant="outline"
              :label="popoverFilterCount ? $t('suppliers.filters.title') + ' · ' + popoverFilterCount : $t('suppliers.filters.title')"
            />
            <template #content>
              <div class="p-3 min-w-72 space-y-3">
                <div>
                  <p class="text-xs font-semibold text-muted mb-1.5">{{ $t('deliveryNotes.filters.converted') }}</p>
                  <USelectMenu v-model="convertedSel" :items="convertedOptions" value-key="value" class="w-full" @update:model-value="onPopoverFilterChange" />
                </div>
                <div class="grid grid-cols-2 gap-2">
                  <UFormField :label="$t('receipts.filters.dateFrom')">
                    <UInput v-model="dateFrom" type="date" class="w-full" @update:model-value="onPopoverFilterChange" />
                  </UFormField>
                  <UFormField :label="$t('receipts.filters.dateTo')">
                    <UInput v-model="dateTo" type="date" class="w-full" @update:model-value="onPopoverFilterChange" />
                  </UFormField>
                </div>
                <div v-if="popoverFilterCount" class="pt-1 border-t border-default">
                  <UButton block variant="ghost" size="xs" icon="i-lucide-x" :label="$t('suppliers.filters.clear')" @click="clearAllFilters" />
                </div>
              </div>
            </template>
          </UPopover>

          <template v-if="deliveryNoteStore.distinctCurrencies.length > 1">
            <div class="h-5 w-px bg-(--ui-border) mx-1 hidden sm:block" />
            <div class="flex items-center gap-1">
              <button
                v-for="cur in deliveryNoteStore.distinctCurrencies"
                :key="cur"
                type="button"
                class="px-2.5 py-1 rounded-full text-xs font-medium border transition-colors cursor-pointer whitespace-nowrap"
                :class="currencyFilter === cur
                  ? 'bg-primary/10 border-primary text-primary'
                  : 'bg-(--ui-bg-elevated) border-(--ui-border) text-(--ui-text-muted) hover:border-(--ui-text-muted)'"
                @click="currencyFilter = cur"
              >
                {{ cur }}
              </button>
            </div>
          </template>
        </div>
      </UDashboardToolbar>
    </template>

    <template #body>
      <SharedTableBulkBar :count="selectionCount" :loading="bulkLoading" @clear="clearSelection">
        <template #actions>
          <UButton v-if="can(P.INVOICE_EDIT) && eligibleForConvert.length > 0" :label="`${$t('deliveryNotes.bulkConvert')} (${eligibleForConvert.length})`" icon="i-lucide-file-output" color="primary" variant="soft" size="sm" @click="bulkConvertModalOpen = true" />
          <UButton v-if="can(P.INVOICE_DELETE) && eligibleForDelete.length > 0" :label="`${$t('bulk.delete')} (${eligibleForDelete.length})`" icon="i-lucide-trash-2" color="error" variant="soft" size="sm" @click="deleteConfirmOpen = true" />
        </template>
      </SharedTableBulkBar>

      <UTable
        :data="deliveryNotes"
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
          {{ row.original.issueDate ? new Date(row.original.issueDate).toLocaleDateString(intlLocale) : '-' }}
        </template>
        <template #clientName-cell="{ row }">
          {{ row.original.clientName || '-' }}
        </template>
        <template #subtotal-cell="{ row }">
          <span class="tabular-nums text-sm">{{ formatMoney(row.original.subtotal, row.original.currency) }}</span>
        </template>
        <template #vatTotal-cell="{ row }">
          <span class="tabular-nums text-sm">{{ formatMoney(row.original.vatTotal, row.original.currency) }}</span>
        </template>
        <template #total-cell="{ row }">
          <span class="font-medium tabular-nums">
            {{ formatMoney(row.original.total, row.original.currency) }}
          </span>
        </template>
        <template #deliveryLocation-cell="{ row }">
          <span class="text-sm truncate max-w-48">{{ row.original.deliveryLocation || '-' }}</span>
        </template>
        <template #status-cell="{ row }">
          <UBadge :color="statusColor(row.original.status)" variant="subtle" size="sm">
            {{ $t(`deliveryNoteStatus.${row.original.status}`) }}
          </UBadge>
        </template>
        <template #actions-cell="{ row }">
          <UDropdownMenu :items="getRowActions(row.original)">
            <UButton icon="i-lucide-ellipsis-vertical" variant="ghost" size="xs" @click.stop />
          </UDropdownMenu>
        </template>
      </UTable>

      <UEmpty v-if="!loading && !deliveryNotes.length" icon="i-lucide-package-check" :title="$t('deliveryNotes.noDeliveryNotes')" :description="$t('deliveryNotes.noDeliveryNotesDesc')" class="py-12" />

      <div v-if="deliveryNotes.length" class="flex items-center bg-elevated/50 rounded-lg border border-default mt-2 py-2.5 px-4 gap-0">
        <div class="flex-1 px-3">
          <div class="text-[10px] font-semibold text-muted uppercase tracking-wide mb-0.5">{{ $t('invoices.totalExcluding') }}</div>
          <div class="text-sm font-bold tabular-nums">{{ formatPlainMoney(deliveryNoteStore.totals.subtotal) }} <span class="text-xs text-muted font-normal">{{ deliveryNoteStore.activeCurrency }}</span></div>
        </div>
        <div class="w-px h-8 bg-default shrink-0" />
        <div class="flex-1 px-3">
          <div class="text-[10px] font-semibold text-muted uppercase tracking-wide mb-0.5">{{ $t('invoices.vatLabel') }}</div>
          <div class="text-sm font-bold tabular-nums">{{ formatPlainMoney(deliveryNoteStore.totals.vatTotal) }} <span class="text-xs text-muted font-normal">{{ deliveryNoteStore.activeCurrency }}</span></div>
        </div>
        <div class="w-px h-8 bg-default shrink-0" />
        <div class="flex-1 px-3">
          <div class="text-[10px] font-semibold text-muted uppercase tracking-wide mb-0.5">{{ $t('invoices.totalIncluding') }}</div>
          <div class="text-[15px] font-extrabold tabular-nums">{{ formatPlainMoney(deliveryNoteStore.totals.total) }} <span class="text-xs text-muted font-normal">{{ deliveryNoteStore.activeCurrency }}</span></div>
        </div>
        <div class="w-px h-8 bg-default shrink-0" />
        <div class="ml-auto pl-4 flex items-center gap-2.5 shrink-0">
          <span class="text-xs text-muted font-medium whitespace-nowrap">{{ $t('common.showing') }} {{ deliveryNotes.length }} {{ $t('common.of') }} {{ total }}</span>
          <UPagination v-model:page="page" :total="total" :items-per-page="limit" />
        </div>
      </div>

      <div v-else class="flex items-center bg-elevated/50 rounded-lg border border-default mt-2 py-2.5 px-4">
        <span class="text-xs text-muted font-medium whitespace-nowrap">{{ $t('common.showing') }} {{ deliveryNotes.length }} {{ $t('common.of') }} {{ total }}</span>
        <div class="ml-auto pl-4">
          <UPagination v-model:page="page" :total="total" :items-per-page="limit" />
        </div>
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

      <!-- Bulk Convert Confirm -->
      <UModal v-model:open="bulkConvertModalOpen">
        <template #header>
          <h3 class="text-lg font-semibold">{{ $t('deliveryNotes.bulkConvertConfirmTitle') }}</h3>
        </template>
        <template #body>
          <p class="text-sm">{{ $t('deliveryNotes.bulkConvertConfirmDescription') }}</p>
        </template>
        <template #footer>
          <div class="flex justify-end gap-2">
            <UButton :label="$t('common.cancel')" variant="ghost" @click="bulkConvertModalOpen = false" />
            <UButton :label="$t('deliveryNotes.bulkConvert')" icon="i-lucide-file-output" color="primary" :loading="bulkLoading" @click="handleBulkConvert" />
          </div>
        </template>
      </UModal>

      <!-- Create / Edit Delivery Note Slideover -->
      <USlideover
        v-model:open="formSlideoverOpen"
        :ui="{ content: 'sm:max-w-2xl' }"
      >
        <template #header>
          <span class="text-lg font-semibold">{{ formSlideoverTitle }}</span>
        </template>
        <template #body>
          <DeliveryNotesDeliveryNoteForm
            v-if="formSlideoverOpen"
            :delivery-note="formEditDeliveryNote"
            :copy-of="formCopyOf"
            :prefill-client-id="formPrefillClientId"
            @saved="onFormSaved"
            @cancel="formSlideoverOpen = false"
          />
        </template>
      </USlideover>
    </template>
  </UDashboardPanel>
</template>
