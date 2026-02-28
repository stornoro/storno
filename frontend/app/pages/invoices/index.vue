<script setup lang="ts">
import type { SortingState } from '@tanstack/vue-table'
import type { Invoice, ValidationResponse } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('invoices.title') })
const router = useRouter()
const route = useRoute()
const toast = useToast()
const invoicesStore = useInvoiceStore()
const companyStore = useCompanyStore()

// ── Date helpers ──────────────────────────────────────────────────
function formatDateISO(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function getMonthRange(offset = 0): { from: string, to: string } {
  const d = new Date()
  d.setMonth(d.getMonth() + offset)
  const first = new Date(d.getFullYear(), d.getMonth(), 1)
  const last = new Date(d.getFullYear(), d.getMonth() + 1, 0)
  return { from: formatDateISO(first), to: formatDateISO(last) }
}

function getQuarterRange(): { from: string, to: string } {
  const d = new Date()
  const q = Math.floor(d.getMonth() / 3)
  const first = new Date(d.getFullYear(), q * 3, 1)
  const last = new Date(d.getFullYear(), q * 3 + 3, 0)
  return { from: formatDateISO(first), to: formatDateISO(last) }
}

function getYearRange(): { from: string, to: string } {
  const y = new Date().getFullYear()
  return { from: `${y}-01-01`, to: `${y}-12-31` }
}

// ── Filter state ──────────────────────────────────────────────────
const page = ref(1)
const limit = ref(PAGINATION.DEFAULT_LIMIT)
const search = ref('')
const statusFilter = ref('all')
const activeDirection = ref('all')
const sorting = ref<SortingState>([])
const paidFilter = ref('all')
const activeDatePreset = ref('thisMonth')

const thisMonth = getMonthRange(0)
const dateFrom = ref(thisMonth.from)
const dateTo = ref(thisMonth.to)

// ── Quick date presets ────────────────────────────────────────────
type DatePreset = { label: string, value: string, range: () => { from: string, to: string } }

const datePresets = computed<DatePreset[]>(() => [
  { label: $t('invoices.datePresets.today'), value: 'today', range: () => ({ from: formatDateISO(new Date()), to: formatDateISO(new Date()) }) },
  { label: $t('invoices.datePresets.thisWeek'), value: 'thisWeek', range: () => {
    const d = new Date()
    const day = d.getDay() || 7
    const monday = new Date(d)
    monday.setDate(d.getDate() - day + 1)
    const sunday = new Date(monday)
    sunday.setDate(monday.getDate() + 6)
    return { from: formatDateISO(monday), to: formatDateISO(sunday) }
  } },
  { label: $t('invoices.datePresets.thisMonth'), value: 'thisMonth', range: () => getMonthRange(0) },
  { label: $t('invoices.datePresets.lastMonth'), value: 'lastMonth', range: () => getMonthRange(-1) },
  { label: $t('invoices.datePresets.thisQuarter'), value: 'thisQuarter', range: () => getQuarterRange() },
  { label: $t('invoices.datePresets.thisYear'), value: 'thisYear', range: () => getYearRange() },
  { label: $t('invoices.datePresets.all'), value: 'all', range: () => ({ from: '', to: '' }) },
])

function applyDatePreset(preset: DatePreset) {
  activeDatePreset.value = preset.value
  const range = preset.range()
  dateFrom.value = range.from
  dateTo.value = range.to
}

function onDateManualChange() {
  activeDatePreset.value = 'custom'
}

// ── Active filters (for chips) ────────────────────────────────────
interface ActiveFilter {
  key: string
  label: string
  clear: () => void
}

const activeFilters = computed<ActiveFilter[]>(() => {
  const chips: ActiveFilter[] = []
  if (activeDirection.value !== 'all') {
    chips.push({
      key: 'direction',
      label: `${$t('invoices.direction')}: ${activeDirection.value === 'incoming' ? $t('common.incoming') : $t('common.outgoing')}`,
      clear: () => { activeDirection.value = 'all' },
    })
  }
  if (statusFilter.value !== 'all') {
    chips.push({
      key: 'status',
      label: `${$t('invoices.status')}: ${$t(`documentStatus.${statusFilter.value}`)}`,
      clear: () => { statusFilter.value = 'all' },
    })
  }
  if (paidFilter.value !== 'all') {
    chips.push({
      key: 'paid',
      label: paidFilter.value === 'paid' ? $t('invoices.filterPaid') : $t('invoices.filterUnpaid'),
      clear: () => { paidFilter.value = 'all' },
    })
  }
  if (search.value) {
    chips.push({
      key: 'search',
      label: `${$t('common.search')}: "${search.value}"`,
      clear: () => { search.value = ''; fetchInvoices() },
    })
  }
  if (dateFrom.value || dateTo.value) {
    const preset = datePresets.value.find(p => p.value === activeDatePreset.value)
    const label = preset && preset.value !== 'custom' && preset.value !== 'all'
      ? preset.label
      : [dateFrom.value, dateTo.value].filter(Boolean).join(' — ')
    chips.push({
      key: 'date',
      label,
      clear: () => { applyDatePreset(datePresets.value.find(p => p.value === 'all')!) },
    })
  }
  return chips
})

const hasActiveFilters = computed(() => activeFilters.value.length > 0)

function resetAllFilters() {
  search.value = ''
  statusFilter.value = 'all'
  paidFilter.value = 'all'
  activeDirection.value = 'all'
  applyDatePreset(datePresets.value.find(p => p.value === 'thisMonth')!)
  page.value = 1
}

// ── Create/Edit slideover state ───────────────────────────────────
const formSlideoverOpen = ref(false)
const formSlideoverTitle = ref('')
const formEditInvoice = ref<Invoice | null>(null)
const formRefundOf = ref<string | undefined>(undefined)
const formCopyOf = ref<string | undefined>(undefined)

function openCreateSlideover() {
  formEditInvoice.value = null
  formRefundOf.value = undefined
  formCopyOf.value = undefined
  formSlideoverTitle.value = $t('invoices.newInvoice')
  formSlideoverOpen.value = true
}

function onFormSaved(invoice: Invoice, validation: ValidationResponse | null) {
  formSlideoverOpen.value = false
  toast.add({
    title: formEditInvoice.value ? $t('invoices.updateSuccess') : $t('invoices.createSuccess'),
    color: 'success',
    icon: 'i-lucide-check',
  })
  fetchInvoices()
  router.push(`/invoices/${invoice.id}`)
}

async function openEditSlideover(uuid: string) {
  const invoice = await invoicesStore.fetchInvoice(uuid)
  if (!invoice) return
  if (invoice.status !== 'draft') {
    toast.add({ title: $t('invoices.notEditable'), color: 'warning' })
    router.replace(`/invoices/${uuid}`)
    return
  }
  formEditInvoice.value = invoice
  formRefundOf.value = undefined
  formCopyOf.value = undefined
  formSlideoverTitle.value = $t('invoices.editInvoice')
  formSlideoverOpen.value = true
}

function openCopySlideover(uuid: string) {
  formEditInvoice.value = null
  formRefundOf.value = undefined
  formCopyOf.value = uuid
  formSlideoverTitle.value = $t('invoices.copyInvoice')
  formSlideoverOpen.value = true
}

function openRefundSlideover(uuid: string) {
  formEditInvoice.value = null
  formCopyOf.value = undefined
  formRefundOf.value = uuid
  formSlideoverTitle.value = $t('invoices.createRefund')
  formSlideoverOpen.value = true
}

function checkSlideoverQueryParams() {
  const refundOfParam = route.query.refundOf as string | undefined
  const copyOfParam = route.query.copyOf as string | undefined
  const editParam = route.query.edit as string | undefined
  if (refundOfParam) {
    openRefundSlideover(refundOfParam)
  }
  else if (copyOfParam) {
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
function getRowActions(inv: any) {
  const group: any[] = []

  if (can(P.INVOICE_CREATE)) {
    group.push({
      label: $t('common.copy'),
      icon: 'i-lucide-copy',
      onSelect: () => openCopySlideover(inv.id),
    })
  }

  if (inv.status === 'draft') {
    if (can(P.INVOICE_EDIT)) {
      group.push({
        label: $t('common.edit'),
        icon: 'i-lucide-pencil',
        onSelect: () => openEditSlideover(inv.id),
      })
    }
  }

  if (['validated', 'synced', 'issued', 'sent_to_provider'].includes(inv.status) && inv.direction === 'outgoing' && !inv.parentDocumentId) {
    if (can(P.INVOICE_REFUND)) {
      group.push({
        label: $t('common.refund'),
        icon: 'i-lucide-file-minus',
        onSelect: () => openRefundSlideover(inv.id),
      })
    }
  }

  // Mark as paid / unpaid per-row
  if (!['draft', 'cancelled'].includes(inv.status)) {
    if (!inv.paidAt && can(P.INVOICE_EDIT)) {
      group.push({
        label: $t('bulk.markPaid'),
        icon: 'i-lucide-circle-check',
        onSelect: () => handleRowMarkPaid(inv.id),
      })
    }
    if (inv.paidAt && can(P.INVOICE_EDIT)) {
      group.push({
        label: $t('bulk.markUnpaid'),
        icon: 'i-lucide-circle-x',
        onSelect: () => handleRowMarkUnpaid(inv.id),
      })
    }
  }

  if (['draft', 'cancelled'].includes(inv.status)) {
    if (can(P.INVOICE_DELETE)) {
      group.push({
        label: $t('common.delete'),
        icon: 'i-lucide-trash-2',
        onSelect: () => handleDeleteInvoice(inv.id),
      })
    }
  }

  return [group]
}

async function handleDeleteInvoice(uuid: string) {
  const success = await invoicesStore.deleteInvoice(uuid)
  if (success) {
    toast.add({ title: $t('invoices.deleteSuccess'), color: 'success' })
    fetchInvoices()
  }
}

async function handleRowMarkPaid(uuid: string) {
  const result = await invoicesStore.bulkMarkPaid([uuid])
  if (result) {
    toast.add({ title: $t('bulk.markPaidSuccess', { count: 1 }), color: 'success' })
    await fetchInvoices()
  }
}

async function handleRowMarkUnpaid(uuid: string) {
  const result = await invoicesStore.bulkMarkUnpaid([uuid])
  if (result) {
    toast.add({ title: $t('bulk.markUnpaidSuccess', { count: 1 }), color: 'success' })
    await fetchInvoices()
  }
}

// ── Selection ──────────────────────────────────────────────────────
const { selectedIds, allSelected, toggle, isSelected, clear: clearSelection, count: selectionCount } = useTableSelection(
  computed(() => invoicesStore.items),
)

const bulkLoading = ref(false)

const deleteConfirmOpen = ref(false)
const cancelConfirmOpen = ref(false)
const stornoConfirmOpen = ref(false)
const markPaidConfirmOpen = ref(false)
const markUnpaidConfirmOpen = ref(false)

const eligibleForDelete = computed(() =>
  invoicesStore.items.filter(i => selectedIds.value.includes(i.id) && ['draft', 'cancelled'].includes(i.status)),
)

const eligibleForCancel = computed(() =>
  invoicesStore.items.filter(i => selectedIds.value.includes(i.id) && ['draft', 'issued'].includes(i.status)),
)

const eligibleForStorno = computed(() =>
  invoicesStore.items.filter(i =>
    selectedIds.value.includes(i.id)
    && ['issued', 'sent_to_provider', 'validated', 'synced'].includes(i.status)
    && i.direction === 'outgoing'
    && !i.parentDocumentId,
  ),
)

const eligibleForMarkPaid = computed(() =>
  invoicesStore.items.filter(i =>
    selectedIds.value.includes(i.id)
    && !i.paidAt
    && !['draft', 'cancelled'].includes(i.status),
  ),
)

const eligibleForMarkUnpaid = computed(() =>
  invoicesStore.items.filter(i =>
    selectedIds.value.includes(i.id)
    && (i.paidAt || parseFloat(i.amountPaid) > 0)
    && !['draft', 'cancelled'].includes(i.status),
  ),
)

async function handleBulkDelete() {
  deleteConfirmOpen.value = false
  if (!eligibleForDelete.value.length) {
    toast.add({ title: $t('bulk.noneEligible'), color: 'warning' })
    return
  }
  bulkLoading.value = true
  const result = await invoicesStore.bulkDelete(eligibleForDelete.value.map(i => i.id))
  bulkLoading.value = false
  if (result) {
    if (result.errors.length > 0) {
      toast.add({ title: $t('bulk.deletePartial', { deleted: result.deleted, errors: result.errors.length }), color: 'warning' })
    }
    else {
      toast.add({ title: $t('bulk.deleteSuccess', { count: result.deleted }), color: 'success' })
    }
    clearSelection()
    fetchInvoices()
  }
}

async function handleBulkCancel() {
  cancelConfirmOpen.value = false
  if (!eligibleForCancel.value.length) {
    toast.add({ title: $t('bulk.noneEligible'), color: 'warning' })
    return
  }
  bulkLoading.value = true
  const result = await invoicesStore.bulkCancel(eligibleForCancel.value.map(i => i.id))
  bulkLoading.value = false
  if (result) {
    if (result.errors.length > 0) {
      toast.add({ title: $t('bulk.deletePartial', { deleted: result.cancelled, errors: result.errors.length }), color: 'warning' })
    }
    else {
      toast.add({ title: $t('bulk.cancelSuccess', { count: result.cancelled }), color: 'success' })
    }
    clearSelection()
    fetchInvoices()
  }
}

async function handleBulkExportZip() {
  if (!selectedIds.value.length) return
  bulkLoading.value = true
  const success = await invoicesStore.exportZip(selectedIds.value)
  bulkLoading.value = false
  if (success) {
    toast.add({ title: $t('bulk.exportSuccess'), color: 'info', icon: 'i-lucide-archive' })
  }
}

async function handleBulkStorno() {
  stornoConfirmOpen.value = false
  if (!eligibleForStorno.value.length) {
    toast.add({ title: $t('bulk.noneEligible'), color: 'warning' })
    return
  }
  bulkLoading.value = true
  const result = await invoicesStore.bulkStorno(eligibleForStorno.value.map(i => i.id))
  bulkLoading.value = false
  if (result) {
    if (result.errors.length > 0) {
      toast.add({ title: $t('bulk.stornoPartial', { created: result.created, errors: result.errors.length }), color: 'warning' })
    }
    else {
      toast.add({ title: $t('bulk.stornoSuccess', { count: result.created }), color: 'success' })
    }
    clearSelection()
    fetchInvoices()
  }
}

async function handleBulkMarkPaid() {
  markPaidConfirmOpen.value = false
  if (!eligibleForMarkPaid.value.length) {
    toast.add({ title: $t('bulk.noneEligible'), color: 'warning' })
    return
  }
  bulkLoading.value = true
  const result = await invoicesStore.bulkMarkPaid(eligibleForMarkPaid.value.map(i => i.id))
  if (result) {
    if (result.errors.length > 0) {
      toast.add({ title: $t('bulk.markPaidPartial', { marked: result.marked, errors: result.errors.length }), color: 'warning' })
    }
    else {
      toast.add({ title: $t('bulk.markPaidSuccess', { count: result.marked }), color: 'success' })
    }
    clearSelection()
    await fetchInvoices()
  }
  bulkLoading.value = false
}

async function handleBulkMarkUnpaid() {
  markUnpaidConfirmOpen.value = false
  if (!eligibleForMarkUnpaid.value.length) {
    toast.add({ title: $t('bulk.noneEligible'), color: 'warning' })
    return
  }
  bulkLoading.value = true
  const result = await invoicesStore.bulkMarkUnpaid(eligibleForMarkUnpaid.value.map(i => i.id))
  if (result) {
    if (result.errors.length > 0) {
      toast.add({ title: $t('bulk.markUnpaidPartial', { unmarked: result.unmarked, errors: result.errors.length }), color: 'warning' })
    }
    else {
      toast.add({ title: $t('bulk.markUnpaidSuccess', { count: result.unmarked }), color: 'success' })
    }
    clearSelection()
    await fetchInvoices()
  }
  bulkLoading.value = false
}

// ── Table ──────────────────────────────────────────────────────────
const loading = computed(() => invoicesStore.loading)
const invoices = computed(() => invoicesStore.items)
const total = computed(() => invoicesStore.total)

const directionTabs = computed(() => [
  { label: $t('common.all'), value: 'all' },
  { label: $t('common.incoming'), value: 'incoming' },
  { label: $t('common.outgoing'), value: 'outgoing' },
])

const statusOptions = computed(() => [
  { label: $t('common.all'), value: 'all' },
  { label: $t('documentStatus.draft'), value: 'draft' },
  { label: $t('documentStatus.issued'), value: 'issued' },
  { label: $t('documentStatus.synced'), value: 'synced' },
  { label: $t('documentStatus.validated'), value: 'validated' },
  { label: $t('documentStatus.rejected'), value: 'rejected' },
  { label: $t('documentStatus.sent_to_provider'), value: 'sent_to_provider' },
])

const paidOptions = computed(() => [
  { label: $t('common.all'), value: 'all' },
  { label: $t('invoices.filterPaid'), value: 'paid' },
  { label: $t('invoices.filterUnpaid'), value: 'unpaid' },
])

const exportItems = computed(() => [[
  {
    label: $t('invoices.exportCsv'),
    icon: 'i-lucide-file-spreadsheet',
    onSelect: exportCsv,
  },
  {
    label: $t('invoices.exportZip'),
    icon: 'i-lucide-archive',
    onSelect: exportZip,
  },
]])

const columns = [
  { id: 'select', header: '', accessorKey: 'id', size: 40, enableSorting: false },
  { accessorKey: 'number', header: $t('invoices.number'), enableSorting: true },
  { accessorKey: 'issueDate', header: $t('invoices.issueDate'), enableSorting: true },
  { id: 'counterparty', header: $t('invoices.counterparty'), accessorFn: (row: any) => row.senderName || row.receiverName || '-', enableSorting: true },
  { accessorKey: 'direction', header: $t('invoices.direction'), enableSorting: true },
  { accessorKey: 'total', header: $t('invoices.total'), enableSorting: true },
  { accessorKey: 'status', header: $t('invoices.status'), enableSorting: true },
  { id: 'actions', header: '', accessorKey: 'id', size: 50, enableSorting: false },
]

function getCounterparty(inv: any): string {
  if (inv.direction === 'incoming') {
    return inv.senderName || inv.senderCif || '-'
  }
  return inv.receiverName || inv.receiverCif || '-'
}

function formatMoney(amount?: string | number, currency = 'RON') {
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency }).format(Number(amount || 0))
}

function formatPlainMoney(amount?: string | number) {
  return new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(amount || 0))
}

type BadgeColor = 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'error' | 'neutral'

function statusColor(status: string): BadgeColor {
  const map: Record<string, BadgeColor> = { draft: 'neutral', issued: 'info', synced: 'info', validated: 'success', rejected: 'error', sent_to_provider: 'warning', cancelled: 'neutral', refund: 'warning', refunded: 'warning' }
  return map[status] || 'neutral'
}

const onSearchInput = useDebounceFn(() => {
  page.value = 1
  fetchInvoices()
}, 300)

function onSortChange(newSorting: SortingState | undefined) {
  sorting.value = newSorting ?? []
  applySortToStore()
  fetchInvoices()
}

function applySortToStore() {
  const firstSort = sorting.value[0]
  if (firstSort) {
    invoicesStore.sort = firstSort.id
    invoicesStore.order = firstSort.desc ? 'desc' : 'asc'
  }
}

function onRowClick(_e: Event, row: any) {
  router.push(`/invoices/${row.original.id}`)
}

async function exportCsv() {
  const blob = await invoicesStore.exportCsv()
  if (blob) {
    downloadBlob(blob, 'facturi.csv')
  }
}

async function exportZip() {
  const ids = invoices.value.map((i: any) => i.id)
  if (!ids.length) return
  const success = await invoicesStore.exportZip(ids)
  if (success) {
    toast.add({
      title: $t('invoices.exportZipStarted'),
      description: $t('invoices.exportZipStartedDescription'),
      icon: 'i-lucide-archive',
      color: 'info',
    })
  }
}

function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}

async function fetchInvoices() {
  const direction = activeDirection.value === 'all' ? null : activeDirection.value

  invoicesStore.setFilters({
    direction: direction as any,
    status: statusFilter.value !== 'all' ? statusFilter.value as any : null,
    search: search.value,
    isPaid: paidFilter.value === 'paid' ? true : paidFilter.value === 'unpaid' ? false : null,
    dateFrom: dateFrom.value || null,
    dateTo: dateTo.value || null,
  })
  invoicesStore.page = page.value
  invoicesStore.limit = limit.value
  applySortToStore()
  await invoicesStore.fetchInvoices()
}

watch([page, activeDirection, statusFilter, paidFilter, dateFrom, dateTo], () => fetchInvoices())

watch(() => companyStore.currentCompanyId, () => {
  resetAllFilters()
  sorting.value = []
  fetchInvoices()
  invoiceRealtime.stop()
  invoiceRealtime.start()
})

const invoiceRealtime = useInvoiceRealtime(() => fetchInvoices())

const { can } = usePermissions()

onMounted(() => {
  fetchInvoices()
  invoiceRealtime.start()
  checkSlideoverQueryParams()
})

onUnmounted(() => {
  invoiceRealtime.stop()
})
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('invoices.title')" :ui="{ right: 'gap-1.5' }">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UDropdownMenu :items="exportItems">
            <UButton icon="i-lucide-download" color="neutral" variant="outline" />
          </UDropdownMenu>
          <UDropdownMenu
            v-if="can(P.INVOICE_CREATE)"
            :items="[
              { label: $t('invoices.newInvoice'), icon: 'i-lucide-file-text', onSelect: openCreateSlideover },
              { label: $t('invoices.createRecurring'), icon: 'i-lucide-repeat', onSelect: () => router.push('/recurring-invoices?create=true') },
            ]"
          >
            <UButton icon="i-lucide-plus" trailing-icon="i-lucide-chevron-down">
              {{ $t('invoices.newInvoice') }}
            </UButton>
          </UDropdownMenu>
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <div class="flex flex-col gap-3 w-full pt-5">
          <!-- Row 1: Tabs + Search -->
          <div class="flex flex-wrap items-center gap-2">
            <UTabs v-model="activeDirection" :items="directionTabs" size="xs" class="mr-auto" />
            <UInput v-model="search" :placeholder="$t('common.search')" icon="i-lucide-search" class="w-full sm:w-56" @update:model-value="onSearchInput" />
          </div>

          <!-- Row 2: Quick date presets + Date pickers + Filters -->
          <div class="flex flex-wrap items-center gap-1.5">
            <!-- Quick date presets -->
            <div class="flex items-center gap-1 mr-1">
              <button
                v-for="preset in datePresets"
                :key="preset.value"
                type="button"
                class="px-2.5 py-1 rounded-full text-xs font-medium border transition-colors cursor-pointer whitespace-nowrap"
                :class="activeDatePreset === preset.value
                  ? 'bg-primary/10 border-primary text-primary'
                  : 'bg-(--ui-bg-elevated) border-(--ui-border) text-(--ui-text-muted) hover:border-(--ui-text-muted)'"
                @click="applyDatePreset(preset)"
              >
                {{ preset.label }}
              </button>
            </div>

            <!-- Custom date range -->
            <UInput v-model="dateFrom" type="date" class="w-34" @update:model-value="onDateManualChange" />
            <span class="text-xs text-muted">—</span>
            <UInput v-model="dateTo" type="date" class="w-34" @update:model-value="onDateManualChange" />

            <div class="h-5 w-px bg-(--ui-border) mx-1 hidden sm:block" />

            <!-- Status & Paid filters -->
            <USelectMenu v-model="statusFilter" :items="statusOptions" value-key="value" :placeholder="$t('invoices.filterByStatus')" class="w-full sm:w-40" />
            <USelectMenu v-model="paidFilter" :items="paidOptions" value-key="value" :placeholder="$t('invoices.filterPaid')" class="w-full sm:w-28" />
          </div>

          <!-- Row 3: Active filter chips -->
          <div v-if="hasActiveFilters" class="flex flex-wrap items-center gap-1.5">
            <span class="text-xs text-muted font-medium">{{ $t('invoices.activeFilters') }}:</span>
            <UBadge
              v-for="f in activeFilters"
              :key="f.key"
              variant="subtle"
              size="sm"
              class="gap-1 cursor-pointer"
              @click="f.clear()"
            >
              {{ f.label }}
              <UIcon name="i-lucide-x" class="size-3" />
            </UBadge>
            <button type="button" class="text-xs text-primary hover:underline cursor-pointer ml-1" @click="resetAllFilters">
              {{ $t('invoices.resetFilters') }}
            </button>
          </div>
        </div>
      </UDashboardToolbar>
    </template>

    <template #body>
      <SharedTableBulkBar :count="selectionCount" :loading="bulkLoading" @clear="clearSelection">
        <template #actions>
          <UButton :label="$t('bulk.export')" icon="i-lucide-archive" variant="soft" size="sm" :loading="bulkLoading" @click="handleBulkExportZip" />
          <UButton v-if="can(P.PAYMENT_CREATE) && eligibleForMarkPaid.length > 0" :label="`${$t('bulk.markPaid')} (${eligibleForMarkPaid.length})`" icon="i-lucide-banknote" color="success" variant="soft" size="sm" @click="markPaidConfirmOpen = true" />
          <UButton v-if="can(P.PAYMENT_CREATE) && eligibleForMarkUnpaid.length > 0" :label="`${$t('bulk.markUnpaid')} (${eligibleForMarkUnpaid.length})`" icon="i-lucide-banknote-x" color="warning" variant="soft" size="sm" @click="markUnpaidConfirmOpen = true" />
          <UButton v-if="can(P.INVOICE_REFUND) && eligibleForStorno.length > 0" :label="`${$t('bulk.storno')} (${eligibleForStorno.length})`" icon="i-lucide-file-minus" color="warning" variant="soft" size="sm" @click="stornoConfirmOpen = true" />
          <UButton v-if="can(P.INVOICE_CANCEL) && eligibleForCancel.length > 0" :label="`${$t('bulk.cancel')} (${eligibleForCancel.length})`" icon="i-lucide-x-circle" color="warning" variant="soft" size="sm" @click="cancelConfirmOpen = true" />
          <UButton v-if="can(P.INVOICE_DELETE) && eligibleForDelete.length > 0" :label="`${$t('bulk.delete')} (${eligibleForDelete.length})`" icon="i-lucide-trash-2" color="error" variant="soft" size="sm" @click="deleteConfirmOpen = true" />
        </template>
      </SharedTableBulkBar>

      <UTable
        :data="invoices"
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
        <template #number-cell="{ row }">
          <div class="flex items-center">
            <span class="w-4 text-[10px] font-bold text-primary tabular-nums">{{ row.original.invoiceTypeCode ? invoiceTypeCodeShort[row.original.invoiceTypeCode] || '' : '' }}</span>
            <span>{{ row.original.number }}</span>
          </div>
        </template>
        <template #issueDate-cell="{ row }">
          {{ row.original.issueDate ? new Date(row.original.issueDate).toLocaleDateString('ro-RO') : '-' }}
        </template>
        <template #counterparty-cell="{ row }">
          {{ getCounterparty(row.original) }}
        </template>
        <template #direction-cell="{ row }">
          <UBadge :color="row.original.direction === 'incoming' ? 'info' : 'success'" variant="subtle" size="sm">
            {{ row.original.direction === 'incoming' ? $t('common.incoming') : $t('common.outgoing') }}
          </UBadge>
        </template>
        <template #total-cell="{ row }">
          <span class="font-medium tabular-nums">
            {{ formatMoney(row.original.total, row.original.currency) }}
          </span>
        </template>
        <template #status-cell="{ row }">
          <div class="flex items-center gap-1">
            <UBadge :color="statusColor(row.original.status)" variant="subtle" size="sm">
              {{ $t(`documentStatus.${row.original.status}`) }}
            </UBadge>
            <UBadge v-if="row.original.paidAt" color="success" variant="subtle" size="sm">
              {{ $t('documentStatus.paid') }}
            </UBadge>
            <UBadge v-else-if="!row.original.paidAt && Number(row.original.amountPaid) > 0" color="warning" variant="subtle" size="sm">
              {{ $t('documentStatus.partially_paid') }}
            </UBadge>
            <UBadge
              v-if="!row.original.paidAt && row.original.dueDate && new Date(row.original.dueDate) < new Date()"
              color="error" variant="subtle" size="sm"
            >
              {{ $t('documentStatus.overdue') }}
            </UBadge>
            <UTooltip v-if="row.original.isDuplicate" :text="$t('invoices.isDuplicate')">
              <UIcon name="i-lucide-copy" class="text-amber-500" />
            </UTooltip>
            <UTooltip v-if="row.original.isLateSubmission" :text="$t('invoices.isLateSubmission')">
              <UIcon name="i-lucide-clock" class="text-red-500" />
            </UTooltip>
          </div>
        </template>
        <template #actions-cell="{ row }">
          <UDropdownMenu :items="getRowActions(row.original)">
            <UButton icon="i-lucide-ellipsis-vertical" variant="ghost" size="xs" @click.stop />
          </UDropdownMenu>
        </template>

      </UTable>

      <UEmpty v-if="!loading && !invoices.length" icon="i-lucide-file-text" :title="$t('invoices.noInvoices')" :description="$t('invoices.noInvoicesDesc')" class="py-12" />

      <!-- Totals summary -->
      <div v-if="invoices.length" class="flex flex-col items-end gap-1 py-3 text-sm">
        <div class="flex items-center gap-4">
          <span class="text-muted">{{ $t('invoices.totalExcluding') }}</span>
          <span class="font-medium tabular-nums w-28 text-right">{{ formatPlainMoney(invoicesStore.totals.subtotal) }}</span>
        </div>
        <div class="flex items-center gap-4">
          <span class="text-muted">{{ $t('invoices.vatLabel') }}</span>
          <span class="font-medium tabular-nums w-28 text-right">{{ formatPlainMoney(invoicesStore.totals.vatTotal) }}</span>
        </div>
        <div class="flex items-center gap-4">
          <span class="text-muted font-semibold">{{ $t('invoices.totalIncluding') }}</span>
          <span class="font-semibold tabular-nums w-28 text-right">{{ formatPlainMoney(invoicesStore.totals.total) }}</span>
        </div>
        <div v-if="activeDirection !== 'incoming'" class="flex items-center gap-4">
          <span class="text-muted">{{ $t('invoices.toBeCollected') }}</span>
          <span class="font-medium tabular-nums w-28 text-right">{{ formatPlainMoney(invoicesStore.totals.receivable) }}</span>
        </div>
        <div v-if="activeDirection !== 'outgoing'" class="flex items-center gap-4">
          <span class="text-muted">{{ $t('invoices.toBePaid') }}</span>
          <span class="font-medium tabular-nums w-28 text-right">{{ formatPlainMoney(invoicesStore.totals.payable) }}</span>
        </div>
      </div>

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <span class="text-sm text-muted">
          {{ $t('common.showing') }} {{ invoices.length }} {{ $t('common.of') }} {{ total }}
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

      <!-- Bulk Cancel Confirm -->
      <UModal v-model:open="cancelConfirmOpen">
        <template #header>
          <h3 class="text-lg font-semibold">{{ $t('bulk.cancelConfirmTitle') }}</h3>
        </template>
        <template #body>
          <p class="text-sm">{{ $t('bulk.cancelConfirmDescription', { count: eligibleForCancel.length }) }}</p>
        </template>
        <template #footer>
          <div class="flex justify-end gap-2">
            <UButton :label="$t('common.cancel')" variant="ghost" @click="cancelConfirmOpen = false" />
            <UButton :label="$t('bulk.cancel')" color="warning" :loading="bulkLoading" @click="handleBulkCancel" />
          </div>
        </template>
      </UModal>

      <!-- Bulk Storno Confirm -->
      <UModal v-model:open="stornoConfirmOpen">
        <template #header>
          <h3 class="text-lg font-semibold">{{ $t('bulk.stornoConfirmTitle') }}</h3>
        </template>
        <template #body>
          <p class="text-sm">{{ $t('bulk.stornoConfirmDescription', { count: eligibleForStorno.length }) }}</p>
        </template>
        <template #footer>
          <div class="flex justify-end gap-2">
            <UButton :label="$t('common.cancel')" variant="ghost" @click="stornoConfirmOpen = false" />
            <UButton :label="$t('bulk.storno')" color="warning" :loading="bulkLoading" @click="handleBulkStorno" />
          </div>
        </template>
      </UModal>

      <!-- Bulk Mark Paid Confirm -->
      <UModal v-model:open="markPaidConfirmOpen">
        <template #header>
          <h3 class="text-lg font-semibold">{{ $t('bulk.markPaidConfirmTitle') }}</h3>
        </template>
        <template #body>
          <p class="text-sm">{{ $t('bulk.markPaidConfirmDescription', { count: eligibleForMarkPaid.length }) }}</p>
        </template>
        <template #footer>
          <div class="flex justify-end gap-2">
            <UButton :label="$t('common.cancel')" variant="ghost" @click="markPaidConfirmOpen = false" />
            <UButton :label="$t('bulk.markPaid')" color="success" :loading="bulkLoading" @click="handleBulkMarkPaid" />
          </div>
        </template>
      </UModal>

      <!-- Bulk Mark Unpaid Confirm -->
      <UModal v-model:open="markUnpaidConfirmOpen">
        <template #header>
          <h3 class="text-lg font-semibold">{{ $t('bulk.markUnpaidConfirmTitle') }}</h3>
        </template>
        <template #body>
          <p class="text-sm">{{ $t('bulk.markUnpaidConfirmDescription', { count: eligibleForMarkUnpaid.length }) }}</p>
        </template>
        <template #footer>
          <div class="flex justify-end gap-2">
            <UButton :label="$t('common.cancel')" variant="ghost" @click="markUnpaidConfirmOpen = false" />
            <UButton :label="$t('bulk.markUnpaid')" color="warning" :loading="bulkLoading" @click="handleBulkMarkUnpaid" />
          </div>
        </template>
      </UModal>

      <!-- Create / Edit / Copy / Refund Invoice Slideover -->
      <USlideover
        v-model:open="formSlideoverOpen"
        :ui="{ content: 'sm:max-w-2xl' }"
      >
        <template #header>
          <span class="text-lg font-semibold">{{ formSlideoverTitle }}</span>
        </template>
        <template #body>
          <InvoicesInvoiceForm
            v-if="formSlideoverOpen"
            :invoice="formEditInvoice"
            :refund-of="formRefundOf"
            :copy-of="formCopyOf"
            @saved="onFormSaved"
            @cancel="formSlideoverOpen = false"
          />
        </template>
      </USlideover>
    </template>
  </UDashboardPanel>
</template>
