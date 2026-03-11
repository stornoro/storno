<script setup lang="ts">
const { t: $t } = useI18n()
const backupStore = useBackupStore()
const { formatDateTime, formatRelative } = useDate()

const statusConfig: Record<string, { color: string; icon: string }> = {
  pending: { color: 'neutral', icon: 'i-lucide-clock' },
  processing: { color: 'warning', icon: 'i-lucide-loader' },
  completed: { color: 'success', icon: 'i-lucide-check-circle' },
  failed: { color: 'error', icon: 'i-lucide-x-circle' },
}

function formatBytes(bytes: number | null): string {
  if (!bytes) return '-'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

const downloading = ref<string | null>(null)

async function handleDownload(jobId: string, filename?: string) {
  downloading.value = jobId
  try {
    await backupStore.downloadBackup(jobId, filename ?? undefined)
  }
  finally {
    downloading.value = null
  }
}
</script>

<template>
  <UPageCard
    :title="$t('backup.historyTitle')"
    variant="subtle"
  >
    <!-- History list -->
    <div v-if="backupStore.loading" class="py-12 flex justify-center">
      <UIcon name="i-lucide-loader" class="w-5 h-5 animate-spin text-(--ui-text-muted)" />
    </div>

    <div v-else-if="backupStore.history.length === 0">
      <UEmpty
        icon="i-lucide-archive"
        :title="$t('backup.noHistory')"
        class="py-12"
      />
    </div>

    <div v-else class="divide-y divide-default">
      <div
        v-for="job in backupStore.history"
        :key="job.id"
        class="flex items-center gap-4 py-3.5 px-1 group"
      >
        <!-- Type icon -->
        <div
          class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0"
          :class="job.type === 'backup'
            ? 'bg-cyan-100 dark:bg-cyan-900/30'
            : 'bg-amber-100 dark:bg-amber-900/30'"
        >
          <UIcon
            :name="job.type === 'backup' ? 'i-lucide-archive' : 'i-lucide-archive-restore'"
            class="w-4.5 h-4.5"
            :class="job.type === 'backup'
              ? 'text-cyan-600 dark:text-cyan-400'
              : 'text-amber-600 dark:text-amber-400'"
          />
        </div>

        <!-- Info -->
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium">
              {{ job.type === 'backup' ? $t('backup.typeBackup') : $t('backup.typeRestore') }}
            </span>
            <UBadge
              :label="$t(`backup.status${job.status.charAt(0).toUpperCase() + job.status.slice(1)}`)"
              :color="(statusConfig[job.status]?.color as any) || 'neutral'"
              :icon="statusConfig[job.status]?.icon"
              variant="subtle"
              size="xs"
            />
          </div>
          <div class="flex items-center gap-3 mt-0.5">
            <span class="text-xs text-(--ui-text-muted)" :title="formatDateTime(job.createdAt)">
              {{ formatRelative(job.createdAt) }}
            </span>
            <template v-if="job.filename && job.status === 'completed'">
              <span class="text-xs text-(--ui-text-dimmed)">·</span>
              <span class="text-xs text-(--ui-text-muted) font-mono truncate max-w-48">{{ job.filename }}</span>
            </template>
            <template v-if="job.fileSize && job.status === 'completed'">
              <span class="text-xs text-(--ui-text-dimmed)">·</span>
              <span class="text-xs text-(--ui-text-muted)">{{ formatBytes(job.fileSize) }}</span>
            </template>
            <template v-if="job.errorMessage && job.status === 'failed'">
              <span class="text-xs text-(--ui-text-dimmed)">·</span>
              <span class="text-xs text-red-500 truncate max-w-64">{{ job.errorMessage }}</span>
            </template>
          </div>
        </div>

        <!-- Download action -->
        <UButton
          v-if="job.status === 'completed' && job.type === 'backup'"
          :label="$t('backup.downloadButton')"
          icon="i-lucide-download"
          size="xs"
          variant="soft"
          :loading="downloading === job.id"
          class="opacity-0 group-hover:opacity-100 transition-opacity shrink-0"
          type="button"
          @click="handleDownload(job.id, job.filename ?? undefined)"
        />
      </div>
    </div>
  </UPageCard>
</template>
