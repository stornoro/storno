<template>
  <div class="flex flex-col items-center text-center">
    <!-- Processing -->
    <div v-if="processing" class="text-center space-y-4">
      <UIcon name="i-lucide-loader-2" class="size-12 text-primary animate-spin" />
      <p class="text-lg">{{ $t('unsubscribe.processing') }}</p>
    </div>

    <!-- Success -->
    <div v-else-if="success" class="text-center space-y-4 max-w-md">
      <UIcon name="i-lucide-check-circle" class="size-16 text-green-500" />
      <h1 class="text-2xl font-bold">{{ $t('unsubscribe.successTitle') }}</h1>
      <p class="text-muted">{{ $t('unsubscribe.successDescription', { email: resultEmail, category: resultCategory }) }}</p>
    </div>

    <!-- Error -->
    <div v-else-if="errorMsg" class="text-center space-y-4 max-w-md">
      <UIcon name="i-lucide-x-circle" class="size-16 text-red-500" />
      <h1 class="text-2xl font-bold">{{ $t('unsubscribe.errorTitle') }}</h1>
      <p class="text-muted">{{ errorMsg }}</p>
    </div>

    <!-- Missing params -->
    <div v-else class="text-center space-y-4 max-w-md">
      <UIcon name="i-lucide-alert-triangle" class="size-16 text-yellow-500" />
      <h1 class="text-2xl font-bold">{{ $t('unsubscribe.invalidTitle') }}</h1>
      <p class="text-muted">{{ $t('unsubscribe.invalidDescription') }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'minimal' })

const { t: $t } = useI18n()
const route = useRoute()

const processing = ref(false)
const success = ref(false)
const errorMsg = ref<string | null>(null)
const resultEmail = ref('')
const resultCategory = ref('')

const fetchFn = useRequestFetch()

async function unsubscribe(token: string, sig: string) {
  const apiBase = useApiBase()
  processing.value = true

  try {
    const res = await fetchFn('/v1/unsubscribe', {
      baseURL: apiBase,
      method: 'POST',
      body: { token, sig },
    }) as { email: string; category: string }
    resultEmail.value = res.email
    resultCategory.value = res.category
    success.value = true
  } catch (err: any) {
    errorMsg.value = err?.data?.error || $t('unsubscribe.errorGeneric')
  } finally {
    processing.value = false
  }
}

onMounted(() => {
  const token = route.query.token as string | undefined
  const sig = route.query.sig as string | undefined

  if (token && sig) {
    unsubscribe(token, sig)
  }
})
</script>
