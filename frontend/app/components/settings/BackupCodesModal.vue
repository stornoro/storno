<script setup lang="ts">
defineProps<{
  open: boolean
  codes: string[]
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const { t: $t } = useI18n()
</script>

<template>
  <UModal :open="open" @update:open="emit('update:open', $event)">
    <template #content>
      <div class="p-6 space-y-5">
        <h3 class="text-lg font-semibold">{{ $t('settings.mfa.backupCodes') }}</h3>

        <SettingsBackupCodesDisplay :codes="codes" />

        <div class="flex justify-end">
          <UButton @click="emit('update:open', false)">
            {{ $t('common.close') }}
          </UButton>
        </div>
      </div>
    </template>
  </UModal>
</template>
