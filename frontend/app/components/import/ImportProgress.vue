<script setup lang="ts">
const props = defineProps<{
  job: any
  progress: any
}>()

const { t: $t } = useI18n()

const isCompleted = computed(() => props.job?.status === 'completed')
const isFailed = computed(() => props.job?.status === 'failed')
const isProcessing = computed(() => props.job?.status === 'processing')

const totalRows = computed(() => props.progress?.totalRows || props.job?.totalRows || 0)

const processed = computed(() => {
  if (props.progress?.processed != null) return props.progress.processed
  const j = props.job
  if (!j) return 0
  return (j.createdCount || 0) + (j.updatedCount || 0) + (j.skippedCount || 0) + (j.errorCount || 0)
})

const progressPercent = computed(() => {
  if (totalRows.value === 0) return 0
  return Math.min(Math.round((processed.value / totalRows.value) * 100), 100)
})

const stats = computed(() => [
  {
    label: $t('importExport.created'),
    value: props.progress?.created ?? props.job?.createdCount ?? 0,
    color: 'text-green-600 dark:text-green-400',
  },
  {
    label: $t('importExport.updated'),
    value: props.progress?.updated ?? props.job?.updatedCount ?? 0,
    color: 'text-blue-600 dark:text-blue-400',
  },
  {
    label: $t('importExport.skipped'),
    value: props.progress?.skipped ?? props.job?.skippedCount ?? 0,
    color: 'text-(--ui-text-muted)',
  },
  {
    label: $t('importExport.errors'),
    value: props.progress?.errors ?? props.job?.errorCount ?? 0,
    color: 'text-red-600 dark:text-red-400',
  },
])
</script>

<template>
  <div class="space-y-6">
    <!-- Status indicator -->
    <div class="text-center">
      <div v-if="isProcessing" class="inline-flex items-center gap-2">
        <UIcon name="i-lucide-loader-2" class="w-5 h-5 animate-spin text-primary" />
        <span class="font-medium">{{ $t('importExport.processing') }}</span>
      </div>
      <div
        v-else-if="isCompleted"
        class="inline-flex items-center gap-2 text-green-600 dark:text-green-400"
      >
        <UIcon name="i-lucide-check-circle" class="w-6 h-6" />
        <span class="text-lg font-medium">{{ $t('importExport.completed') }}</span>
      </div>
      <div
        v-else-if="isFailed"
        class="inline-flex items-center gap-2 text-red-600 dark:text-red-400"
      >
        <UIcon name="i-lucide-x-circle" class="w-6 h-6" />
        <span class="text-lg font-medium">{{ $t('importExport.failed') }}</span>
      </div>
    </div>

    <!-- Progress bar -->
    <div v-if="isProcessing || isCompleted">
      <div class="flex justify-between text-sm mb-1">
        <span>{{ $t('importExport.processedRows') }}</span>
        <span>{{ processed }} / {{ totalRows }}</span>
      </div>
      <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3">
        <div
          class="h-3 rounded-full transition-all duration-300"
          :class="isCompleted ? 'bg-green-500' : 'bg-primary'"
          :style="{ width: `${progressPercent}%` }"
        />
      </div>
    </div>

    <!-- Stats grid -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
      <div
        v-for="stat in stats"
        :key="stat.label"
        class="text-center p-4 rounded-lg bg-gray-50 dark:bg-gray-800"
      >
        <p class="text-2xl font-bold" :class="stat.color">{{ stat.value }}</p>
        <p class="text-sm text-(--ui-text-muted)">{{ stat.label }}</p>
      </div>
    </div>

    <!-- Errors list -->
    <div v-if="job?.errors?.length" class="space-y-2">
      <h4 class="text-sm font-medium text-red-600 dark:text-red-400">
        {{ $t('importExport.errors') }} ({{ job.errors.length }})
      </h4>
      <div class="max-h-48 overflow-y-auto space-y-1">
        <div
          v-for="(err, i) in job.errors.slice(0, 50)"
          :key="i"
          class="text-xs p-2 rounded bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300"
        >
          <span class="font-medium">{{ $t('importExport.totalRows') }} {{ err.row }}:</span>
          {{ err.message }}
        </div>
        <p v-if="job.errors.length > 50" class="text-xs text-(--ui-text-muted) px-2">
          ... si alte {{ job.errors.length - 50 }} erori
        </p>
      </div>
    </div>
  </div>
</template>
