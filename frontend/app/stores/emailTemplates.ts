import { defineStore } from 'pinia'
import type { EmailTemplate } from '~/types'

export const useEmailTemplateStore = defineStore('emailTemplates', () => {
  const items = ref<EmailTemplate[]>([])
  const availableVariables = ref<string[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchTemplates(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const response = await get<{ data: EmailTemplate[], availableVariables: string[] }>('/v1/email-templates')
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

  async function createTemplate(data: { name: string, subject: string, body: string, isDefault?: boolean }): Promise<EmailTemplate | null> {
    const { post } = useApi()
    error.value = null
    try {
      const result = await post<EmailTemplate>('/v1/email-templates', data)
      await fetchTemplates()
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
      await fetchTemplates()
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
      await del(`/v1/email-templates/${id}`)
      await fetchTemplates()
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
