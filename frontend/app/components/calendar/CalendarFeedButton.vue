<script setup lang="ts">
/** Navbar button that opens the calendar subscription in a modal. */
withDefaults(defineProps<{ size?: 'sm' | 'md' }>(), { size: 'sm' })
const { t: $t } = useI18n()
const open = ref(false)
</script>

<template>
  <UButton icon="i-lucide-calendar-plus" color="neutral" variant="ghost" :size="size" @click="open = true">
    <span class="hidden sm:inline">{{ $t('calendarFeed.button') }}</span>
  </UButton>
  <UModal v-model:open="open" :title="$t('calendarFeed.title')" :description="$t('calendarFeed.description')">
    <template #body>
      <CalendarFeedCard v-if="open" />
    </template>
  </UModal>
</template>
