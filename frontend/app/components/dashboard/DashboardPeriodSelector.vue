<script setup lang="ts">
import type { PresetKey } from '~/composables/usePeriodSelector'
import type { DropdownMenuItem } from '@nuxt/ui'

const props = defineProps<{
  selectedPreset: PresetKey
  displayLabel: string
  isCustom: boolean
  customDateFrom: string
  customDateTo: string
  presets: { label: string; value: PresetKey }[]
}>()

const emit = defineEmits<{
  'update:selectedPreset': [value: PresetKey]
  'update:customDateFrom': [value: string]
  'update:customDateTo': [value: string]
}>()

const { t: $t } = useI18n()

const dropdownItems = computed<DropdownMenuItem[][]>(() => {
  const items = props.presets.map(preset => ({
    label: preset.label,
    icon: props.selectedPreset === preset.value ? 'i-lucide-check' : undefined,
    onSelect: () => emit('update:selectedPreset', preset.value),
  }))
  return [items]
})
</script>

<template>
  <div class="flex items-center gap-2">
    <UDropdownMenu :items="dropdownItems">
      <UButton
        color="neutral"
        variant="ghost"
        size="sm"
        trailing-icon="i-lucide-chevron-down"
      >
        {{ displayLabel }}
      </UButton>
    </UDropdownMenu>

    <template v-if="isCustom">
      <div class="flex items-center gap-1.5">
        <label class="text-xs text-(--ui-text-muted)">{{ $t('period.dateFrom') }}</label>
        <UInput
          type="date"
          size="sm"
          :model-value="customDateFrom"
          @update:model-value="emit('update:customDateFrom', $event as string)"
        />
      </div>
      <div class="flex items-center gap-1.5">
        <label class="text-xs text-(--ui-text-muted)">{{ $t('period.dateTo') }}</label>
        <UInput
          type="date"
          size="sm"
          :model-value="customDateTo"
          @update:model-value="emit('update:customDateTo', $event as string)"
        />
      </div>
    </template>
  </div>
</template>
