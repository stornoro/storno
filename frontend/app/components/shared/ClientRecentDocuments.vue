<template>
  <div v-if="loading" class="flex items-center gap-2 py-1 text-xs text-(--ui-text-muted)">
    <UIcon name="i-lucide-loader-2" class="size-3 animate-spin" />
  </div>
  <div v-else-if="hasCounts" class="flex flex-wrap gap-3 pt-1 text-xs text-(--ui-text-muted)">
    <span v-if="invoiceCount > 0">{{ invoiceCount }} {{ $t('clients.invoicesLabel') }}</span>
    <span v-if="deliveryNoteCount > 0">{{ deliveryNoteCount }} {{ $t('nav.deliveryNotes').toLowerCase() }}</span>
    <span v-if="receiptCount > 0">{{ receiptCount }} {{ $t('nav.receipts').toLowerCase() }}</span>
  </div>
</template>

<script setup lang="ts">
const props = defineProps<{
  clientId: string | null
}>()

const { get } = useApi()
const loading = ref(false)

const invoiceCount = ref(0)
const deliveryNoteCount = ref(0)
const receiptCount = ref(0)

const hasCounts = computed(() =>
  invoiceCount.value > 0 || deliveryNoteCount.value > 0 || receiptCount.value > 0,
)

async function fetchCounts(clientId: string) {
  loading.value = true
  try {
    const data = await get<any>(`/v1/clients/${clientId}`, { limit: 1 })
    invoiceCount.value = data.invoiceCount ?? data.invoiceTotal ?? 0
    deliveryNoteCount.value = data.deliveryNoteCount ?? 0
    receiptCount.value = data.receiptCount ?? 0
  }
  catch {
    // Silently fail
  }
  finally {
    loading.value = false
  }
}

function clearCounts() {
  invoiceCount.value = 0
  deliveryNoteCount.value = 0
  receiptCount.value = 0
}

watch(() => props.clientId, (newId) => {
  if (newId) {
    fetchCounts(newId)
  }
  else {
    clearCounts()
  }
}, { immediate: true })
</script>
