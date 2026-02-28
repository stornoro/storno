<script setup lang="ts">
definePageMeta({ middleware: ['auth', 'permissions'] })

const authStore = useAuthStore()
if (authStore.isSelfHosted) {
  navigateTo('/settings')
}

const { t: $t } = useI18n()
useHead({ title: $t('settings.billingPage.title') })

const billingStore = useBillingStore()
const toast = useToast()

const cancellationModalOpen = ref(false)
const resumeModalOpen = ref(false)
const changePlanModalOpen = ref(false)
const pendingPriceId = ref<string | null>(null)
const pendingPlanName = ref('')
const pendingIsUpgrade = ref(true)
const resuming = ref(false)
const changingPlan = ref(false)
const checkoutLoading = ref<string | null>(null)

// Fetch data on mount
onMounted(async () => {
  await Promise.all([
    billingStore.fetchPlans(),
    billingStore.fetchSubscription(),
  ])
  // Refresh user data to sync plan status
  authStore.fetchUser()
})

// Handle Stripe redirect status
const route = useRoute()
const router = useRouter()
watch(() => route.query.status, (status) => {
  if (status === 'success') {
    toast.add({ title: $t('settings.billingPage.subscriptionSuccess'), color: 'success' })
    billingStore.fetchSubscription()
    authStore.fetchUser()
    // Clean up query param
    router.replace({ query: { ...route.query, status: undefined } })
  }
  else if (status === 'canceled') {
    // User canceled checkout — no action needed
    router.replace({ query: { ...route.query, status: undefined } })
  }
}, { immediate: true })

const statusColor = computed(() => {
  switch (billingStore.subscription?.status) {
    case 'active': return 'success' as const
    case 'trialing': return 'info' as const
    case 'past_due': return 'warning' as const
    case 'canceled': return 'error' as const
    default: return 'neutral' as const
  }
})

const statusLabel = computed(() => {
  const status = billingStore.subscription?.status
  if (!status) return $t('settings.billingPage.noSubscription')
  const key = `settings.billingPage.${status === 'past_due' ? 'pastDue' : status}`
  return $t(key)
})

const planBadgeColor = computed(() => {
  switch (billingStore.currentPlan) {
    case 'business': return 'success' as const
    case 'professional': return 'info' as const
    case 'starter': return 'primary' as const
    case 'trial': return 'warning' as const
    default: return 'neutral' as const
  }
})

const planLabel = computed(() => {
  const plan = billingStore.currentPlan
  const key = `settings.billingPage.${plan}`
  return $t(key)
})

function formatDate(dateStr: string | null): string {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleDateString('ro-RO', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
  })
}

function formatPrice(amount: number, currency: string): string {
  return new Intl.NumberFormat('ro-RO', {
    style: 'currency',
    currency: currency.toUpperCase(),
    minimumFractionDigits: 0,
  }).format(amount / 100)
}

/** Monthly equivalent when billed yearly */
function formatMonthlyEquivalent(yearlyAmount: number, currency: string): string {
  return formatPrice(Math.round(yearlyAmount / 12), currency)
}

/** Yearly savings percentage */
function savingsPercent(plan: { prices: Array<{ amount: number, interval: string }> }): number {
  const monthly = plan.prices.find(p => p.interval === 'month')
  const yearly = plan.prices.find(p => p.interval === 'year')
  if (!monthly || !yearly) return 0
  const monthlyTotal = monthly.amount * 12
  return Math.round(((monthlyTotal - yearly.amount) / monthlyTotal) * 100)
}

function onPlanAction(priceId: string, planName: string, upgrade: boolean) {
  if (billingStore.hasActiveSubscription) {
    // Active subscription → confirm plan change
    pendingPriceId.value = priceId
    pendingPlanName.value = planName
    pendingIsUpgrade.value = upgrade
    changePlanModalOpen.value = true
  }
  else {
    // No subscription → create new checkout
    onCheckout(priceId)
  }
}

async function onCheckout(priceId: string) {
  checkoutLoading.value = priceId
  try {
    const result = await billingStore.createCheckout(priceId)
    if (result === 'plan_changed') {
      toast.add({ title: $t('settings.billingPage.planChanged'), color: 'success' })
      authStore.fetchUser()
    }
  }
  catch {
    toast.add({ title: billingStore.error ?? $t('settings.billingPage.error'), color: 'error' })
  }
  finally {
    checkoutLoading.value = null
  }
}

async function onConfirmChangePlan() {
  if (!pendingPriceId.value) return
  changingPlan.value = true
  try {
    await billingStore.changePlan(pendingPriceId.value)
    changePlanModalOpen.value = false
    toast.add({ title: $t('settings.billingPage.planChanged'), color: 'success' })
    authStore.fetchUser()
  }
  catch {
    toast.add({ title: billingStore.error ?? $t('settings.billingPage.error'), color: 'error' })
  }
  finally {
    changingPlan.value = false
  }
}

async function onManage() {
  try {
    await billingStore.openPortal()
  }
  catch {
    toast.add({ title: billingStore.error ?? $t('settings.billingPage.error'), color: 'error' })
  }
}

async function onResume() {
  resuming.value = true
  try {
    await billingStore.resume()
    resumeModalOpen.value = false
    toast.add({ title: $t('settings.billingPage.resumeSuccess'), color: 'success' })
    authStore.fetchUser()
  }
  catch {
    toast.add({ title: billingStore.error ?? $t('settings.billingPage.error'), color: 'error' })
  }
  finally {
    resuming.value = false
  }
}

function isCurrentPlan(plan: string): boolean {
  return billingStore.currentPlan === plan
}

/** Determine if a plan is an upgrade from the current one */
function isUpgrade(plan: string): boolean {
  const order = ['free', 'freemium', 'starter', 'professional', 'business']
  return order.indexOf(plan) > order.indexOf(billingStore.currentPlan)
}

const intervalItems = computed(() => [
  { label: $t('settings.billingPage.monthly'), value: 'month' },
  { label: $t('settings.billingPage.yearly'), value: 'year' },
])
</script>

<template>
  <div>
    <UPageCard
      :title="$t('settings.billingPage.title')"
      :description="$t('settings.billingPage.description')"
      variant="naked"
      class="mb-4"
    />

    <!-- Loading state -->
    <div v-if="billingStore.loading" class="flex justify-center py-12">
      <UIcon name="i-lucide-loader-2" class="animate-spin size-8 text-(--ui-primary)" />
    </div>

    <template v-else>
      <!-- Fully canceled — prominent banner -->
      <div v-if="billingStore.isFullyCanceled" class="rounded-xl border-2 border-dashed border-amber-300 dark:border-amber-700 bg-amber-50/50 dark:bg-amber-950/20 p-6 mb-6">
        <div class="flex flex-col sm:flex-row sm:items-start gap-4">
          <div class="flex items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/40 p-3 shrink-0">
            <UIcon name="i-lucide-circle-alert" class="size-6 text-amber-600 dark:text-amber-400" />
          </div>
          <div class="flex-1 space-y-2">
            <h3 class="font-semibold text-lg text-amber-900 dark:text-amber-100">
              {{ $t('settings.billingPage.canceledTitle') }}
            </h3>
            <p class="text-sm text-amber-700 dark:text-amber-300">
              {{ $t('settings.billingPage.canceledDescription') }}
            </p>
            <div class="flex items-center gap-3 pt-1">
              <UBadge color="neutral" variant="subtle" size="sm">
                {{ $t('settings.billingPage.currentPlan') }}: {{ planLabel }}
              </UBadge>
              <UBadge color="error" variant="subtle" size="sm">
                {{ $t('settings.billingPage.canceled') }}
              </UBadge>
            </div>
          </div>
          <UButton
            color="primary"
            size="lg"
            icon="i-lucide-sparkles"
            class="shrink-0"
            @click="document.getElementById('plans-section')?.scrollIntoView({ behavior: 'smooth' })"
          >
            {{ $t('settings.billingPage.choosePlan') }}
          </UButton>
        </div>
      </div>

      <!-- Current subscription status (when not fully canceled) -->
      <UPageCard v-else variant="subtle" class="mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div class="space-y-2">
            <div class="flex items-center gap-3">
              <h3 class="font-semibold text-lg">{{ $t('settings.billingPage.currentPlan') }}</h3>
              <UBadge :color="planBadgeColor" variant="subtle" size="lg">
                {{ planLabel }}
              </UBadge>
            </div>

            <div v-if="billingStore.subscription?.status" class="flex items-center gap-4 text-sm">
              <div class="flex items-center gap-1.5">
                <span class="text-(--ui-text-muted)">{{ $t('settings.billingPage.status') }}:</span>
                <UBadge :color="statusColor" variant="subtle" size="sm">
                  {{ statusLabel }}
                </UBadge>
              </div>

              <div v-if="billingStore.subscription?.currentPeriodEnd && !billingStore.isCanceled" class="text-(--ui-text-muted)">
                {{ $t('settings.billingPage.nextBilling') }}: {{ formatDate(billingStore.subscription.currentPeriodEnd) }}
              </div>

              <div v-if="billingStore.isCanceled && billingStore.subscription?.currentPeriodEnd" class="text-warning-600 dark:text-warning-400">
                {{ $t('settings.billingPage.cancelDate') }}: {{ formatDate(billingStore.subscription.currentPeriodEnd) }}
              </div>
            </div>

            <!-- Trial banner -->
            <div v-if="billingStore.isTrial" class="rounded-lg bg-warning-50 dark:bg-warning-950/20 p-3 border border-warning-200 dark:border-warning-800 mt-2">
              <div class="flex items-center gap-2 text-sm text-warning-700 dark:text-warning-300">
                <UIcon name="i-lucide-clock" class="shrink-0" />
                {{ $t('plan.trialDaysLeft', billingStore.billing?.trialDaysLeft ?? 0, { count: billingStore.billing?.trialDaysLeft ?? 0 }) }}
              </div>
            </div>

            <!-- Past due warning -->
            <div v-if="billingStore.isPastDue" class="rounded-lg bg-error/10 p-3 border border-error/20 mt-2">
              <div class="flex items-center gap-2 text-sm text-error">
                <UIcon name="i-lucide-alert-triangle" class="shrink-0" />
                {{ $t('settings.billingPage.pastDue') }}
              </div>
            </div>

            <!-- Cancel at period end warning -->
            <div v-if="billingStore.isCanceled && !billingStore.isFullyCanceled" class="rounded-lg bg-amber-50 dark:bg-amber-950/20 p-3 border border-amber-200 dark:border-amber-800 mt-2">
              <div class="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-300">
                <UIcon name="i-lucide-clock" class="shrink-0" />
                {{ $t('settings.billingPage.cancelPendingInfo') }}
              </div>
            </div>
          </div>

          <div class="flex gap-2">
            <UButton
              v-if="billingStore.hasActiveSubscription"
              variant="outline"
              icon="i-lucide-external-link"
              @click="onManage"
            >
              {{ $t('settings.billingPage.manageSubscription') }}
            </UButton>

            <UButton
              v-if="billingStore.hasActiveSubscription"
              color="error"
              variant="soft"
              @click="cancellationModalOpen = true"
            >
              {{ $t('settings.billingPage.cancel') }}
            </UButton>

            <UButton
              v-if="billingStore.isCanceled && !billingStore.isFullyCanceled"
              color="primary"
              @click="resumeModalOpen = true"
            >
              {{ $t('settings.billingPage.resume') }}
            </UButton>
          </div>
        </div>
      </UPageCard>

      <!-- Plans comparison -->
      <div id="plans-section" class="flex items-center justify-between mb-4">
        <h3 class="font-semibold text-lg">{{ $t('settings.billingPage.comparePlans') }}</h3>

        <!-- Monthly / Yearly toggle -->
        <div v-if="billingStore.hasYearlyPricing" class="flex items-center gap-2">
          <div class="inline-flex items-center rounded-lg bg-(--ui-bg-elevated) p-1">
            <button
              v-for="item in intervalItems"
              :key="item.value"
              type="button"
              class="relative rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
              :class="[
                billingStore.billingInterval === item.value
                  ? 'bg-(--ui-bg) text-(--ui-text) shadow-sm'
                  : 'text-(--ui-text-muted) hover:text-(--ui-text)',
              ]"
              @click="billingStore.billingInterval = item.value as 'month' | 'year'"
            >
              {{ item.label }}
              <UBadge
                v-if="item.value === 'year' && billingStore.plans.some(p => savingsPercent(p) > 0)"
                color="success"
                variant="subtle"
                size="xs"
                class="ml-1.5"
              >
                {{ $t('settings.billingPage.saveUpTo', { percent: Math.max(0, ...billingStore.plans.map(p => savingsPercent(p))) }) }}
              </UBadge>
            </button>
          </div>
        </div>
      </div>

      <div v-if="billingStore.plansLoading" class="flex justify-center py-8">
        <UIcon name="i-lucide-loader-2" class="animate-spin size-6 text-(--ui-primary)" />
      </div>

      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <UPageCard
          v-for="plan in billingStore.plans"
          :key="plan.plan"
          variant="subtle"
          :class="[
            'relative h-full',
            isCurrentPlan(plan.plan) ? 'ring-2 ring-(--ui-primary)' : '',
            plan.plan === 'professional' && !isCurrentPlan(plan.plan) ? 'ring-2 ring-(--ui-primary)/40' : '',
          ]"
          :ui="{ root: 'flex flex-col', container: 'flex-1 flex flex-col' }"
        >
          <!-- Current plan or recommended badge -->
          <UBadge
            v-if="isCurrentPlan(plan.plan)"
            color="primary"
            variant="solid"
            size="sm"
            class="absolute top-3 right-3"
          >
            {{ $t('settings.billingPage.currentPlanBadge') }}
          </UBadge>
          <UBadge
            v-else-if="plan.plan === 'professional'"
            color="primary"
            variant="subtle"
            size="sm"
            class="absolute top-3 right-3"
          >
            {{ $t('settings.billingPage.popular') }}
          </UBadge>

          <div class="flex flex-col flex-1 gap-4">
            <div>
              <h4 class="font-semibold text-lg">{{ plan.name }}</h4>
              <p v-if="plan.description" class="text-sm text-(--ui-text-muted)">{{ plan.description }}</p>
            </div>

            <!-- Price display -->
            <div v-if="billingStore.getPriceForInterval(plan)">
              <div class="flex items-baseline gap-2 flex-wrap">
                <span class="text-3xl font-bold">
                  {{ billingStore.billingInterval === 'year'
                    ? formatMonthlyEquivalent(billingStore.getPriceForInterval(plan)!.amount, plan.currency)
                    : formatPrice(billingStore.getPriceForInterval(plan)!.amount, plan.currency)
                  }}
                </span>
                <span class="text-(--ui-text-muted)">/ {{ $t('settings.billingPage.mo') }}</span>
                <UBadge
                  v-if="billingStore.billingInterval === 'year' && savingsPercent(plan) > 0"
                  color="success"
                  variant="subtle"
                  size="sm"
                >
                  {{ $t('settings.billingPage.economisesti', { percent: savingsPercent(plan) }) }}
                </UBadge>
              </div>

              <!-- Yearly billing note -->
              <div v-if="billingStore.billingInterval === 'year'" class="text-sm text-(--ui-text-muted) mt-1">
                {{ formatPrice(billingStore.getPriceForInterval(plan)!.amount, plan.currency) }} {{ $t('settings.billingPage.billedYearly') }}
              </div>
            </div>

            <!-- Includes lower plan badge -->
            <div v-if="plan.includesPlan" class="flex items-center gap-2 text-sm font-medium text-(--ui-primary)">
              <UIcon name="i-lucide-layers" class="shrink-0 size-4" />
              {{ $t('settings.billingPage.includesEverythingFrom', { plan: $t(`settings.billingPage.${plan.includesPlan}`) }) }}
            </div>

            <USeparator v-if="plan.includesPlan" />

            <!-- Features list -->
            <ul v-if="plan.features?.length" class="space-y-2">
              <li v-for="feature in plan.features" :key="feature" class="flex items-center gap-2 text-sm">
                <UIcon name="i-lucide-check" class="text-success-500 shrink-0 size-4" />
                {{ $t(feature) }}
              </li>
            </ul>

            <!-- Action button -->
            <div class="mt-auto">
              <UButton
                v-if="(billingStore.isFullyCanceled || !isCurrentPlan(plan.plan)) && billingStore.getPriceForInterval(plan)"
                :color="plan.plan === 'professional' ? 'primary' : 'neutral'"
                :variant="plan.plan === 'professional' ? 'solid' : 'outline'"
                block
                :loading="checkoutLoading === billingStore.getPriceForInterval(plan)?.priceId"
                :disabled="!!checkoutLoading || changingPlan"
                @click="onPlanAction(billingStore.getPriceForInterval(plan)!.priceId, plan.name, isUpgrade(plan.plan))"
              >
                {{ billingStore.isFullyCanceled ? $t('settings.billingPage.subscribe') : (isUpgrade(plan.plan) ? $t('settings.billingPage.upgrade') : $t('settings.billingPage.downgrade')) }}
              </UButton>

              <div v-if="isCurrentPlan(plan.plan) && !billingStore.isFullyCanceled" class="text-center text-sm text-(--ui-text-muted) py-2">
                {{ $t('settings.billingPage.currentPlanBadge') }}
              </div>
            </div>
          </div>
        </UPageCard>
      </div>

      <!-- No plans available from Stripe (not configured or no products) -->
      <UPageCard
        v-if="!billingStore.plansLoading && billingStore.plans.length === 0"
        variant="subtle"
      >
        <div class="flex flex-col items-center gap-3 py-6">
          <UIcon name="i-lucide-credit-card" class="size-10 text-(--ui-text-muted)" />
          <p class="text-center text-(--ui-text-muted) text-sm">
            {{ $t('settings.billingPage.noPlansConfigured') }}
          </p>
        </div>
      </UPageCard>
    </template>

    <!-- Plan change confirmation modal -->
    <UModal v-model:open="changePlanModalOpen">
      <template #content>
        <div class="p-6 space-y-5">
          <div class="flex items-start gap-3">
            <div class="size-10 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
              <UIcon :name="pendingIsUpgrade ? 'i-lucide-arrow-up-circle' : 'i-lucide-arrow-down-circle'" class="size-5 text-primary" />
            </div>
            <div>
              <h3 class="text-lg font-semibold">
                {{ pendingIsUpgrade ? $t('settings.billingPage.confirmUpgradeTitle') : $t('settings.billingPage.confirmDowngradeTitle') }}
              </h3>
              <p class="text-sm text-(--ui-text-muted) mt-0.5">
                {{ pendingIsUpgrade
                  ? $t('settings.billingPage.confirmUpgradeDescription', { plan: pendingPlanName })
                  : $t('settings.billingPage.confirmDowngradeDescription', { plan: pendingPlanName })
                }}
              </p>
            </div>
          </div>

          <div class="rounded-lg bg-(--ui-bg-elevated) border border-(--ui-border) p-4 space-y-2">
            <div class="flex items-center justify-between text-sm">
              <span class="text-(--ui-text-muted)">{{ $t('settings.billingPage.currentPlan') }}</span>
              <UBadge :color="planBadgeColor" variant="subtle" size="sm">{{ planLabel }}</UBadge>
            </div>
            <div class="flex items-center justify-between text-sm">
              <span class="text-(--ui-text-muted)">{{ $t('settings.billingPage.newPlan') }}</span>
              <UBadge color="primary" variant="subtle" size="sm">{{ pendingPlanName }}</UBadge>
            </div>
          </div>

          <p class="text-xs text-(--ui-text-muted)">
            {{ $t('settings.billingPage.prorateNote') }}
          </p>

          <div class="flex justify-end gap-2 pt-1">
            <UButton variant="ghost" @click="changePlanModalOpen = false">
              {{ $t('common.cancel') }}
            </UButton>
            <UButton
              :color="pendingIsUpgrade ? 'primary' : 'neutral'"
              :loading="changingPlan"
              :icon="pendingIsUpgrade ? 'i-lucide-arrow-up-circle' : 'i-lucide-arrow-down-circle'"
              @click="onConfirmChangePlan"
            >
              {{ pendingIsUpgrade ? $t('settings.billingPage.confirmUpgrade') : $t('settings.billingPage.confirmDowngrade') }}
            </UButton>
          </div>
        </div>
      </template>
    </UModal>

    <!-- Cancellation flow modal -->
    <SharedCancellationModal v-model:open="cancellationModalOpen" />

    <!-- Resume confirmation modal -->
    <UModal v-model:open="resumeModalOpen">
      <template #content>
        <div class="p-6 space-y-5">
          <div class="flex items-start gap-3">
            <div class="size-10 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
              <UIcon name="i-lucide-play-circle" class="size-5 text-primary" />
            </div>
            <div>
              <h3 class="text-lg font-semibold">{{ $t('settings.billingPage.resumeTitle') }}</h3>
              <p class="text-sm text-(--ui-text-muted) mt-0.5">
                {{ $t('settings.billingPage.resumeDescription') }}
              </p>
            </div>
          </div>

          <div class="rounded-lg bg-(--ui-bg-elevated) border border-(--ui-border) p-4 space-y-2">
            <div class="flex items-center justify-between text-sm">
              <span class="text-(--ui-text-muted)">{{ $t('settings.billingPage.currentPlan') }}</span>
              <UBadge :color="planBadgeColor" variant="subtle" size="sm">{{ planLabel }}</UBadge>
            </div>
            <div v-if="billingStore.subscription?.currentPeriodEnd" class="flex items-center justify-between text-sm">
              <span class="text-(--ui-text-muted)">{{ $t('settings.billingPage.nextBilling') }}</span>
              <span>{{ formatDate(billingStore.subscription.currentPeriodEnd) }}</span>
            </div>
          </div>

          <div class="flex justify-end gap-2 pt-1">
            <UButton variant="ghost" @click="resumeModalOpen = false">
              {{ $t('common.cancel') }}
            </UButton>
            <UButton
              color="primary"
              :loading="resuming"
              icon="i-lucide-play-circle"
              @click="onResume"
            >
              {{ $t('settings.billingPage.resumeConfirm') }}
            </UButton>
          </div>
        </div>
      </template>
    </UModal>
  </div>
</template>
