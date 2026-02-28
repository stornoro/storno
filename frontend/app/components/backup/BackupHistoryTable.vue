<script setup lang="ts">
const { t: $t } = useI18n()
const backupStore = useBackupStore()

const columns = [
  { accessorKey: 'createdAt', header: $t('backup.date') },
  { accessorKey: 'type', header: $t('backup.type') },
  { accessorKey: 'status', header: 'Status' },
  { accessorKey: 'filename', header: $t('backup.file') },
  { accessorKey: 'fileSize', header: $t('backup.size') },
  { accessorKey: 'actions', header: '' },
]

const typeLabels: Record<string, string> = {
  backup: 'Backup',
  restore: 'Restaurare',
}

const statusColors: Record<string, string> = {
  pending: 'neutral',
  processing: 'warning',
  completed: 'success',
  failed: 'error',
}

function formatDate(dateStr: string): string {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleDateString('ro-RO', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function formatBytes(bytes: number | null): string {
  if (!bytes) return '-'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

async function handleDownload(jobId: string, filename?: string) {
  await backupStore.downloadBackup(jobId, filename ?? undefined)
}

onMounted(() => {
  backupStore.fetchHistory()
})
</script>

<template>
  <UPageCard
    :title="$t('backup.historyTitle')"
    variant="subtle"
    :ui="{ container: 'p-0 sm:p-0 gap-y-0', wrapper: 'items-stretch' }"
  >
    <UTable
      :data="backupStore.history"
      :columns="columns"
      :loading="backupStore.loading"
      :ui="{
        base: 'table-fixed',
        thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
        tbody: '[&>tr]:last:[&>td]:border-b-0',
        th: 'px-4',
        td: 'px-4 border-b border-default',
      }"
    >
      <template #createdAt-cell="{ row }">
        <span class="text-sm">{{ formatDate(row.original.createdAt) }}</span>
      </template>

      <template #type-cell="{ row }">
        <div class="flex items-center gap-2">
          <UIcon
            :name="row.original.type === 'backup' ? 'i-lucide-archive' : 'i-lucide-archive-restore'"
            class="w-4 h-4 text-(--ui-text-muted)"
          />
          <span class="text-sm">{{ typeLabels[row.original.type] || row.original.type }}</span>
        </div>
      </template>

      <template #status-cell="{ row }">
        <UBadge
          :label="$t(`backup.status${row.original.status.charAt(0).toUpperCase() + row.original.status.slice(1)}`)"
          :color="(statusColors[row.original.status] as any) || 'neutral'"
          variant="subtle"
          size="sm"
        />
      </template>

      <template #filename-cell="{ row }">
        <span class="text-sm font-mono truncate max-w-48 block">{{ row.original.filename || '-' }}</span>
      </template>

      <template #fileSize-cell="{ row }">
        <span class="text-sm">{{ formatBytes(row.original.fileSize) }}</span>
      </template>

      <template #actions-cell="{ row }">
        <UButton
          v-if="row.original.status === 'completed' && row.original.type === 'backup'"
          icon="i-lucide-download"
          size="xs"
          variant="ghost"
          type="button"
          @click="handleDownload(row.original.id, row.original.filename)"
        />
      </template>
    </UTable>

    <UEmpty
      v-if="!backupStore.loading && backupStore.history.length === 0"
      icon="i-lucide-archive"
      :title="$t('backup.noHistory')"
      class="py-12"
    />
  </UPageCard>
</template>
