<script setup lang="ts">
const props = defineProps<{
  codes: string[]
}>()

const { t: $t } = useI18n()
const toast = useToast()

function copyAll() {
  navigator.clipboard.writeText(props.codes.join('\n'))
  toast.add({ title: $t('settings.mfa.copied'), color: 'success' })
}

function download() {
  const text = `Storno - Coduri de recuperare 2FA\n${'='.repeat(40)}\n\n${props.codes.join('\n')}\n\nFiecare cod poate fi folosit o singura data.`
  const blob = new Blob([text], { type: 'text/plain' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'storno-backup-codes.txt'
  a.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div>
    <p class="text-sm text-muted">{{ $t('settings.mfa.backupCodesDescription') }}</p>

    <div class="bg-default rounded-lg p-4 my-3">
      <div class="grid grid-cols-2 gap-2">
        <div
          v-for="code in codes"
          :key="code"
          class="font-mono text-sm text-center py-1.5 px-3 bg-elevated rounded select-all"
        >
          {{ code }}
        </div>
      </div>
    </div>

    <p class="text-xs text-muted mb-3">{{ $t('settings.mfa.backupCodesWarning') }}</p>

    <div class="flex gap-2">
      <UButton variant="outline" color="neutral" size="sm" icon="i-lucide-copy" @click="copyAll">
        {{ $t('settings.mfa.copyAll') }}
      </UButton>
      <UButton variant="outline" color="neutral" size="sm" icon="i-lucide-download" @click="download">
        {{ $t('settings.mfa.download') }}
      </UButton>
    </div>
  </div>
</template>
