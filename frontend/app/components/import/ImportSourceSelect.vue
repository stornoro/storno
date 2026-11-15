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
  uber: { color: '#000000', initials: 'UB' },
  glovo: { color: '#FFC244', initials: 'GL' },
  tazz: { color: '#EE2E24', initials: 'TZ' },
  woocommerce: { color: '#7F54B3', initials: 'WC' },
  prestashop: { color: '#DF0067', initials: 'PS' },
  cash_register: { color: '#0F766E', initials: 'CM' },
  generic: { color: '#9E9E9E', initials: '?' },
}

// Sources that are coming soon (shown in UI but not selectable)
const comingSoonSources = [
  { key: 'blue', label: 'Blue', color: '#0066FF', initials: 'BL' },
]

// When the source changes, reset the import type if the currently selected type
// is not supported by the new source.
// The type that had to be dropped because the chosen source cannot import it — said out
// loud, so that a vanished selection does not look like a fault.
const droppedType = ref<string | null>(null)

watch(source, (newSource) => {
  droppedType.value = null
  if (!newSource || !importType.value) return
  const src = props.sources.find(s => s.key === newSource)
  if (src && !src.importTypes.includes(importType.value)) {
    droppedType.value = props.importTypes.find(t => t.value === importType.value)?.label ?? importType.value
    importType.value = null
  }
})

watch(importType, (value) => {
  if (value) droppedType.value = null
})

const selectedSource = computed(() => props.sources.find(s => s.key === source.value) ?? null)

// Every type stays on screen; the ones the selected source cannot import are disabled.
function isTypeSupported(type: string): boolean {
  return !selectedSource.value || selectedSource.value.importTypes.includes(type)
}

// A source that cannot import the selected type is still selectable, but marked.
function supportsSelectedType(src: { importTypes: string[] }): boolean {
  return !importType.value || src.importTypes.includes(importType.value)
}

function formatBadge(fmt: string): string {
  if (fmt === 'saga_xml' || fmt === 'a4200_xml') return 'XML'
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
          v-for="type in importTypes"
          :key="type.value"
          type="button"
          class="p-4 rounded-lg border-2 text-left transition-colors"
          :class="[
            importType === type.value
              ? 'border-primary bg-primary/5'
              : 'border-gray-200 dark:border-gray-700',
            isTypeSupported(type.value)
              ? 'hover:border-gray-300 dark:hover:border-gray-600'
              : 'opacity-50 cursor-not-allowed border-dashed',
          ]"
          :disabled="!isTypeSupported(type.value)"
          :aria-disabled="!isTypeSupported(type.value)"
          @click="importType = type.value"
        >
          <span class="text-sm font-medium block">{{ type.label }}</span>
          <span v-if="!isTypeSupported(type.value)" class="text-xs text-(--ui-text-muted) block mt-1">
            {{ $t('importExport.typeUnavailableForSource', { source: selectedSource?.label ?? '' }) }}
          </span>
        </button>
      </div>
      <p v-if="droppedType && selectedSource" class="mt-3 text-sm text-warning-600 dark:text-warning-400" role="status">
        {{ $t('importExport.typeDroppedForSource', { type: droppedType, source: selectedSource.label }) }}
      </p>
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
          :class="[
            source === src.key
              ? 'border-primary bg-primary/5'
              : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600',
            supportsSelectedType(src) ? '' : 'opacity-50',
          ]"
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
