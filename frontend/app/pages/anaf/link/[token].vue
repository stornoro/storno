<template>
  <div class="w-full max-w-md mx-auto space-y-6">
      <div class="text-center">
        <p class="text-sm text-(--ui-text-muted)">{{ $t('anaf.linkPageSubtitle') }}</p>
      </div>

      <!-- Loading -->
      <UCard v-if="loading" variant="outline">
        <div class="flex flex-col items-center gap-4 py-8">
          <UIcon name="i-lucide-loader-2" class="animate-spin h-8 w-8 text-(--ui-primary)" />
          <p class="text-sm text-(--ui-text-muted)">{{ $t('common.loading') }}</p>
        </div>
      </UCard>

      <!-- Link expired or invalid -->
      <UCard v-else-if="linkError" variant="outline">
        <div class="flex flex-col items-center gap-4 py-8">
          <UIcon name="i-lucide-x-circle" class="h-12 w-12 text-(--ui-error)" />
          <p class="text-center font-medium">{{ linkError }}</p>
        </div>
      </UCard>

      <!-- Success -->
      <UCard v-else-if="success" variant="outline">
        <div class="flex flex-col items-center gap-4 py-8">
          <UIcon name="i-lucide-check-circle" class="h-12 w-12 text-(--ui-success)" />
          <p class="text-center font-medium">{{ $t('anaf.linkSuccess') }}</p>
          <p class="text-sm text-(--ui-text-muted) text-center">{{ $t('anaf.linkSuccessDescription') }}</p>
        </div>
      </UCard>

      <!-- Ready to connect -->
      <UCard v-else variant="outline">
        <div class="flex flex-col items-center gap-4 py-8">
          <UIcon name="i-lucide-link" class="h-12 w-12 text-(--ui-primary)" />
          <p class="text-center font-medium">{{ $t('anaf.linkReadyTitle') }}</p>
          <p class="text-sm text-(--ui-text-muted) text-center">
            {{ $t('anaf.linkReadyDescription') }}
          </p>
          <UButton
            icon="i-lucide-external-link"
            size="lg"
            :loading="connecting"
            @click="connectAnaf"
          >
            {{ $t('anaf.linkConnectButton') }}
          </UButton>
        </div>
      </UCard>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'minimal' })

const { t: $t } = useI18n()
const route = useRoute()
const config = useRuntimeConfig()

const linkToken = computed(() => route.params.token as string)
const loading = ref(true)
const linkError = ref<string | null>(null)
const success = ref(false)
const connecting = ref(false)

const fetchFn = useRequestFetch()

async function verifyLink() {
  try {
    const apiBase = useApiBase()
    const res = await fetchFn<any>('/v1/anaf/token-links/' + linkToken.value, { baseURL: apiBase })

    if (res.expired) {
      linkError.value = $t('anaf.linkExpiredError')
    } else if (res.used || res.completed) {
      linkError.value = $t('anaf.linkAlreadyUsed')
    }
  }
  catch {
    linkError.value = $t('anaf.linkNotFound')
  }
  finally {
    loading.value = false
  }
}

function connectAnaf() {
  connecting.value = true
  const apiBase = config.public.apiBase as string
  const url = `${apiBase}/connect/anaf?link=${linkToken.value}`
  window.location.href = url
}

// Check for success from redirect
onMounted(async () => {
  const query = route.query
  if (query.success === 'true') {
    loading.value = false
    success.value = true
    return
  }

  await verifyLink()
})
</script>
