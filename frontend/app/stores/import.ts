import { defineStore } from 'pinia'

interface ImportSource {
  key: string
  label: string
  importTypes: string[]
  formats: string[]
}

interface ImportJob {
  id: string
  importType: string
  source: string
  fileFormat: string
  originalFilename: string | null
  status: string
  columnMapping: Record<string, string> | null
  detectedColumns: string[] | null
  suggestedMapping: Record<string, string> | null
  previewData: Record<string, string>[] | null
  totalRows: number
  createdCount: number
  updatedCount: number
  skippedCount: number
  errorCount: number
  errors: Array<{ row: number; field: string; message: string }> | null
  processedAt: string | null
  createdAt: string
}

interface ImportProgress {
  jobId: string
  status: string
  totalRows: number
  processed: number
  created: number
  updated: number
  skipped: number
  errors: number
}

export const useImportStore = defineStore('import', () => {
  // ── State ──────────────────────────────────────────────────────────
  const sources = ref<ImportSource[]>([])
  const importTypes = ref<Array<{ value: string; label: string }>>([])
  const currentJob = ref<ImportJob | null>(null)
  const history = ref<ImportJob[]>([])
  const targetFields = ref<Record<string, string>>({})
  const loading = ref(false)
  const uploading = ref(false)
  const executing = ref(false)
  const error = ref<string | null>(null)
  const progress = ref<ImportProgress | null>(null)

  // ── Actions ────────────────────────────────────────────────────────
  async function fetchSources(): Promise<void> {
    const { get } = useApi()
    try {
      const res = await get<{ sources: ImportSource[]; importTypes: Array<{ value: string; label: string }> }>('/v1/import/sources')
      sources.value = res.sources
      importTypes.value = res.importTypes
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca sursele.'
    }
  }

  async function uploadFile(file: File, importType: string, source: string): Promise<ImportJob | null> {
    const { apiFetch } = useApi()
    uploading.value = true
    error.value = null
    try {
      const formData = new FormData()
      formData.append('file', file)
      formData.append('importType', importType)
      formData.append('source', source)

      const res = await apiFetch<{ job: ImportJob; targetFields?: Record<string, string> }>('/v1/import/upload', {
        method: 'POST',
        body: formData,
      })
      currentJob.value = res.job
      if (res.targetFields) {
        targetFields.value = res.targetFields
      }
      return res.job
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Incarcarea a esuat.'
      return null
    }
    finally {
      uploading.value = false
    }
  }

  async function fetchPreview(jobId: string): Promise<void> {
    const { get } = useApi()
    loading.value = true
    try {
      const res = await get<{ job: ImportJob; targetFields: Record<string, string> }>(`/v1/import/${jobId}/preview`)
      currentJob.value = res.job
      targetFields.value = res.targetFields
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca previzualizarea.'
    }
    finally {
      loading.value = false
    }
  }

  async function updateMapping(jobId: string, columnMapping: Record<string, string>): Promise<boolean> {
    const { patch } = useApi()
    try {
      const res = await patch<{ job: ImportJob }>(`/v1/import/${jobId}/mapping`, { columnMapping })
      currentJob.value = res.job
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut salva maparea.'
      return false
    }
  }

  async function executeImport(jobId: string, importOptions?: Record<string, any>): Promise<boolean> {
    const { post } = useApi()
    executing.value = true
    error.value = null
    try {
      const res = await post<{ job: ImportJob }>(`/v1/import/${jobId}/execute`, importOptions ? { importOptions } : undefined)
      currentJob.value = res.job
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut porni importul.'
      return false
    }
    finally {
      executing.value = false
    }
  }

  async function fetchJob(jobId: string): Promise<void> {
    const { get } = useApi()
    try {
      const res = await get<{ job: ImportJob }>(`/v1/import/${jobId}`)
      currentJob.value = res.job
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca job-ul.'
    }
  }

  async function fetchHistory(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    try {
      const res = await get<{ data: ImportJob[] }>('/v1/import/history')
      history.value = res.data
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca istoricul.'
    }
    finally {
      loading.value = false
    }
  }

  async function downloadTemplate(importType: string): Promise<void> {
    const { apiFetch } = useApi()
    try {
      const blob = await apiFetch<Blob>(`/v1/import/template?importType=${importType}`, {
        method: 'GET',
        responseType: 'blob',
      })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `model_import_${importType}.csv`
      a.click()
      URL.revokeObjectURL(url)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut descarca sablonul.'
    }
  }

  function handleProgress(data: ImportProgress): void {
    progress.value = data
    if (currentJob.value && data.jobId === currentJob.value.id) {
      currentJob.value.status = data.status
      currentJob.value.createdCount = data.created
      currentJob.value.updatedCount = data.updated
      currentJob.value.skippedCount = data.skipped
      currentJob.value.errorCount = data.errors
    }
  }

  function $reset(): void {
    sources.value = []
    importTypes.value = []
    currentJob.value = null
    history.value = []
    targetFields.value = {}
    loading.value = false
    uploading.value = false
    executing.value = false
    error.value = null
    progress.value = null
  }

  return {
    // State
    sources,
    importTypes,
    currentJob,
    history,
    targetFields,
    loading,
    uploading,
    executing,
    error,
    progress,

    // Actions
    fetchSources,
    uploadFile,
    fetchPreview,
    updateMapping,
    executeImport,
    fetchJob,
    fetchHistory,
    downloadTemplate,
    handleProgress,
    $reset,
  }
})
