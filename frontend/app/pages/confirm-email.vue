<template>
  <div class="flex flex-col items-center justify-center min-h-[60vh] px-4">
    <!-- Confirming token -->
    <div v-if="confirming" class="text-center space-y-4">
      <UIcon name="i-lucide-loader-2" class="size-12 text-primary animate-spin" />
      <p class="text-lg">{{ $t('auth.confirmingEmail') }}</p>
    </div>

    <!-- Success -->
    <div v-else-if="confirmed" class="text-center space-y-4 max-w-md">
      <UIcon name="i-lucide-check-circle" class="size-16 text-green-500" />
      <h1 class="text-2xl font-bold">{{ $t('auth.emailConfirmed') }}</h1>
      <p class="text-muted">{{ $t('auth.emailConfirmedDesc') }}</p>
      <UButton to="/login" size="lg">{{ $t('auth.login') }}</UButton>
    </div>

    <!-- Error (invalid/expired token) -->
    <div v-else-if="errorMsg" class="text-center space-y-4 max-w-md">
      <UIcon name="i-lucide-x-circle" class="size-16 text-red-500" />
      <h1 class="text-2xl font-bold">{{ $t('auth.confirmEmailError') }}</h1>
      <p class="text-muted">{{ errorMsg }}</p>
      <div class="flex gap-2 justify-center">
        <UButton variant="outline" @click="resend" :loading="resending">{{ $t('auth.resendConfirmation') }}</UButton>
        <UButton to="/login">{{ $t('auth.login') }}</UButton>
      </div>
    </div>

    <!-- Awaiting confirmation (after registration) -->
    <div v-else class="text-center space-y-4 max-w-md">
      <UIcon name="i-lucide-mail" class="size-16 text-primary" />
      <h1 class="text-2xl font-bold">{{ $t('auth.checkEmail') }}</h1>
      <p class="text-muted">{{ $t('auth.checkEmailDesc') }}</p>
      <p v-if="email" class="font-medium">{{ email }}</p>
      <div class="pt-4 space-y-2">
        <UButton variant="outline" @click="resend" :loading="resending" block>{{ $t('auth.resendConfirmation') }}</UButton>
        <UButton to="/login" variant="ghost" block>{{ $t('auth.backToLogin') }}</UButton>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'auth', middleware: 'guest' })

const { t: $t } = useI18n()
const route = useRoute()
const toast = useToast()

const confirming = ref(false)
const confirmed = ref(false)
const errorMsg = ref<string | null>(null)
const resending = ref(false)
const email = ref(route.query.email as string || '')

const token = computed(() => route.query.token as string | undefined)

const fetchFn = useRequestFetch()

async function confirmEmail(tokenValue: string) {
  const apiBase = useApiBase()
  confirming.value = true

  try {
    await fetchFn('/auth/confirm-email', {
      baseURL: apiBase,
      method: 'POST',
      body: { token: tokenValue },
    })
    confirmed.value = true
  } catch (err: any) {
    errorMsg.value = err?.data?.error || $t('auth.confirmEmailError')
  } finally {
    confirming.value = false
  }
}

async function resend() {
  if (!email.value) {
    toast.add({ title: $t('auth.enterEmailToResend'), color: 'warning' })
    return
  }

  const apiBase = useApiBase()
  resending.value = true

  try {
    await fetchFn('/auth/resend-confirmation', {
      baseURL: apiBase,
      method: 'POST',
      body: { email: email.value },
    })
    toast.add({ title: $t('auth.confirmationResent'), color: 'success' })
  } catch {
    toast.add({ title: $t('error.generic'), color: 'error' })
  } finally {
    resending.value = false
  }
}

onMounted(() => {
  if (token.value) {
    confirmEmail(token.value)
  }
})
</script>
