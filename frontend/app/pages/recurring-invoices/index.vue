<script setup lang="ts">
import type { RecurringInvoice } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('recurringInvoices.title') })
const { can } = usePermissions()
const router = useRouter()
const route = useRoute()
const toast = useToast()
const store = useRecurringInvoiceStore()
const companyStore = useCompanyStore()

const page = ref(1)
const limit = ref(PAGINATION.DEFAULT_LIMIT)
const search = ref('')
const activeFilter = ref('all')
const frequencyFilter = ref('all')

// Create slideover state
const formSlideoverOpen = ref(false)

function openCreateSlideover() {
  formSlideoverOpen.value = true
}

function onFormSaved(ri: RecurringInvoice) {
  formSlideoverOpen.value = false
  toast.add({
    title: $t('recurringInvoices.createSuccess'),
    color: 'success',
    icon: 'i-lucide-check',
  })
  fetchData()
  router.push(`/recurring-invoices/${ri.id}`)
}

// ── Selection ──────────────────────────────────────────────────────
const { selectedIds, allSelected, toggle, isSelected, clear: clearSelection, count: selectionCount } = useTableSelection(
  computed(() => store.items),
)
const bulkLoading = ref(false)
const deleteConfirmOpen = ref(false)
const issueNowConfirmOpen = ref(false)

const eligibleForIssueNow = computed(() =>
  store.items.filter(i =>
    selectedIds.value.includes(i.id) && i.isActive,
  ),
)

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
    fetchData()
  }
}

async function handleBulkActivate() {
  bulkLoading.value = true
  const result = await store.bulkToggleActive(selectedIds.value, true)
  bulkLoading.value = false
  if (result) {
    toast.add({ title: $t('bulk.activateSuccess', { count: result.updated }), color: 'success' })
    clearSelection()
    fetchData()
  }
}

async function handleBulkIssueNow() {
  issueNowConfirmOpen.value = false
  bulkLoading.value = true
  const ids = eligibleForIssueNow.value.map(i => i.id)
  const result = await store.bulkIssueNow(ids)
  bulkLoading.value = false
  if (result) {
    if (result.errors.length > 0) {
      toast.add({ title: $t('bulk.issueNowPartial', { issued: result.issued, errors: result.errors.length }), color: 'warning' })
    }
    else {
      toast.add({ title: $t('bulk.issueNowSuccess', { count: result.issued }), color: 'success' })
    }
    clearSelection()
    fetchData()
  }
}

async function handleBulkDeactivate() {
  bulkLoading.value = true
  const result = await store.bulkToggleActive(selectedIds.value, false)
  bulkLoading.value = false
  if (result) {
    toast.add({ title: $t('bulk.deactivateSuccess', { count: result.updated }), color: 'success' })
    clearSelection()
    fetchData()
  }
}

const loading = computed(() => store.loading)
const recurringInvoices = computed(() => store.items)
const total = computed(() => store.total)

const activeOptions = computed(() => [
  { label: $t('common.all'), value: 'all' },
  { label: $t('common.active'), value: 'true' },
  { label: $t('common.inactive'), value: 'false' },
])

const frequencyOptions = computed(() => [
  { label: $t('common.all'), value: 'all' },
  { label: $t('recurringInvoices.frequencies.once'), value: 'once' },
  { label: $t('recurringInvoices.frequencies.weekly'), value: 'weekly' },
  { label: $t('recurringInvoices.frequencies.monthly'), value: 'monthly' },
  { label: $t('recurringInvoices.frequencies.bimonthly'), value: 'bimonthly' },
  { label: $t('recurringInvoices.frequencies.quarterly'), value: 'quarterly' },
  { label: $t('recurringInvoices.frequencies.semi_annually'), value: 'semi_annually' },
  { label: $t('recurringInvoices.frequencies.yearly'), value: 'yearly' },
])

const columns = [
  { id: 'select', header: '', accessorKey: 'id', size: 40 },
  { accessorKey: 'reference', header: $t('recurringInvoices.reference') },
  { accessorKey: 'clientName', header: $t('invoices.client') },
  { accessorKey: 'frequency', header: $t('recurringInvoices.frequency') },
  { accessorKey: 'nextIssuanceDate', header: $t('recurringInvoices.nextIssuanceDate') },
  { accessorKey: 'total', header: $t('invoices.total') },
  { accessorKey: 'isActive', header: $t('common.status') },
]

function formatMoney(amount?: string | number, currency = 'RON') {
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency }).format(Number(amount || 0))
}

const onSearchInput = useDebounceFn(() => {
  page.value = 1
  fetchData()
}, 300)

function onRowClick(_e: Event, row: any) {
  router.push(`/recurring-invoices/${row.original.id}`)
}

async function fetchData() {
  store.setFilters({
    search: search.value,
    isActive: activeFilter.value === 'all' ? null : activeFilter.value === 'true',
    frequency: frequencyFilter.value === 'all' ? null : frequencyFilter.value,
  })
  store.page = page.value
  store.limit = limit.value
  await store.fetchRecurringInvoices()
}

watch([page, activeFilter, frequencyFilter], () => fetchData())

watch(() => companyStore.currentCompanyId, () => {
  page.value = 1
  search.value = ''
  activeFilter.value = 'all'
  frequencyFilter.value = 'all'
  fetchData()
})


onMounted(() => {
  fetchData()
  if (route.query.create) {
    openCreateSlideover()
  }
})
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('recurringInvoices.title')" :ui="{ right: 'gap-1.5' }">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UTooltip :kbds="['C', 'R']">
            <UButton v-if="can(P.RECURRING_INVOICE_MANAGE)" icon="i-lucide-plus" @click="openCreateSlideover">
              {{ $t('recurringInvoices.newRecurringInvoice') }}
            </UButton>
          </UTooltip>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-wrap items-center justify-between gap-1.5">
        <UInput v-model="search" :placeholder="$t('common.search')" icon="i-lucide-search" class="max-w-sm" @update:model-value="onSearchInput" />
        <div class="flex flex-wrap items-center gap-1.5">
          <USelectMenu v-model="activeFilter" :items="activeOptions" value-key="value" :placeholder="$t('common.status')" class="w-36" />
          <USelectMenu v-model="frequencyFilter" :items="frequencyOptions" value-key="value" :placeholder="$t('recurringInvoices.frequency')" class="w-36" />
        </div>
      </div>

      <SharedTableBulkBar :count="selectionCount" :loading="bulkLoading" @clear="clearSelection">
        <template #actions>
          <UButton v-if="can(P.RECURRING_INVOICE_MANAGE)" :label="$t('bulk.issueNow')" icon="i-lucide-file-output" variant="soft" size="sm" :loading="bulkLoading" :disabled="eligibleForIssueNow.length === 0" @click="issueNowConfirmOpen = true" />
          <UButton v-if="can(P.RECURRING_INVOICE_MANAGE)" :label="$t('bulk.activate')" icon="i-lucide-play" variant="soft" size="sm" :loading="bulkLoading" @click="handleBulkActivate" />
          <UButton v-if="can(P.RECURRING_INVOICE_MANAGE)" :label="$t('bulk.deactivate')" icon="i-lucide-pause" color="warning" variant="soft" size="sm" :loading="bulkLoading" @click="handleBulkDeactivate" />
          <UButton v-if="can(P.RECURRING_INVOICE_MANAGE)" :label="$t('bulk.delete')" icon="i-lucide-trash-2" color="error" variant="soft" size="sm" @click="deleteConfirmOpen = true" />
        </template>
      </SharedTableBulkBar>

      <UTable
        :data="recurringInvoices"
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
        <template #nextIssuanceDate-cell="{ row }">
          {{ row.original.nextIssuanceDate ? new Date(row.original.nextIssuanceDate).toLocaleDateString('ro-RO') : '-' }}
        </template>
        <template #frequency-cell="{ row }">
          {{ $t(`recurringInvoices.frequencies.${row.original.frequency}`) }}
        </template>
        <template #total-cell="{ row }">
          <span class="font-medium tabular-nums">
            {{ formatMoney(row.original.estimatedTotal || row.original.total, row.original.currency) }}
          </span>
          <UPopover v-if="row.original.estimatedTotal && row.original.estimatedTotal !== row.original.total" mode="hover">
            <UIcon name="i-lucide-info" class="size-3.5 text-(--ui-text-muted) ml-1 inline-block align-text-bottom cursor-help" />
            <template #content>
              <div class="p-2 text-xs max-w-48">
                {{ $t('recurringInvoices.estimatedTotalInfo') }}
              </div>
            </template>
          </UPopover>
        </template>
        <template #isActive-cell="{ row }">
          <UBadge :color="row.original.isActive ? 'success' : 'neutral'" variant="subtle" size="sm">
            {{ row.original.isActive ? $t('common.active') : $t('common.inactive') }}
          </UBadge>
        </template>
      </UTable>

      <UEmpty v-if="!loading && !recurringInvoices.length" icon="i-lucide-repeat" :title="$t('recurringInvoices.noRecurringInvoices')" :description="$t('recurringInvoices.noRecurringInvoicesDesc')" class="py-12" />

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <span class="text-sm text-muted">
          {{ $t('common.showing') }} {{ recurringInvoices.length }} {{ $t('common.of') }} {{ total }}
        </span>
        <UPagination v-model:page="page" :total="total" :items-per-page="limit" />
      </div>

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

      <!-- Bulk Issue Now Confirm -->
      <UModal v-model:open="issueNowConfirmOpen">
        <template #header>
          <h3 class="text-lg font-semibold">{{ $t('bulk.issueNowConfirmTitle') }}</h3>
        </template>
        <template #body>
          <p class="text-sm">{{ $t('bulk.issueNowConfirmDescription', { count: eligibleForIssueNow.length }) }}</p>
        </template>
        <template #footer>
          <div class="flex justify-end gap-2">
            <UButton :label="$t('common.cancel')" variant="ghost" @click="issueNowConfirmOpen = false" />
            <UButton :label="$t('bulk.issueNow')" color="primary" :loading="bulkLoading" @click="handleBulkIssueNow" />
          </div>
        </template>
      </UModal>

      <!-- Create Recurring Invoice Slideover -->
      <USlideover
        v-model:open="formSlideoverOpen"
        :ui="{ content: 'sm:max-w-2xl' }"
      >
        <template #header>
          <span class="text-lg font-semibold">{{ $t('recurringInvoices.newRecurringInvoice') }}</span>
        </template>
        <template #body>
          <RecurringInvoicesRecurringInvoiceForm
            v-if="formSlideoverOpen"
            @saved="onFormSaved"
            @cancel="formSlideoverOpen = false"
          />
        </template>
      </USlideover>
    </template>
  </UDashboardPanel>
</template>
