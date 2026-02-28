<template>
  <div class="w-full max-w-sm mx-auto text-center space-y-4">
      <!-- Loading -->
      <template v-if="status === 'loading'">
        <UIcon name="i-lucide-loader-2" class="animate-spin h-10 w-10 mx-auto text-(--ui-primary)" />
        <p class="text-(--ui-text-muted)">{{ $t('anafCallback.loading') }}</p>
      </template>

      <!-- Success -->
      <template v-else-if="status === 'success'">
        <UIcon name="i-lucide-check-circle" class="h-10 w-10 mx-auto text-(--ui-success)" />
        <p class="font-medium">{{ $t('anafCallback.success') }}</p>
        <UButton variant="outline" @click="closeWindow">
          {{ $t('anafCallback.close') }}
        </UButton>
      </template>

      <!-- Error -->
      <template v-else>
        <UIcon name="i-lucide-alert-circle" class="h-10 w-10 mx-auto text-(--ui-error)" />
        <p class="font-medium text-(--ui-error)">{{ $t('anafCallback.error') }}</p>
        <p v-if="errorMessage" class="text-sm text-(--ui-text-muted)">{{ errorMessage }}</p>
        <UButton variant="outline" color="error" @click="closeWindow">
          {{ $t('anafCallback.tryAgain') }}
        </UButton>
      </template>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'minimal' })

const { t: $t } = useI18n()
const route = useRoute()

const status = ref<'loading' | 'success' | 'error'>('loading')
const errorMessage = ref('')

function closeWindow() {
  window.close()
}

onMounted(async () => {
  const code = route.query.code as string | undefined
  const stateParam = route.query.state as string | undefined

  if (!code) {
    status.value = 'error'
    errorMessage.value = 'Missing authorization code.'
    return
  }

  // Check if this is a link-based flow (unauthenticated)
  let linkToken: string | null = null
  if (stateParam) {
    try {
      const state = JSON.parse(stateParam)
      linkToken = state.link ?? null
    }
    catch {
      // not JSON, ignore
    }
  }

  try {
    let result: any

    if (linkToken) {
      // Link-based flow — call unauthenticated endpoint
      const apiBase = useApiBase()
      const fetchFn = useRequestFetch()
      result = await fetchFn('/anaf/link-callback', {
        baseURL: apiBase,
        params: { code, state: stateParam },
      })
    }
    else {
      // Standard authenticated flow
      const { apiFetch } = useApi()
      result = await apiFetch('/account/anaf', {
        method: 'PATCH',
        params: { code },
      })
    }

    // Check response body for failure status (safety net)
    if (result?.status === 'fail') {
      status.value = 'error'
      errorMessage.value = result.message || ''
      return
    }

    // Notify the opener window that the token was saved
    if (window.opener) {
      window.opener.postMessage({ type: 'anaf-token-saved' }, '*')
    }

    status.value = 'success'

    // Auto-close after 2 seconds
    setTimeout(() => {
      window.close()
    }, 2000)
  }
  catch (err: any) {
    status.value = 'error'
    errorMessage.value = err?.data?.error || err?.data?.message || ''
  }
})
</script>
