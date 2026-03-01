<script setup lang="ts">
const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ verified: [verificationToken: string] }>()

const { t: $t } = useI18n()
const toast = useToast()
const stepUp = useStepUpMfa()

const activeTab = ref('passkey')
const totpCode = ref('')
const backupCode = ref('')

const hasPasskey = computed(() => stepUp.methods.value.includes('passkey'))
const hasTotp = computed(() => stepUp.methods.value.includes('totp'))
const hasBackup = computed(() => stepUp.methods.value.includes('backup_code'))

const tabs = computed(() => {
  const items: { label: string; value: string }[] = []
  if (hasPasskey.value) items.push({ label: $t('auth.mfaPasskeyTab'), value: 'passkey' })
  if (hasTotp.value) items.push({ label: $t('auth.mfaTotpTab'), value: 'totp' })
  if (hasBackup.value) items.push({ label: $t('auth.mfaBackupTab'), value: 'backup' })
  return items
})

watch(open, async (isOpen) => {
  if (isOpen) {
    stepUp.reset()
    totpCode.value = ''
    backupCode.value = ''
    const needed = await stepUp.requestChallenge()
    if (!needed) {
      // No MFA required — shouldn't happen but handle gracefully
      emit('verified', '')
      open.value = false
      return
    }
    activeTab.value = hasPasskey.value ? 'passkey' : 'totp'
    // Auto-trigger passkey
    if (hasPasskey.value) {
      await onVerifyPasskey()
    }
  }
})

async function onVerifyPasskey() {
  const success = await stepUp.verifyWithPasskey()
  if (success && stepUp.verificationToken.value) {
    emit('verified', stepUp.verificationToken.value)
    open.value = false
  } else {
    toast.add({ title: $t('auth.mfaPasskeyRetry'), color: 'error' })
  }
}

async function onVerifyTotp() {
  if (totpCode.value.length !== 6) return
  const success = await stepUp.verify('totp', totpCode.value)
  if (success && stepUp.verificationToken.value) {
    emit('verified', stepUp.verificationToken.value)
    open.value = false
  } else {
    totpCode.value = ''
    toast.add({ title: $t('auth.mfaInvalidCode'), color: 'error' })
  }
}

async function onVerifyBackup() {
  if (backupCode.value.length < 8) return
  const success = await stepUp.verify('backup', backupCode.value)
  if (success && stepUp.verificationToken.value) {
    emit('verified', stepUp.verificationToken.value)
    open.value = false
  } else {
    backupCode.value = ''
    toast.add({ title: $t('auth.mfaInvalidCode'), color: 'error' })
  }
}
</script>

<template>
  <UModal v-model:open="open">
    <template #header>
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-shield-check" class="size-5 shrink-0 text-(--ui-primary)" />
        <h3 class="font-semibold">{{ $t('stepUpMfa.title') }}</h3>
      </div>
    </template>
    <template #body>
      <p class="text-sm text-(--ui-text-muted) mb-4">{{ $t('stepUpMfa.description') }}</p>

      <div v-if="stepUp.loading.value && !stepUp.challengeToken.value" class="flex justify-center py-6">
        <UIcon name="i-lucide-loader-2" class="size-6 animate-spin text-(--ui-text-muted)" />
      </div>

      <template v-else-if="stepUp.challengeToken.value">
        <UTabs
          v-if="tabs.length > 1"
          v-model="activeTab"
          :items="tabs"
          class="mb-4"
        />

        <!-- Passkey -->
        <div v-if="activeTab === 'passkey'" class="space-y-4">
          <div class="text-center py-2">
            <UIcon name="i-lucide-fingerprint" class="w-10 h-10 mx-auto mb-2 text-(--ui-text-muted)" />
            <p class="text-sm text-(--ui-text-muted)">{{ $t('auth.mfaPasskeyPrompt') }}</p>
          </div>
          <UButton
            :loading="stepUp.loading.value"
            size="lg"
            block
            :ui="{ base: 'rounded-xl justify-center font-semibold' }"
            @click="onVerifyPasskey"
          >
            {{ $t('auth.mfaPasskeyVerify') }}
          </UButton>
        </div>

        <!-- TOTP -->
        <div v-if="activeTab === 'totp'" class="space-y-4">
          <UInput
            v-model="totpCode"
            :placeholder="$t('auth.mfaTotpPlaceholder')"
            maxlength="6"
            inputmode="numeric"
            pattern="[0-9]*"
            autofocus
            size="xl"
            class="w-full font-mono text-center text-lg tracking-widest"
            :ui="{ base: 'rounded-xl shadow-sm' }"
            @keydown.enter="onVerifyTotp"
          />
          <UButton
            :loading="stepUp.loading.value"
            :disabled="totpCode.length !== 6"
            size="lg"
            block
            :ui="{ base: 'rounded-xl justify-center font-semibold' }"
            @click="onVerifyTotp"
          >
            {{ $t('auth.mfaVerify') }}
          </UButton>
        </div>

        <!-- Backup -->
        <div v-if="activeTab === 'backup'" class="space-y-4">
          <UInput
            v-model="backupCode"
            :placeholder="$t('auth.mfaBackupPlaceholder')"
            maxlength="9"
            autofocus
            size="xl"
            class="w-full font-mono text-center text-lg tracking-widest"
            :ui="{ base: 'rounded-xl shadow-sm' }"
            @keydown.enter="onVerifyBackup"
          />
          <UButton
            :loading="stepUp.loading.value"
            :disabled="backupCode.length < 8"
            size="lg"
            block
            :ui="{ base: 'rounded-xl justify-center font-semibold' }"
            @click="onVerifyBackup"
          >
            {{ $t('auth.mfaVerify') }}
          </UButton>
        </div>
      </template>

      <div v-if="stepUp.error.value" class="mt-3 text-sm text-red-500 text-center">
        {{ stepUp.error.value }}
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end">
        <UButton variant="ghost" @click="open = false">
          {{ $t('common.cancel') }}
        </UButton>
      </div>
    </template>
  </UModal>
</template>
