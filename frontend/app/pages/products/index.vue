<script setup lang="ts">
import type { Product, NcCode, CpvCode } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('products.title') })
const { can } = usePermissions()
const productsStore = useProductStore()
const companyStore = useCompanyStore()
const toast = useToast()
const { get } = useApi()
const { fetchDefaults, vatRateOptions, currencyOptions, unitOfMeasureOptions, defaultCurrency, defaultUnitOfMeasure, defaultVatRate } = useInvoiceDefaults()

// ── List state ───────────────────────────────────────────────────────
const page = ref(1)
const limit = ref(PAGINATION.DEFAULT_LIMIT)
const search = ref('')
const typeFilter = ref<'all' | 'service' | 'goods'>('all')
const usageFilter = ref<string>('all')

const loading = computed(() => productsStore.loading)
const products = computed(() => {
  let items = productsStore.items
  if (typeFilter.value === 'service') items = items.filter(p => p.isService)
  else if (typeFilter.value === 'goods') items = items.filter(p => !p.isService)
  if (usageFilter.value !== 'all') items = items.filter(p => p.usage === usageFilter.value)
  return items
})
const total = computed(() => productsStore.total)

// ── Slideover state ──────────────────────────────────────────────────
const slideoverOpen = ref(false)
const saving = ref(false)
const editingProduct = ref<Product | null>(null)

// ── Delete modal state ───────────────────────────────────────────────
const deleteModalOpen = ref(false)
const deletingProduct = ref<Product | null>(null)
const deleteLoading = ref(false)
const deleteUsage = ref<{ invoices: number, proformaInvoices: number, recurringInvoices: number, total: number } | null>(null)

const form = ref({
  name: '',
  code: '',
  description: '',
  defaultPrice: '',
  currency: 'RON',
  unitOfMeasure: 'buc',
  vatRate: '21.00',
  isService: false,
  isActive: true,
  usage: 'both',
  ncCode: null as string | null,
  cpvCode: null as string | null,
})

// ── NC Code search ──────────────────────────────────────────────────
const ncCodeSearchTerm = ref('')
const ncCodeSearchResults = ref<{ label: string, value: string }[]>([])
const ncCodeLoading = ref(false)

const _doNcSearch = useDebounceFn(async (term: string) => {
  if (!term || term.length < 2) {
    ncCodeSearchResults.value = []
    ncCodeLoading.value = false
    return
  }
  ncCodeLoading.value = true
  try {
    const results = await get<NcCode[]>('/v1/nc-codes', { search: term, limit: 30 })
    ncCodeSearchResults.value = results.map(nc => ({
      label: `${nc.cod} - ${nc.denumire}`,
      value: nc.cod,
    }))
  }
  catch {
    ncCodeSearchResults.value = []
  }
  finally {
    ncCodeLoading.value = false
  }
}, 300)

watch(ncCodeSearchTerm, (val) => {
  if (!val || val.length < 2) {
    ncCodeSearchResults.value = []
    return
  }
  ncCodeLoading.value = true
  _doNcSearch(val)
})

// ── CPV Code search ─────────────────────────────────────────────────
const cpvCodeSearchTerm = ref('')
const cpvCodeSearchResults = ref<{ label: string, value: string }[]>([])
const cpvCodeLoading = ref(false)

const _doCpvSearch = useDebounceFn(async (term: string) => {
  if (!term || term.length < 2) {
    cpvCodeSearchResults.value = []
    cpvCodeLoading.value = false
    return
  }
  cpvCodeLoading.value = true
  try {
    const results = await get<CpvCode[]>('/v1/cpv-codes', { search: term, limit: 30 })
    cpvCodeSearchResults.value = results.map(cpv => ({
      label: `${cpv.cod} - ${cpv.denumire}`,
      value: cpv.cod,
    }))
  }
  catch {
    cpvCodeSearchResults.value = []
  }
  finally {
    cpvCodeLoading.value = false
  }
}, 300)

watch(cpvCodeSearchTerm, (val) => {
  if (!val || val.length < 2) {
    cpvCodeSearchResults.value = []
    return
  }
  cpvCodeLoading.value = true
  _doCpvSearch(val)
})

const canSave = computed(() =>
  form.value.name.trim().length > 0
  && !!form.value.unitOfMeasure
  && !!form.value.vatRate
  && form.value.defaultPrice !== '',
)

// ── Filter options ───────────────────────────────────────────────────
const typeOptions = [
  { label: $t('products.allTypes'), value: 'all' },
  { label: $t('products.servicesOnly'), value: 'service' },
  { label: $t('products.goodsOnly'), value: 'goods' },
]

const usageOptions = [
  { label: $t('products.allTypes'), value: 'all' },
  { label: $t('products.usageOptions.sales'), value: 'sales' },
  { label: $t('products.usageOptions.purchases'), value: 'purchases' },
  { label: $t('products.usageOptions.both'), value: 'both' },
  { label: $t('products.usageOptions.internal'), value: 'internal' },
]

const usageFormOptions = [
  { label: $t('products.usageOptions.sales'), value: 'sales' },
  { label: $t('products.usageOptions.purchases'), value: 'purchases' },
  { label: $t('products.usageOptions.both'), value: 'both' },
  { label: $t('products.usageOptions.internal'), value: 'internal' },
]

// ── Selection ──────────────────────────────────────────────────────
const { selectedIds, allSelected, toggle, isSelected, clear: clearSelection, count: selectionCount } = useTableSelection(products)
const bulkLoading = ref(false)
const bulkDeleteConfirmOpen = ref(false)

async function handleBulkDelete() {
  bulkDeleteConfirmOpen.value = false
  bulkLoading.value = true
  const result = await productsStore.bulkDelete(selectedIds.value)
  bulkLoading.value = false
  if (result) {
    if (result.errors.length > 0) {
      toast.add({ title: $t('bulk.deletePartial', { deleted: result.deleted, errors: result.errors.length }), color: 'warning' })
    }
    else {
      toast.add({ title: $t('bulk.deleteSuccess', { count: result.deleted }), color: 'success' })
    }
    clearSelection()
    fetchProducts()
  }
  else if (productsStore.error) {
    toast.add({ title: productsStore.error, color: 'error' })
  }
}

// ── Table columns ────────────────────────────────────────────────────
const columns = [
  { id: 'select', header: '', accessorKey: 'id', size: 40 },
  { accessorKey: 'name', header: $t('products.name'), enableSorting: true },
  { accessorKey: 'code', header: $t('products.code') },
  { accessorKey: 'defaultPrice', header: $t('products.unitPrice'), enableSorting: true },
  { accessorKey: 'vatRate', header: $t('products.vatRate') },
  { accessorKey: 'usage', header: $t('products.usage') },
  { accessorKey: 'isService', header: $t('products.isService') },
  { id: 'actions', header: $t('common.actions') },
]

function formatPrice(amount: number | string, currency?: string) {
  return new Intl.NumberFormat('ro-RO', {
    style: 'currency',
    currency: currency || 'RON',
    minimumFractionDigits: 2,
  }).format(Number(amount || 0))
}

// ── Search ───────────────────────────────────────────────────────────
const onSearchInput = useDebounceFn(() => {
  page.value = 1
  fetchProducts()
}, 300)

// ── CRUD ─────────────────────────────────────────────────────────────
async function fetchProducts() {
  productsStore.search = search.value
  productsStore.page = page.value
  await productsStore.fetchProducts()
}

function openCreate() {
  editingProduct.value = null
  form.value = {
    name: '',
    code: '',
    description: '',
    defaultPrice: '',
    currency: defaultCurrency.value,
    unitOfMeasure: defaultUnitOfMeasure.value,
    vatRate: defaultVatRate.value,
    isService: false,
    isActive: true,
    usage: 'both',
    ncCode: null,
    cpvCode: null,
  }
  ncCodeSearchTerm.value = ''
  ncCodeSearchResults.value = []
  cpvCodeSearchTerm.value = ''
  cpvCodeSearchResults.value = []
  slideoverOpen.value = true
}

function openEdit(product: Product) {
  editingProduct.value = product
  form.value = {
    name: product.name,
    code: product.code ?? '',
    description: product.description ?? '',
    defaultPrice: product.defaultPrice,
    currency: product.currency,
    unitOfMeasure: product.unitOfMeasure,
    vatRate: parseFloat(product.vatRate).toFixed(2),
    isService: product.isService,
    isActive: product.isActive,
    usage: product.usage ?? 'both',
    ncCode: product.ncCode ?? null,
    cpvCode: product.cpvCode ?? null,
  }
  ncCodeSearchTerm.value = ''
  cpvCodeSearchTerm.value = ''
  // Pre-populate NC code label if editing product has one
  if (product.ncCode) {
    ncCodeSearchResults.value = [{ label: product.ncCode, value: product.ncCode }]
    get<NcCode[]>('/v1/nc-codes', { search: product.ncCode, limit: 1 }).then((results) => {
      if (results.length) {
        ncCodeSearchResults.value = [{ label: `${results[0].cod} - ${results[0].denumire}`, value: results[0].cod }]
      }
    }).catch(() => {})
  }
  else {
    ncCodeSearchResults.value = []
  }
  // Pre-populate CPV code label if editing product has one
  if (product.cpvCode) {
    cpvCodeSearchResults.value = [{ label: product.cpvCode, value: product.cpvCode }]
    get<CpvCode[]>('/v1/cpv-codes', { search: product.cpvCode, limit: 1 }).then((results) => {
      if (results.length) {
        cpvCodeSearchResults.value = [{ label: `${results[0].cod} - ${results[0].denumire}`, value: results[0].cod }]
      }
    }).catch(() => {})
  }
  else {
    cpvCodeSearchResults.value = []
  }
  slideoverOpen.value = true
}

async function onSave() {
  saving.value = true
  const payload = {
    name: form.value.name.trim(),
    code: form.value.code.trim() || null,
    description: form.value.description.trim() || null,
    defaultPrice: form.value.defaultPrice || '0',
    currency: form.value.currency,
    unitOfMeasure: form.value.unitOfMeasure,
    vatRate: form.value.vatRate,
    isService: form.value.isService,
    isActive: form.value.isActive,
    usage: form.value.usage,
    ncCode: form.value.ncCode || null,
    cpvCode: form.value.cpvCode || null,
  }

  if (editingProduct.value) {
    const ok = await productsStore.updateProduct(editingProduct.value.id, payload)
    if (ok) {
      toast.add({ title: $t('products.updateSuccess'), color: 'success' })
      slideoverOpen.value = false
    }
    else if (productsStore.error) {
      toast.add({ title: productsStore.error, color: 'error' })
    }
  }
  else {
    const result = await productsStore.createProduct(payload)
    if (result) {
      toast.add({ title: $t('products.createSuccess'), color: 'success' })
      slideoverOpen.value = false
    }
    else if (productsStore.error) {
      toast.add({ title: productsStore.error, color: 'error' })
    }
  }
  saving.value = false
}

async function openDelete(product: Product) {
  deletingProduct.value = product
  deleteUsage.value = null
  deleteLoading.value = true
  deleteModalOpen.value = true
  deleteUsage.value = await productsStore.checkProductUsage(product.id)
  deleteLoading.value = false
}

async function confirmDelete() {
  if (!deletingProduct.value) return
  const ok = await productsStore.deleteProduct(deletingProduct.value.id)
  if (ok) {
    toast.add({ title: $t('products.deleteSuccess'), color: 'success' })
    deleteModalOpen.value = false
    deletingProduct.value = null
  }
  else if (productsStore.error) {
    toast.add({ title: productsStore.error, color: 'error' })
  }
}

// ── Watchers ─────────────────────────────────────────────────────────
watch([page], () => fetchProducts())

watch(() => companyStore.currentCompanyId, () => {
  page.value = 1
  search.value = ''
  typeFilter.value = 'all'
  usageFilter.value = 'all'
  fetchProducts()
})


onMounted(() => {
  fetchProducts()
  fetchDefaults()
})
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('products.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            v-if="can(P.PRODUCT_CREATE)"
            :label="$t('products.addProduct')"
            icon="i-lucide-plus"
            @click="openCreate"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <!-- Filters bar -->
      <div class="flex flex-wrap items-center gap-3 mb-4">
        <UInput
          v-model="search"
          :placeholder="$t('common.search')"
          icon="i-lucide-search"
          class="max-w-sm"
          @update:model-value="onSearchInput"
        />
        <div class="flex gap-1">
          <UButton
            v-for="opt in typeOptions"
            :key="opt.value"
            size="sm"
            :variant="typeFilter === opt.value ? 'solid' : 'outline'"
            :color="typeFilter === opt.value ? 'primary' : 'neutral'"
            @click="typeFilter = opt.value as any"
          >
            {{ opt.label }}
          </UButton>
        </div>
        <USelectMenu
          v-model="usageFilter"
          :items="usageOptions"
          value-key="value"
          size="sm"
          class="w-48"
          :placeholder="$t('products.usage')"
        />
      </div>

      <!-- Bulk Bar -->
      <SharedTableBulkBar :count="selectionCount" :loading="bulkLoading" @clear="clearSelection">
        <template #actions>
          <UButton v-if="can(P.PRODUCT_DELETE)" :label="$t('bulk.delete')" icon="i-lucide-trash-2" color="error" variant="soft" size="sm" @click="bulkDeleteConfirmOpen = true" />
        </template>
      </SharedTableBulkBar>

      <!-- Table -->
      <UTable
        :data="products"
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
        @select="(_e: Event, row: any) => openEdit(row.original)"
      >
        <template #select-header>
          <input v-model="allSelected" type="checkbox" class="accent-primary">
        </template>
        <template #select-cell="{ row }">
          <input :checked="isSelected(row.original.id)" type="checkbox" class="accent-primary" @click.stop @change="toggle(row.original.id)">
        </template>
        <template #name-cell="{ row }">
          <div>
            <span class="font-medium text-highlighted">{{ row.original.name }}</span>
            <p v-if="row.original.description" class="text-xs text-muted truncate max-w-xs">{{ row.original.description }}</p>
          </div>
        </template>
        <template #code-cell="{ row }">
          <span class="font-mono text-xs text-muted">{{ row.original.code || '-' }}</span>
        </template>
        <template #defaultPrice-cell="{ row }">
          <span class="font-medium tabular-nums">{{ formatPrice(row.original.defaultPrice, row.original.currency) }}</span>
        </template>
        <template #vatRate-cell="{ row }">
          <UBadge color="neutral" variant="subtle" size="sm">
            {{ row.original.vatRate }}%
          </UBadge>
        </template>
        <template #usage-cell="{ row }">
          <span class="text-sm text-muted">{{ $t(`products.usageOptions.${row.original.usage}`) }}</span>
        </template>
        <template #isService-cell="{ row }">
          <UBadge
            :color="row.original.isService ? 'info' : 'neutral'"
            variant="subtle"
            size="sm"
          >
            {{ row.original.isService ? $t('products.isService') : $t('products.isGoods') }}
          </UBadge>
        </template>
        <template #actions-cell="{ row }">
          <div class="flex gap-1">
            <UButton v-if="can(P.PRODUCT_EDIT)" icon="i-lucide-pencil" variant="ghost" size="xs" @click.stop="openEdit(row.original)" />
            <UButton v-if="can(P.PRODUCT_DELETE)" icon="i-lucide-trash-2" variant="ghost" size="xs" color="error" @click.stop="openDelete(row.original)" />
          </div>
        </template>
      </UTable>

      <UEmpty
        v-if="!loading && !products.length"
        icon="i-lucide-package"
        :title="$t('products.noProducts')"
        :description="$t('products.noProductsDesc')"
        class="py-12"
      >
        <template #actions>
          <UButton v-if="can(P.PRODUCT_CREATE)" :label="$t('products.addProduct')" icon="i-lucide-plus" @click="openCreate" />
        </template>
      </UEmpty>

      <!-- Pagination -->
      <div v-if="total > limit" class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <span class="text-sm text-muted">
          {{ $t('common.showing') }} {{ products.length }} {{ $t('common.of') }} {{ total }}
        </span>
        <UPagination v-model:page="page" :total="total" :items-per-page="limit" />
      </div>
    </template>
  </UDashboardPanel>

  <!-- Create / Edit Slideover -->
  <USlideover v-model:open="slideoverOpen" :ui="{ content: 'sm:max-w-lg' }">
    <template #header>
      <h3 class="text-lg font-semibold">
        {{ editingProduct ? $t('products.editProduct') : $t('products.addProduct') }}
      </h3>
    </template>
    <template #body>
      <div class="space-y-4">
        <!-- Name -->
        <UFormField :label="$t('products.name')">
          <UInput
            v-model="form.name"
            size="xl"
            class="w-full"
            :placeholder="$t('products.namePlaceholder')"
          />
        </UFormField>

        <!-- Code + Type row -->
        <div class="grid grid-cols-2 gap-3">
          <UFormField :label="$t('products.code')">
            <UInput
              v-model="form.code"
              size="xl"
              class="w-full"
              :placeholder="$t('products.codePlaceholder')"
            />
          </UFormField>
          <UFormField :label="$t('products.isService')">
            <div class="flex items-center gap-2 h-[42px]">
              <USwitch v-model="form.isService" />
              <span class="text-sm">{{ form.isService ? $t('products.isService') : $t('products.isGoods') }}</span>
            </div>
          </UFormField>
        </div>

        <!-- Price + Currency row -->
        <div class="grid grid-cols-3 gap-3">
          <UFormField :label="$t('products.defaultPrice')" class="col-span-2">
            <UInput
              v-model="form.defaultPrice"
              size="xl"
              class="w-full"
              type="number"
              step="0.01"
              placeholder="0.00"
            />
          </UFormField>
          <UFormField :label="$t('products.currency')">
            <USelectMenu
              v-model="form.currency"
              :items="currencyOptions"
              value-key="value"
              size="xl"
              class="w-full"
            />
          </UFormField>
        </div>

        <!-- VAT + Unit row -->
        <div class="grid grid-cols-2 gap-3">
          <UFormField :label="$t('products.vatRate')">
            <USelectMenu
              v-model="form.vatRate"
              :items="vatRateOptions"
              value-key="value"
              size="xl"
              class="w-full"
            />
          </UFormField>
          <UFormField :label="$t('products.unitOfMeasure')">
            <USelectMenu
              v-model="form.unitOfMeasure"
              :items="unitOfMeasureOptions"
              value-key="value"
              size="xl"
              class="w-full"
            />
          </UFormField>
        </div>

        <!-- Usage -->
        <UFormField :label="$t('products.usage')">
          <USelectMenu
            v-model="form.usage"
            :items="usageFormOptions"
            value-key="value"
            size="xl"
            class="w-full"
          />
        </UFormField>

        <!-- NC Code -->
        <UFormField :label="$t('products.ncCode')" :description="$t('products.ncCodeDescription')">
          <USelectMenu
            v-model="form.ncCode"
            v-model:search-term="ncCodeSearchTerm"
            :items="ncCodeSearchResults"
            value-key="value"
            size="xl"
            class="w-full"
            :ignore-filter="true"
            :loading="ncCodeLoading"
            :placeholder="$t('products.ncCodePlaceholder')"
          />
          <div v-if="form.ncCode" class="mt-1">
            <UButton
              variant="link"
              size="xs"
              color="neutral"
              :label="$t('products.ncCodeNone')"
              @click="form.ncCode = null; ncCodeSearchResults = []"
            />
          </div>
        </UFormField>

        <!-- CPV Code -->
        <UFormField :label="$t('products.cpvCode')" :description="$t('products.cpvCodeDescription')">
          <USelectMenu
            v-model="form.cpvCode"
            v-model:search-term="cpvCodeSearchTerm"
            :items="cpvCodeSearchResults"
            value-key="value"
            size="xl"
            class="w-full"
            :ignore-filter="true"
            :loading="cpvCodeLoading"
            :placeholder="$t('products.cpvCodePlaceholder')"
          />
          <div v-if="form.cpvCode" class="mt-1">
            <UButton
              variant="link"
              size="xs"
              color="neutral"
              :label="$t('products.cpvCodeNone')"
              @click="form.cpvCode = null; cpvCodeSearchResults = []"
            />
          </div>
        </UFormField>

        <!-- Description -->
        <UFormField :label="$t('products.description2')">
          <UTextarea
            v-model="form.description"
            class="w-full"
            :placeholder="$t('products.descriptionPlaceholder')"
            :rows="3"
          />
        </UFormField>

        <!-- Active toggle (only when editing) -->
        <div v-if="editingProduct" class="flex items-center justify-between pt-2 border-t border-default">
          <div>
            <p class="text-sm font-medium">{{ $t('products.active') }}</p>
            <p class="text-xs text-muted">{{ form.isActive ? $t('products.active') : $t('products.inactive') }}</p>
          </div>
          <USwitch v-model="form.isActive" />
        </div>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton variant="ghost" @click="slideoverOpen = false">{{ $t('common.cancel') }}</UButton>
        <UButton :loading="saving" :disabled="!canSave" @click="onSave">{{ $t('common.save') }}</UButton>
      </div>
    </template>
  </USlideover>

  <!-- Bulk Delete Confirm -->
  <UModal v-model:open="bulkDeleteConfirmOpen">
    <template #header>
      <h3 class="text-lg font-semibold">{{ $t('bulk.deleteConfirmTitle') }}</h3>
    </template>
    <template #body>
      <p class="text-sm">{{ $t('bulk.deleteConfirmDescription', { count: selectionCount }) }}</p>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton :label="$t('common.cancel')" variant="ghost" @click="bulkDeleteConfirmOpen = false" />
        <UButton :label="$t('common.delete')" color="error" :loading="bulkLoading" @click="handleBulkDelete" />
      </div>
    </template>
  </UModal>

  <!-- Delete Confirmation Modal -->
  <UModal v-model:open="deleteModalOpen">
    <template #header>
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-triangle-alert" class="text-red-500 size-5" />
        <h3 class="text-lg font-semibold">{{ $t('products.deleteConfirm') }}</h3>
      </div>
    </template>
    <template #body>
      <div v-if="deletingProduct" class="space-y-3">
        <p class="text-sm">
          {{ $t('products.deleteWarning', { name: deletingProduct.name }) }}
        </p>

        <div v-if="deleteLoading" class="flex items-center gap-2 text-sm text-muted">
          <UIcon name="i-lucide-loader-2" class="animate-spin size-4" />
          {{ $t('common.loading') }}
        </div>

        <div v-else-if="deleteUsage && deleteUsage.total > 0" class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30 p-3 space-y-2">
          <p class="text-sm font-medium text-amber-800 dark:text-amber-200">
            {{ $t('products.usedInDocuments') }}
          </p>
          <ul class="text-sm text-amber-700 dark:text-amber-300 space-y-1">
            <li v-if="deleteUsage.invoices > 0" class="flex items-center gap-2">
              <UIcon name="i-lucide-file-text" class="size-4" />
              {{ deleteUsage.invoices }} {{ $t('products.usageInvoices') }}
            </li>
            <li v-if="deleteUsage.proformaInvoices > 0" class="flex items-center gap-2">
              <UIcon name="i-lucide-file-clock" class="size-4" />
              {{ deleteUsage.proformaInvoices }} {{ $t('products.usageProformas') }}
            </li>
            <li v-if="deleteUsage.recurringInvoices > 0" class="flex items-center gap-2">
              <UIcon name="i-lucide-repeat" class="size-4" />
              {{ deleteUsage.recurringInvoices }} {{ $t('products.usageRecurring') }}
            </li>
          </ul>
          <p class="text-xs text-amber-600 dark:text-amber-400">
            {{ $t('products.deleteImpact') }}
          </p>
        </div>

        <div v-else-if="deleteUsage && deleteUsage.total === 0" class="rounded-lg border border-default bg-elevated/50 p-3">
          <p class="text-sm text-muted">{{ $t('products.notUsedAnywhere') }}</p>
        </div>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton variant="ghost" @click="deleteModalOpen = false">{{ $t('common.cancel') }}</UButton>
        <UButton color="error" :loading="deleteLoading" @click="confirmDelete">{{ $t('common.delete') }}</UButton>
      </div>
    </template>
  </UModal>
</template>
