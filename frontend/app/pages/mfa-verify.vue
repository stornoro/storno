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

    <!-- Passkey -->
    <div v-if="activeTab === 'passkey'" class="space-y-5">
      <div class="text-center py-4">
        <UIcon name="i-lucide-fingerprint" class="w-12 h-12 mx-auto mb-3 text-(--ui-text-muted)" />
        <p class="text-sm text-(--ui-text-muted) mb-4">{{ $t('auth.mfaPasskeyPrompt') }}</p>
      </div>

      <UButton
        :loading="loading"
        size="xl"
        block
        :ui="{ base: 'rounded-xl justify-center font-semibold' }"
        @click="onVerifyPasskey"
      >
        {{ $t('auth.mfaPasskeyVerify') }}
      </UButton>
    </div>

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
import { bufferToBase64url, base64urlToBuffer } from '~/utils/webauthn'

definePageMeta({ layout: 'auth', middleware: 'guest' })

const { t: $t } = useI18n()
useHead({ title: $t('auth.mfaTitle') })
const authStore = useAuthStore()
const toast = useToast()

const { resolve: resolvePostLogin } = usePostLoginRoute()
const loading = ref(false)
const totpCode = ref('')
const backupCode = ref('')

const hasPasskey = computed(() => authStore.mfaMethods.includes('passkey'))
const activeTab = ref(hasPasskey.value ? 'passkey' : 'totp')

const tabs = computed(() => {
  const items: { label: string; value: string }[] = []
  if (hasPasskey.value) {
    items.push({ label: $t('auth.mfaPasskeyTab'), value: 'passkey' })
  }
  if (authStore.mfaMethods.includes('totp')) {
    items.push({ label: $t('auth.mfaTotpTab'), value: 'totp' })
  }
  if (authStore.mfaMethods.includes('backup_code')) {
    items.push({ label: $t('auth.mfaBackupTab'), value: 'backup' })
  }
  return items
})

// Redirect to login if no MFA challenge pending
onMounted(() => {
  if (!authStore.mfaPending || !authStore.mfaToken) {
    navigateTo('/login')
    return
  }
  // Auto-trigger passkey prompt
  if (hasPasskey.value) {
    onVerifyPasskey()
  }
})

async function onVerifyPasskey() {
  loading.value = true
  try {
    const apiBase = useApiBase()
    const fetchFn = useRequestFetch()

    // 1. Get passkey assertion options from server
    const options = await fetchFn<any>('/auth/mfa/passkey/options', {
      baseURL: apiBase,
      method: 'POST',
      body: { mfaToken: authStore.mfaToken },
    })

    // 2. Build browser WebAuthn request
    const publicKeyOptions: PublicKeyCredentialRequestOptions = {
      challenge: base64urlToBuffer(options.challenge),
      rpId: options.rpId,
      timeout: options.timeout,
      userVerification: options.userVerification || 'preferred',
      allowCredentials: (options.allowCredentials || []).map((c: any) => ({
        type: c.type,
        id: base64urlToBuffer(c.id),
        transports: c.transports,
      })),
    }

    // 3. Get assertion from browser
    const credential = await navigator.credentials.get({
      publicKey: publicKeyOptions,
    }) as PublicKeyCredential

    if (!credential) throw new Error('Authentication cancelled')

    const assertionResponse = credential.response as AuthenticatorAssertionResponse

    // 4. Encode response for server
    const credentialData = {
      id: bufferToBase64url(credential.rawId),
      rawId: bufferToBase64url(credential.rawId),
      type: credential.type,
      response: {
        clientDataJSON: bufferToBase64url(assertionResponse.clientDataJSON),
        authenticatorData: bufferToBase64url(assertionResponse.authenticatorData),
        signature: bufferToBase64url(assertionResponse.signature),
        userHandle: assertionResponse.userHandle
          ? bufferToBase64url(assertionResponse.userHandle)
          : null,
      },
    }

    // 5. Submit to auth store
    const success = await authStore.completeMfaWithPasskey(credentialData)
    if (success) {
      await navigateTo(await resolvePostLogin())
    } else {
      toast.add({ title: $t('auth.mfaInvalidCode'), color: 'error' })
    }
  }
  catch {
    toast.add({ title: $t('auth.mfaPasskeyRetry'), color: 'error' })
  }
  finally {
    loading.value = false
  }
}

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
