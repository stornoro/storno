<template>
  <UCard>
    <template #header>
      <div class="flex items-start gap-3">
        <div class="p-2 rounded-lg bg-(--ui-bg-elevated) flex-shrink-0">
          <UIcon :name="icon" class="size-4 text-(--ui-text-muted)" />
        </div>
        <div class="min-w-0">
          <p class="text-sm font-semibold text-(--ui-text-highlighted)">{{ title }}</p>
          <p v-if="subtitle" class="text-xs text-(--ui-text-muted) mt-0.5">{{ subtitle }}</p>
        </div>
      </div>
    </template>
    <slot />
  </UCard>
</template>

<script setup lang="ts">
defineProps<{
  icon: string
  title: string
  subtitle?: string
}>()
</script>
