<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="product" class="space-y-6">
        <div class="flex items-center gap-3">
          <UButton icon="i-lucide-arrow-left" variant="ghost" to="/products" />
          <h1 class="text-2xl font-bold">{{ product.name }}</h1>
          <UBadge v-if="product.source" color="blue" variant="subtle">
            {{ $t(`common.sources.${product.source}`, product.source) }}
          </UBadge>
        </div>

        <UCard>
          <template #header>
            <h3 class="font-semibold">{{ $t('products.details') }}</h3>
          </template>
          <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
              <dt class="text-muted">{{ $t('products.name') }}</dt>
              <dd class="font-medium">{{ product.name }}</dd>
            </div>
            <div>
              <dt class="text-muted">{{ $t('products.unitOfMeasure') }}</dt>
              <dd class="font-medium">{{ product.unitOfMeasure }}</dd>
            </div>
            <div>
              <dt class="text-muted">{{ $t('products.unitPrice') }}</dt>
              <dd class="font-medium">{{ formatMoney(product.unitPrice) }}</dd>
            </div>
            <div>
              <dt class="text-muted">TVA %</dt>
              <dd class="font-medium">{{ product.vatRate }}%</dd>
            </div>
            <div v-if="product.source">
              <dt class="text-muted">{{ $t('common.source') }}</dt>
              <dd>{{ $t(`common.sources.${product.source}`, product.source) }}</dd>
            </div>
            <div v-if="product.lastSyncedAt">
              <dt class="text-muted">{{ $t('common.lastSynced') }}</dt>
              <dd>{{ formatDate(product.lastSyncedAt) }}</dd>
            </div>
          </dl>
        </UCard>
      </div>
      <div v-else class="text-center py-20">
        <USkeleton class="h-8 w-64 mx-auto mb-4" />
        <USkeleton class="h-4 w-48 mx-auto" />
      </div>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const route = useRoute()

const product = ref<any>(null)

function formatMoney(amount?: string | number, currency = 'RON') {
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency }).format(Number(amount || 0))
}

function formatDate(date: string) {
  return new Date(date).toLocaleDateString('ro-RO', { dateStyle: 'medium' })
}

onMounted(async () => {
  const { get } = useApi()
  try {
    product.value = await get<any>(`/v1/products/${route.params.uuid}`)
  } catch {
    useToast().add({ title: $t('products.loadError'), color: 'error' })
  }
})
</script>
