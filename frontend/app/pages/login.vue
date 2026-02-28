<template>
  <div>
    <div class="mb-8">
      <h2 class="text-2xl font-bold text-(--ui-text)">{{ $t('auth.loginTitle') }}</h2>
      <p class="mt-1 text-sm text-(--ui-text-muted)">{{ $t('auth.loginDescription') }}</p>
    </div>

    <!-- Login form -->
    <UForm :schema="schema" :state="state" class="space-y-5" @submit="onSubmit">
      <UFormField :label="$t('auth.email')" name="email">
        <UInput
          v-model="state.email"
          type="email"
          :placeholder="$t('auth.email')"
          size="xl"
          autofocus
          class="w-full"
          :ui="{
            base: 'rounded-xl shadow-sm',
          }"
        />
      </UFormField>

      <div>
        <div class="flex items-center justify-between mb-1.5">
          <label class="text-sm font-medium text-(--ui-text)">{{ $t('auth.password') }}</label>
          <NuxtLink to="/forgot-password" class="text-sm text-primary font-medium hover:underline">
            {{ $t('auth.forgotPassword') }}
          </NuxtLink>
        </div>
        <UFormField name="password">
          <UInput
            v-model="state.password"
            :type="showPassword ? 'text' : 'password'"
            :placeholder="$t('auth.password')"
            size="xl"
            class="w-full"
            :ui="{
              base: 'rounded-xl shadow-sm',
              trailing: 'pe-1.5',
            }"
          >
            <template #trailing>
              <UButton
                type="button"
                :icon="showPassword ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                color="neutral"
                variant="ghost"
                size="sm"
                square
                @click="showPassword = !showPassword"
              />
            </template>
          </UInput>
        </UFormField>
      </div>

      <NuxtTurnstile ref="turnstileRef" v-model="turnstileToken" />

      <UButton type="submit" :loading="loading" :disabled="!turnstileToken" size="xl" block :ui="{ base: 'rounded-xl justify-center font-semibold' }">
        {{ $t('auth.login') }}
      </UButton>
    </UForm>

    <!-- Social providers -->
    <div v-if="providers.length" class="mt-6">
      <div class="relative my-6">
        <div class="absolute inset-0 flex items-center">
          <div class="w-full border-t border-(--ui-border)" />
        </div>
        <div class="relative flex justify-center text-xs">
          <span class="bg-(--ui-bg) px-3 text-(--ui-text-muted) uppercase tracking-wider">{{ $t('auth.orSeparator') }}</span>
        </div>
      </div>
      <div class="space-y-2">
        <UButton
          v-for="provider in providers"
          :key="provider.label"
          :icon="provider.icon"
          :label="provider.label"
          color="neutral"
          variant="outline"
          size="lg"
          block
          @click="provider.onClick"
        />
      </div>
    </div>

    <p class="mt-8 text-center text-sm text-(--ui-text-muted)">
      {{ $t('auth.noAccount') }}
      <NuxtLink to="/register" class="text-primary font-semibold hover:underline">
        {{ $t('auth.register') }}
      </NuxtLink>
    </p>
  </div>
</template>

<script setup lang="ts">
import { z } from 'zod'

definePageMeta({ layout: 'auth', middleware: 'guest' })

const { t: $t } = useI18n()
useHead({ title: $t('auth.login') })
const authStore = useAuthStore()
const googleOneTap = useGoogleOneTap()
const passkey = usePasskey()

const { resolve: resolvePostLogin } = usePostLoginRoute()
const loading = ref(false)
const showPassword = ref(false)
const turnstileToken = ref('')
const turnstileRef = ref()

const state = reactive({
  email: '',
  password: '',
})

const schema = z.object({
  email: z.string({ required_error: 'Adresa de email este obligatorie' }).email('Adresa de email nu este valida'),
  password: z.string({ required_error: 'Parola este obligatorie' }).min(6, 'Parola trebuie sa aiba cel putin 6 caractere'),
})

const config = useRuntimeConfig()

const mounted = ref(false)
onMounted(() => { mounted.value = true })

const providers = computed(() => {
  if (!mounted.value) return []
  const items: any[] = []
  if (config.public.googleClientId) {
    items.push({
      label: $t('auth.continueWithGoogle'),
      icon: 'i-simple-icons-google',
      onClick: () => googleOneTap.signIn(),
    })
  }
  if (passkey.isSupported.value) {
    items.push({
      label: $t('auth.continueWithPasskey'),
      icon: 'i-lucide-fingerprint',
      onClick: onPasskeyLogin,
    })
  }
  return items
})

async function onPasskeyLogin() {
  const success = await passkey.authenticate()
  if (success) {
    await navigateTo(await resolvePostLogin())
  } else {
    useToast().add({ title: $t('auth.passkeyError'), color: 'error' })
  }
}

async function onSubmit() {
  loading.value = true
  try {
    const result = await authStore.login(state.email, state.password, turnstileToken.value)
    if (result === 'mfa_required') {
      const redirect = useRoute().query.redirect as string | undefined
      await navigateTo({ path: '/mfa-verify', query: redirect ? { redirect } : undefined })
    } else if (result === true) {
      await navigateTo(await resolvePostLogin())
    } else if (authStore.error === 'login.mail.not_confirmed') {
      await navigateTo({ path: '/confirm-email', query: { email: state.email } })
    } else {
      turnstileRef.value?.reset()
      turnstileToken.value = ''
      useToast().add({ title: authStore.error || $t('auth.loginError'), color: 'error' })
    }
  } catch {
    turnstileRef.value?.reset()
    turnstileToken.value = ''
    useToast().add({ title: $t('auth.loginError'), color: 'error' })
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  googleOneTap.initialize()
})
</script>
