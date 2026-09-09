/**
 * An individual person (persoană fizică, company type "individual") neither sells nor keeps a
 * ledger: the pages about issuing documents, products, series, VAT and sales reports are
 * hidden for them, in the sidebar and by the global route guard alike. The rest stays:
 * invoices received, e-Factura, declarations, SPV, dosare, clients, suppliers, settings.
 */
export const INDIVIDUAL_HIDDEN_PATHS = [
  '/recurring-invoices', '/proforma-invoices', '/delivery-notes', '/receipts', '/products', '/reports',
  '/settings/document-series', '/settings/product-categories', '/settings/vat-rates', '/settings/pdf-templates',
  '/settings/white-label', '/settings/email-sender', '/settings/email-templates', '/settings/borderou',
] as const

export function isHiddenForIndividual(path: string | undefined | null): boolean {
  if (!path) return false
  return INDIVIDUAL_HIDDEN_PATHS.some(p => path === p || path.startsWith(p + '/'))
}

export function useIndividual() {
  const companyStore = useCompanyStore()
  const isIndividual = computed(() => !!companyStore.currentCompany?.isIndividual)
  /** Keep a navigation entry unless the current company is a person and the entry is about selling. */
  function visibleForCompany<T extends { to?: unknown }>(entry: T): boolean {
    return !isIndividual.value || !isHiddenForIndividual(typeof entry.to === 'string' ? entry.to : null)
  }
  return { isIndividual, visibleForCompany, isHiddenForIndividual }
}
