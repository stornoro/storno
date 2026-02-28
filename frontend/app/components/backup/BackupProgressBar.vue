<script setup lang="ts">
const props = defineProps<{
  progress: number
  step: string | null
  status: string
}>()

const progressColor = computed(() => {
  if (props.status === 'failed') return 'error'
  if (props.status === 'completed') return 'success'
  return 'primary'
})
</script>

<template>
  <div class="space-y-2">
    <div class="flex items-center justify-between text-sm">
      <span class="text-(--ui-text-muted)">{{ step || $t('backup.processing') }}</span>
      <span class="font-medium">{{ progress }}%</span>
    </div>
    <UProgress :model-value="progress" :color="progressColor" size="md" />
  </div>
</template>
