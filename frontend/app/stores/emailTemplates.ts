import { defineStore } from 'pinia'
import type { EmailTemplate } from '~/types'

export const useEmailTemplateStore = defineStore('emailTemplates', () => {
  const items = ref<EmailTemplate[]>([])
  const availableVariables = ref<string[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchTemplates(category?: string): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const query = category ? `?category=${category}` : ''
      const response = await get<{ data: EmailTemplate[], availableVariables: string[] }>(`/v1/email-templates${query}`)
      items.value = response.data
      availableVariables.value = response.availableVariables ?? []
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca sabloanele de email.'
      items.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function createTemplate(data: { name: string, subject: string, body: string, isDefault?: boolean, category?: string }): Promise<EmailTemplate | null> {
    const { post } = useApi()
    error.value = null
    try {
      const result = await post<EmailTemplate>('/v1/email-templates', data)
      await fetchTemplates(data.category)
      return result
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea sablonul de email.'
      return null
    }
  }

  async function updateTemplate(id: string, data: Partial<EmailTemplate>): Promise<boolean> {
    const { patch } = useApi()
    error.value = null
    try {
      await patch(`/v1/email-templates/${id}`, data)
      // Re-fetch with the category of the updated template
      const category = data.category || items.value.find(t => t.id === id)?.category
      await fetchTemplates(category)
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza sablonul de email.'
      return false
    }
  }

  async function deleteTemplate(id: string): Promise<boolean> {
    const { del } = useApi()
    error.value = null
    try {
      const category = items.value.find(t => t.id === id)?.category
      await del(`/v1/email-templates/${id}`)
      await fetchTemplates(category)
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut sterge sablonul de email.'
      return false
    }
  }

  function $reset() {
    items.value = []
    availableVariables.value = []
    loading.value = false
    error.value = null
  }

  return {
    items, availableVariables, loading, error,
    fetchTemplates, createTemplate, updateTemplate, deleteTemplate, $reset,
  }
})
