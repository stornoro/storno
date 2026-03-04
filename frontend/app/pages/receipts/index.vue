<script setup lang="ts">
import type { SortingState } from '@tanstack/vue-table'
import type { ReceiptStatus } from '~/types/enums'
import type { Receipt } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('receipts.title') })
const { can } = usePermissions()
const router = useRouter()
const route = useRoute()
const toast = useToast()
const receiptStore = useReceiptStore()
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
const formEditReceipt = ref<Receipt | null>(null)
const formCopyOf = ref<string | undefined>(undefined)
const formPrefillClientId = ref<string | undefined>(undefined)

function openCreateSlideover(clientId?: string) {
  formEditReceipt.value = null
  formCopyOf.value = undefined
  formPrefillClientId.value = clientId
  formSlideoverTitle.value = $t('receipts.newReceipt')
  formSlideoverOpen.value = true
}

async function openEditSlideover(uuid: string) {
  const receipt = await receiptStore.fetchReceipt(uuid)
  if (!receipt) {
    toast.add({ title: $t('common.error'), color: 'error' })
    return
  }
  if (!['draft', 'issued'].includes(receipt.status)) {
    toast.add({ title: $t('receipts.notEditable'), color: 'warning' })
    router.replace(`/receipts/${uuid}`)
    return
  }
  formEditReceipt.value = receipt
  formCopyOf.value = undefined
  formSlideoverTitle.value = $t('receipts.editReceipt')
  formSlideoverOpen.value = true
}

function openCopySlideover(uuid: string) {
  formEditReceipt.value = null
  formCopyOf.value = uuid
  formSlideoverTitle.value = $t('receipts.copyReceipt')
  formSlideoverOpen.value = true
}

function onFormSaved(receipt: Receipt) {
  formSlideoverOpen.value = false
  toast.add({
    title: formEditReceipt.value ? $t('receipts.updateSuccess') : $t('receipts.createSuccess'),
    color: 'success',
    icon: 'i-lucide-check',
  })
  fetchReceipts()
  router.push(`/receipts/${receipt.id}`)
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
function getRowActions(receipt: any) {
  const group: any[] = []

  if (can(P.INVOICE_CREATE)) {
    group.push({
      label: $t('common.copy'),
      icon: 'i-lucide-copy',
      onSelect: () => openCopySlideover(receipt.id),
    })
  }

  if (can(P.INVOICE_EDIT) && ['draft', 'issued'].includes(receipt.status)) {
    group.push({
      label: $t('common.edit'),
      icon: 'i-lucide-pencil',
      onSelect: () => openEditSlideover(receipt.id),
    })
  }

  if (receipt.status === 'cancelled') {
    group.push({
      label: $t('receipts.restore'),
      icon: 'i-lucide-rotate-ccw',
      onSelect: () => router.push(`/receipts/${receipt.id}`),
    })
  }

  return [group]
}

const loading = computed(() => receiptStore.loading)
const receipts = computed(() => receiptStore.items)
const total = computed(() => receiptStore.total)

const statusOptions = computed(() => [
  { label: $t('common.all'), value: 'all' },
  { label: $t('receiptStatus.draft'), value: 'draft' },
  { label: $t('receiptStatus.issued'), value: 'issued' },
  { label: $t('receiptStatus.invoiced'), value: 'invoiced' },
  { label: $t('receiptStatus.cancelled'), value: 'cancelled' },
])

const { visibility: columnVisibility, toggle: toggleColumn, filterColumns, toggleableColumns } = useColumnVisibility('storno:receipts:columns', [
  { key: 'subtotal', label: $t('invoices.subtotal'), default: false },
  { key: 'vatTotal', label: $t('invoices.vatLabel'), default: false },
  { key: 'customerCif', label: $t('receipts.customerCif'), default: false },
])

const allColumnDefs = [
  { accessorKey: 'number', header: $t('receipts.number'), enableSorting: true, _always: true },
  { accessorKey: 'clientName', header: $t('receipts.client'), enableSorting: false, _always: true },
  { accessorKey: 'customerCif', header: $t('receipts.customerCif'), enableSorting: false, _toggle: 'customerCif' },
  { accessorKey: 'issueDate', header: $t('receipts.issueDate'), enableSorting: true, _always: true },
  { accessorKey: 'subtotal', header: $t('invoices.subtotal'), enableSorting: true, _toggle: 'subtotal' },
  { accessorKey: 'vatTotal', header: $t('invoices.vatLabel'), enableSorting: true, _toggle: 'vatTotal' },
  { accessorKey: 'total', header: $t('receipts.total'), enableSorting: true, _always: true },
  { accessorKey: 'paymentMethod', header: $t('receipts.paymentMethod'), enableSorting: false, _always: true },
  { accessorKey: 'status', header: $t('receipts.status'), enableSorting: true, _always: true },
  { id: 'actions', header: '', accessorKey: 'id', size: 50, enableSorting: false, _always: true },
]

const columns = computed(() => filterColumns(allColumnDefs))

function formatMoney(amount?: string | number, currency = 'RON') {
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency }).format(Number(amount || 0))
}

function formatPlainMoney(amount?: string | number) {
  return new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(amount || 0))
}

function formatPaymentMethod(method: string | null): string {
  if (!method) return '-'
  const key = `receipts.paymentMethod${method.charAt(0).toUpperCase() + method.slice(1).replace(/_([a-z])/g, (_, c) => c.toUpperCase())}`
  return $t(key) || method
}

type BadgeColor = 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'error' | 'neutral'

function statusColor(status: string): BadgeColor {
  const map: Record<string, BadgeColor> = { draft: 'neutral', issued: 'primary', invoiced: 'info', cancelled: 'neutral' }
  return map[status] || 'neutral'
}

const onSearchInput = useDebounceFn(() => {
  page.value = 1
  fetchReceipts()
}, 300)

function onSortChange(newSorting: SortingState | undefined) {
  sorting.value = newSorting ?? []
  applySortToStore()
  fetchReceipts()
}

function applySortToStore() {
  const firstSort = sorting.value[0]
  if (firstSort) {
    receiptStore.sort = firstSort.id
    receiptStore.order = firstSort.desc ? 'desc' : 'asc'
  }
  else {
    receiptStore.sort = null
    receiptStore.order = null
  }
}

function onRowClick(_e: Event, row: any) {
  router.push(`/receipts/${row.original.id}`)
}

async function fetchReceipts() {
  receiptStore.setFilters({
    status: statusFilter.value !== 'all' ? statusFilter.value as ReceiptStatus : null,
    search: search.value,
    currency: currencyFilter.value || null,
  })
  receiptStore.page = page.value
  receiptStore.limit = limit.value
  applySortToStore()
  await receiptStore.fetchReceipts()
}

watch([page, statusFilter, currencyFilter], () => fetchReceipts())

watch(() => companyStore.currentCompanyId, () => {
  page.value = 1
  search.value = ''
  statusFilter.value = 'all'
  currencyFilter.value = companyStore.currentCompany?.defaultCurrency || 'RON'
  sorting.value = []
  fetchReceipts()
})

onMounted(() => {
  fetchReceipts()
  checkSlideoverQueryParams()
})
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('receipts.title')" :ui="{ right: 'gap-1.5' }">
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
          <UTooltip :kbds="['C', 'B']">
            <UButton v-if="can(P.INVOICE_CREATE)" icon="i-lucide-plus" @click="openCreateSlideover">
              {{ $t('receipts.newReceipt') }}
            </UButton>
          </UTooltip>
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <div class="flex flex-wrap items-center gap-2 w-full">
          <UInput v-model="search" :placeholder="$t('common.search')" icon="i-lucide-search" class="w-full sm:w-56" @update:model-value="onSearchInput" />
          <USelectMenu v-model="statusFilter" :items="statusOptions" value-key="value" :placeholder="$t('receipts.status')" class="w-full sm:w-44" />
          <template v-if="receiptStore.distinctCurrencies.length > 1">
            <div class="h-5 w-px bg-(--ui-border) mx-1 hidden sm:block" />
            <div class="flex items-center gap-1">
              <button
                v-for="cur in receiptStore.distinctCurrencies"
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
      <UTable
        :data="receipts"
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
        <template #issueDate-cell="{ row }">
          {{ row.original.issueDate ? new Date(row.original.issueDate).toLocaleDateString('ro-RO') : '-' }}
        </template>
        <template #clientName-cell="{ row }">
          {{ row.original.clientName || row.original.customerName || '-' }}
        </template>
        <template #subtotal-cell="{ row }">
          <span class="tabular-nums text-sm">{{ formatMoney(row.original.subtotal, row.original.currency) }}</span>
        </template>
        <template #vatTotal-cell="{ row }">
          <span class="tabular-nums text-sm">{{ formatMoney(row.original.vatTotal, row.original.currency) }}</span>
        </template>
        <template #customerCif-cell="{ row }">
          <span class="font-mono text-sm">{{ row.original.customerCif || '-' }}</span>
        </template>
        <template #total-cell="{ row }">
          <span class="font-medium tabular-nums">
            {{ formatMoney(row.original.total, row.original.currency) }}
          </span>
        </template>
        <template #paymentMethod-cell="{ row }">
          {{ formatPaymentMethod(row.original.paymentMethod) }}
        </template>
        <template #status-cell="{ row }">
          <UBadge :color="statusColor(row.original.status)" variant="subtle" size="sm">
            {{ $t(`receiptStatus.${row.original.status}`) }}
          </UBadge>
        </template>
        <template #actions-cell="{ row }">
          <UDropdownMenu :items="getRowActions(row.original)">
            <UButton icon="i-lucide-ellipsis-vertical" variant="ghost" size="xs" @click.stop />
          </UDropdownMenu>
        </template>
      </UTable>

      <UEmpty v-if="!loading && !receipts.length" icon="i-lucide-receipt" :title="$t('receipts.noReceipts')" :description="$t('receipts.noReceiptsDesc')" class="py-12" />

      <div v-if="receipts.length" class="flex items-center bg-elevated/50 rounded-lg border border-default mt-2 py-2.5 px-4 gap-0">
        <div class="flex-1 px-3">
          <div class="text-[10px] font-semibold text-muted uppercase tracking-wide mb-0.5">{{ $t('invoices.totalExcluding') }}</div>
          <div class="text-sm font-bold tabular-nums">{{ formatPlainMoney(receiptStore.totals.subtotal) }} <span class="text-xs text-muted font-normal">{{ receiptStore.activeCurrency }}</span></div>
        </div>
        <div class="w-px h-8 bg-default shrink-0" />
        <div class="flex-1 px-3">
          <div class="text-[10px] font-semibold text-muted uppercase tracking-wide mb-0.5">{{ $t('invoices.vatLabel') }}</div>
          <div class="text-sm font-bold tabular-nums">{{ formatPlainMoney(receiptStore.totals.vatTotal) }} <span class="text-xs text-muted font-normal">{{ receiptStore.activeCurrency }}</span></div>
        </div>
        <div class="w-px h-8 bg-default shrink-0" />
        <div class="flex-1 px-3">
          <div class="text-[10px] font-semibold text-muted uppercase tracking-wide mb-0.5">{{ $t('invoices.totalIncluding') }}</div>
          <div class="text-[15px] font-extrabold tabular-nums">{{ formatPlainMoney(receiptStore.totals.total) }} <span class="text-xs text-muted font-normal">{{ receiptStore.activeCurrency }}</span></div>
        </div>
        <div class="w-px h-8 bg-default shrink-0" />
        <div class="ml-auto pl-4 flex items-center gap-2.5 shrink-0">
          <span class="text-xs text-muted font-medium whitespace-nowrap">{{ $t('common.showing') }} {{ receipts.length }} {{ $t('common.of') }} {{ total }}</span>
          <UPagination v-model:page="page" :total="total" :items-per-page="limit" />
        </div>
      </div>

      <div v-else class="flex items-center bg-elevated/50 rounded-lg border border-default mt-2 py-2.5 px-4">
        <span class="text-xs text-muted font-medium whitespace-nowrap">{{ $t('common.showing') }} {{ receipts.length }} {{ $t('common.of') }} {{ total }}</span>
        <div class="ml-auto pl-4">
          <UPagination v-model:page="page" :total="total" :items-per-page="limit" />
        </div>
      </div>

      <!-- Create / Edit Receipt Slideover -->
      <USlideover
        v-model:open="formSlideoverOpen"
        :ui="{ content: 'sm:max-w-2xl' }"
      >
        <template #header>
          <span class="text-lg font-semibold">{{ formSlideoverTitle }}</span>
        </template>
        <template #body>
          <ReceiptsReceiptForm
            v-if="formSlideoverOpen"
            :receipt="formEditReceipt"
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
