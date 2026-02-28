import { defineStore } from 'pinia'
import type { BankAccount } from '~/types'

export const useBankAccountStore = defineStore('bankAccounts', () => {
  const items = ref<BankAccount[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchBankAccounts(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const response = await get<{ data: BankAccount[] }>('/v1/bank-accounts')
      items.value = response.data
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca conturile bancare.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function createBankAccount(data: { iban: string, bankName?: string, currency?: string, isDefault?: boolean }): Promise<BankAccount | null> {
    const { post } = useApi()
    error.value = null
    try {
      const result = await post<BankAccount>('/v1/bank-accounts', data)
      await fetchBankAccounts()
      return result
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea contul bancar.'
      return null
    }
  }

  async function updateBankAccount(id: string, data: Partial<BankAccount>): Promise<boolean> {
    const { patch } = useApi()
    error.value = null
    try {
      await patch(`/v1/bank-accounts/${id}`, data)
      await fetchBankAccounts()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza contul bancar.'
      return false
    }
  }

  async function deleteBankAccount(id: string): Promise<boolean> {
    const { del } = useApi()
    error.value = null
    try {
      await del(`/v1/bank-accounts/${id}`)
      await fetchBankAccounts()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge contul bancar.'
      return false
    }
  }

  function $reset() {
    items.value = []
    loading.value = false
    error.value = null
  }

  return {
    items, loading, error,
    fetchBankAccounts, createBankAccount, updateBankAccount, deleteBankAccount, $reset,
  }
})
