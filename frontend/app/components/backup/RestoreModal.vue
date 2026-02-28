<script setup lang="ts">
const { t: $t } = useI18n()
const backupStore = useBackupStore()
const toast = useToast()

const open = defineModel<boolean>('open', { default: false })

const selectedFile = ref<File | null>(null)
const purgeExisting = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const isRestoring = computed(() => {
  const job = backupStore.currentJob
  return job && job.type === 'restore' && (job.status === 'pending' || job.status === 'processing')
})

function handleFileSelect(event: Event) {
  const target = event.target as HTMLInputElement
  if (target.files?.[0]) {
    selectedFile.value = target.files[0]
  }
}

function handleDrop(event: DragEvent) {
  event.preventDefault()
  if (event.dataTransfer?.files[0]) {
    selectedFile.value = event.dataTransfer.files[0]
  }
}

function handleDragOver(event: DragEvent) {
  event.preventDefault()
}

async function startRestore() {
  if (!selectedFile.value) return

  const job = await backupStore.uploadRestore(selectedFile.value, purgeExisting.value)
  if (job) {
    toast.add({
      title: $t('backup.restoreStarted'),
      description: $t('backup.restoreStartedDesc'),
      color: 'info',
      icon: 'i-lucide-archive-restore',
    })
    open.value = false
    selectedFile.value = null
    purgeExisting.value = false
  }
  else if (backupStore.error) {
    toast.add({
      title: $t('backup.restoreTitle'),
      description: backupStore.error,
      color: 'error',
    })
  }
}

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}
</script>

<template>
  <UModal v-model:open="open">
    <template #header>
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
          <UIcon name="i-lucide-archive-restore" class="w-5 h-5 text-amber-600 dark:text-amber-400" />
        </div>
        <div>
          <h3 class="text-base font-semibold">{{ $t('backup.restoreTitle') }}</h3>
          <p class="text-sm text-(--ui-text-muted)">{{ $t('backup.restoreDescription') }}</p>
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
            accept=".zip"
            class="hidden"
            @change="handleFileSelect"
          >
          <UIcon name="i-lucide-upload" class="w-8 h-8 text-(--ui-text-muted) mx-auto mb-2" />
          <p v-if="!selectedFile" class="text-sm text-(--ui-text-muted)">
            {{ $t('backup.restoreUploadHint') }}
          </p>
          <div v-else class="flex items-center justify-center gap-2">
            <UIcon name="i-lucide-file-archive" class="w-5 h-5 text-primary" />
            <span class="text-sm font-medium">{{ selectedFile.name }}</span>
            <span class="text-xs text-(--ui-text-muted)">({{ formatBytes(selectedFile.size) }})</span>
          </div>
        </div>

        <!-- Purge toggle -->
        <div class="flex items-start gap-3 p-4 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-900/10">
          <USwitch v-model="purgeExisting" />
          <div>
            <p class="text-sm font-medium">{{ $t('backup.purgeExisting') }}</p>
            <p class="text-xs text-(--ui-text-muted)">{{ $t('backup.purgeExistingDesc') }}</p>
          </div>
        </div>

        <!-- Warning -->
        <div class="flex items-start gap-2 p-3 rounded-lg bg-red-50 dark:bg-red-900/10 text-red-700 dark:text-red-400">
          <UIcon name="i-lucide-alert-triangle" class="w-5 h-5 shrink-0 mt-0.5" />
          <p class="text-xs">{{ $t('backup.restoreWarning') }}</p>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton
          :label="$t('common.cancel')"
          variant="ghost"
          type="button"
          @click="open = false"
        />
        <UButton
          :label="$t('backup.restoreButton')"
          icon="i-lucide-archive-restore"
          color="warning"
          :loading="backupStore.uploading"
          :disabled="!selectedFile"
          type="button"
          @click="startRestore"
        />
      </div>
    </template>
  </UModal>
</template>
