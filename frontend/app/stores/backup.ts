import { defineStore } from 'pinia'

interface BackupJob {
  id: string
  type: 'backup' | 'restore'
  status: 'pending' | 'processing' | 'completed' | 'failed'
  progress: number
  currentStep: string | null
  filename: string | null
  fileSize: number | null
  metadata: Record<string, number> | null
  errorMessage: string | null
  completedAt: string | null
  createdAt: string
}

interface BackupProgress {
  event: string
  jobId: string
  progress: number
  step?: string
  error?: string
  filename?: string
  fileSize?: number
  entityCounts?: Record<string, number>
}

export const useBackupStore = defineStore('backup', () => {
  // ── State ──────────────────────────────────────────────────────────
  const history = ref<BackupJob[]>([])
  const currentJob = ref<BackupJob | null>(null)
  const loading = ref(false)
  const creating = ref(false)
  const uploading = ref(false)
  const error = ref<string | null>(null)

  // ── Actions ────────────────────────────────────────────────────────
  async function createBackup(includeFiles: boolean = true): Promise<BackupJob | null> {
    const { post } = useApi()
    creating.value = true
    error.value = null
    try {
      const res = await post<{ job: BackupJob }>('/v1/backup', { includeFiles })
      currentJob.value = res.job
      return res.job
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea backup-ul.'
      return null
    }
    finally {
      creating.value = false
    }
  }

  async function fetchStatus(jobId: string): Promise<void> {
    const { get } = useApi()
    try {
      const res = await get<{ job: BackupJob }>(`/v1/backup/${jobId}/status`)
      currentJob.value = res.job
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca statusul.'
    }
  }

  async function fetchHistory(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    try {
      const res = await get<{ data: BackupJob[] }>('/v1/backup/history')
      history.value = res.data
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut incarca istoricul.'
    }
    finally {
      loading.value = false
    }
  }

  async function downloadBackup(jobId: string, filename?: string): Promise<void> {
    const { apiFetch } = useApi()
    try {
      const blob = await apiFetch<Blob>(`/v1/backup/${jobId}/download`, {
        method: 'GET',
        responseType: 'blob',
      })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = filename ?? 'backup.zip'
      a.click()
      URL.revokeObjectURL(url)
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut descarca backup-ul.'
    }
  }

  async function uploadRestore(file: File, purgeExisting: boolean = false): Promise<BackupJob | null> {
    const { apiFetch } = useApi()
    uploading.value = true
    error.value = null
    try {
      const formData = new FormData()
      formData.append('file', file)
      formData.append('purgeExisting', purgeExisting ? 'true' : 'false')

      const res = await apiFetch<{ job: BackupJob }>('/v1/backup/restore', {
        method: 'POST',
        body: formData,
      })
      currentJob.value = res.job
      return res.job
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Restaurarea a esuat.'
      return null
    }
    finally {
      uploading.value = false
    }
  }

  function handleProgress(data: BackupProgress): void {
    if (currentJob.value && data.jobId === currentJob.value.id) {
      currentJob.value.progress = data.progress
      if (data.step) {
        currentJob.value.currentStep = data.step
      }
    }

    if (data.event === 'backup_completed' || data.event === 'restore_completed') {
      if (currentJob.value && data.jobId === currentJob.value.id) {
        currentJob.value.status = 'completed'
        currentJob.value.progress = 100
        currentJob.value.currentStep = null
        if (data.filename) currentJob.value.filename = data.filename
        if (data.fileSize) currentJob.value.fileSize = data.fileSize
      }
      fetchHistory()
    }

    if (data.event === 'backup_error') {
      if (currentJob.value && data.jobId === currentJob.value.id) {
        currentJob.value.status = 'failed'
        currentJob.value.currentStep = null
        error.value = data.error ?? 'Backup failed'
      }
    }
  }

  function $reset(): void {
    history.value = []
    currentJob.value = null
    loading.value = false
    creating.value = false
    uploading.value = false
    error.value = null
  }

  return {
    // State
    history,
    currentJob,
    loading,
    creating,
    uploading,
    error,

    // Actions
    createBackup,
    fetchStatus,
    fetchHistory,
    downloadBackup,
    uploadRestore,
    handleProgress,
    $reset,
  }
})
