<script setup lang="ts">
const props = defineProps<{
  open: boolean
  initialImportType?: string
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'completed': []
}>()

const { t: $t } = useI18n()
const importStore = useImportStore()
const companyStore = useCompanyStore()

const currentStep = ref(0)
const selectedSource = ref<string | null>(null)
const selectedImportType = ref<string | null>(null)
const selectedFile = ref<File | null>(null)
const columnMapping = ref<Record<string, string>>({})
const importOptions = ref<Record<string, any>>({ markAsPaid: false })

const isInvoiceImport = computed(() =>
  selectedImportType.value?.startsWith('invoices') ?? false,
)

// Apply initialImportType when the wizard opens
watch(() => props.open, (isOpen) => {
  if (isOpen && props.initialImportType) {
    selectedImportType.value = props.initialImportType
  }
})

const steps = computed(() => [
  $t('importExport.step1Source'),
  $t('importExport.step2Upload'),
  $t('importExport.step3Mapping'),
  $t('importExport.step4Preview'),
  $t('importExport.step5Progress'),
])

const canGoNext = computed(() => {
  switch (currentStep.value) {
    case 0: return !!selectedSource.value && !!selectedImportType.value
    case 1: return !!selectedFile.value
    case 2: return Object.keys(columnMapping.value).length > 0
    case 3: return true
    default: return false
  }
})

const isLastDataStep = computed(() => currentStep.value === 3)

async function handleNext() {
  if (currentStep.value === 1 && selectedFile.value) {
    const job = await importStore.uploadFile(selectedFile.value, selectedImportType.value!, selectedSource.value!)
    if (!job) return
    if (job.suggestedMapping) {
      columnMapping.value = { ...job.suggestedMapping }
    }
    currentStep.value = 2
  }
  else if (currentStep.value === 2) {
    const success = await importStore.updateMapping(importStore.currentJob!.id, columnMapping.value)
    if (!success) return
    currentStep.value = 3
  }
  else if (currentStep.value === 3) {
    const opts = isInvoiceImport.value ? importOptions.value : undefined
    const success = await importStore.executeImport(importStore.currentJob!.id, opts)
    if (success) {
      currentStep.value = 4
      startProgressPolling()
    }
  }
  else {
    currentStep.value++
  }
}

function handlePrevious() {
  if (currentStep.value > 0) {
    currentStep.value--
  }
}

let pollInterval: ReturnType<typeof setInterval> | null = null

function startProgressPolling() {
  const { subscribe } = useCentrifugo()
  const channel = `import:company_${companyStore.currentCompanyId}`
  subscribe(channel, (data: any) => {
    if (data?.type === 'import_progress' && data.jobId === importStore.currentJob?.id) {
      importStore.handleProgress(data)
      if (data.status === 'completed' || data.status === 'failed') {
        stopProgressPolling()
      }
    }
  })

  pollInterval = setInterval(async () => {
    if (!importStore.currentJob) return
    await importStore.fetchJob(importStore.currentJob.id)
    if (importStore.currentJob.status === 'completed' || importStore.currentJob.status === 'failed') {
      stopProgressPolling()
    }
  }, 3000)
}

function stopProgressPolling() {
  if (pollInterval) {
    clearInterval(pollInterval)
    pollInterval = null
  }
}

function handleClose() {
  stopProgressPolling()
  if (importStore.currentJob?.status === 'completed') {
    emit('completed')
  }
  currentStep.value = 0
  selectedSource.value = null
  selectedImportType.value = null
  selectedFile.value = null
  columnMapping.value = {}
  importOptions.value = { markAsPaid: false }
  importStore.currentJob = null
  importStore.progress = null
  emit('update:open', false)
}

onMounted(() => {
  if (importStore.sources.length === 0) {
    importStore.fetchSources()
  }
})

onUnmounted(() => {
  stopProgressPolling()
})
</script>

<template>
  <USlideover
    :open="open"
    :ui="{ content: 'sm:max-w-4xl' }"
    @update:open="handleClose"
  >
    <template #header>
      <div class="flex flex-col w-full gap-3">
        <div class="flex items-center justify-between w-full">
          <h3 class="text-lg font-semibold">{{ $t('importExport.startImport') }}</h3>
        </div>
        <!-- Step indicator -->
        <div class="flex items-center gap-2">
          <template v-for="(step, i) in steps" :key="i">
            <div class="flex items-center gap-1.5 shrink-0">
              <div
                class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-medium transition-colors"
                :class="i < currentStep
                  ? 'bg-primary text-white'
                  : i === currentStep
                    ? 'bg-primary text-white'
                    : 'bg-gray-200 dark:bg-gray-700 text-gray-500'"
              >
                <UIcon v-if="i < currentStep" name="i-lucide-check" class="w-4 h-4" />
                <span v-else>{{ i + 1 }}</span>
              </div>
              <span
                class="text-xs hidden sm:inline"
                :class="i === currentStep ? 'font-medium' : 'text-(--ui-text-muted)'"
              >
                {{ step }}
              </span>
            </div>
            <div
              v-if="i < steps.length - 1"
              class="flex-1 h-px bg-gray-200 dark:bg-gray-700 min-w-2"
            />
          </template>
        </div>
      </div>
    </template>

    <template #body>
      <ImportSourceSelect
        v-if="currentStep === 0"
        v-model:source="selectedSource"
        v-model:import-type="selectedImportType"
        :sources="importStore.sources"
        :import-types="importStore.importTypes"
      />
      <ImportFileUpload
        v-else-if="currentStep === 1"
        v-model="selectedFile"
        :source="selectedSource!"
        :import-type="selectedImportType ?? undefined"
        :loading="importStore.uploading"
      />
      <ImportColumnMapping
        v-else-if="currentStep === 2"
        v-model="columnMapping"
        :detected-columns="importStore.currentJob?.detectedColumns ?? []"
        :target-fields="importStore.targetFields"
        :suggested-mapping="importStore.currentJob?.suggestedMapping ?? {}"
        :required-fields="[]"
      />
      <div v-else-if="currentStep === 3" class="space-y-6">
        <ImportPreview
          :preview-data="importStore.currentJob?.previewData ?? []"
          :column-mapping="columnMapping"
          :total-rows="importStore.currentJob?.totalRows ?? 0"
        />

        <!-- Mark as paid option for invoice imports -->
        <div v-if="isInvoiceImport" class="flex items-start gap-3 p-4 rounded-lg border border-gray-200 dark:border-gray-700">
          <USwitch v-model="importOptions.markAsPaid" />
          <div>
            <p class="text-sm font-medium">{{ $t('importExport.markAsPaid') }}</p>
            <p class="text-xs text-(--ui-text-muted)">{{ $t('importExport.markAsPaidDescription') }}</p>
          </div>
        </div>
      </div>
      <ImportProgress
        v-else-if="currentStep === 4"
        :job="importStore.currentJob"
        :progress="importStore.progress"
      />
    </template>

    <template #footer>
      <div class="flex justify-between w-full">
        <UButton
          v-if="currentStep > 0 && currentStep < 4"
          :label="$t('importExport.previous')"
          variant="outline"
          @click="handlePrevious"
        />
        <div v-else />

        <div class="flex gap-2">
          <UButton
            v-if="currentStep === 4"
            :label="$t('importExport.close')"
            @click="handleClose"
          />
          <UButton
            v-else
            :label="isLastDataStep ? $t('importExport.startImportAction') : $t('importExport.next')"
            :disabled="!canGoNext"
            :loading="importStore.uploading || importStore.executing"
            @click="handleNext"
          />
        </div>
      </div>
    </template>
  </USlideover>
</template>
