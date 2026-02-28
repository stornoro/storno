export function useInvoiceRealtime(onUpdate: () => void) {
  const { subscribe, unsubscribe } = useCentrifugo()
  const companyStore = useCompanyStore()
  let currentChannel: string | null = null
  let debounceTimer: ReturnType<typeof setTimeout> | null = null

  function debouncedUpdate() {
    if (debounceTimer) clearTimeout(debounceTimer)
    debounceTimer = setTimeout(onUpdate, 500)
  }

  function start() {
    stop()
    const companyId = companyStore.currentCompanyId
    if (!companyId) return
    currentChannel = `invoices:company_${companyId}`
    subscribe(currentChannel, debouncedUpdate, { recover: true })
  }

  function stop() {
    if (currentChannel) {
      unsubscribe(currentChannel)
      currentChannel = null
    }
    if (debounceTimer) {
      clearTimeout(debounceTimer)
      debounceTimer = null
    }
  }

  return { start, stop }
}
