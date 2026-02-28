<script setup lang="ts">
const { t: $t } = useI18n()
const authStore = useAuthStore()
</script>

<template>
  <div
    v-if="authStore.isImpersonating"
    class="flex items-center gap-3 px-4 py-1.5 bg-warning-100 dark:bg-warning-900/40 border-b border-warning-300 dark:border-warning-700"
  >
    <UIcon name="i-lucide-eye" class="size-4 text-warning-600 dark:text-warning-400 shrink-0" />
    <span class="text-sm font-medium text-warning-700 dark:text-warning-300">
      {{ $t('admin.impersonating', { name: authStore.user?.fullName || authStore.user?.email }) }}
    </span>
    <div class="ml-auto" />
    <UButton
      size="xs"
      color="warning"
      variant="soft"
      icon="i-lucide-log-out"
      :label="$t('admin.stopImpersonating')"
      @click="authStore.stopImpersonation()"
    />
  </div>
</template>
