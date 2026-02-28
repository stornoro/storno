<script setup lang="ts">
const props = defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'enabled': [backupCodes: string[]]
}>()

const { t: $t } = useI18n()
const mfa = useMfa()
const toast = useToast()

const step = ref<'qr' | 'verify' | 'backup'>('qr')
const loading = ref(false)
const qrCode = ref('')
const secret = ref('')
const code = ref('')
const backupCodes = ref<string[]>([])

watch(() => props.open, async (isOpen) => {
  if (isOpen) {
    step.value = 'qr'
    code.value = ''
    backupCodes.value = []
    loading.value = true
    try {
      const result = await mfa.setupTotp()
      qrCode.value = result.qrCode
      secret.value = result.secret
    }
    catch {
      toast.add({ title: $t('settings.mfa.enableError'), color: 'error' })
      emit('update:open', false)
    }
    finally {
      loading.value = false
    }
  }
})

async function onVerify() {
  if (code.value.length !== 6) return
  loading.value = true
  try {
    const result = await mfa.enableTotp(code.value)
    backupCodes.value = result.backupCodes
    step.value = 'backup'
    toast.add({ title: $t('settings.mfa.enableSuccess'), color: 'success' })
  }
  catch {
    toast.add({ title: $t('settings.mfa.invalidCode'), color: 'error' })
  }
  finally {
    loading.value = false
  }
}

function onClose() {
  if (step.value === 'backup') {
    emit('enabled', backupCodes.value)
  }
  emit('update:open', false)
}

function formatSecret(s: string): string {
  return s.match(/.{1,4}/g)?.join(' ') ?? s
}
</script>

<template>
  <UModal :open="open" @update:open="onClose">
    <template #content>
      <div class="p-6 space-y-5">
        <h3 class="text-lg font-semibold">{{ $t('settings.mfa.setupTitle') }}</h3>

        <!-- Step 1: QR Code -->
        <template v-if="step === 'qr'">
          <p class="text-sm text-muted">{{ $t('settings.mfa.setupStep1') }}</p>

          <div v-if="loading" class="flex justify-center py-8">
            <UIcon name="i-lucide-loader-2" class="size-8 animate-spin text-muted" />
          </div>
          <div v-else class="flex flex-col items-center gap-4">
            <img :src="qrCode" alt="QR Code" class="w-64 h-64 rounded-lg border border-default" />

            <div class="w-full">
              <p class="text-sm text-muted mb-1">{{ $t('settings.mfa.setupStep2') }}</p>
              <div class="bg-default rounded-lg px-3 py-2 font-mono text-sm text-center select-all break-all">
                {{ formatSecret(secret) }}
              </div>
            </div>
          </div>

          <div class="flex justify-end">
            <UButton @click="step = 'verify'" :disabled="loading">
              {{ $t('common.next') }}
            </UButton>
          </div>
        </template>

        <!-- Step 2: Verify Code -->
        <template v-if="step === 'verify'">
          <p class="text-sm text-muted">{{ $t('settings.mfa.setupStep3') }}</p>

          <UFormField :label="$t('settings.mfa.verifyCode')">
            <UInput
              v-model="code"
              :placeholder="$t('auth.mfaTotpPlaceholder')"
              maxlength="6"
              inputmode="numeric"
              pattern="[0-9]*"
              autofocus
              class="font-mono text-center text-lg tracking-widest"
              @keydown.enter="onVerify"
            />
          </UFormField>

          <div class="flex justify-between">
            <UButton variant="ghost" @click="step = 'qr'">
              {{ $t('common.back') }}
            </UButton>
            <UButton :loading="loading" :disabled="code.length !== 6" @click="onVerify">
              {{ $t('settings.mfa.verifyButton') }}
            </UButton>
          </div>
        </template>

        <!-- Step 3: Backup Codes -->
        <template v-if="step === 'backup'">
          <SettingsBackupCodesDisplay :codes="backupCodes" />

          <div class="flex justify-end">
            <UButton @click="onClose">
              {{ $t('common.close') }}
            </UButton>
          </div>
        </template>
      </div>
    </template>
  </UModal>
</template>
