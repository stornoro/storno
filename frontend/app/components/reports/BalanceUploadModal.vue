<template>
  <UModal :open="props.open" @update:open="emit('update:open', $event)">
    <template #header>
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center">
          <UIcon name="i-lucide-upload" class="w-5 h-5 text-primary" />
        </div>
        <div>
          <h3 class="text-base font-semibold">{{ $t('reports.balanceAnalysis.uploadTitle') }}</h3>
          <p class="text-sm text-(--ui-text-muted)">{{ $t('reports.balanceAnalysis.uploadDescription') }}</p>
        </div>
      </div>
    </template>

    <template #body>
      <div class="space-y-4">
        <!-- File upload area -->
        <div
          class="border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-lg p-8 text-center hover:border-gray-300 dark:hover:border-gray-600 transition-colors cursor-pointer"
          @click="fileInput?.click()"
          @drop="handleDrop"
          @dragover="handleDragOver"
        >
          <input
            ref="fileInput"
            type="file"
            accept=".pdf"
            multiple
            class="hidden"
            @change="handleFileSelect"
          >
          <UIcon name="i-lucide-file-text" class="w-8 h-8 text-(--ui-text-muted) mx-auto mb-2" />
          <p v-if="!selectedFiles.length" class="text-sm text-(--ui-text-muted)">
            {{ $t('reports.balanceAnalysis.selectFile') }}
          </p>
          <div v-else class="space-y-1">
            <div v-for="(file, idx) in selectedFiles" :key="idx" class="flex items-center justify-center gap-2">
              <UIcon name="i-lucide-file-text" class="w-4 h-4 text-primary shrink-0" />
              <span class="text-sm font-medium truncate max-w-60">{{ file.name }}</span>
              <span class="text-xs text-(--ui-text-muted)">({{ formatBytes(file.size) }})</span>
            </div>
          </div>
        </div>

        <p class="text-xs text-(--ui-text-muted)">
          {{ $t('reports.balanceAnalysis.autoDetectHint') }}
        </p>

        <!-- Upload results -->
        <div v-if="uploadResults.length" class="space-y-2">
          <div
            v-for="(result, idx) in uploadResults"
            :key="idx"
            class="flex items-start gap-2 p-2 rounded-lg text-xs"
            :class="result.success
              ? 'bg-green-50 dark:bg-green-900/10 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400'
              : 'bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400'"
          >
            <UIcon
              :name="result.success ? 'i-lucide-check-circle' : 'i-lucide-alert-triangle'"
              class="w-4 h-4 shrink-0 mt-0.5"
            />
            <div>
              <span class="font-medium">{{ result.filename }}</span>
              <span v-if="result.success"> — {{ result.month }}/{{ result.year }}</span>
              <span v-else> — {{ translateError(result) }}</span>
            </div>
          </div>
        </div>

        <!-- General error -->
        <div
          v-if="uploadError"
          class="flex items-start gap-2 p-3 rounded-lg bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400"
        >
          <UIcon name="i-lucide-alert-triangle" class="w-5 h-5 shrink-0 mt-0.5" />
          <p class="text-xs">{{ uploadError }}</p>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton
          :label="$t('common.cancel')"
          variant="ghost"
          type="button"
          :disabled="uploading"
          @click="handleClose"
        />
        <UButton
          :label="$t('reports.balanceAnalysis.upload')"
          icon="i-lucide-upload"
          :loading="uploading"
          :disabled="!selectedFiles.length"
          type="button"
          @click="handleUpload"
        />
      </div>
    </template>
  </UModal>
</template>

<script setup lang="ts">
const props = defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  uploaded: []
}>()

const { t: $t } = useI18n()
const { apiFetch } = useApi()

const selectedFiles = ref<File[]>([])
const uploading = ref(false)
const uploadError = ref<string | null>(null)
const uploadResults = ref<Array<{ filename: string; success: boolean; error?: string; code?: string; year?: number; month?: number }>>([])
const fileInput = ref<HTMLInputElement | null>(null)

function handleFileSelect(event: Event) {
  const target = event.target as HTMLInputElement
  if (target.files?.length) {
    selectedFiles.value = Array.from(target.files)
    uploadError.value = null
    uploadResults.value = []
  }
}

function handleDrop(event: DragEvent) {
  event.preventDefault()
  const files = event.dataTransfer?.files
  if (files?.length) {
    const pdfFiles = Array.from(files).filter(f => f.type === 'application/pdf')
    if (pdfFiles.length) {
      selectedFiles.value = pdfFiles
      uploadError.value = null
      uploadResults.value = []
    }
  }
}

function handleDragOver(event: DragEvent) {
  event.preventDefault()
}

function handleClose() {
  emit('update:open', false)
  selectedFiles.value = []
  uploadError.value = null
  uploadResults.value = []
  if (fileInput.value) {
    fileInput.value.value = ''
  }
}

function translateError(result: { code?: string; error?: string }): string {
  if (result.code === 'DUPLICATE_FILE') return $t('reports.balanceAnalysis.duplicateFile')
  if (result.code === 'CUI_MISMATCH') return $t('reports.balanceAnalysis.companyMismatch')
  return result.error || $t('common.error')
}

async function handleUpload() {
  if (!selectedFiles.value.length) return

  const formData = new FormData()
  for (const file of selectedFiles.value) {
    formData.append('files[]', file)
  }

  uploading.value = true
  uploadError.value = null
  uploadResults.value = []
  try {
    const response = await apiFetch<{ results: typeof uploadResults.value }>('/v1/balances/upload', { method: 'POST', body: formData })
    uploadResults.value = response.results
    const hasSuccess = response.results.some((r: any) => r.success)
    if (hasSuccess) {
      emit('uploaded')
    }
    // Auto-close if all succeeded
    if (response.results.every((r: any) => r.success)) {
      setTimeout(() => handleClose(), 1500)
    }
  }
  catch (error: any) {
    uploadError.value = error?.data?.error || error?.message || $t('common.error')
  }
  finally {
    uploading.value = false
  }
}

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

// Reset state when modal closes
watch(() => props.open, (val) => {
  if (!val) {
    selectedFiles.value = []
    uploadError.value = null
    uploadResults.value = []
    if (fileInput.value) {
      fileInput.value.value = ''
    }
  }
})
</script>
