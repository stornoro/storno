import { defineStore } from 'pinia'
import type { PdfTemplateConfig, PdfTemplateInfo } from '~/types'

export const usePdfTemplateConfigStore = defineStore('pdfTemplateConfig', () => {
  // ── State ──────────────────────────────────────────────────────────
  const config = ref<PdfTemplateConfig | null>(null)
  const templates = ref<PdfTemplateInfo[]>([])
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)
  const previewHtml = ref<string>('')
  const previewLoading = ref(false)

  // ── Actions ────────────────────────────────────────────────────────
  async function fetchConfig(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      config.value = await get<PdfTemplateConfig>('/v1/pdf-template-config')
    } catch (err: any) {
      error.value = err?.data?.error ?? 'Failed to load PDF config.'
    } finally {
      loading.value = false
    }
  }

  async function updateConfig(data: Partial<PdfTemplateConfig>): Promise<void> {
    const { put } = useApi()
    saving.value = true
    error.value = null

    try {
      config.value = await put<PdfTemplateConfig>('/v1/pdf-template-config', data)
    } catch (err: any) {
      error.value = err?.data?.error ?? 'Failed to save PDF config.'
    } finally {
      saving.value = false
    }
  }

  async function fetchTemplates(): Promise<void> {
    const { get } = useApi()

    try {
      templates.value = await get<PdfTemplateInfo[]>('/v1/pdf-template-config/templates')
    } catch (err: any) {
      error.value = err?.data?.error ?? 'Failed to load templates.'
    }
  }

  async function fetchPreviewHtml(slug: string, color?: string | null, font?: string | null): Promise<void> {
    const { post } = useApi()
    previewLoading.value = true

    try {
      const html = await post<string>('/v1/pdf-template-config/preview', {
        templateSlug: slug,
        primaryColor: color,
        fontFamily: font,
      })
      previewHtml.value = typeof html === 'string' ? html : ''
    } catch {
      previewHtml.value = ''
    } finally {
      previewLoading.value = false
    }
  }

  async function uploadLogo(companyId: string, file: File): Promise<void> {
    const { apiFetch } = useApi()
    const formData = new FormData()
    formData.append('logo', file)

    try {
      await apiFetch(`/v1/companies/${companyId}/logo`, {
        method: 'POST',
        body: formData,
      })
    } catch (err: any) {
      error.value = err?.data?.error ?? 'Failed to upload logo.'
      throw err
    }
  }

  async function deleteLogo(companyId: string): Promise<void> {
    const { del } = useApi()

    try {
      await del(`/v1/companies/${companyId}/logo`)
    } catch (err: any) {
      error.value = err?.data?.error ?? 'Failed to delete logo.'
      throw err
    }
  }

  return {
    config,
    templates,
    loading,
    saving,
    error,
    previewHtml,
    previewLoading,
    fetchConfig,
    updateConfig,
    fetchTemplates,
    fetchPreviewHtml,
    uploadLogo,
    deleteLogo,
  }
})
