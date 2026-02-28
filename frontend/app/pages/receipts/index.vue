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
const sorting = ref<SortingState>([])

// Create/Edit/Copy slideover state
const formSlideoverOpen = ref(false)
const formSlideoverTitle = ref('')
const formEditReceipt = ref<Receipt | null>(null)
const formCopyOf = ref<string | undefined>(undefined)

function openCreateSlideover() {
  formEditReceipt.value = null
  formCopyOf.value = undefined
  formSlideoverTitle.value = $t('receipts.newReceipt')
  formSlideoverOpen.value = true
}

async function openEditSlideover(uuid: string) {
  const receipt = await receiptStore.fetchReceipt(uuid)
  if (!receipt) return
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
    openCreateSlideover()
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

const columns = [
  { accessorKey: 'number', header: $t('receipts.number'), enableSorting: true },
  { accessorKey: 'clientName', header: $t('receipts.client'), enableSorting: false },
  { accessorKey: 'issueDate', header: $t('receipts.issueDate'), enableSorting: true },
  { accessorKey: 'total', header: $t('receipts.total'), enableSorting: true },
  { accessorKey: 'paymentMethod', header: $t('receipts.paymentMethod'), enableSorting: false },
  { accessorKey: 'status', header: $t('receipts.status'), enableSorting: true },
  { id: 'actions', header: '', accessorKey: 'id', size: 50, enableSorting: false },
]

function formatMoney(amount?: string | number, currency = 'RON') {
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency }).format(Number(amount || 0))
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
  })
  receiptStore.page = page.value
  receiptStore.limit = limit.value
  applySortToStore()
  await receiptStore.fetchReceipts()
}

watch([page, statusFilter], () => fetchReceipts())

watch(() => companyStore.currentCompanyId, () => {
  page.value = 1
  search.value = ''
  statusFilter.value = 'all'
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

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <span class="text-sm text-muted">
          {{ $t('common.showing') }} {{ receipts.length }} {{ $t('common.of') }} {{ total }}
        </span>
        <UPagination v-model:page="page" :total="total" :items-per-page="limit" />
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
            @saved="onFormSaved"
            @cancel="formSlideoverOpen = false"
          />
        </template>
      </USlideover>
    </template>
  </UDashboardPanel>
</template>
