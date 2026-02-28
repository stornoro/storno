<script setup lang="ts">
const open = defineModel<boolean>('open', { default: false })

const props = withDefaults(defineProps<{
  title: string
  description?: string
  icon?: string
  color?: 'error' | 'warning' | 'primary' | 'success' | 'neutral'
  confirmLabel?: string
  cancelLabel?: string
  loading?: boolean
}>(), {
  color: 'primary',
  icon: 'i-lucide-alert-triangle',
})

const emit = defineEmits<{ confirm: [] }>()

const { t: $t } = useI18n()
</script>

<template>
  <UModal v-model:open="open">
    <template #header>
      <div class="flex items-center gap-2">
        <UIcon :name="icon" class="size-5 shrink-0" :class="{
          'text-red-500': color === 'error',
          'text-amber-500': color === 'warning',
          'text-(--ui-primary)': color === 'primary',
          'text-green-500': color === 'success',
          'text-(--ui-text-muted)': color === 'neutral',
        }" />
        <h3 class="font-semibold">{{ title }}</h3>
      </div>
    </template>
    <template #body>
      <p v-if="description" class="text-sm text-(--ui-text-muted) whitespace-pre-line">{{ description }}</p>
      <slot />
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton variant="ghost" @click="open = false">
          {{ cancelLabel || $t('common.cancel') }}
        </UButton>
        <UButton :color="color" :loading="loading" @click="emit('confirm')">
          {{ confirmLabel || $t('common.confirm') }}
        </UButton>
      </div>
    </template>
  </UModal>
</template>
