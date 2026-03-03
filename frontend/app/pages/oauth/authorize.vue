<script setup lang="ts">
definePageMeta({ layout: 'minimal', middleware: ['auth'] })

const { t: $t } = useI18n()
useHead({ title: $t('oauth2.authorize.title') })

const route = useRoute()
const { get, post } = useApi()
const authStore = useAuthStore()

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
const showMfaModal = ref(false)

const scopeCategoryIcons: Record<string, string> = {
  company: 'i-lucide-building-2',
  client: 'i-lucide-users',
  product: 'i-lucide-package',
  invoice: 'i-lucide-file-text',
  series: 'i-lucide-hash',
  payment: 'i-lucide-credit-card',
  efactura: 'i-lucide-send',
  settings: 'i-lucide-settings',
  org: 'i-lucide-landmark',
  export: 'i-lucide-download',
  oauth2_app: 'i-lucide-app-window',
}

// Group scopes by category for display
const permissionGroups = computed(() => {
  const grouped: Record<string, string[]> = {}
  for (const s of requestedScopes.value) {
    const category = s.split('.')[0] ?? 'other'
    if (!grouped[category]) {
      grouped[category] = []
    }
    grouped[category].push(s)
  }
  return Object.entries(grouped).map(([category, scopes]) => {
    const key = `apiKeys.scopeCategories.${category}` as any
    const translated = $t(key)
    const label = translated !== key ? translated : category
    return {
      label,
      icon: scopeCategoryIcons[category] ?? 'i-lucide-shield-check',
      value: category,
      scopes,
    }
  })
})

const expandedCategories = ref<Set<string>>(new Set())

function toggleCategory(category: string) {
  if (expandedCategories.value.has(category)) {
    expandedCategories.value.delete(category)
  }
  else {
    expandedCategories.value.add(category)
  }
}

const redirectDomain = computed(() => {
  try {
    return new URL(redirectUri.value).hostname
  }
  catch {
    return redirectUri.value
  }
})

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
  if (approved) {
    // Check if step-up MFA is needed
    try {
      const challengeResult = await post<{ mfa_required: boolean }>('/v1/mfa/challenge', {})
      if (challengeResult.mfa_required) {
        showMfaModal.value = true
        return
      }
    } catch {
      // If challenge endpoint fails, proceed without MFA (user may not have MFA)
    }
  }
  await doAuthorize(approved)
}

async function onMfaVerified(verificationToken: string) {
  await doAuthorize(true, verificationToken)
}

async function doAuthorize(approved: boolean, verificationToken?: string) {
  submitting.value = true
  try {
    const body: Record<string, any> = {
      client_id: clientId.value,
      redirect_uri: redirectUri.value,
      scope: scope.value,
      state: state.value,
      code_challenge: codeChallenge.value,
      code_challenge_method: codeChallengeMethod.value,
      approved,
    }
    if (verificationToken) {
      body.verification_token = verificationToken
    }

    const response = await post<{ redirect_uri: string }>('/v1/oauth2/authorize', body)
    window.location.href = response.redirect_uri
  }
  catch (err: any) {
    if (err?.data?.error === 'mfa_required') {
      showMfaModal.value = true
      submitting.value = false
      return
    }
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
  <div>
    <!-- Loading -->
    <div v-if="loading" class="py-8 flex flex-col items-center gap-4">
      <UIcon name="i-lucide-loader-2" class="size-8 animate-spin text-(--ui-text-muted)" />
      <p class="text-sm text-(--ui-text-muted)">{{ $t('common.loading') }}...</p>
    </div>

    <!-- Error -->
    <UCard v-else-if="error" variant="outline">
      <div class="py-6 flex flex-col items-center gap-4">
        <div class="size-12 rounded-full bg-error/10 flex items-center justify-center">
          <UIcon name="i-lucide-alert-circle" class="size-6 text-error" />
        </div>
        <p class="text-sm text-(--ui-text-muted) text-center">{{ error }}</p>
      </div>
    </UCard>

    <!-- Consent screen -->
    <UCard v-else-if="clientInfo" variant="outline">
      <div class="space-y-5">
        <!-- App-to-app connection -->
        <div class="flex items-center justify-center gap-4">
          <!-- App logo -->
          <div v-if="clientInfo.logoUrl" class="size-14 rounded-xl overflow-hidden ring-1 ring-(--ui-border) shrink-0">
            <img :src="clientInfo.logoUrl" :alt="clientInfo.name" class="size-full object-cover" />
          </div>
          <div v-else class="size-14 rounded-xl bg-primary/10 flex items-center justify-center ring-1 ring-(--ui-border) shrink-0">
            <UIcon name="i-lucide-app-window" class="size-7 text-primary" />
          </div>

          <!-- Connection indicator -->
          <div class="flex items-center gap-1 text-(--ui-text-dimmed)">
            <div class="w-4 h-px bg-(--ui-border)" />
            <UIcon name="i-lucide-arrow-left-right" class="size-4" />
            <div class="w-4 h-px bg-(--ui-border)" />
          </div>

          <!-- Storno logo -->
          <div class="size-14 rounded-xl overflow-hidden ring-1 ring-(--ui-border) shrink-0 flex items-center justify-center bg-(--ui-bg)">
            <img src="/logo.png" alt="Storno.ro" class="h-8 w-auto" />
          </div>
        </div>

        <!-- Title -->
        <div class="text-center space-y-1">
          <h2 class="text-lg font-semibold text-(--ui-text-highlighted)">
            {{ $t('oauth2.authorize.requestsAccess', { app: clientInfo.name }) }}
          </h2>
          <p v-if="clientInfo.websiteUrl" class="text-xs text-(--ui-text-muted)">
            {{ clientInfo.websiteUrl }}
          </p>
        </div>

        <!-- Logged-in user -->
        <div class="flex items-center gap-2 rounded-lg bg-(--ui-bg-elevated) px-3 py-2">
          <UAvatar :text="authStore.initials" size="xs" />
          <div class="min-w-0 flex-1">
            <p class="text-xs font-medium text-(--ui-text-highlighted) truncate">{{ authStore.displayName }}</p>
            <p v-if="authStore.user?.email" class="text-xs text-(--ui-text-muted) truncate">{{ authStore.user.email }}</p>
          </div>
        </div>

        <!-- Requested permissions -->
        <div>
          <h3 class="text-xs font-medium text-(--ui-text-muted) uppercase tracking-wide mb-2">
            {{ $t('oauth2.authorize.permissions') }}
          </h3>
          <div class="rounded-lg border border-(--ui-border) divide-y divide-(--ui-border) overflow-hidden">
            <div v-for="group in permissionGroups" :key="group.value">
              <button
                class="w-full flex items-center gap-2.5 px-3 py-2.5 text-left hover:bg-(--ui-bg-elevated)/50 transition-colors"
                @click="toggleCategory(group.value)"
              >
                <UIcon :name="group.icon" class="size-4 text-primary shrink-0" />
                <span class="text-sm font-medium text-(--ui-text-highlighted) flex-1">{{ group.label }}</span>
                <UBadge variant="subtle" size="sm" color="neutral">{{ group.scopes.length }}</UBadge>
                <UIcon
                  name="i-lucide-chevron-down"
                  class="size-4 text-(--ui-text-dimmed) shrink-0 transition-transform duration-200"
                  :class="expandedCategories.has(group.value) ? 'rotate-180' : ''"
                />
              </button>
              <Transition
                enter-active-class="transition-all duration-200 ease-out"
                leave-active-class="transition-all duration-150 ease-in"
                enter-from-class="opacity-0 max-h-0"
                enter-to-class="opacity-100 max-h-48"
                leave-from-class="opacity-100 max-h-48"
                leave-to-class="opacity-0 max-h-0"
              >
                <div v-show="expandedCategories.has(group.value)" class="overflow-hidden">
                  <div class="px-3 pb-2.5 pt-0.5 ml-6.5 space-y-1">
                    <div v-for="s in group.scopes" :key="s" class="text-xs text-(--ui-text-muted) flex items-center gap-1.5">
                      <UIcon name="i-lucide-check" class="size-3 text-success shrink-0" />
                      <span class="font-mono">{{ s }}</span>
                    </div>
                  </div>
                </div>
              </Transition>
            </div>
          </div>
        </div>

        <!-- Security note -->
        <div class="flex items-start gap-2 rounded-lg bg-(--ui-bg-elevated) p-3">
          <UIcon name="i-lucide-info" class="size-3.5 text-(--ui-text-dimmed) shrink-0 mt-0.5" />
          <p class="text-xs text-(--ui-text-muted) leading-relaxed">
            {{ $t('oauth2.authorize.securityNote') }}
          </p>
        </div>

        <!-- Redirect notice -->
        <p class="text-center text-xs text-(--ui-text-dimmed)">
          {{ $t('oauth2.authorize.redirectNotice', { domain: redirectDomain }) }}
        </p>

        <!-- Actions -->
        <div class="flex gap-3">
          <UButton
            class="flex-1"
            variant="outline"
            color="neutral"
            size="lg"
            :loading="submitting"
            :ui="{ base: 'rounded-xl justify-center font-semibold' }"
            @click="onAuthorize(false)"
          >
            {{ $t('oauth2.authorize.deny') }}
          </UButton>
          <UButton
            class="flex-1"
            size="lg"
            :loading="submitting"
            :ui="{ base: 'rounded-xl justify-center font-semibold' }"
            @click="onAuthorize(true)"
          >
            {{ $t('oauth2.authorize.approve') }}
          </UButton>
        </div>
      </div>
    </UCard>

    <SharedStepUpMfaModal v-model:open="showMfaModal" @verified="onMfaVerified" />
  </div>
</template>
