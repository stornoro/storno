<script setup lang="ts">
const props = defineProps<{
  detectedColumns: string[]
  targetFields: Record<string, string>
  suggestedMapping: Record<string, string>
  requiredFields: string[]
}>()

const mapping = defineModel<Record<string, string>>()

const { t: $t } = useI18n()

// Build dropdown options from target fields.
// USelectMenu v-model must NOT be empty string — use null for unselected.
// value-key="value" is required since items have { label, value } shape.
const targetOptions = computed(() => {
  const opts: Array<{ label: string; value: string | null }> = [
    { label: `-- ${$t('importExport.unmapped')} --`, value: null },
  ]
  for (const [key, label] of Object.entries(props.targetFields)) {
    opts.push({ label, value: key })
  }
  return opts
})

function getSelectedValue(column: string): string | null {
  return mapping.value?.[column] ?? null
}

function updateMapping(column: string, targetField: string | null) {
  if (!mapping.value) mapping.value = {}
  if (!targetField) {
    const { [column]: _, ...rest } = mapping.value
    mapping.value = rest
  }
  else {
    mapping.value = { ...mapping.value, [column]: targetField }
  }
}
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm text-(--ui-text-muted)">{{ $t('importExport.mappingDescription') }}</p>

    <div class="space-y-3">
      <div
        v-for="column in detectedColumns"
        :key="column"
        class="flex items-center gap-4 p-3 rounded-lg bg-gray-50 dark:bg-gray-800"
      >
        <!-- Source column label -->
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium truncate">{{ column }}</p>
          <p
            v-if="suggestedMapping[column]"
            class="text-xs text-(--ui-text-muted)"
          >
            {{ $t('importExport.autoDetected') }}
          </p>
        </div>

        <!-- Arrow -->
        <UIcon name="i-lucide-arrow-right" class="w-4 h-4 text-gray-400 flex-shrink-0" />

        <!-- Target field dropdown -->
        <div class="flex-1">
          <USelectMenu
            :model-value="getSelectedValue(column)"
            :items="targetOptions"
            value-key="value"
            :placeholder="$t('importExport.unmapped')"
            @update:model-value="(val: string | null) => updateMapping(column, val)"
          />
        </div>

        <!-- Required badge -->
        <UBadge
          v-if="requiredFields.includes(mapping?.[column] ?? '')"
          color="error"
          variant="subtle"
          size="xs"
        >
          {{ $t('importExport.requiredField') }}
        </UBadge>
      </div>
    </div>
  </div>
</template>
