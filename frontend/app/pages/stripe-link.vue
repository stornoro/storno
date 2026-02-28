<script setup lang="ts">
definePageMeta({
  middleware: ['auth'],
  layout: 'auth',
})

const { post } = useApi()
const code = ref<string | null>(null)
const expiresIn = ref(300)
const loading = ref(true)
const error = ref<string | null>(null)
const countdown = ref(0)
let timer: ReturnType<typeof setInterval> | null = null

async function generateCode() {
  loading.value = true
  error.value = null
  code.value = null

  try {
    const res = await post<{ code: string; expires_in: number }>('/v1/stripe-app/linking-code')
    code.value = res.code
    expiresIn.value = res.expires_in
    countdown.value = res.expires_in
    startCountdown()
  }
  catch (e: any) {
    error.value = e?.data?.message || 'Eroare la generarea codului'
  }
  finally {
    loading.value = false
  }
}

function startCountdown() {
  if (timer) clearInterval(timer)
  timer = setInterval(() => {
    countdown.value--
    if (countdown.value <= 0) {
      if (timer) clearInterval(timer)
      code.value = null
    }
  }, 1000)
}

function formatTime(seconds: number) {
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return `${m}:${s.toString().padStart(2, '0')}`
}

onMounted(() => {
  generateCode()
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
  <div class="flex min-h-screen items-center justify-center p-4">
    <div class="w-full max-w-md space-y-6">
      <div class="text-center">
        <img
          src="/logo.png"
          alt="Storno.ro"
          class="mx-auto h-12 w-auto"
        >
        <h1 class="mt-4 text-2xl font-bold text-gray-900 dark:text-white">
          Conectare Stripe App
        </h1>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
          Introdu acest cod in aplicatia Stripe pentru a conecta contul tau Storno.ro
        </p>
      </div>

      <UCard>
        <div class="space-y-6 text-center">
          <div v-if="loading" class="py-8">
            <UIcon name="i-lucide-loader-2" class="mx-auto h-8 w-8 animate-spin text-primary" />
            <p class="mt-2 text-sm text-gray-500">Se genereaza codul...</p>
          </div>

          <div v-else-if="error" class="py-4">
            <UAlert
              color="error"
              variant="subtle"
              :title="error"
            />
            <UButton
              class="mt-4"
              @click="generateCode"
            >
              Incearca din nou
            </UButton>
          </div>

          <div v-else-if="code" class="py-4">
            <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
              Codul tau:
            </p>
            <div class="mt-3 font-mono text-5xl font-bold tracking-[0.3em] text-primary">
              {{ code }}
            </div>
            <div class="mt-4 flex items-center justify-center gap-2 text-sm text-gray-500 dark:text-gray-400">
              <UIcon name="i-lucide-clock" class="h-4 w-4" />
              <span>Expira in {{ formatTime(countdown) }}</span>
            </div>
            <UDivider class="my-4" />
            <div class="text-left text-sm text-gray-600 dark:text-gray-300">
              <p class="font-medium">Pasi:</p>
              <ol class="mt-2 list-inside list-decimal space-y-1">
                <li>Deschide aplicatia Storno.ro din Stripe Dashboard</li>
                <li>Mergi la Settings</li>
                <li>Introdu codul de mai sus</li>
                <li>Apasa "Conecteaza"</li>
              </ol>
            </div>
          </div>

          <div v-else class="py-4">
            <p class="text-sm text-gray-500">
              Codul a expirat.
            </p>
            <UButton
              class="mt-4"
              @click="generateCode"
            >
              Genereaza cod nou
            </UButton>
          </div>
        </div>
      </UCard>
    </div>
  </div>
</template>
