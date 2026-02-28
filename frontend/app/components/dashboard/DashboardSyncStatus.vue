<template>
  <UCard variant="outline">
    <template #header>
      <div class="flex items-center gap-2">
        <div class="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center">
          <UIcon name="i-lucide-refresh-cw" class="w-4 h-4 text-primary" />
        </div>
        <h3 class="font-semibold text-(--ui-text)">{{ $t('dashboard.syncStatus') }}</h3>
      </div>
    </template>

    <div class="space-y-4">
      <div class="flex items-center justify-between">
        <span class="text-sm text-(--ui-text-muted)">{{ $t('common.status') }}</span>
        <UBadge :color="syncEnabled ? 'success' : 'neutral'" variant="subtle">
          {{ syncEnabled ? $t('common.enabled') : $t('common.disabled') }}
        </UBadge>
      </div>

      <USeparator />

      <div class="flex items-center justify-between">
        <span class="text-sm text-(--ui-text-muted)">{{ $t('common.lastSynced') }}</span>
        <UTooltip v-if="lastSyncedAt" :text="formatDateFull(lastSyncedAt)">
          <span class="text-sm font-medium text-(--ui-text)">
            {{ formatDate(lastSyncedAt) }}
          </span>
        </UTooltip>
        <span v-else class="text-sm font-medium text-(--ui-text)">
          {{ $t('common.never') }}
        </span>
      </div>
    </div>
  </UCard>
</template>

<script setup lang="ts">
defineProps<{
  syncEnabled: boolean
  lastSyncedAt: string | null
}>()

const { t: $t } = useI18n()

function formatDate(dateStr: string) {
  return new Date(dateStr).toLocaleDateString('ro-RO', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}

function formatDateFull(dateStr: string) {
  return new Date(dateStr).toLocaleDateString('ro-RO', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>
