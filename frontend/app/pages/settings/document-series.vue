<script setup lang="ts">
import type { DocumentSeries } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('documentSeries.title') })
const { can } = usePermissions()
const store = useDocumentSeriesStore()
const companyStore = useCompanyStore()
const toast = useToast()
const { fetchDefaults, documentSeriesTypeOptions } = useInvoiceDefaults()

const loading = computed(() => store.loading)
const series = computed(() => store.items)

const modalOpen = ref(false)
const saving = ref(false)
const editingSeries = ref<DocumentSeries | null>(null)
const form = ref({ prefix: '', type: 'invoice', currentNumber: 1, active: true })

const deleteModalOpen = ref(false)
const deletingSeries = ref<DocumentSeries | null>(null)
const deleting = ref(false)

const typeOptions = computed(() => documentSeriesTypeOptions.value)

const seriesTypes = ['invoice', 'proforma', 'credit_note', 'delivery_note', 'receipt', 'voucher']

const groupedSeries = computed(() => {
  const groups: Record<string, DocumentSeries[]> = {}
  for (const type of seriesTypes) {
    const items = series.value.filter(s => s.type === type)
    if (items.length > 0) {
      groups[type] = items
    }
  }
  return groups
})

const hasAnySeries = computed(() => series.value.length > 0)

// Preview next number based on form
const nextNumberPreview = computed(() => {
  const num = (form.value.currentNumber || 0) + 1
  return form.value.prefix + String(num).padStart(4, '0')
})

function openCreate(type?: string) {
  editingSeries.value = null
  form.value = { prefix: '', type: type || 'invoice', currentNumber: 0, active: true }
  modalOpen.value = true
}

function openEdit(item: DocumentSeries) {
  editingSeries.value = item
  form.value = {
    prefix: item.prefix,
    type: item.type,
    currentNumber: item.currentNumber,
    active: item.active,
  }
  modalOpen.value = true
}

async function onSave() {
  saving.value = true
  if (editingSeries.value) {
    const ok = await store.updateSeries(editingSeries.value.id, {
      currentNumber: form.value.currentNumber,
      active: form.value.active,
    })
    if (ok) {
      toast.add({ title: $t('documentSeries.updateSuccess'), color: 'success' })
      modalOpen.value = false
    }
    else if (store.error) {
      toast.add({ title: store.error, color: 'error' })
    }
  }
  else {
    const result = await store.createSeries(form.value)
    if (result) {
      toast.add({ title: $t('documentSeries.createSuccess'), color: 'success' })
      modalOpen.value = false
    }
    else if (store.error) {
      toast.add({ title: store.error, color: 'error' })
    }
  }
  saving.value = false
}

function openDelete(item: DocumentSeries) {
  deletingSeries.value = item
  deleteModalOpen.value = true
}

async function onDelete() {
  if (!deletingSeries.value) return
  deleting.value = true
  const ok = await store.deleteSeries(deletingSeries.value.id)
  if (ok) {
    toast.add({ title: $t('documentSeries.deleteSuccess'), color: 'success' })
    deleteModalOpen.value = false
  }
  else if (store.error) {
    toast.add({ title: store.error, color: 'error' })
  }
  deleting.value = false
}

async function onSetDefault(item: DocumentSeries) {
  const ok = await store.setDefault(item.id)
  if (ok) {
    toast.add({ title: $t('documentSeries.defaultSuccess'), color: 'success' })
  }
  else if (store.error) {
    toast.add({ title: store.error, color: 'error' })
  }
}

watch(() => companyStore.currentCompanyId, () => store.fetchSeries())

onMounted(() => {
  store.fetchSeries()
  fetchDefaults()
})
</script>

<template>
  <div>
    <UPageCard
      :title="$t('documentSeries.title')"
      :description="$t('settings.documentSeriesDescription')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        v-if="can(P.SERIES_MANAGE)"
        :label="$t('documentSeries.addSeries')"
        color="neutral"
        icon="i-lucide-plus"
        class="w-fit lg:ms-auto"
        @click="openCreate()"
      />
    </UPageCard>

    <div v-if="loading" class="flex justify-center py-12">
      <UIcon name="i-lucide-loader-2" class="size-6 animate-spin text-(--ui-text-muted)" />
    </div>

    <div v-else-if="!hasAnySeries">
      <UPageCard variant="subtle">
        <UEmpty icon="i-lucide-hash" :title="$t('documentSeries.noSeries')" class="py-12" />
      </UPageCard>
    </div>

    <div v-else class="space-y-4">
      <UPageCard
        v-for="(items, type) in groupedSeries"
        :key="type"
        variant="subtle"
      >
        <template #header>
          <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold">{{ $t(`documentSeries.types.${type}`) }}</h3>
            <UButton
              v-if="can(P.SERIES_MANAGE)"
              icon="i-lucide-plus"
              size="xs"
              variant="ghost"
              @click="openCreate(type as string)"
            />
          </div>
        </template>

        <div class="divide-y divide-(--ui-border)">
          <div
            v-for="item in items"
            :key="item.id"
            class="flex items-center gap-3 py-3 first:pt-0 last:pb-0"
          >
            <!-- Prefix & next number -->
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-2">
                <span class="font-mono text-sm font-medium">{{ item.prefix }}</span>
                <UBadge v-if="item.isDefault" color="primary" variant="subtle" size="xs">
                  {{ $t('documentSeries.isDefault') }}
                </UBadge>
                <UBadge v-if="!item.active" color="neutral" variant="subtle" size="xs">
                  {{ $t('common.inactive') }}
                </UBadge>
              </div>
              <p class="text-xs text-(--ui-text-muted) mt-0.5">
                {{ $t('documentSeries.nextNumber') }}: <span class="font-mono">{{ item.nextNumber }}</span>
                <span class="mx-1.5">&middot;</span>
                {{ $t('documentSeries.currentNumber') }}: {{ item.currentNumber }}
              </p>
            </div>

            <!-- Actions -->
            <div v-if="can(P.SERIES_MANAGE)" class="flex items-center gap-1">
              <UTooltip v-if="!item.isDefault && item.active" :text="$t('documentSeries.setDefault')">
                <UButton
                  icon="i-lucide-star"
                  variant="ghost"
                  size="xs"
                  @click="onSetDefault(item)"
                />
              </UTooltip>
              <UButton icon="i-lucide-pencil" variant="ghost" size="xs" @click="openEdit(item)" />
              <UButton icon="i-lucide-trash-2" variant="ghost" size="xs" color="error" @click="openDelete(item)" />
            </div>
          </div>
        </div>
      </UPageCard>
    </div>

    <!-- Create/Edit Slideover -->
    <USlideover v-model:open="modalOpen">
      <template #header>
        <h3 class="font-semibold">{{ editingSeries ? $t('documentSeries.editSeries') : $t('documentSeries.addSeries') }}</h3>
      </template>
      <template #body>
        <div class="space-y-5">
          <!-- Prefix + Type side by side -->
          <div class="grid grid-cols-2 gap-3">
            <UFormField :label="$t('documentSeries.prefix')" required>
              <UInput v-model="form.prefix" placeholder="ex: FACT" :disabled="!!editingSeries" />
            </UFormField>
            <UFormField :label="$t('documentSeries.type')" required>
              <USelectMenu v-model="form.type" :items="typeOptions" value-key="value" :disabled="!!editingSeries" />
            </UFormField>
          </div>

          <!-- Current number -->
          <UFormField :label="$t('documentSeries.currentNumber')">
            <UInput v-model.number="form.currentNumber" type="number" :min="0" />
          </UFormField>

          <!-- Preview -->
          <div v-if="form.prefix" class="rounded-lg bg-(--ui-bg-elevated) p-3">
            <p class="text-xs text-(--ui-text-muted) mb-1">{{ $t('documentSeries.nextNumberPreview') }}</p>
            <p class="font-mono text-lg font-semibold text-(--ui-text)">{{ nextNumberPreview }}</p>
          </div>

          <!-- Active toggle -->
          <div class="flex items-center justify-between pt-1">
            <span class="text-sm font-medium text-(--ui-text)">{{ $t('documentSeries.active') }}</span>
            <USwitch v-model="form.active" size="sm" />
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="ghost" @click="modalOpen = false">{{ $t('common.cancel') }}</UButton>
          <UButton :loading="saving" :disabled="!form.prefix" @click="onSave">{{ $t('common.save') }}</UButton>
        </div>
      </template>
    </USlideover>

    <!-- Delete confirmation -->
    <SharedConfirmModal
      v-model:open="deleteModalOpen"
      :title="$t('documentSeries.deleteSeries')"
      :description="deletingSeries ? $t('documentSeries.deleteDescription', { prefix: deletingSeries.prefix }) : ''"
      icon="i-lucide-trash-2"
      color="error"
      :confirm-label="$t('common.delete')"
      :loading="deleting"
      @confirm="onDelete"
    />
  </div>
</template>
