<script setup lang="ts">
import type { VatRate } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('vatRates.title') })
const store = useVatRateStore()
const companyStore = useCompanyStore()
const toast = useToast()

const loading = computed(() => store.loading)
const rates = computed(() => store.items)

const modalOpen = ref(false)
const saving = ref(false)
const editingRate = ref<VatRate | null>(null)

const deleteModalOpen = ref(false)
const deletingRate = ref<VatRate | null>(null)
const deleting = ref(false)
const form = ref({
  rate: '',
  label: '',
  categoryCode: 'S',
  isDefault: false,
  isActive: true,
  position: 0,
})

const categoryCodeOptions = [
  { label: 'S - Standard', value: 'S' },
  { label: 'Z - Zero', value: 'Z' },
  { label: 'E - Exempt', value: 'E' },
  { label: 'AE - Taxare inversa', value: 'AE' },
]

const columns = [
  {
    accessorKey: 'rate',
    header: $t('vatRates.rate'),
    enableSorting: true,
    sortingFn: (a: any, b: any) => Number(a.original.rate) - Number(b.original.rate),
  },
  { accessorKey: 'label', header: $t('vatRates.label'), enableSorting: true },
  { accessorKey: 'categoryCode', header: $t('vatRates.categoryCode'), enableSorting: true },
  { accessorKey: 'isDefault', header: $t('vatRates.isDefault'), enableSorting: true },
  { accessorKey: 'isActive', header: $t('vatRates.isActive'), enableSorting: true },
  { id: 'actions', header: $t('common.actions'), enableSorting: false },
]

function openCreate() {
  editingRate.value = null
  form.value = { rate: '', label: '', categoryCode: 'S', isDefault: false, isActive: true, position: 0 }
  modalOpen.value = true
}

function openEdit(rate: VatRate) {
  editingRate.value = rate
  form.value = {
    rate: rate.rate,
    label: rate.label,
    categoryCode: rate.categoryCode,
    isDefault: rate.isDefault,
    isActive: rate.isActive,
    position: rate.position,
  }
  modalOpen.value = true
}

async function onSave() {
  saving.value = true
  if (editingRate.value) {
    const ok = await store.updateVatRate(editingRate.value.id, form.value)
    if (ok) {
      toast.add({ title: $t('vatRates.updateSuccess'), color: 'success' })
      modalOpen.value = false
    }
    else if (store.error) {
      toast.add({ title: store.error, color: 'error' })
    }
  }
  else {
    const result = await store.createVatRate(form.value)
    if (result) {
      toast.add({ title: $t('vatRates.createSuccess'), color: 'success' })
      modalOpen.value = false
    }
    else if (store.error) {
      toast.add({ title: store.error, color: 'error' })
    }
  }
  saving.value = false
}

function openDelete(rate: VatRate) {
  deletingRate.value = rate
  deleteModalOpen.value = true
}

async function onDelete() {
  if (!deletingRate.value) return
  deleting.value = true
  const ok = await store.deleteVatRate(deletingRate.value.id)
  if (ok) {
    toast.add({ title: $t('vatRates.deleteSuccess'), color: 'success' })
    deleteModalOpen.value = false
  }
  else if (store.error) {
    toast.add({ title: store.error, color: 'error' })
  }
  deleting.value = false
}

watch(() => companyStore.currentCompanyId, () => store.fetchVatRates())

onMounted(() => {
  store.fetchVatRates()
})
</script>

<template>
  <div>
    <UPageCard
      :title="$t('vatRates.title')"
      :description="$t('vatRates.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        :label="$t('vatRates.addRate')"
        color="neutral"
        icon="i-lucide-plus"
        class="w-fit lg:ms-auto"
        @click="openCreate"
      />
    </UPageCard>

    <UPageCard
      variant="subtle"
      :ui="{ container: 'p-0 sm:p-0 gap-y-0', wrapper: 'items-stretch' }"
    >
      <UTable
        :data="rates"
        :columns="columns"
        :loading="loading"
        :ui="{
          base: 'table-fixed',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'px-4',
          td: 'px-4 border-b border-default',
        }"
      >
        <template #rate-cell="{ row }">
          <span class="font-mono font-semibold">{{ row.original.rate }}%</span>
        </template>
        <template #isDefault-cell="{ row }">
          <UBadge v-if="row.original.isDefault" color="success" variant="subtle" size="sm">
            {{ $t('vatRates.isDefault') }}
          </UBadge>
        </template>
        <template #isActive-cell="{ row }">
          <UBadge :color="row.original.isActive ? 'success' : 'neutral'" variant="subtle" size="sm">
            {{ row.original.isActive ? $t('vatRates.active') : $t('vatRates.inactive') }}
          </UBadge>
        </template>
        <template #actions-cell="{ row }">
          <div class="flex gap-1">
            <UButton icon="i-lucide-pencil" variant="ghost" size="xs" @click="openEdit(row.original)" />
            <UButton icon="i-lucide-trash-2" variant="ghost" size="xs" color="error" @click="openDelete(row.original)" />
          </div>
        </template>
      </UTable>

      <UEmpty v-if="!loading && rates.length === 0" icon="i-lucide-percent" :title="$t('vatRates.noRates')" class="py-12" />
    </UPageCard>

    <!-- Create/Edit Slideover -->
    <USlideover v-model:open="modalOpen">
      <template #header>
        <div class="flex items-center justify-between w-full">
          <h3 class="text-lg font-semibold">{{ editingRate ? $t('vatRates.editRate') : $t('vatRates.addRate') }}</h3>
          <div class="flex items-center gap-2">
            <USwitch v-model="form.isDefault" size="sm" />
            <span class="text-sm text-(--ui-text-muted)">{{ $t('vatRates.isDefault') }}</span>
          </div>
        </div>
      </template>
      <template #body>
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <UFormField :label="$t('vatRates.rate')">
              <UInput v-model="form.rate" type="number" step="0.01" min="0" max="100" placeholder="21" />
            </UFormField>
            <UFormField :label="$t('vatRates.label')">
              <UInput v-model="form.label" placeholder="Standard" />
            </UFormField>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <UFormField :label="$t('vatRates.categoryCode')">
              <USelectMenu v-model="form.categoryCode" :items="categoryCodeOptions" value-key="value" />
            </UFormField>
            <UFormField :label="$t('vatRates.position')">
              <UInput v-model.number="form.position" type="number" min="0" />
            </UFormField>
          </div>
          <div class="flex items-center gap-2">
            <USwitch v-model="form.isActive" size="sm" />
            <span class="text-sm">{{ $t('vatRates.isActive') }}</span>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="ghost" @click="modalOpen = false">{{ $t('common.cancel') }}</UButton>
          <UButton :loading="saving" @click="onSave">{{ $t('common.save') }}</UButton>
        </div>
      </template>
    </USlideover>

    <!-- Delete confirmation -->
    <SharedConfirmModal
      v-model:open="deleteModalOpen"
      :title="$t('vatRates.deleteRate')"
      :description="deletingRate ? $t('vatRates.deleteDescription', { label: deletingRate.label }) : ''"
      icon="i-lucide-trash-2"
      color="error"
      :confirm-label="$t('common.delete')"
      :loading="deleting"
      @confirm="onDelete"
    />
  </div>
</template>
