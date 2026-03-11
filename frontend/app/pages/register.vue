<template>
  <div>
    <div class="mb-8">
      <h2 class="text-2xl font-bold text-(--ui-text)">{{ $t('auth.registerTitle') }}</h2>
      <p class="mt-1 text-sm text-(--ui-text-muted)">{{ $t('auth.registerDescription') }}</p>
    </div>

    <!-- Register form -->
    <UForm :schema="schema" :state="state" class="space-y-5" @submit="onSubmit">
      <div class="grid grid-cols-2 gap-4">
        <UFormField :label="$t('auth.firstName')" name="firstName">
          <UInput
            v-model="state.firstName"
            :placeholder="$t('auth.firstName')"
            size="xl"
            class="w-full"
            autofocus
            :ui="{ base: 'rounded-xl shadow-sm' }"
          />
        </UFormField>

        <UFormField :label="$t('auth.lastName')" name="lastName">
          <UInput
            v-model="state.lastName"
            :placeholder="$t('auth.lastName')"
            size="xl"
            class="w-full"
            :ui="{ base: 'rounded-xl shadow-sm' }"
          />
        </UFormField>
      </div>

      <UFormField :label="$t('auth.email')" name="email">
        <UInput
          v-model="state.email"
          type="email"
          placeholder="email@example.com"
          size="xl"
          class="w-full"
          :ui="{ base: 'rounded-xl shadow-sm' }"
        />
      </UFormField>

      <UFormField :label="$t('auth.password')" name="password">
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

      <UFormField :label="$t('auth.confirmPassword')" name="confirmPassword">
        <UInput
          v-model="state.confirmPassword"
          :type="showPassword ? 'text' : 'password'"
          :placeholder="$t('auth.confirmPassword')"
          size="xl"
          class="w-full"
          :ui="{ base: 'rounded-xl shadow-sm' }"
        />
      </UFormField>

      <NuxtTurnstile ref="turnstileRef" v-model="turnstileToken" />

      <UButton type="submit" :loading="loading" :disabled="!turnstileToken" size="xl" block :ui="{ base: 'rounded-xl justify-center font-semibold' }">
        {{ $t('auth.register') }}
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
      {{ $t('auth.hasAccount') }}
      <NuxtLink to="/login" class="text-primary font-semibold hover:underline">
        {{ $t('auth.login') }}
      </NuxtLink>
    </p>
  </div>
</template>

<script setup lang="ts">
import { z } from 'zod'

definePageMeta({ layout: 'auth', middleware: 'guest' })

const { t: $t } = useI18n()
useHead({ title: $t('auth.register') })
const authStore = useAuthStore()
const router = useRouter()
const googleOneTap = useGoogleOneTap()

const loading = ref(false)
const showPassword = ref(false)
const turnstileToken = ref('')
const turnstileRef = ref()

const state = reactive({
  firstName: '',
  lastName: '',
  email: '',
  password: '',
  confirmPassword: '',
})

const schema = computed(() => z.object({
  firstName: z.string({ required_error: $t('validation.required') }).min(2, $t('validation.nameMin')),
  lastName: z.string({ required_error: $t('validation.required') }).min(2, $t('validation.nameMin')),
  email: z.string({ required_error: $t('validation.required') }).email($t('validation.emailInvalid')),
  password: z.string({ required_error: $t('validation.required') }).min(8, $t('validation.minLength', { min: 8 })),
  confirmPassword: z.string({ required_error: $t('validation.required') }).min(8, $t('validation.minLength', { min: 8 })),
}).refine(d => d.password === d.confirmPassword, {
  message: $t('validation.passwordMismatch'),
  path: ['confirmPassword'],
}))

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
  return items
})

async function onSubmit() {
  loading.value = true
  try {
    const success = await authStore.register({
      email: state.email,
      password: state.password,
      firstName: state.firstName,
      lastName: state.lastName,
      turnstileToken: turnstileToken.value,
    })
    if (success) {
      router.push({ path: '/confirm-email', query: { registered: '1', email: state.email } })
    } else {
      turnstileRef.value?.reset()
      turnstileToken.value = ''
      const msg = authStore.error?.startsWith('auth.') ? $t(authStore.error) : (authStore.error || $t('auth.registerError'))
      useToast().add({ title: msg, color: 'error' })
    }
  } catch (e: any) {
    turnstileRef.value?.reset()
    turnstileToken.value = ''
    useToast().add({ title: $t('auth.registerError'), description: translateApiError(e.message), color: 'error' })
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  googleOneTap.initialize()
})
</script>
