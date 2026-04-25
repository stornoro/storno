import { defineStore } from 'pinia'

export interface MonthlyTotal {
  month: string
  incoming: string
  outgoing: string
}

export interface PaymentStats {
  outstandingCount: number
  outstandingAmount: string
  overdueCount: number
  overdueAmount: string
}

export interface PreviousPeriod {
  from: string
  to: string
  invoices: { total: number; incoming: number; outgoing: number }
  amounts: { total: string; vat: string }
  amountsByDirection: { incoming: string; outgoing: string }
  clientCount: number
  productCount: number
}

export interface DeltaResult {
  value: number | null
  direction: 'up' | 'down' | 'flat'
}

export interface DashboardStatsResponse {
  invoices: {
    total: number
    incoming: number
    outgoing: number
  }
  byStatus: Record<string, number>
  amounts: {
    total: string
    vat: string
  }
  clientCount: number
  productCount: number
  lastSyncedAt: string | null
  syncEnabled: boolean
  recentActivity: RecentActivityItem[]
  monthlyTotals: MonthlyTotal[]
  amountsByDirection: {
    incoming: string
    outgoing: string
  }
  payments?: PaymentStats
  currency?: string
  previousPeriod?: PreviousPeriod | null
}

export interface RecentActivityItem {
  id: string
  number: string
  direction: string | null
  status: string
  total: string
  currency: string
  senderName: string | null
  receiverName: string | null
  issueDate: string | null
  syncedAt: string | null
  paidAt: string | null
}

export const useDashboardStore = defineStore('dashboard', () => {
  // ── State ──────────────────────────────────────────────────────────
  const stats = ref<DashboardStatsResponse | null>(null)
  const loading = ref(true)
  const error = ref<string | null>(null)

  // ── Getters ────────────────────────────────────────────────────────
  const recentActivity = computed<RecentActivityItem[]>(() =>
    stats.value?.recentActivity ?? [],
  )

  const totalInvoices = computed(() => stats.value?.invoices.total ?? 0)
  const incomingInvoices = computed(() => stats.value?.invoices.incoming ?? 0)
  const outgoingInvoices = computed(() => stats.value?.invoices.outgoing ?? 0)
  const totalAmount = computed(() => stats.value?.amounts.total ?? '0.00')
  const totalVat = computed(() => stats.value?.amounts.vat ?? '0.00')
  const clientCount = computed(() => stats.value?.clientCount ?? 0)
  const productCount = computed(() => stats.value?.productCount ?? 0)
  const isSyncEnabled = computed(() => stats.value?.syncEnabled ?? false)
  const lastSyncedAt = computed(() => stats.value?.lastSyncedAt ?? null)

  const invoicesByStatus = computed<Record<string, number>>(() =>
    stats.value?.byStatus ?? {},
  )

  const monthlyTotals = computed<MonthlyTotal[]>(() =>
    stats.value?.monthlyTotals ?? [],
  )

  const incomingAmount = computed(() =>
    stats.value?.amountsByDirection?.incoming ?? '0.00',
  )

  const outgoingAmount = computed(() =>
    stats.value?.amountsByDirection?.outgoing ?? '0.00',
  )

  const outstandingCount = computed(() => stats.value?.payments?.outstandingCount ?? 0)
  const outstandingAmount = computed(() => stats.value?.payments?.outstandingAmount ?? '0.00')
  const overdueCount = computed(() => stats.value?.payments?.overdueCount ?? 0)
  const overdueAmount = computed(() => stats.value?.payments?.overdueAmount ?? '0.00')
  const currency = computed(() => stats.value?.currency ?? 'RON')

  // ── Previous period ────────────────────────────────────────────────
  const previousPeriod = computed<PreviousPeriod | null>(
    () => stats.value?.previousPeriod ?? null,
  )

  function calcDelta(current: number, previous: number): DeltaResult {
    if (previous === 0) return { value: null, direction: 'flat' }
    const pct = ((current - previous) / previous) * 100
    const direction = pct > 0 ? 'up' : pct < 0 ? 'down' : 'flat'
    return { value: pct, direction }
  }

  function numericDelta(current: number, previous: number | null): DeltaResult {
    if (previous === null || previousPeriod.value === null) return { value: null, direction: 'flat' }
    return calcDelta(current, previous)
  }

  const outgoingAmountDelta = computed<DeltaResult>(() => {
    const prev = previousPeriod.value
    if (!prev) return { value: null, direction: 'flat' }
    return numericDelta(Number(outgoingAmount.value), Number(prev.amountsByDirection.outgoing))
  })

  const incomingAmountDelta = computed<DeltaResult>(() => {
    const prev = previousPeriod.value
    if (!prev) return { value: null, direction: 'flat' }
    return numericDelta(Number(incomingAmount.value), Number(prev.amountsByDirection.incoming))
  })

  const totalAmountDelta = computed<DeltaResult>(() => {
    const prev = previousPeriod.value
    if (!prev) return { value: null, direction: 'flat' }
    return numericDelta(Number(totalAmount.value), Number(prev.amounts.total))
  })

  const totalVatDelta = computed<DeltaResult>(() => {
    const prev = previousPeriod.value
    if (!prev) return { value: null, direction: 'flat' }
    return numericDelta(Number(totalVat.value), Number(prev.amounts.vat))
  })

  const totalInvoicesDelta = computed<DeltaResult>(() => {
    const prev = previousPeriod.value
    if (!prev) return { value: null, direction: 'flat' }
    return numericDelta(totalInvoices.value, prev.invoices.total)
  })

  const outgoingInvoicesDelta = computed<DeltaResult>(() => {
    const prev = previousPeriod.value
    if (!prev) return { value: null, direction: 'flat' }
    return numericDelta(outgoingInvoices.value, prev.invoices.outgoing)
  })

  const incomingInvoicesDelta = computed<DeltaResult>(() => {
    const prev = previousPeriod.value
    if (!prev) return { value: null, direction: 'flat' }
    return numericDelta(incomingInvoices.value, prev.invoices.incoming)
  })

  const clientCountDelta = computed<DeltaResult>(() => {
    const prev = previousPeriod.value
    if (!prev) return { value: null, direction: 'flat' }
    return numericDelta(clientCount.value, prev.clientCount)
  })

  const productCountDelta = computed<DeltaResult>(() => {
    const prev = previousPeriod.value
    if (!prev) return { value: null, direction: 'flat' }
    return numericDelta(productCount.value, prev.productCount)
  })

  // ── Actions ────────────────────────────────────────────────────────
  async function fetchStats(params?: { dateFrom?: string; dateTo?: string }): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const query: Record<string, string> = {}
      if (params?.dateFrom) query.dateFrom = params.dateFrom
      if (params?.dateTo) query.dateTo = params.dateTo

      const queryString = Object.keys(query).length
        ? '?' + new URLSearchParams(query).toString()
        : ''

      stats.value = await get<DashboardStatsResponse>(`/v1/dashboard/stats${queryString}`)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca statisticile.'
    }
    finally {
      loading.value = false
    }
  }

  function $reset() {
    stats.value = null
    loading.value = false
    error.value = null
  }

  return {
    // State
    stats,
    loading,
    error,

    // Getters
    recentActivity,
    totalInvoices,
    incomingInvoices,
    outgoingInvoices,
    totalAmount,
    totalVat,
    clientCount,
    productCount,
    isSyncEnabled,
    lastSyncedAt,
    invoicesByStatus,
    monthlyTotals,
    incomingAmount,
    outgoingAmount,
    outstandingCount,
    outstandingAmount,
    overdueCount,
    overdueAmount,
    currency,
    previousPeriod,

    // Delta getters
    outgoingAmountDelta,
    incomingAmountDelta,
    totalAmountDelta,
    totalVatDelta,
    totalInvoicesDelta,
    outgoingInvoicesDelta,
    incomingInvoicesDelta,
    clientCountDelta,
    productCountDelta,

    // Actions
    fetchStats,
    $reset,
  }
})
