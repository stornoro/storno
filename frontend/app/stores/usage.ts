import { defineStore } from 'pinia'

interface UsageResource {
  used: number
  limit: number
  percentage: number
}

interface UsageData {
  invoices: UsageResource
  companies: UsageResource
  users: UsageResource
}

const WARNING_THRESHOLD = 80

export const useUsageStore = defineStore('usage', () => {
  // ── State ──────────────────────────────────────────────────────────
  const invoices = ref<UsageResource>({ used: 0, limit: 0, percentage: 0 })
  const companies = ref<UsageResource>({ used: 0, limit: 0, percentage: 0 })
  const users = ref<UsageResource>({ used: 0, limit: 0, percentage: 0 })
  const loading = ref(false)
  const error = ref<string | null>(null)

  // ── Getters ────────────────────────────────────────────────────────
  const hasWarning = computed(() =>
    invoices.value.percentage >= WARNING_THRESHOLD
    || companies.value.percentage >= WARNING_THRESHOLD
    || users.value.percentage >= WARNING_THRESHOLD,
  )

  const criticalResources = computed(() => {
    const resources: Array<{ key: string, resource: UsageResource }> = []
    if (invoices.value.percentage >= WARNING_THRESHOLD) {
      resources.push({ key: 'invoices', resource: invoices.value })
    }
    if (companies.value.percentage >= WARNING_THRESHOLD) {
      resources.push({ key: 'companies', resource: companies.value })
    }
    if (users.value.percentage >= WARNING_THRESHOLD) {
      resources.push({ key: 'users', resource: users.value })
    }
    return resources
  })

  // ── Actions ────────────────────────────────────────────────────────
  async function fetchUsage(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const data = await get<UsageData>('/v1/billing/usage')
      invoices.value = data.invoices
      companies.value = data.companies
      users.value = data.users
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca utilizarea.'
    }
    finally {
      loading.value = false
    }
  }

  return {
    // State
    invoices,
    companies,
    users,
    loading,
    error,

    // Getters
    hasWarning,
    criticalResources,

    // Actions
    fetchUsage,
  }
})
