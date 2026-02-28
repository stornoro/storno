<template>
  <div>
    <div class="mb-8">
      <h2 class="text-2xl font-bold text-(--ui-text)">{{ $t('auth.mfaTitle') }}</h2>
      <p class="mt-1 text-sm text-(--ui-text-muted)">{{ $t('auth.mfaDescription') }}</p>
    </div>

    <!-- Tab selector -->
    <UTabs
      v-model="activeTab"
      :items="tabs"
      class="mb-5"
    />

    <!-- TOTP Code -->
    <div v-if="activeTab === 'totp'" class="space-y-5">
      <UFormField :label="$t('settings.mfa.verifyCode')">
        <UInput
          ref="totpInput"
          v-model="totpCode"
          :placeholder="$t('auth.mfaTotpPlaceholder')"
          maxlength="6"
          inputmode="numeric"
          pattern="[0-9]*"
          autofocus
          size="xl"
          class="w-full font-mono text-center text-lg tracking-widest"
          :ui="{ base: 'rounded-xl shadow-sm' }"
          @keydown.enter="onVerify"
        />
      </UFormField>

      <UButton
        :loading="loading"
        :disabled="totpCode.length !== 6"
        size="xl"
        block
        :ui="{ base: 'rounded-xl justify-center font-semibold' }"
        @click="onVerify"
      >
        {{ $t('auth.mfaVerify') }}
      </UButton>
    </div>

    <!-- Backup Code -->
    <div v-if="activeTab === 'backup'" class="space-y-5">
      <UFormField :label="$t('settings.mfa.backupCodes')">
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
      </UFormField>

      <UButton
        :loading="loading"
        :disabled="backupCode.length < 8"
        size="xl"
        block
        :ui="{ base: 'rounded-xl justify-center font-semibold' }"
        @click="onVerifyBackup"
      >
        {{ $t('auth.mfaVerify') }}
      </UButton>
    </div>

    <div class="mt-6 text-center">
      <NuxtLink to="/login" class="text-sm text-primary font-medium hover:underline" @click="authStore.clearMfa()">
        {{ $t('auth.mfaBackToLogin') }}
      </NuxtLink>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'auth', middleware: 'guest' })

const { t: $t } = useI18n()
useHead({ title: $t('auth.mfaTitle') })
const authStore = useAuthStore()
const toast = useToast()

const { resolve: resolvePostLogin } = usePostLoginRoute()
const loading = ref(false)
const totpCode = ref('')
const backupCode = ref('')
const activeTab = ref('totp')

const tabs = computed(() => {
  const items = [{ label: $t('auth.mfaTotpTab'), value: 'totp' }]
  if (authStore.mfaMethods.includes('backup_code')) {
    items.push({ label: $t('auth.mfaBackupTab'), value: 'backup' })
  }
  return items
})

// Redirect to login if no MFA challenge pending
onMounted(() => {
  if (!authStore.mfaPending || !authStore.mfaToken) {
    navigateTo('/login')
  }
})

async function onVerify() {
  if (totpCode.value.length !== 6) return
  loading.value = true
  try {
    const success = await authStore.completeMfaLogin(totpCode.value, 'totp')
    if (success) {
      await navigateTo(await resolvePostLogin())
    } else {
      totpCode.value = ''
      toast.add({ title: $t('auth.mfaInvalidCode'), color: 'error' })
    }
  }
  catch {
    totpCode.value = ''
    toast.add({ title: $t('auth.mfaInvalidCode'), color: 'error' })
  }
  finally {
    loading.value = false
  }
}

async function onVerifyBackup() {
  if (backupCode.value.length < 8) return
  loading.value = true
  try {
    const success = await authStore.completeMfaLogin(backupCode.value, 'backup')
    if (success) {
      await navigateTo(await resolvePostLogin())
    } else {
      backupCode.value = ''
      toast.add({ title: $t('auth.mfaInvalidCode'), color: 'error' })
    }
  }
  catch {
    backupCode.value = ''
    toast.add({ title: $t('auth.mfaInvalidCode'), color: 'error' })
  }
  finally {
    loading.value = false
  }
}
</script>
