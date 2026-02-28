<script setup lang="ts">
import type { SortingState } from '@tanstack/vue-table'
import type { DeliveryNoteStatus } from '~/types/enums'
import type { DeliveryNote } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
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
const sorting = ref<SortingState>([])

// Create/Edit/Copy slideover state
const formSlideoverOpen = ref(false)
const formSlideoverTitle = ref('')
const formEditDeliveryNote = ref<DeliveryNote | null>(null)
const formCopyOf = ref<string | undefined>(undefined)

function openCreateSlideover() {
  formEditDeliveryNote.value = null
  formCopyOf.value = undefined
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
    openCreateSlideover()
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

const columns = [
  { id: 'select', header: '', accessorKey: 'id', size: 40, enableSorting: false },
  { accessorKey: 'number', header: $t('deliveryNotes.number'), enableSorting: true },
  { accessorKey: 'clientName', header: $t('deliveryNotes.client'), enableSorting: false },
  { accessorKey: 'issueDate', header: $t('deliveryNotes.issueDate'), enableSorting: true },
  { accessorKey: 'total', header: $t('deliveryNotes.total'), enableSorting: true },
  { accessorKey: 'status', header: $t('deliveryNotes.status'), enableSorting: true },
  { id: 'actions', header: '', accessorKey: 'id', size: 50, enableSorting: false },
]

function formatMoney(amount?: string | number, currency = 'RON') {
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency }).format(Number(amount || 0))
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

async function fetchDeliveryNotes() {
  deliveryNoteStore.setFilters({
    status: statusFilter.value !== 'all' ? statusFilter.value as DeliveryNoteStatus : null,
    search: search.value,
  })
  deliveryNoteStore.page = page.value
  deliveryNoteStore.limit = limit.value
  applySortToStore()
  await deliveryNoteStore.fetchDeliveryNotes()
}

watch([page, statusFilter], () => fetchDeliveryNotes())

watch(() => companyStore.currentCompanyId, () => {
  page.value = 1
  search.value = ''
  statusFilter.value = 'all'
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
          {{ row.original.issueDate ? new Date(row.original.issueDate).toLocaleDateString('ro-RO') : '-' }}
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

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <span class="text-sm text-muted">
          {{ $t('common.showing') }} {{ deliveryNotes.length }} {{ $t('common.of') }} {{ total }}
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
            @saved="onFormSaved"
            @cancel="formSlideoverOpen = false"
          />
        </template>
      </USlideover>
    </template>
  </UDashboardPanel>
</template>
