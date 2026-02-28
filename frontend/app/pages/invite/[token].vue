<template>
  <div>
    <!-- Loading -->
    <div v-if="loading" class="text-center py-16">
      <UIcon name="i-lucide-loader-2" class="w-8 h-8 animate-spin text-primary mb-4" />
      <p class="text-(--ui-text-muted)">{{ $t('common.loading') }}</p>
    </div>

    <!-- Invalid/Expired -->
    <div v-else-if="error" class="bg-(--ui-bg-elevated) rounded-2xl shadow-lg border border-(--ui-border) p-8 text-center">
      <div class="w-16 h-16 rounded-full bg-error/10 flex items-center justify-center mx-auto mb-5">
        <UIcon name="i-lucide-alert-circle" class="w-8 h-8 text-error" />
      </div>
      <h2 class="text-xl font-bold text-(--ui-text) mb-2">{{ $t('invite.invalidTitle') }}</h2>
      <p class="text-(--ui-text-muted) text-sm mb-8">{{ $t('invite.invalidDescription') }}</p>
      <UButton size="lg" block :ui="{ base: 'rounded-xl justify-center font-semibold' }" @click="navigateTo('/login')">
        {{ $t('auth.backToLogin') }}
      </UButton>
    </div>

    <!-- Accepted -->
    <div v-else-if="accepted" class="bg-(--ui-bg-elevated) rounded-2xl shadow-lg border border-(--ui-border) p-8 text-center">
      <div class="w-16 h-16 rounded-full bg-success/10 flex items-center justify-center mx-auto mb-5">
        <UIcon name="i-lucide-check-circle" class="w-8 h-8 text-success" />
      </div>
      <h2 class="text-xl font-bold text-(--ui-text) mb-2">{{ $t('invite.accepted') }}</h2>
      <p class="text-(--ui-text-muted) text-sm mb-8">
        {{ $t('invite.acceptedDescription', { org: details?.organizationName }) }}
      </p>
      <UButton size="lg" block :ui="{ base: 'rounded-xl justify-center font-semibold' }" @click="navigateTo('/dashboard')">
        {{ $t('invite.goToDashboard') }}
      </UButton>
    </div>

    <!-- Not Authenticated -->
    <div v-else-if="details && !authStore.isAuthenticated" class="bg-(--ui-bg-elevated) rounded-2xl shadow-lg border border-(--ui-border) overflow-hidden">
      <!-- Invitation header strip -->
      <div class="bg-primary/5 dark:bg-primary/10 px-8 py-6 text-center border-b border-(--ui-border)">
        <div class="w-14 h-14 rounded-full bg-primary/10 flex items-center justify-center mx-auto mb-4">
          <UIcon name="i-lucide-mail-open" class="w-7 h-7 text-primary" />
        </div>
        <h2 class="text-xl font-bold text-(--ui-text)">{{ $t('invite.acceptTitle') }}</h2>
      </div>

      <div class="p-8 text-center">
        <!-- Org info -->
        <div class="inline-flex items-center gap-2 bg-(--ui-bg) border border-(--ui-border) rounded-xl px-4 py-2.5 mb-5">
          <UIcon name="i-lucide-building-2" class="w-5 h-5 text-primary shrink-0" />
          <span class="font-semibold text-(--ui-text)">{{ details.organizationName }}</span>
          <UBadge variant="subtle" size="sm">{{ $t(`settings.roles.${details.role}`) }}</UBadge>
        </div>

        <p class="text-(--ui-text-muted) text-sm mb-2">
          {{ $t('invite.acceptDescription', { org: details.organizationName, role: $t(`settings.roles.${details.role}`) }) }}
        </p>
        <p class="text-(--ui-text-dimmed) text-xs mb-8">{{ $t('invite.loginPrompt') }}</p>

        <div class="flex gap-3">
          <UButton
            size="lg"
            variant="outline"
            block
            :ui="{ base: 'rounded-xl justify-center font-semibold' }"
            @click="navigateTo(`/login?redirect=/invite/${token}`)"
          >
            {{ $t('invite.login') }}
          </UButton>
          <UButton
            size="lg"
            block
            :ui="{ base: 'rounded-xl justify-center font-semibold' }"
            @click="navigateTo(`/register?redirect=/invite/${token}`)"
          >
            {{ $t('invite.register') }}
          </UButton>
        </div>
      </div>
    </div>

    <!-- Authenticated — Accept -->
    <div v-else-if="details" class="bg-(--ui-bg-elevated) rounded-2xl shadow-lg border border-(--ui-border) overflow-hidden">
      <!-- Invitation header strip -->
      <div class="bg-primary/5 dark:bg-primary/10 px-8 py-6 text-center border-b border-(--ui-border)">
        <div class="w-14 h-14 rounded-full bg-primary/10 flex items-center justify-center mx-auto mb-4">
          <UIcon name="i-lucide-mail-open" class="w-7 h-7 text-primary" />
        </div>
        <h2 class="text-xl font-bold text-(--ui-text)">{{ $t('invite.acceptTitle') }}</h2>
      </div>

      <div class="p-8 text-center">
        <!-- Org info -->
        <div class="inline-flex items-center gap-2 bg-(--ui-bg) border border-(--ui-border) rounded-xl px-4 py-2.5 mb-5">
          <UIcon name="i-lucide-building-2" class="w-5 h-5 text-primary shrink-0" />
          <span class="font-semibold text-(--ui-text)">{{ details.organizationName }}</span>
          <UBadge variant="subtle" size="sm">{{ $t(`settings.roles.${details.role}`) }}</UBadge>
        </div>

        <p class="text-(--ui-text-muted) text-sm mb-8">
          {{ $t('invite.acceptDescription', { org: details.organizationName, role: $t(`settings.roles.${details.role}`) }) }}
        </p>

        <p v-if="acceptError" class="text-sm text-error mb-4">{{ acceptError }}</p>

        <UButton
          size="lg"
          block
          :loading="accepting"
          :ui="{ base: 'rounded-xl justify-center font-semibold' }"
          @click="acceptInvitation"
        >
          {{ $t('invite.accept') }}
        </UButton>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { InvitationDetails } from '~/types'

definePageMeta({ layout: 'minimal' })

const { t: $t } = useI18n()
const route = useRoute()
const authStore = useAuthStore()

const token = computed(() => route.params.token as string)

const loading = ref(true)
const error = ref(false)
const details = ref<InvitationDetails | null>(null)
const accepted = ref(false)
const accepting = ref(false)
const acceptError = ref('')

const fetchFn = useRequestFetch()

async function fetchDetails() {
  loading.value = true
  error.value = false
  try {
    const apiBase = useApiBase()
    const data = await fetchFn<InvitationDetails>('/v1/invitations/accept/' + token.value, { baseURL: apiBase })
    details.value = data
  }
  catch {
    error.value = true
  }
  finally {
    loading.value = false
  }
}

async function acceptInvitation() {
  accepting.value = true
  acceptError.value = ''
  try {
    const { post } = useApi()
    await post(`/v1/invitations/accept/${token.value}`)
    accepted.value = true
    // Refresh user data to pick up the new org membership
    await authStore.fetchUser()
    // Reload companies so the auth middleware sees them
    const companyStore = useCompanyStore()
    companyStore.companies = []
    await companyStore.fetchCompanies()
  }
  catch (err: any) {
    acceptError.value = err?.data?.error ?? $t('invite.error')
  }
  finally {
    accepting.value = false
  }
}

onMounted(() => fetchDetails())
</script>
