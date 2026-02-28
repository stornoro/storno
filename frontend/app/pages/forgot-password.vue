<template>
  <div>
    <div class="mb-8">
      <h2 class="text-2xl font-bold text-(--ui-text)">{{ $t('auth.forgotPasswordTitle') }}</h2>
      <p class="mt-1 text-sm text-(--ui-text-muted)">{{ $t('auth.forgotPasswordDescription') }}</p>
    </div>

    <!-- Forgot password form -->
    <UForm :schema="schema" :state="state" class="space-y-5" @submit="onSubmit">
      <UFormField :label="$t('auth.email')" name="email">
        <UInput
          v-model="state.email"
          type="email"
          placeholder="email@exemplu.ro"
          size="xl"
          autofocus
          class="w-full"
          :ui="{ base: 'rounded-xl shadow-sm' }"
        />
      </UFormField>

      <NuxtTurnstile ref="turnstileRef" v-model="turnstileToken" />

      <UButton type="submit" :loading="loading" :disabled="!turnstileToken" size="xl" block :ui="{ base: 'rounded-xl justify-center font-semibold' }">
        {{ $t('auth.sendResetLink') }}
      </UButton>
    </UForm>

    <p class="mt-8 text-center text-sm text-(--ui-text-muted)">
      <NuxtLink to="/login" class="text-primary font-semibold hover:underline">
        {{ $t('auth.backToLogin') }}
      </NuxtLink>
    </p>
  </div>
</template>

<script setup lang="ts">
import { z } from 'zod'

definePageMeta({ layout: 'auth', middleware: 'guest' })

const { t: $t } = useI18n()
useHead({ title: $t('auth.forgotPasswordTitle') })

const loading = ref(false)
const turnstileToken = ref('')
const turnstileRef = ref()

const state = reactive({
  email: '',
})

const schema = z.object({
  email: z.string({ required_error: 'Adresa de email este obligatorie' }).email('Adresa de email nu este valida'),
})

const fetchFn = useRequestFetch()

async function onSubmit() {
  loading.value = true
  try {
    const apiBase = useApiBase()
    await fetchFn('/auth/forgot-password', { baseURL: apiBase, method: 'POST', body: { email: state.email, turnstileToken: turnstileToken.value } })
    useToast().add({ title: $t('auth.resetSent'), color: 'success' })
  } catch (e: any) {
    turnstileRef.value?.reset()
    turnstileToken.value = ''
    useToast().add({ title: $t('auth.resetError'), description: translateApiError(e.message), color: 'error' })
  } finally {
    loading.value = false
  }
}
</script>
