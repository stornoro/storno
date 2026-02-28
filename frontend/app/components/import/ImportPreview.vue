<script setup lang="ts">
const props = defineProps<{
  previewData: Record<string, string>[]
  columnMapping: Record<string, string>
  totalRows: number
}>()

const { t: $t } = useI18n()

// Build TanStack-compatible column definitions from the active mapping.
// accessorKey must match keys in previewData rows (the source column names).
const columns = computed(() => {
  return Object.entries(props.columnMapping).map(([sourceCol, targetField]) => ({
    accessorKey: sourceCol,
    header: `${targetField} (${sourceCol})`,
  }))
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <p class="text-sm text-(--ui-text-muted)">
        {{ $t('importExport.previewDescription', { count: previewData.length }) }}
      </p>
      <UBadge variant="subtle" color="info">
        {{ $t('importExport.rowsDetected', { count: totalRows }) }}
      </UBadge>
    </div>

    <div
      v-if="columns.length > 0 && previewData.length > 0"
      class="overflow-x-auto border border-(--ui-border) rounded-lg"
    >
      <UTable
        :data="previewData"
        :columns="columns"
      />
    </div>

    <div
      v-else
      class="text-center py-10 text-sm text-(--ui-text-muted) border border-(--ui-border) rounded-lg"
    >
      {{ $t('common.noData') }}
    </div>
  </div>
</template>
