<template>
  <UModal v-model:open="isOpen" :ui="{ content: 'sm:max-w-2xl' }">
    <template #content>
      <div class="flex flex-col h-[70vh]">
        <!-- Header -->
        <div class="flex items-center justify-between p-4 border-b border-(--ui-border)">
          <h3 class="font-semibold text-lg">{{ $t('invoices.selectProduct') }}</h3>
          <UButton icon="i-lucide-x" variant="ghost" size="sm" @click="isOpen = false" />
        </div>

        <!-- Search -->
        <div class="p-4 pb-2">
          <UInput
            v-model="searchQuery"
            :placeholder="$t('common.search')"
            icon="i-lucide-search"
            size="xl"
            autofocus
            class="w-full"
          />
        </div>

        <!-- Product list -->
        <div class="flex-1 overflow-y-auto px-4 pb-4">
          <div v-if="productStore.loading" class="flex items-center justify-center py-8">
            <UIcon name="i-lucide-loader-2" class="size-5 animate-spin text-(--ui-text-muted)" />
          </div>
          <div v-else-if="productStore.items.length === 0" class="text-center py-8 text-(--ui-text-muted) text-sm">
            {{ $t('products.noProducts') }}
          </div>
          <div v-else class="space-y-1">
            <button
              v-for="product in productStore.items"
              :key="product.id"
              type="button"
              class="w-full text-left p-3 rounded-lg hover:bg-(--ui-bg-elevated) transition-colors"
              @click="selectProduct(product)"
            >
              <div class="font-medium text-sm">{{ product.name }}</div>
              <div v-if="product.description && product.description !== product.name" class="text-xs text-(--ui-text-dimmed) mt-0.5 line-clamp-2">
                {{ product.description }}
              </div>
              <div class="text-xs text-(--ui-text-muted) mt-0.5">
                {{ formatPrice(product.defaultPrice) }} {{ product.currency }}
                · TVA {{ product.vatRate }}%
                · {{ product.unitOfMeasure }}
              </div>
            </button>
          </div>
        </div>
      </div>
    </template>
  </UModal>
</template>

<script setup lang="ts">
import type { Product } from '~/types'

const props = defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  select: [product: Product]
}>()

const { t: $t } = useI18n()
const productStore = useProductStore()

const isOpen = computed({
  get: () => props.open,
  set: (val) => emit('update:open', val),
})

const searchQuery = ref('')

const debouncedSearch = useDebounceFn(async (val: string) => {
  productStore.setSearch(val)
  await productStore.fetchProducts()
}, 300)

watch(searchQuery, (val) => {
  debouncedSearch(val)
})

watch(isOpen, async (val) => {
  if (val) {
    searchQuery.value = ''
    productStore.setSearch('')
    await productStore.fetchProducts()
  }
})

function selectProduct(product: Product) {
  emit('select', product)
  isOpen.value = false
}

function formatPrice(price: string): string {
  const num = parseFloat(price) || 0
  return new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(num)
}
</script>
