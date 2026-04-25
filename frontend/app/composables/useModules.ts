export const MODULE_KEYS = {
  DELIVERY_NOTES: 'delivery_notes',
  RECEIPTS: 'receipts',
  PROFORMA_INVOICES: 'proforma_invoices',
  RECURRING_INVOICES: 'recurring_invoices',
  REPORTS: 'reports',
  CASH_REGISTER: 'cash_register',
  EFACTURA: 'efactura',
  SPV_MESSAGES: 'spv_messages',
} as const

export function useModules() {
  const companyStore = useCompanyStore()

  function isModuleEnabled(key: string): boolean {
    const modules = companyStore.currentCompany?.enabledModules
    if (modules == null) return true
    return modules.includes(key)
  }

  return { isModuleEnabled, MODULE_KEYS }
}
