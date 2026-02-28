<script setup lang="ts">
definePageMeta({ middleware: ['auth', 'permissions'] })

const { t: $t } = useI18n()
useHead({ title: $t('backup.title') })

const backupStore = useBackupStore()
const companyStore = useCompanyStore()

const restoreModalOpen = ref(false)

watch(() => companyStore.currentCompanyId, () => {
  backupStore.fetchHistory()
})

onMounted(() => {
  backupStore.fetchHistory()
})
</script>

<template>
  <div class="space-y-8">
    <!-- Section 1: Create Backup -->
    <BackupBackupSection />

    <!-- Section 2: Restore -->
    <UPageCard variant="subtle">
      <div class="rounded-lg bg-gradient-to-r from-amber-500/10 via-amber-500/5 to-transparent p-6">
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 rounded-xl bg-amber-500/20 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-archive-restore" class="w-6 h-6 text-amber-600 dark:text-amber-400" />
          </div>
          <div class="flex-1">
            <h2 class="text-lg font-semibold">{{ $t('backup.restoreTitle') }}</h2>
            <p class="text-sm text-(--ui-text-muted) mt-0.5">{{ $t('backup.restoreDescription') }}</p>
          </div>
          <UButton
            :label="$t('backup.restoreButton')"
            icon="i-lucide-archive-restore"
            variant="soft"
            color="warning"
            type="button"
            @click="restoreModalOpen = true"
          />
        </div>
      </div>

      <!-- Active restore progress -->
      <div
        v-if="backupStore.currentJob?.type === 'restore' && (backupStore.currentJob?.status === 'pending' || backupStore.currentJob?.status === 'processing')"
        class="mt-4 p-4 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-900/10"
      >
        <BackupBackupProgressBar
          :progress="backupStore.currentJob.progress"
          :step="backupStore.currentJob.currentStep"
          :status="backupStore.currentJob.status"
        />
      </div>
    </UPageCard>

    <!-- Section 3: History -->
    <BackupBackupHistoryTable />

    <!-- Modals -->
    <BackupRestoreModal v-model:open="restoreModalOpen" />
  </div>
</template>
