import { defineStore } from 'pinia'

interface Price {
  priceId: string
  amount: number
  interval: 'month' | 'year'
  intervalCount: number
}

interface Plan {
  plan: string
  name: string
  description: string | null
  currency: string
  features: string[]
  includesPlan: string | null
  prices: Price[]
}

interface RawPlan {
  priceId: string
  plan: string
  name: string
  description: string | null
  amount: number
  currency: string
  interval: string
  intervalCount: number
  features: string[]
  includesPlan: string | null
}

interface Subscription {
  stripeSubscriptionId: string | null
  stripePriceId: string | null
  status: string | null
  currentPeriodEnd: string | null
  cancelAtPeriodEnd: boolean
}

interface BillingState {
  plan: string
  features: Record<string, any>
  trialEndsAt: string | null
  trialActive: boolean
  trialDaysLeft: number | null
  subscription: Subscription
}

export const useBillingStore = defineStore('billing', () => {
  // ── State ──────────────────────────────────────────────────────────
  const rawPlans = ref<RawPlan[]>([])
  const billing = ref<BillingState | null>(null)
  const loading = ref(false)
  const plansLoading = ref(false)
  const error = ref<string | null>(null)
  const billingInterval = ref<'month' | 'year'>('month')

  // ── Getters ────────────────────────────────────────────────────────
  const currentPlan = computed(() => billing.value?.plan ?? 'free')
  const subscription = computed(() => billing.value?.subscription ?? null)
  const isActive = computed(() => ['active', 'trialing'].includes(subscription.value?.status ?? ''))
  const isCanceled = computed(() => subscription.value?.cancelAtPeriodEnd === true)
  const isFullyCanceled = computed(() => subscription.value?.status === 'canceled')
  const isPastDue = computed(() => subscription.value?.status === 'past_due')
  const isTrial = computed(() => billing.value?.trialActive === true)
  const hasActiveSubscription = computed(() => isActive.value && !isCanceled.value)

  /** Group raw prices by plan, merging monthly + yearly into a single plan object */
  const plans = computed<Plan[]>(() => {
    const grouped = new Map<string, Plan>()

    for (const raw of rawPlans.value) {
      if (!grouped.has(raw.plan)) {
        grouped.set(raw.plan, {
          plan: raw.plan,
          name: raw.name,
          description: raw.description,
          currency: raw.currency,
          features: raw.features,
          includesPlan: raw.includesPlan,
          prices: [],
        })
      }

      grouped.get(raw.plan)!.prices.push({
        priceId: raw.priceId,
        amount: raw.amount,
        interval: raw.interval as 'month' | 'year',
        intervalCount: raw.intervalCount,
      })
    }

    return Array.from(grouped.values())
  })

  /** Get the price for the currently selected interval */
  function getPriceForInterval(plan: Plan): Price | undefined {
    return plan.prices.find(p => p.interval === billingInterval.value)
  }

  /** Check if yearly pricing is available for at least one plan */
  const hasYearlyPricing = computed(() => {
    return rawPlans.value.some(p => p.interval === 'year')
  })

  // ── Actions ────────────────────────────────────────────────────────
  async function fetchPlans(): Promise<void> {
    const { get } = useApi()
    plansLoading.value = true
    error.value = null

    try {
      const data = await get<{ plans: RawPlan[], publishableKey: string }>('/v1/billing/plans')
      rawPlans.value = data.plans
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca planurile.'
    }
    finally {
      plansLoading.value = false
    }
  }

  async function fetchSubscription(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const data = await get<BillingState>('/v1/billing/subscription')
      billing.value = data
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca abonamentul.'
    }
    finally {
      loading.value = false
    }
  }

  async function createCheckout(priceId: string): Promise<'redirected' | 'plan_changed'> {
    const { post } = useApi()
    error.value = null

    try {
      const data = await post<{ url?: string, status?: string }>('/v1/billing/checkout', { priceId })
      if (data.url) {
        window.location.href = data.url
        return 'redirected'
      }
      // Backend handled as plan change (active subscription)
      await fetchSubscription()
      return 'plan_changed'
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea sesiunea de plata.'
      throw err
    }
  }

  async function changePlan(priceId: string): Promise<void> {
    const { post } = useApi()
    error.value = null

    try {
      await post('/v1/billing/change-plan', { priceId })
      await fetchSubscription()
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut schimba planul.'
      throw err
    }
  }

  async function openPortal(): Promise<void> {
    const { post } = useApi()
    error.value = null

    try {
      const data = await post<{ url: string }>('/v1/billing/portal')
      if (data.url) {
        window.location.href = data.url
      }
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut deschide portalul de facturare.'
      throw err
    }
  }

  async function cancel(): Promise<void> {
    const { post } = useApi()
    error.value = null

    try {
      await post('/v1/billing/cancel')
      await fetchSubscription()
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut anula abonamentul.'
      throw err
    }
  }

  async function resume(): Promise<void> {
    const { post } = useApi()
    error.value = null

    try {
      await post('/v1/billing/resume')
      await fetchSubscription()
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut relua abonamentul.'
      throw err
    }
  }

  return {
    // State
    plans,
    rawPlans,
    billing,
    loading,
    plansLoading,
    error,
    billingInterval,

    // Getters
    currentPlan,
    subscription,
    isActive,
    isCanceled,
    isFullyCanceled,
    isPastDue,
    isTrial,
    hasActiveSubscription,
    hasYearlyPricing,

    // Methods
    getPriceForInterval,

    // Actions
    fetchPlans,
    fetchSubscription,
    createCheckout,
    changePlan,
    openPortal,
    cancel,
    resume,
  }
})
