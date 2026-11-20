export function useSyncRealtime() {
  const { subscribe, unsubscribe } = useCentrifugo()
  const companyStore = useCompanyStore()
  const syncStore = useSyncStore()
  let currentChannel: string | null = null

  function onMessage(data: any) {
    if (data?.type?.startsWith('sync.')) {
      syncStore.handleSyncEvent(data)
    }
  }

  function start() {
    stop()
    const companyId = companyStore.currentCompanyId
    if (!companyId) return
    currentChannel = `invoices:company_${companyId}`
    subscribe(currentChannel, onMessage)
  }

  function stop() {
    // Only our listener goes: useInvoiceRealtime may be on the same channel
    if (currentChannel) unsubscribe(currentChannel, onMessage)
    currentChannel = null
  }

  return { start, stop }
}
