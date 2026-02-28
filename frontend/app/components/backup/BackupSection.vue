<script setup lang="ts">
const { t: $t } = useI18n()
const backupStore = useBackupStore()
const companyStore = useCompanyStore()
const toast = useToast()

const { subscribe, unsubscribe } = useCentrifugo()

const isActiveJob = computed(() => {
  const job = backupStore.currentJob
  return job && job.type === 'backup' && (job.status === 'pending' || job.status === 'processing')
})

async function startBackup() {
  const job = await backupStore.createBackup(true)
  if (job) {
    toast.add({
      title: $t('backup.backupStarted'),
      description: $t('backup.backupStartedDesc'),
      color: 'info',
      icon: 'i-lucide-archive',
    })
  }
  else if (backupStore.error) {
    toast.add({
      title: $t('backup.title'),
      description: backupStore.error,
      color: 'error',
    })
  }
}

async function handleDownload(jobId: string, filename?: string) {
  await backupStore.downloadBackup(jobId, filename ?? undefined)
}

// Subscribe to Centrifugo for real-time progress
const channel = computed(() => {
  return companyStore.currentCompanyId
    ? `backup:company_${companyStore.currentCompanyId}`
    : null
})

watch(channel, (newChannel, oldChannel) => {
  if (oldChannel) unsubscribe(oldChannel)
  if (newChannel) {
    subscribe(newChannel, (data: any) => {
      backupStore.handleProgress(data)

      if (data.event === 'backup_completed') {
        toast.add({
          title: $t('backup.backupReady'),
          description: $t('backup.backupReadyDesc'),
          color: 'success',
          icon: 'i-lucide-check-circle',
        })
      }
      if (data.event === 'backup_error') {
        toast.add({
          title: $t('backup.backupFailed'),
          description: data.error,
          color: 'error',
          icon: 'i-lucide-alert-circle',
        })
      }
    })
  }
}, { immediate: true })

onUnmounted(() => {
  if (channel.value) unsubscribe(channel.value)
})
</script>

<template>
  <UPageCard variant="subtle">
    <!-- Gradient hero header -->
    <div class="rounded-lg bg-gradient-to-r from-cyan-500/10 via-cyan-500/5 to-transparent p-6 mb-6">
      <div class="flex items-start gap-4">
        <div class="w-12 h-12 rounded-xl bg-cyan-500/20 flex items-center justify-center shrink-0">
          <UIcon name="i-lucide-archive" class="w-6 h-6 text-cyan-600 dark:text-cyan-400" />
        </div>
        <div class="flex-1">
          <h2 class="text-lg font-semibold">{{ $t('backup.title') }}</h2>
          <p class="text-sm text-(--ui-text-muted) mt-1">{{ $t('backup.description') }}</p>
        </div>
        <UButton
          :label="$t('backup.createButton')"
          icon="i-lucide-archive"
          :loading="backupStore.creating"
          :disabled="!!isActiveJob"
          type="button"
          @click="startBackup"
        />
      </div>
    </div>

    <!-- Active job progress -->
    <div v-if="isActiveJob && backupStore.currentJob" class="mb-6 p-4 rounded-lg border border-cyan-200 dark:border-cyan-800 bg-cyan-50/50 dark:bg-cyan-900/10">
      <BackupBackupProgressBar
        :progress="backupStore.currentJob.progress"
        :step="backupStore.currentJob.currentStep"
        :status="backupStore.currentJob.status"
      />
    </div>

    <!-- Download ready banner -->
    <div
      v-if="backupStore.currentJob?.status === 'completed' && backupStore.currentJob?.type === 'backup'"
      class="mb-6 p-4 rounded-lg border border-green-200 dark:border-green-800 bg-green-50/50 dark:bg-green-900/10"
    >
      <div class="flex items-center gap-3">
        <UIcon name="i-lucide-check-circle" class="w-5 h-5 text-green-600 dark:text-green-400" />
        <div class="flex-1">
          <p class="text-sm font-medium">{{ $t('backup.backupReady') }}</p>
          <p class="text-xs text-(--ui-text-muted)">
            {{ backupStore.currentJob.filename }}
            <span v-if="backupStore.currentJob.fileSize">
              ({{ formatBytes(backupStore.currentJob.fileSize) }})
            </span>
          </p>
        </div>
        <UButton
          :label="$t('backup.downloadButton')"
          icon="i-lucide-download"
          size="sm"
          variant="soft"
          color="success"
          type="button"
          @click="handleDownload(backupStore.currentJob!.id, backupStore.currentJob!.filename ?? undefined)"
        />
      </div>
    </div>
  </UPageCard>
</template>

<script lang="ts">
function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}
</script>
