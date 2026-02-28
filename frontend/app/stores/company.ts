import { defineStore } from 'pinia'
import type { Company } from '~/types'

export const useCompanyStore = defineStore('company', () => {
  // ── State ──────────────────────────────────────────────────────────
  // Cookie sync for currentCompanyId is handled by plugins/auth-cookies.ts
  const companies = ref<Company[]>([])
  const deletedCompanies = ref<Company[]>([])
  const currentCompanyId = ref<string | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const errorCode = ref<string | null>(null)

  // ── Getters ────────────────────────────────────────────────────────
  const currentCompany = computed<Company | null>(() => {
    if (!currentCompanyId.value) return null
    return companies.value.find(c => c.id === currentCompanyId.value) ?? null
  })

  const hasCompanies = computed(() => companies.value.length > 0)

  const syncEnabledCompanies = computed(() =>
    companies.value.filter(c => c.syncEnabled),
  )

  const isCurrentCompanyReadOnly = computed(() =>
    currentCompany.value?.isReadOnly === true,
  )

  // ── Actions ────────────────────────────────────────────────────────
  async function fetchCompanies(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const response = await get<{ data: Company[] }>('/v1/companies')
      companies.value = response.data

      // Auto-select first company if none selected or selected no longer exists
      if (companies.value.length > 0) {
        const stillExists = companies.value.some(c => c.id === currentCompanyId.value)
        if (!currentCompanyId.value || !stillExists) {
          selectCompany(companies.value[0].id)
        }
      }
      else {
        currentCompanyId.value = null
      }
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca companiile.'
    }
    finally {
      loading.value = false
    }
  }

  function selectCompany(companyId: string) {
    if (currentCompanyId.value === companyId) return
    currentCompanyId.value = companyId
    // Reset all company-scoped stores so stale data isn't shown
    useDashboardStore().$reset()
    useInvoiceStore().$reset()
    useClientStore().$reset()
    useProductStore().$reset()
    useSyncStore().$reset()
    useSupplierStore().$reset()
    useBankAccountStore().$reset()
    useDocumentSeriesStore().$reset()
    useEFacturaMessageStore().$reset()
    useRecurringInvoiceStore().$reset()
    useProformaInvoiceStore().$reset()
  }

  async function createCompany(cif: string): Promise<Company | null> {
    const { post } = useApi()
    loading.value = true
    error.value = null
    errorCode.value = null

    try {
      const company = await post<Company>('/v1/companies', { cif })
      companies.value.push(company)

      // Auto-select if it is the first company
      if (companies.value.length === 1) {
        selectCompany(company.id)
      }

      return company
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea compania.'
      errorCode.value = err?.data?.code ?? null
      return null
    }
    finally {
      loading.value = false
    }
  }

  async function toggleSync(companyId: string): Promise<boolean> {
    const { post } = useApi()
    error.value = null

    try {
      const response = await post<{ syncEnabled: boolean; message: string }>(
        `/v1/companies/${companyId}/toggle-sync`,
      )

      // Update local state
      const company = companies.value.find(c => c.id === companyId)
      if (company) {
        company.syncEnabled = response.syncEnabled
      }

      return response.syncEnabled
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut comuta sincronizarea.'
      return false
    }
  }

  async function updateCompany(
    companyId: string,
    data: Partial<Company>,
  ): Promise<Company | null> {
    const { patch } = useApi()
    error.value = null

    try {
      const updated = await patch<Company>(`/v1/companies/${companyId}`, data)

      // Update local state
      const index = companies.value.findIndex(c => c.id === companyId)
      if (index !== -1) {
        companies.value[index] = { ...companies.value[index], ...updated }
      }

      return updated
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza compania.'
      return null
    }
  }

  async function refreshFromAnaf(companyId: string): Promise<Company | null> {
    const { post } = useApi()
    error.value = null

    try {
      const updated = await post<Company>(`/v1/companies/${companyId}/refresh-anaf`)

      const index = companies.value.findIndex(c => c.id === companyId)
      if (index !== -1) {
        companies.value[index] = { ...companies.value[index], ...updated }
      }

      return updated
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut reincarca datele de la ANAF.'
      return null
    }
  }

  async function resetCompany(companyId: string): Promise<boolean> {
    const { post } = useApi()
    error.value = null

    try {
      await post(`/v1/companies/${companyId}/reset`)

      // Reset all company-scoped stores since data was wiped
      useDashboardStore().$reset()
      useInvoiceStore().$reset()
      useClientStore().$reset()
      useProductStore().$reset()
      useSyncStore().$reset()
      useSupplierStore().$reset()
      useBankAccountStore().$reset()
      useDocumentSeriesStore().$reset()
      useEFacturaMessageStore().$reset()
      useRecurringInvoiceStore().$reset()
      useProformaInvoiceStore().$reset()

      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut reseta compania.'
      return false
    }
  }

  async function deleteCompany(companyId: string): Promise<boolean> {
    const { del } = useApi()
    error.value = null

    try {
      await del<{ message: string; deletedAt: string; hardDeleteAt: string }>(`/v1/companies/${companyId}`)
      companies.value = companies.value.filter(c => c.id !== companyId)

      // Re-select if deleted company was the current one
      if (currentCompanyId.value === companyId) {
        if (companies.value.length > 0) {
          selectCompany(companies.value[0].id)
        }
        else {
          currentCompanyId.value = null
        }
      }

      // Refresh deleted companies list
      await fetchDeletedCompanies()

      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge compania.'
      return false
    }
  }

  async function fetchDeletedCompanies(): Promise<void> {
    const { get } = useApi()
    try {
      const response = await get<{ data: Company[] }>('/v1/companies/deleted')
      deletedCompanies.value = response.data
    }
    catch {
      deletedCompanies.value = []
    }
  }

  async function setActiveCompany(companyId: string): Promise<boolean> {
    const { put } = useApi()
    error.value = null

    try {
      const response = await put<{ data: Company[] }>(`/v1/companies/${companyId}/set-active`)
      companies.value = response.data
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut seta compania ca activa.'
      return false
    }
  }

  async function restoreCompany(companyId: string): Promise<boolean> {
    const { post } = useApi()
    error.value = null

    try {
      const restored = await post<Company>(`/v1/companies/${companyId}/restore`)
      // Add back to companies list
      companies.value.push(restored)
      // Remove from deleted list
      deletedCompanies.value = deletedCompanies.value.filter(c => c.id !== companyId)

      // Auto-select if it's the only company
      if (companies.value.length === 1) {
        selectCompany(restored.id)
      }

      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut restaura compania.'
      return false
    }
  }

  return {
    // State
    companies,
    deletedCompanies,
    currentCompanyId,
    loading,
    error,
    errorCode,

    // Getters
    currentCompany,
    hasCompanies,
    syncEnabledCompanies,
    isCurrentCompanyReadOnly,

    // Actions
    fetchCompanies,
    fetchDeletedCompanies,
    selectCompany,
    createCompany,
    toggleSync,
    updateCompany,
    refreshFromAnaf,
    resetCompany,
    deleteCompany,
    restoreCompany,
    setActiveCompany,
  }
})
