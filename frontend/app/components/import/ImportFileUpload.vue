<script setup lang="ts">
const props = defineProps<{
  source: string
  importType?: string
  loading?: boolean
}>()

const file = defineModel<File | null>()

const { t: $t } = useI18n()
const importStore = useImportStore()
const isDragging = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const acceptedFormats = computed(() => {
  if (props.source === 'saga') return '.csv,.xlsx,.xml'
  if (props.source === 'icefact') return '.csv'
  return '.csv,.xlsx'
})

const showTemplateLink = computed(() => {
  return props.source === 'generic' && props.importType
})

function handleDrop(e: DragEvent) {
  isDragging.value = false
  const droppedFile = e.dataTransfer?.files?.[0]
  if (droppedFile) {
    file.value = droppedFile
  }
}

function handleFileSelect(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files?.[0]) {
    file.value = input.files[0]
  }
}

function openFilePicker() {
  fileInput.value?.click()
}

function removeFile() {
  file.value = null
  if (fileInput.value) fileInput.value.value = ''
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function handleDownloadTemplate() {
  if (props.importType) {
    importStore.downloadTemplate(props.importType)
  }
}
</script>

<template>
  <div class="space-y-4">
    <input
      ref="fileInput"
      type="file"
      :accept="acceptedFormats"
      class="hidden"
      @change="handleFileSelect"
    />

    <!-- Template download link -->
    <div v-if="showTemplateLink" class="flex items-center gap-2 p-3 rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800">
      <UIcon name="i-lucide-download" class="w-4 h-4 text-blue-600 dark:text-blue-400 shrink-0" />
      <div class="flex-1">
        <button
          type="button"
          class="text-sm text-blue-600 dark:text-blue-400 hover:underline font-medium"
          @click="handleDownloadTemplate"
        >
          {{ $t('importExport.downloadTemplate') }}
        </button>
        <p class="text-xs text-(--ui-text-muted)">{{ $t('importExport.downloadTemplateHint') }}</p>
      </div>
    </div>

    <!-- Drop zone -->
    <div
      v-if="!file"
      class="border-2 border-dashed rounded-lg p-8 text-center transition-colors cursor-pointer"
      :class="isDragging
        ? 'border-primary bg-primary/5'
        : 'border-gray-300 dark:border-gray-600 hover:border-gray-400 dark:hover:border-gray-500'"
      @dragover.prevent="isDragging = true"
      @dragleave="isDragging = false"
      @drop.prevent="handleDrop"
      @click="openFilePicker"
    >
      <UIcon name="i-lucide-upload-cloud" class="w-12 h-12 mx-auto mb-3 text-gray-400" />
      <p class="text-sm font-medium">{{ $t('importExport.uploadDescription') }}</p>
      <p class="text-xs text-(--ui-text-muted) mt-1">
        {{ $t('importExport.supportedFormats') }}: {{ acceptedFormats }}
      </p>
      <p class="text-xs text-(--ui-text-muted)">{{ $t('importExport.maxFileSize') }}</p>
    </div>

    <!-- Selected file -->
    <div
      v-else
      class="flex items-center gap-3 p-4 rounded-lg bg-gray-50 dark:bg-gray-800"
    >
      <UIcon name="i-lucide-file-spreadsheet" class="w-8 h-8 text-primary flex-shrink-0" />
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium truncate">{{ file.name }}</p>
        <p class="text-xs text-(--ui-text-muted)">{{ formatSize(file.size) }}</p>
      </div>
      <UButton
        icon="i-lucide-x"
        variant="ghost"
        size="xs"
        :disabled="loading"
        @click="removeFile"
      />
    </div>

    <div v-if="loading" class="flex items-center gap-2 text-sm text-(--ui-text-muted)">
      <UIcon name="i-lucide-loader-2" class="w-4 h-4 animate-spin" />
      <span>{{ $t('importExport.processing') }}</span>
    </div>
  </div>
</template>
