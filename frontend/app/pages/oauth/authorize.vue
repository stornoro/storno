<script setup lang="ts">
definePageMeta({ layout: 'minimal', middleware: ['auth'] })

const { t: $t } = useI18n()
useHead({ title: $t('oauth2.authorize.title') })

const route = useRoute()
const { get, post } = useApi()

const clientId = computed(() => route.query.client_id as string)
const redirectUri = computed(() => route.query.redirect_uri as string)
const scope = computed(() => (route.query.scope as string) ?? '')
const state = computed(() => route.query.state as string | undefined)
const codeChallenge = computed(() => route.query.code_challenge as string | undefined)
const codeChallengeMethod = computed(() => route.query.code_challenge_method as string | undefined)
const responseType = computed(() => route.query.response_type as string)

const loading = ref(true)
const submitting = ref(false)
const error = ref<string | null>(null)
const clientInfo = ref<{ name: string, description: string | null, logoUrl: string | null, websiteUrl: string | null } | null>(null)
const requestedScopes = ref<string[]>([])

// Group scopes by category for display
const scopesByCategory = computed(() => {
  const grouped: Record<string, string[]> = {}
  for (const s of requestedScopes.value) {
    const category = s.split('.')[0] ?? 'other'
    if (!grouped[category]) {
      grouped[category] = []
    }
    grouped[category].push(s)
  }
  return grouped
})

function getCategoryLabel(category: string): string {
  const key = `apiKeys.scopeCategories.${category}` as any
  const translated = $t(key)
  return translated !== key ? translated : category
}

async function fetchClientInfo() {
  loading.value = true
  error.value = null

  try {
    const params = new URLSearchParams({
      response_type: responseType.value || 'code',
      client_id: clientId.value,
      redirect_uri: redirectUri.value,
      scope: scope.value,
    })

    const response = await get<{
      data: {
        client: { name: string, description: string | null, logoUrl: string | null, websiteUrl: string | null }
        requestedScopes: string[]
      }
    }>(`/v1/oauth2/authorize?${params.toString()}`)

    clientInfo.value = response.data.client
    requestedScopes.value = response.data.requestedScopes
  }
  catch (err: any) {
    const errorCode = err?.data?.error
    if (errorCode === 'invalid_client') {
      error.value = $t('oauth2.authorize.invalidClient')
    }
    else if (errorCode === 'invalid_redirect_uri') {
      error.value = $t('oauth2.authorize.invalidRedirect')
    }
    else {
      error.value = err?.data?.error_description ?? $t('common.error')
    }
  }
  finally {
    loading.value = false
  }
}

async function onAuthorize(approved: boolean) {
  submitting.value = true
  try {
    const response = await post<{ redirect_uri: string }>('/v1/oauth2/authorize', {
      client_id: clientId.value,
      redirect_uri: redirectUri.value,
      scope: scope.value,
      state: state.value,
      code_challenge: codeChallenge.value,
      code_challenge_method: codeChallengeMethod.value,
      approved,
    })

    window.location.href = response.redirect_uri
  }
  catch {
    error.value = $t('common.error')
    submitting.value = false
  }
}

onMounted(() => {
  if (!clientId.value || !redirectUri.value) {
    error.value = $t('oauth2.authorize.invalidClient')
    loading.value = false
    return
  }
  fetchClientInfo()
})
</script>

<template>
  <div class="min-h-svh flex items-center justify-center bg-muted p-4">
    <div class="w-full max-w-md">
      <!-- Loading -->
      <UCard v-if="loading" class="text-center">
        <div class="py-8 flex flex-col items-center gap-4">
          <UIcon name="i-lucide-loader-2" class="size-8 animate-spin text-muted" />
          <p class="text-sm text-(--ui-text-muted)">{{ $t('common.loading') }}...</p>
        </div>
      </UCard>

      <!-- Error -->
      <UCard v-else-if="error" class="text-center">
        <div class="py-8 flex flex-col items-center gap-4">
          <UIcon name="i-lucide-alert-circle" class="size-12 text-error" />
          <p class="text-sm text-(--ui-text-muted)">{{ error }}</p>
        </div>
      </UCard>

      <!-- Consent screen -->
      <UCard v-else-if="clientInfo">
        <div class="space-y-6">
          <!-- App info -->
          <div class="text-center space-y-3">
            <div v-if="clientInfo.logoUrl" class="flex justify-center">
              <img :src="clientInfo.logoUrl" :alt="clientInfo.name" class="size-16 rounded-xl" />
            </div>
            <div v-else class="flex justify-center">
              <div class="size-16 rounded-xl bg-primary/10 flex items-center justify-center">
                <UIcon name="i-lucide-app-window" class="size-8 text-primary" />
              </div>
            </div>
            <h2 class="text-lg font-semibold text-(--ui-text-highlighted)">
              {{ $t('oauth2.authorize.requestsAccess', { app: clientInfo.name }) }}
            </h2>
            <p v-if="clientInfo.websiteUrl" class="text-sm text-(--ui-text-muted)">
              {{ clientInfo.websiteUrl }}
            </p>
          </div>

          <!-- Requested permissions -->
          <div>
            <h3 class="text-sm font-medium text-(--ui-text-highlighted) mb-3">{{ $t('oauth2.authorize.permissions') }}</h3>
            <div class="space-y-2">
              <div v-for="(scopes, category) in scopesByCategory" :key="category" class="rounded-lg border border-default p-3">
                <div class="text-sm font-medium text-(--ui-text-highlighted) mb-1">{{ getCategoryLabel(category as string) }}</div>
                <div class="space-y-0.5">
                  <div v-for="s in scopes" :key="s" class="text-xs text-(--ui-text-muted) flex items-center gap-1.5">
                    <UIcon name="i-lucide-check" class="size-3 text-success" />
                    {{ s }}
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Security note -->
          <div class="rounded-lg bg-muted p-3">
            <p class="text-xs text-(--ui-text-muted)">{{ $t('oauth2.authorize.securityNote') }}</p>
          </div>

          <!-- Actions -->
          <div class="flex gap-3">
            <UButton
              class="flex-1"
              variant="outline"
              color="neutral"
              :loading="submitting"
              @click="onAuthorize(false)"
            >
              {{ $t('oauth2.authorize.deny') }}
            </UButton>
            <UButton
              class="flex-1"
              :loading="submitting"
              @click="onAuthorize(true)"
            >
              {{ $t('oauth2.authorize.approve') }}
            </UButton>
          </div>
        </div>
      </UCard>
    </div>
  </div>
</template>
