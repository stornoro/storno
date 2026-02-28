<script setup lang="ts">
const { t: $t } = useI18n()

defineProps<{
  count: number
  loading?: boolean
}>()

const emit = defineEmits<{
  clear: []
}>()
</script>

<template>
  <div
    v-if="count > 0"
    class="flex items-center justify-between p-3 rounded-lg bg-primary/5 border border-primary/20"
  >
    <span class="text-sm font-medium">
      {{ $t('bulk.selectedCount', { count }) }}
    </span>

    <div class="flex items-center gap-2">
      <slot name="actions" />

      <UButton
        :label="$t('bulk.deselectAll')"
        icon="i-lucide-x"
        variant="ghost"
        size="sm"
        @click="emit('clear')"
      />
    </div>
  </div>
</template>
