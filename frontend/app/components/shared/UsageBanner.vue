<script setup lang="ts">
const { t: $t } = useI18n()
const authStore = useAuthStore()
const usageStore = useUsageStore()

const upgradeModalOpen = ref(false)

// Map resource key to i18n label
const resourceLabel = (key: string): string => {
  switch (key) {
    case 'invoices': return $t('usage.resource.invoices')
    case 'companies': return $t('usage.resource.companies')
    case 'users': return $t('usage.resource.users')
    default: return key
  }
}

// Only show when: not on paid plan AND there are resources at/above warning threshold
const shouldShow = computed(() =>
  !authStore.isPaid
  && usageStore.hasWarning
  && usageStore.criticalResources.length > 0,
)

// Show the most critical resource first (highest percentage)
const primaryResource = computed(() => {
  if (usageStore.criticalResources.length === 0) return null
  return [...usageStore.criticalResources].sort(
    (a, b) => b.resource.percentage - a.resource.percentage,
  )[0]
})
</script>

<template>
  <ClientOnly>
    <div v-if="shouldShow && primaryResource" class="mb-4">
      <UAlert
        color="warning"
        variant="subtle"
        :title="$t('usage.bannerTitle')"
        :description="$t('usage.bannerDescription', {
          percentage: primaryResource.resource.percentage,
          resource: resourceLabel(primaryResource.key),
          used: primaryResource.resource.used,
          limit: primaryResource.resource.limit,
        })"
        icon="i-lucide-alert-triangle"
      >
        <template #actions>
          <UButton
            color="warning"
            variant="solid"
            size="sm"
            icon="i-lucide-arrow-up-circle"
            @click="upgradeModalOpen = true"
          >
            {{ $t('usage.upgradeNow') }}
          </UButton>
        </template>
      </UAlert>

      <SharedUpgradeModal v-model:open="upgradeModalOpen" />
    </div>
  </ClientOnly>
</template>
