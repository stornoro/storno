<script setup lang="ts">
const { t: $t } = useI18n()
const { isNotificationsSlideoverOpen } = useDashboard()
const { unreadCount } = useNotifications()
</script>

<template>
  <UTooltip :text="$t('notifications.title')" :kbds="['N']">
    <UChip :show="unreadCount > 0" :text="unreadCount" size="sm" color="error">
      <UButton
        icon="i-lucide-bell"
        color="neutral"
        variant="ghost"
        square
        @click="isNotificationsSlideoverOpen = true"
      />
    </UChip>
  </UTooltip>
</template>
