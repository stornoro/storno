export function useSyncRealtime() {
  const { subscribe } = useCentrifugo()
  const companyStore = useCompanyStore()
  const syncStore = useSyncStore()
  let currentChannel: string | null = null

  function start() {
    stop()
    const companyId = companyStore.currentCompanyId
    if (!companyId) return
    currentChannel = `invoices:company_${companyId}`
    subscribe(currentChannel, (data: any) => {
      if (data?.type?.startsWith('sync.')) {
        syncStore.handleSyncEvent(data)
      }
    })
  }

  function stop() {
    // Don't unsubscribe the channel — useInvoiceRealtime may be using it
    // Just clear our reference
    currentChannel = null
  }

  return { start, stop }
}
