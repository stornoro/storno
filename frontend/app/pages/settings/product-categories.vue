<script setup lang="ts">
import type { ProductCategory } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('productCategories.title') })
const { can } = usePermissions()
const store = useProductCategoriesStore()
const companyStore = useCompanyStore()
const toast = useToast()

const loading = computed(() => store.loading)
const items = computed(() => store.items)
const hasAny = computed(() => items.value.length > 0)

const modalOpen = ref(false)
const saving = ref(false)
const editing = ref<ProductCategory | null>(null)
const form = ref<{ name: string, color: string | null, sortOrder: number }>({
  name: '',
  color: null,
  sortOrder: 0,
})

const deleteModalOpen = ref(false)
const deleting = ref<ProductCategory | null>(null)
const deletingBusy = ref(false)

// Suggested swatch palette — same as POS fallback so newly-created categories
// blend visually with the existing product cards if no color is picked.
const palette = [
  '#1e40af', '#0284c7', '#16a34a', '#d97706',
  '#dc2626', '#7c3aed', '#0891b2', '#db2777',
  '#475569', '#84cc16', '#f59e0b', '#ec4899',
]

function openCreate() {
  editing.value = null
  form.value = {
    name: '',
    color: null,
    sortOrder: items.value.length, // append by default
  }
  modalOpen.value = true
}

function openEdit(item: ProductCategory) {
  editing.value = item
  form.value = {
    name: item.name,
    color: item.color,
    sortOrder: item.sortOrder,
  }
  modalOpen.value = true
}

async function onSave() {
  saving.value = true
  if (editing.value) {
    const ok = await store.updateCategory(editing.value.id, {
      name: form.value.name,
      color: form.value.color,
      sortOrder: form.value.sortOrder,
    })
    if (ok) {
      toast.add({ title: $t('productCategories.updateSuccess'), color: 'success' })
      modalOpen.value = false
    }
    else if (store.error) {
      toast.add({ title: store.error, color: 'error' })
    }
  }
  else {
    const result = await store.createCategory({
      name: form.value.name,
      color: form.value.color,
      sortOrder: form.value.sortOrder,
    })
    if (result) {
      toast.add({ title: $t('productCategories.createSuccess'), color: 'success' })
      modalOpen.value = false
    }
    else if (store.error) {
      toast.add({ title: store.error, color: 'error' })
    }
  }
  saving.value = false
}

function openDelete(item: ProductCategory) {
  deleting.value = item
  deleteModalOpen.value = true
}

async function onDelete() {
  if (!deleting.value) return
  deletingBusy.value = true
  const ok = await store.deleteCategory(deleting.value.id)
  if (ok) {
    toast.add({ title: $t('productCategories.deleteSuccess'), color: 'success' })
    deleteModalOpen.value = false
  }
  else if (store.error) {
    toast.add({ title: store.error, color: 'error' })
  }
  deletingBusy.value = false
}

watch(() => companyStore.currentCompanyId, () => store.fetchCategories())

onMounted(() => {
  store.fetchCategories()
})
</script>

<template>
  <div>
    <UPageCard
      :title="$t('productCategories.title')"
      :description="$t('productCategories.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        v-if="can(P.PRODUCT_EDIT)"
        :label="$t('productCategories.add')"
        color="neutral"
        icon="i-lucide-plus"
        class="w-fit lg:ms-auto"
        @click="openCreate()"
      />
    </UPageCard>

    <div v-if="loading" class="flex justify-center py-12">
      <UIcon name="i-lucide-loader-2" class="size-6 animate-spin text-(--ui-text-muted)" />
    </div>

    <div v-else-if="!hasAny">
      <UPageCard variant="subtle">
        <UEmpty
          icon="i-lucide-tags"
          :title="$t('productCategories.noCategories')"
          :description="$t('productCategories.noCategoriesHint')"
          class="py-12"
        />
      </UPageCard>
    </div>

    <UPageCard v-else variant="subtle">
      <div class="divide-y divide-(--ui-border)">
        <div
          v-for="item in items"
          :key="item.id"
          class="flex items-center gap-3 py-3 first:pt-0 last:pb-0"
        >
          <div
            class="size-3 shrink-0 rounded-full border border-(--ui-border)"
            :style="{ backgroundColor: item.color || 'transparent' }"
          />
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
              <span class="text-sm font-medium text-(--ui-text)">{{ item.name }}</span>
              <UBadge color="neutral" variant="subtle" size="xs">
                {{ $t('productCategories.sortOrder') }}: {{ item.sortOrder }}
              </UBadge>
            </div>
            <p v-if="item.color" class="text-xs text-(--ui-text-muted) mt-0.5 font-mono">
              {{ item.color }}
            </p>
          </div>
          <div v-if="can(P.PRODUCT_EDIT)" class="flex items-center gap-1">
            <UButton icon="i-lucide-pencil" variant="ghost" size="xs" @click="openEdit(item)" />
            <UButton icon="i-lucide-trash-2" variant="ghost" size="xs" color="error" @click="openDelete(item)" />
          </div>
        </div>
      </div>
    </UPageCard>

    <!-- Create / Edit slideover -->
    <USlideover v-model:open="modalOpen">
      <template #header>
        <h3 class="font-semibold">
          {{ editing ? $t('productCategories.edit') : $t('productCategories.add') }}
        </h3>
      </template>
      <template #body>
        <div class="space-y-5">
          <UFormField :label="$t('productCategories.name')" required>
            <UInput v-model="form.name" :placeholder="$t('productCategories.namePlaceholder')" />
          </UFormField>

          <UFormField :label="$t('productCategories.color')">
            <div class="space-y-3">
              <div class="flex items-center gap-2">
                <UInput v-model="form.color" placeholder="#1e40af" class="flex-1 font-mono" />
                <UButton
                  v-if="form.color"
                  icon="i-lucide-x"
                  size="xs"
                  variant="ghost"
                  @click="form.color = null"
                />
              </div>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="hex in palette"
                  :key="hex"
                  type="button"
                  class="size-7 rounded-full border-2 transition"
                  :class="form.color === hex ? 'border-(--ui-text) scale-110' : 'border-transparent hover:scale-110'"
                  :style="{ backgroundColor: hex }"
                  :aria-label="hex"
                  @click="form.color = hex"
                />
              </div>
            </div>
          </UFormField>

          <UFormField :label="$t('productCategories.sortOrder')">
            <UInput v-model.number="form.sortOrder" type="number" :min="0" />
          </UFormField>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="ghost" @click="modalOpen = false">{{ $t('common.cancel') }}</UButton>
          <UButton :loading="saving" :disabled="!form.name.trim()" @click="onSave">
            {{ $t('common.save') }}
          </UButton>
        </div>
      </template>
    </USlideover>

    <SharedConfirmModal
      v-model:open="deleteModalOpen"
      :title="$t('productCategories.deleteTitle')"
      :description="deleting ? $t('productCategories.deleteDescription', { name: deleting.name }) : ''"
      icon="i-lucide-trash-2"
      color="error"
      :confirm-label="$t('common.delete')"
      :loading="deletingBusy"
      @confirm="onDelete"
    />
  </div>
</template>
