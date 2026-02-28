<script setup lang="ts">
const open = defineModel<boolean>('open', { default: false })

const props = withDefaults(defineProps<{
  title: string
  description?: string
  label?: string
  placeholder?: string
  confirmLabel?: string
  loading?: boolean
  inputType?: 'text' | 'number'
}>(), {
  inputType: 'text',
})

const emit = defineEmits<{ confirm: [value: string] }>()

const { t: $t } = useI18n()
const inputValue = ref<string | number>('')

const inputStr = computed(() => String(inputValue.value).trim())

watch(open, (isOpen) => {
  if (!isOpen) inputValue.value = ''
})

function onConfirm() {
  if (!inputStr.value) return
  emit('confirm', inputStr.value)
}
</script>

<template>
  <UModal v-model:open="open">
    <template #header>
      <h3 class="font-semibold">{{ title }}</h3>
    </template>
    <template #body>
      <div class="space-y-4">
        <p v-if="description" class="text-sm text-(--ui-text-muted)">{{ description }}</p>
        <UFormField :label="label">
          <UInput
            v-model="inputValue"
            :type="inputType"
            :placeholder="placeholder"
            class="w-full"
            @keydown.enter="onConfirm"
          />
        </UFormField>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton variant="ghost" @click="open = false">
          {{ $t('common.cancel') }}
        </UButton>
        <UButton :loading="loading" :disabled="!inputStr" @click="onConfirm">
          {{ confirmLabel || $t('common.confirm') }}
        </UButton>
      </div>
    </template>
  </UModal>
</template>
