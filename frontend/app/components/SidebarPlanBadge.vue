<script setup lang="ts">
defineProps<{
  collapsed?: boolean
}>()

const { t: $t } = useI18n()
const authStore = useAuthStore()

const showUpgrade = computed(() => {
  const plan = authStore.effectivePlan
  return ['free', 'freemium'].includes(plan) || authStore.isTrial
})

const planColor = computed(() => {
  switch (authStore.effectivePlan) {
    case 'business': return 'success' as const
    case 'professional': return 'info' as const
    case 'starter': return 'primary' as const
    default: return 'neutral' as const
  }
})

const planLabel = computed(() => {
  const plan = authStore.effectivePlan
  if (authStore.isTrial) return $t('plan.trial')
  const key = `settings.billingPage.${plan}`
  return $t(key)
})
</script>

<template>
  <div v-if="!authStore.isSelfHosted && !authStore.isCommunityEdition">
    <!-- Collapsed: icon only -->
    <UTooltip v-if="collapsed" :text="showUpgrade ? $t('plan.upgrade') : planLabel">
      <UButton
        :icon="showUpgrade ? 'i-lucide-sparkles' : 'i-lucide-gem'"
        :color="showUpgrade ? 'primary' : 'neutral'"
        :variant="showUpgrade ? 'soft' : 'ghost'"
        square
        size="sm"
        :to="showUpgrade ? '/settings/billing' : undefined"
      />
    </UTooltip>

    <!-- Expanded: plan badge + upgrade button -->
    <div v-else class="flex items-center gap-2 px-2 py-1.5">
      <UBadge :color="planColor" variant="subtle" size="xs" class="shrink-0">
        {{ planLabel }}
      </UBadge>
      <NuxtLink
        v-if="showUpgrade"
        to="/settings/billing"
        class="flex items-center gap-1 text-xs font-medium text-(--ui-primary) hover:underline ml-auto"
      >
        <UIcon name="i-lucide-sparkles" class="size-3" />
        {{ $t('plan.upgrade') }}
      </NuxtLink>
    </div>
  </div>
</template>
