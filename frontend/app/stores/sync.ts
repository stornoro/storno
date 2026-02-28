import { defineStore } from 'pinia'

export interface SyncStatusResponse {
  syncEnabled: boolean
  lastSyncedAt: string | null
  hasValidToken: boolean
  tokenError: string | null
  syncDaysBack: number
  syncInterval: number
}

export interface SyncStats {
  newInvoices: number
  skippedDuplicates: number
  newClients: number
  newProducts: number
  errors: string[]
}

export interface SyncTriggerResponse {
  success: boolean
  stats: SyncStats
}

export interface SyncLogEntry {
  id: string
  number: string
  direction: string | null
  status: string
  syncedAt: string | null
  senderName: string | null
  receiverName: string | null
  total: string
  currency: string
}

export const useSyncStore = defineStore('sync', () => {
  // ── State ──────────────────────────────────────────────────────────
  const syncStatus = ref<SyncStatusResponse | null>(null)
  const syncLog = ref<SyncLogEntry[]>([])
  const syncLogTotal = ref(0)
  const syncLogLoading = ref(false)
  const syncing = ref(false)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const lastSyncResult = ref<SyncTriggerResponse | null>(null)
  const lastSyncError = ref<{ message: string, code: string | null } | null>(null)
  const syncProgress = ref<{ processed: number, total: number, stats?: SyncStats } | null>(null)

  // Track whether we already showed a toast for the current sync cycle
  let _toastShownForCurrentSync = false

  // ── Getters ────────────────────────────────────────────────────────
  const isSyncEnabled = computed(() => syncStatus.value?.syncEnabled ?? false)
  const hasValidToken = computed(() => syncStatus.value?.hasValidToken ?? false)
  const tokenError = computed(() => syncStatus.value?.tokenError ?? null)
  const canSync = computed(() => isSyncEnabled.value && hasValidToken.value && !syncing.value)

  const lastSyncedAt = computed(() => syncStatus.value?.lastSyncedAt ?? null)

  const nextSyncAt = computed(() => {
    const last = syncStatus.value?.lastSyncedAt
    const interval = syncStatus.value?.syncInterval
    if (!last || !interval) return null
    return new Date(new Date(last).getTime() + interval * 1000).toISOString()
  })

  const lastSyncHadErrors = computed(() =>
    (lastSyncResult.value?.stats?.errors?.length ?? 0) > 0,
  )

  // ── Actions ────────────────────────────────────────────────────────
  async function fetchStatus(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      syncStatus.value = await get<SyncStatusResponse>('/v1/sync/status')
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca statusul sincronizarii.'
    }
    finally {
      loading.value = false
    }
  }

  const hasMoreLog = computed(() => syncLog.value.length < syncLogTotal.value)

  async function fetchLog(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const response = await get<{ entries: SyncLogEntry[], total: number }>('/v1/sync/log', { limit: 10, offset: 0 })
      syncLog.value = response.entries
      syncLogTotal.value = response.total
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca jurnalul sincronizarii.'
    }
    finally {
      loading.value = false
    }
  }

  async function fetchMoreLog(): Promise<void> {
    if (syncLogLoading.value || !hasMoreLog.value) return
    const { get } = useApi()
    syncLogLoading.value = true

    try {
      const response = await get<{ entries: SyncLogEntry[], total: number }>('/v1/sync/log', { limit: 10, offset: syncLog.value.length })
      syncLog.value = [...syncLog.value, ...response.entries]
      syncLogTotal.value = response.total
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca jurnalul sincronizarii.'
    }
    finally {
      syncLogLoading.value = false
    }
  }

  async function triggerSync(days?: number): Promise<boolean> {
    const { post } = useApi()
    syncing.value = true
    error.value = null
    lastSyncResult.value = null
    lastSyncError.value = null
    syncProgress.value = { processed: 0, total: 0 }
    _toastShownForCurrentSync = false

    try {
      const body = days !== undefined ? { days } : undefined
      await post('/v1/sync/trigger', body)
      // 202 returned — sync is running in background
      // Real-time updates via Centrifugo will update syncProgress with real totals
      useToast().add({ title: useNuxtApp().$i18n.t('sync.syncStarted'), color: 'info' })
      return true
    }
    catch (err: any) {
      // Pre-validation errors (NO_TOKEN, PLAN_LIMIT, etc.) come as 4xx
      const message = err?.data?.error ? translateApiError(err.data.error) : 'Sincronizarea a esuat.'
      const code = err?.data?.code ?? null
      error.value = message
      lastSyncError.value = { message, code }
      syncing.value = false
      syncProgress.value = null
      useToast().add({ title: message, color: 'error' })
      return false
    }
  }

  function handleSyncEvent(data: any) {
    switch (data.type) {
      case 'sync.started':
        syncing.value = true
        syncProgress.value = { processed: 0, total: data.total }
        _toastShownForCurrentSync = false
        break
      case 'sync.progress':
        syncProgress.value = { processed: data.processed, total: data.total, stats: data.stats }
        break
      case 'sync.completed':
        syncing.value = false
        syncProgress.value = null
        lastSyncResult.value = { success: true, stats: data.stats }
        if (!_toastShownForCurrentSync) {
          _toastShownForCurrentSync = true
          useToast().add({ title: useNuxtApp().$i18n.t('sync.syncComplete'), color: 'success' })
        }
        fetchStatus()
        fetchLog()
        break
      case 'sync.error':
        syncing.value = false
        syncProgress.value = null
        lastSyncError.value = { message: data.error || data.errors?.[0] || 'Eroare sincronizare', code: data.code || null }
        if (!_toastShownForCurrentSync) {
          _toastShownForCurrentSync = true
          useToast().add({ title: data.error || data.errors?.[0] || 'Eroare sincronizare', color: 'error' })
        }
        break
    }
  }

  function clearSyncError() {
    lastSyncError.value = null
  }

  function $reset() {
    syncStatus.value = null
    syncLog.value = []
    syncLogTotal.value = 0
    syncing.value = false
    loading.value = false
    error.value = null
    lastSyncResult.value = null
    lastSyncError.value = null
    syncProgress.value = null
  }

  return {
    // State
    syncStatus,
    syncLog,
    syncing,
    loading,
    error,
    lastSyncResult,
    lastSyncError,
    syncProgress,

    // Getters
    isSyncEnabled,
    hasValidToken,
    tokenError,
    canSync,
    lastSyncedAt,
    nextSyncAt,
    lastSyncHadErrors,

    // Actions
    fetchStatus,
    fetchLog,
    fetchMoreLog,
    syncLogLoading,
    syncLogTotal,
    hasMoreLog,
    triggerSync,
    handleSyncEvent,
    clearSyncError,
    $reset,
  }
})
