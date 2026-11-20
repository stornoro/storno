/**
 * Keeps a page in step with the server: `onUpdate` runs when a document of one of the given
 * kinds changes for the current company, whoever changed it. Kinds are prefixes of the event
 * type: 'proforma.', 'receipt.', 'delivery_note.', 'recurring_invoice.', 'client.'.
 *
 * Call it once in a page's setup: it starts on mount, follows the selected company and stops
 * on unmount.
 */
export function useDocumentRealtime(kinds: string[], onUpdate: () => void) {
  const companyStore = useCompanyStore()
  const realtime = useInvoiceRealtime(onUpdate, kinds)

  onMounted(() => realtime.start())
  onUnmounted(() => realtime.stop())
  watch(() => companyStore.currentCompanyId, () => realtime.start())

  return realtime
}
