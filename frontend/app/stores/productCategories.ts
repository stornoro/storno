import { defineStore } from 'pinia'
import type { ProductCategory } from '~/types'

export const useProductCategoriesStore = defineStore('productCategories', () => {
  const items = ref<ProductCategory[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchCategories(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null
    try {
      const response = await get<{ data: ProductCategory[] }>('/v1/product-categories')
      items.value = response.data
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca categoriile.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function createCategory(data: { name: string, color?: string | null, sortOrder?: number }): Promise<ProductCategory | null> {
    const { post } = useApi()
    error.value = null
    try {
      const result = await post<ProductCategory>('/v1/product-categories', data)
      await fetchCategories()
      return result
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea categoria.'
      return null
    }
  }

  async function updateCategory(id: string, data: Partial<ProductCategory>): Promise<boolean> {
    const { patch } = useApi()
    error.value = null
    try {
      await patch(`/v1/product-categories/${id}`, data)
      await fetchCategories()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza categoria.'
      return false
    }
  }

  async function deleteCategory(id: string): Promise<boolean> {
    const { del } = useApi()
    error.value = null
    try {
      await del(`/v1/product-categories/${id}`)
      await fetchCategories()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge categoria.'
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
    fetchCategories, createCategory, updateCategory, deleteCategory, $reset,
  }
})
