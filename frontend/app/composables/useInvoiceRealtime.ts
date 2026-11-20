/**
 * Refetch when something changes for the current company: an invoice, a payment, an email,
 * an ANAF answer, a sync. The server says what kind of change it was in `type`; `kinds`
 * narrows the events a page reacts to (prefixes of `type`). Without `kinds`, the page reacts
 * to invoice-related events, the way invoice pages always did.
 */
const OTHER_DOCUMENT_KINDS = ['proforma.', 'receipt.', 'delivery_note.', 'recurring_invoice.', 'client.']

export function useInvoiceRealtime(onUpdate: () => void, kinds?: string[]) {
  const { subscribe, unsubscribe } = useCentrifugo()
  const companyStore = useCompanyStore()
  let currentChannel: string | null = null
  let debounceTimer: ReturnType<typeof setTimeout> | null = null
  let hiddenSince: number | null = null

  function debouncedUpdate() {
    if (debounceTimer) clearTimeout(debounceTimer)
    debounceTimer = setTimeout(onUpdate, 500)
  }

  function onMessage(data: any) {
    const type: string = typeof data?.type === 'string' ? data.type : ''
    if (type !== REALTIME_RESYNC) {
      if (kinds) {
        if (!kinds.some(kind => type.startsWith(kind))) return
      }
      else if (OTHER_DOCUMENT_KINDS.some(kind => type.startsWith(kind))) {
        return
      }
    }
    debouncedUpdate()
  }

  // A tab that was in the background for a while may have missed events (the browser
  // throttles or drops the socket): refetch once when it is looked at again.
  function onVisibility() {
    if (document.hidden) {
      hiddenSince = Date.now()
    }
    else if (hiddenSince !== null) {
      const away = Date.now() - hiddenSince
      hiddenSince = null
      if (away > 60_000) debouncedUpdate()
    }
  }

  function start() {
    stop()
    const companyId = companyStore.currentCompanyId
    if (!companyId) return
    currentChannel = `invoices:company_${companyId}`
    subscribe(currentChannel, onMessage, { recover: true, resync: true })
    if (import.meta.client) document.addEventListener('visibilitychange', onVisibility)
  }

  function stop() {
    if (currentChannel) {
      unsubscribe(currentChannel, onMessage)
      currentChannel = null
    }
    if (debounceTimer) {
      clearTimeout(debounceTimer)
      debounceTimer = null
    }
    if (import.meta.client) document.removeEventListener('visibilitychange', onVisibility)
    hiddenSince = null
  }

  return { start, stop }
}
