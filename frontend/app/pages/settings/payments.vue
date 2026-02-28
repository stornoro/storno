<script setup lang="ts">
definePageMeta({ middleware: ['auth', 'permissions'] })

const authStore = useAuthStore()
if (authStore.isSelfHosted) {
  navigateTo('/settings')
}

const { t: $t } = useI18n()
useHead({ title: $t('settings.billingPage.stripeConnect') })

const { get, post, patch, del } = useApi()
const toast = useToast()
const route = useRoute()

const loading = ref(true)
const connectStatus = ref<any>(null)
const onboarding = ref(false)
const stats = ref<any>(null)

const settings = reactive({
  paymentEnabledByDefault: true,
  allowPartialPayments: false,
  successMessage: '',
  notifyOnPayment: true,
})

let saveTimeout: ReturnType<typeof setTimeout> | null = null

function onSettingChange() {
  if (saveTimeout) clearTimeout(saveTimeout)
  saveTimeout = setTimeout(saveSettings, 500)
}

async function saveSettings() {
  try {
    await patch('/v1/stripe-connect/settings', {
      paymentEnabledByDefault: settings.paymentEnabledByDefault,
      allowPartialPayments: settings.allowPartialPayments,
      successMessage: settings.successMessage || null,
      notifyOnPayment: settings.notifyOnPayment,
    })
    toast.add({ title: $t('settings.billingPage.settingsSaved'), color: 'success' })
  }
  catch {
    toast.add({ title: $t('settings.billingPage.error'), color: 'error' })
  }
}

async function fetchStatus() {
  loading.value = true
  try {
    connectStatus.value = await get<any>('/v1/stripe-connect/status')
  }
  catch {
    connectStatus.value = null
  }
  finally {
    loading.value = false
  }
}

async function fetchStats() {
  try {
    stats.value = await get<any>('/v1/stripe-connect/stats')
  }
  catch {
    stats.value = null
  }
}

const statsColumns = [
  { accessorKey: 'paymentDate', header: $t('settings.billingPage.statsDate') },
  { accessorKey: 'amount', header: $t('settings.billingPage.statsAmount') },
  { accessorKey: 'currency', header: $t('settings.billingPage.statsCurrency') },
  { accessorKey: 'reference', header: $t('settings.billingPage.statsReference') },
]

async function onConnect() {
  onboarding.value = true
  try {
    const data = await post<{ url: string }>('/v1/stripe-connect/onboard')
    if (data.url) {
      window.location.href = data.url
    }
  }
  catch {
    toast.add({ title: $t('settings.billingPage.error'), color: 'error' })
  }
  finally {
    onboarding.value = false
  }
}

async function onDashboard() {
  try {
    const data = await post<{ url: string }>('/v1/stripe-connect/dashboard')
    if (data.url) {
      window.open(data.url, '_blank')
    }
  }
  catch {
    toast.add({ title: $t('settings.billingPage.error'), color: 'error' })
  }
}

async function onDisconnect() {
  try {
    await del('/v1/stripe-connect')
    await fetchStatus()
    toast.add({ title: $t('settings.billingPage.disconnect'), color: 'success' })
  }
  catch {
    toast.add({ title: $t('settings.billingPage.error'), color: 'error' })
  }
}

onMounted(async () => {
  await fetchStatus()
  // Handle return from Stripe onboarding
  if (route.query.status === 'complete') {
    await fetchStatus()
  }
  // Populate settings and fetch stats if onboarding complete
  if (connectStatus.value?.onboardingComplete) {
    const s = connectStatus.value.settings
    if (s) {
      settings.paymentEnabledByDefault = s.paymentEnabledByDefault
      settings.allowPartialPayments = s.allowPartialPayments
      settings.successMessage = s.successMessage ?? ''
      settings.notifyOnPayment = s.notifyOnPayment
    }
    await fetchStats()
  }
})
</script>

<template>
  <div>
    <UPageCard
      :title="$t('settings.billingPage.stripeConnect')"
      :description="$t('settings.billingPage.connectDescription')"
      variant="naked"
      class="mb-4"
    />

    <div v-if="loading" class="flex justify-center py-12">
      <UIcon name="i-lucide-loader-2" class="animate-spin size-8 text-(--ui-primary)" />
    </div>

    <template v-else>
      <!-- Not connected -->
      <UPageCard v-if="!connectStatus?.connected" variant="subtle">
        <div class="flex flex-col items-center gap-4 py-6">
          <UIcon name="i-lucide-credit-card" class="size-12 text-(--ui-text-muted)" />
          <div class="text-center space-y-1">
            <p class="font-medium">{{ $t('settings.billingPage.connectDescription') }}</p>
            <p class="text-sm text-(--ui-text-muted)">
              Conectati contul Stripe pentru a permite clientilor sa plateasca facturile online prin link-urile de partajare.
            </p>
          </div>
          <UButton
            color="primary"
            size="lg"
            icon="i-lucide-link"
            :loading="onboarding"
            @click="onConnect"
          >
            {{ $t('settings.billingPage.connect') }}
          </UButton>
        </div>
      </UPageCard>

      <!-- Connected -->
      <UPageCard v-else variant="subtle">
        <div class="space-y-4">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <UIcon name="i-lucide-check-circle" class="size-6 text-success-500" />
              <div>
                <p class="font-semibold">{{ $t('settings.billingPage.connected') }}</p>
                <p class="text-sm text-(--ui-text-muted)">{{ connectStatus.stripeAccountId }}</p>
              </div>
            </div>
            <UBadge :color="connectStatus.onboardingComplete ? 'success' : 'warning'" variant="subtle">
              {{ connectStatus.onboardingComplete ? $t('settings.billingPage.connected') : 'Onboarding...' }}
            </UBadge>
          </div>

          <USeparator />

          <div class="grid grid-cols-2 gap-4">
            <div class="flex items-center gap-2">
              <UIcon
                :name="connectStatus.chargesEnabled ? 'i-lucide-check-circle' : 'i-lucide-x-circle'"
                :class="connectStatus.chargesEnabled ? 'text-success-500' : 'text-neutral-400'"
              />
              <span class="text-sm">{{ $t('settings.billingPage.paymentsEnabled') }}</span>
            </div>
            <div class="flex items-center gap-2">
              <UIcon
                :name="connectStatus.payoutsEnabled ? 'i-lucide-check-circle' : 'i-lucide-x-circle'"
                :class="connectStatus.payoutsEnabled ? 'text-success-500' : 'text-neutral-400'"
              />
              <span class="text-sm">{{ $t('settings.billingPage.payoutsEnabled') }}</span>
            </div>
          </div>

          <div class="flex gap-2 pt-2">
            <UButton
              v-if="!connectStatus.onboardingComplete"
              color="primary"
              :loading="onboarding"
              @click="onConnect"
            >
              Continua onboarding
            </UButton>
            <UButton
              v-if="connectStatus.onboardingComplete"
              variant="outline"
              icon="i-lucide-external-link"
              @click="onDashboard"
            >
              {{ $t('settings.billingPage.stripeDashboard') }}
            </UButton>
            <UButton
              color="error"
              variant="soft"
              @click="onDisconnect"
            >
              {{ $t('settings.billingPage.disconnect') }}
            </UButton>
          </div>
        </div>
      </UPageCard>

      <!-- Payment Settings -->
      <UPageCard
        v-if="connectStatus?.onboardingComplete"
        :title="$t('settings.billingPage.settingsTitle')"
        :description="$t('settings.billingPage.settingsDescription')"
        variant="subtle"
        class="mt-4"
      >
        <div class="space-y-5">
          <div class="flex items-center justify-between">
            <div>
              <p class="font-medium text-sm">{{ $t('settings.billingPage.paymentEnabledByDefault') }}</p>
              <p class="text-xs text-(--ui-text-muted)">{{ $t('settings.billingPage.paymentEnabledByDefaultDescription') }}</p>
            </div>
            <USwitch v-model="settings.paymentEnabledByDefault" @update:model-value="onSettingChange" />
          </div>

          <USeparator />

          <div class="flex items-center justify-between">
            <div>
              <p class="font-medium text-sm">{{ $t('settings.billingPage.allowPartialPayments') }}</p>
              <p class="text-xs text-(--ui-text-muted)">{{ $t('settings.billingPage.allowPartialPaymentsDescription') }}</p>
            </div>
            <USwitch v-model="settings.allowPartialPayments" @update:model-value="onSettingChange" />
          </div>

          <USeparator />

          <div class="flex items-center justify-between">
            <div>
              <p class="font-medium text-sm">{{ $t('settings.billingPage.notifyOnPayment') }}</p>
              <p class="text-xs text-(--ui-text-muted)">{{ $t('settings.billingPage.notifyOnPaymentDescription') }}</p>
            </div>
            <USwitch v-model="settings.notifyOnPayment" @update:model-value="onSettingChange" />
          </div>

          <USeparator />

          <div>
            <p class="font-medium text-sm mb-1">{{ $t('settings.billingPage.successMessage') }}</p>
            <p class="text-xs text-(--ui-text-muted) mb-2">{{ $t('settings.billingPage.successMessageDescription') }}</p>
            <UTextarea
              v-model="settings.successMessage"
              :placeholder="$t('settings.billingPage.successMessagePlaceholder')"
              :rows="2"
              @update:model-value="onSettingChange"
            />
          </div>
        </div>
      </UPageCard>

      <!-- Payment Stats -->
      <UPageCard
        v-if="connectStatus?.onboardingComplete && stats"
        :title="$t('settings.billingPage.statsTitle')"
        variant="subtle"
        class="mt-4"
      >
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div class="rounded-lg border border-(--ui-border) p-4 text-center">
              <p class="text-2xl font-bold">{{ stats.totalCount }}</p>
              <p class="text-sm text-(--ui-text-muted)">{{ $t('settings.billingPage.statsTotalPayments') }}</p>
            </div>
            <div class="rounded-lg border border-(--ui-border) p-4 text-center">
              <p class="text-2xl font-bold">{{ stats.totalAmount }} RON</p>
              <p class="text-sm text-(--ui-text-muted)">{{ $t('settings.billingPage.statsTotalAmount') }}</p>
            </div>
          </div>

          <UTable
            v-if="stats.recentPayments?.length"
            :data="stats.recentPayments"
            :columns="statsColumns"
          />
          <p v-else class="text-sm text-(--ui-text-muted) text-center py-4">
            {{ $t('settings.billingPage.statsNoPayments') }}
          </p>
        </div>
      </UPageCard>
    </template>
  </div>
</template>
