<script setup lang="ts">
const { t: $t } = useI18n()
const store = useBordereauStore()

const emit = defineEmits<{
  filter: []
}>()

const providerOptions = computed(() => {
  const items: { label: string, value: string }[] = []
  if (store.providers) {
    const sourceType = (store.filters.sourceType as 'borderou' | 'bank_statement' | 'marketplace') || 'borderou'
    const list = store.providers[sourceType] || []
    for (const p of list) {
      items.push({ label: p.label, value: p.key })
    }
  }
  return items
})

const providerLabel = computed(() => {
  const sourceType = store.filters.sourceType
  return sourceType === 'bank_statement' ? $t('borderou.filterBank') : $t('borderou.filterProvider')
})

function onProviderChange(e: Event) {
  const val = (e.target as HTMLSelectElement).value
  store.filters.provider = val || undefined
  emit('filter')
}

function onConfidenceChange(e: Event) {
  store.filters.confidence = (e.target as HTMLSelectElement).value
  emit('filter')
}

function onStatusChange(e: Event) {
  store.filters.status = (e.target as HTMLSelectElement).value
  emit('filter')
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-3">
    <select
      :value="store.filters.provider || ''"
      class="h-8 rounded-md border border-(--ui-border) bg-(--ui-bg) px-2.5 text-sm text-(--ui-text) focus:outline-none focus:ring-2 focus:ring-(--ui-primary) w-44"
      @change="onProviderChange"
    >
      <option value="">
        {{ providerLabel }} — {{ $t('borderou.filterAll') }}
      </option>
      <option v-for="opt in providerOptions" :key="opt.value" :value="opt.value">
        {{ opt.label }}
      </option>
    </select>

    <select
      :value="store.filters.confidence"
      class="h-8 rounded-md border border-(--ui-border) bg-(--ui-bg) px-2.5 text-sm text-(--ui-text) focus:outline-none focus:ring-2 focus:ring-(--ui-primary) w-36"
      @change="onConfidenceChange"
    >
      <option value="all">{{ $t('borderou.filterConfidence') }} — {{ $t('borderou.filterAll') }}</option>
      <option value="certain">{{ $t('borderou.confidenceCertain') }}</option>
      <option value="attention">{{ $t('borderou.confidenceAttention') }}</option>
      <option value="no_match">{{ $t('borderou.confidenceNoMatch') }}</option>
    </select>

    <select
      :value="store.filters.status"
      class="h-8 rounded-md border border-(--ui-border) bg-(--ui-bg) px-2.5 text-sm text-(--ui-text) focus:outline-none focus:ring-2 focus:ring-(--ui-primary) w-36"
      @change="onStatusChange"
    >
      <option value="all">{{ $t('borderou.filterStatus') }} — {{ $t('borderou.filterAll') }}</option>
      <option value="unsaved">{{ $t('borderou.statusUnsaved') }}</option>
      <option value="saved">{{ $t('borderou.statusSaved') }}</option>
    </select>
  </div>
</template>
