<script setup lang="ts">
const props = defineProps<{
  sources: Array<{ key: string; label: string; importTypes: string[]; formats: string[] }>
  importTypes: Array<{ value: string; label: string }>
}>()

const source = defineModel<string | null>('source')
const importType = defineModel<string | null>('importType')

const { t: $t } = useI18n()

const sourceBranding: Record<string, { color: string; initials: string }> = {
  smartbill: { color: '#2196F3', initials: 'SB' },
  saga: { color: '#4CAF50', initials: 'SA' },
  oblio: { color: '#28143d', initials: 'OB' },
  fgo: { color: '#FF9800', initials: 'FG' },
  facturis_online: { color: '#009688', initials: 'FO' },
  easybill: { color: '#3F51B5', initials: 'EB' },
  ciel: { color: '#F44336', initials: 'CI' },
  factureaza: { color: '#FFC107', initials: 'FZ' },
  facturare_pro: { color: '#00BCD4', initials: 'FP' },
  icefact: { color: '#607D8B', initials: 'IF' },
  bolt: { color: '#34D186', initials: 'BT' },
  facturis: { color: '#E91E63', initials: 'FC' },
  emag: { color: '#F5A623', initials: 'eM' },
  generic: { color: '#9E9E9E', initials: '?' },
}

// Sources that are coming soon (shown in UI but not selectable)
const comingSoonSources = [
  { key: 'uber', label: 'Uber', color: '#000000', initials: 'UB' },
  { key: 'blue', label: 'Blue', color: '#0066FF', initials: 'BL' },
]

// When the source changes, reset the import type if the currently selected type
// is not supported by the new source.
watch(source, (newSource) => {
  if (!newSource || !importType.value) return
  const src = props.sources.find(s => s.key === newSource)
  if (src && !src.importTypes.includes(importType.value)) {
    importType.value = null
  }
})

// Filter import types based on selected source
const availableImportTypes = computed(() => {
  if (!source.value) return props.importTypes
  const src = props.sources.find(s => s.key === source.value)
  if (!src) return props.importTypes
  return props.importTypes.filter(t => src.importTypes.includes(t.value))
})

function formatBadge(fmt: string): string {
  if (fmt === 'saga_xml') return 'XML'
  return fmt.toUpperCase()
}
</script>

<template>
  <div class="space-y-6">
    <!-- Import Type Selection -->
    <div>
      <h4 class="text-sm font-medium mb-3">{{ $t('importExport.selectImportType') }}</h4>
      <div class="grid grid-cols-2 gap-3">
        <button
          v-for="type in availableImportTypes"
          :key="type.value"
          type="button"
          class="p-4 rounded-lg border-2 text-left transition-colors"
          :class="importType === type.value
            ? 'border-primary bg-primary/5'
            : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'"
          @click="importType = type.value"
        >
          <span class="text-sm font-medium">{{ type.label }}</span>
        </button>
      </div>
    </div>

    <!-- Source Selection -->
    <div>
      <h4 class="text-sm font-medium mb-3">{{ $t('importExport.selectSource') }}</h4>
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
        <button
          v-for="src in sources"
          :key="src.key"
          type="button"
          class="flex items-center gap-3 p-3 rounded-lg border-2 transition-colors text-left"
          :class="source === src.key
            ? 'border-primary bg-primary/5'
            : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'"
          @click="source = src.key"
        >
          <!-- Branded color initial square -->
          <div
            class="w-10 h-10 rounded-lg flex items-center justify-center text-white font-bold text-sm shrink-0"
            :style="{ backgroundColor: sourceBranding[src.key]?.color ?? '#9E9E9E' }"
          >
            {{ sourceBranding[src.key]?.initials ?? '?' }}
          </div>
          <div class="flex-1 min-w-0">
            <span class="text-sm font-medium block truncate">{{ src.label }}</span>
            <div class="flex gap-1 mt-1">
              <span
                v-for="fmt in src.formats"
                :key="fmt"
                class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-(--ui-text-muted) font-medium"
              >
                {{ formatBadge(fmt) }}
              </span>
            </div>
          </div>
        </button>

        <!-- Coming soon sources -->
        <div
          v-for="cs in comingSoonSources"
          :key="cs.key"
          class="flex items-center gap-3 p-3 rounded-lg border-2 border-dashed border-gray-200 dark:border-gray-700 opacity-50 cursor-not-allowed"
        >
          <div
            class="w-10 h-10 rounded-lg flex items-center justify-center text-white font-bold text-sm shrink-0"
            :style="{ backgroundColor: cs.color }"
          >
            {{ cs.initials }}
          </div>
          <div class="flex-1 min-w-0">
            <span class="text-sm font-medium block truncate">{{ cs.label }}</span>
            <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-(--ui-text-muted) font-medium">
              {{ $t('importExport.comingSoon') }}
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
