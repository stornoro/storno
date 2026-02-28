<script setup lang="ts">
const props = withDefaults(defineProps<{
  /** What was the user trying to do, e.g. "companii" */
  feature?: string
  /** Current limit value, e.g. 1 */
  currentLimit?: string | number
}>(), {
  feature: '',
})

const isOpen = defineModel<boolean>('open', { required: true })

const { t: $t } = useI18n()
const authStore = useAuthStore()

const planLabel = computed(() => {
  switch (authStore.effectivePlan) {
    case 'business': return $t('plan.business')
    case 'professional': return $t('plan.professional')
    case 'starter': return $t('plan.starter')
    case 'freemium': return $t('plan.freemium')
    case 'trial': return $t('plan.trial')
    default: return $t('plan.free')
  }
})

const planBadgeColor = computed(() => {
  switch (authStore.effectivePlan) {
    case 'business': return 'success' as const
    case 'professional': return 'success' as const
    case 'starter': return 'info' as const
    case 'freemium': return 'neutral' as const
    case 'trial': return 'warning' as const
    default: return 'neutral' as const
  }
})

const nextPlan = computed(() => {
  if (authStore.effectivePlan === 'free' || authStore.effectivePlan === 'freemium') return { label: 'Starter', key: 'starter' }
  if (authStore.effectivePlan === 'starter') return { label: 'Professional', key: 'professional' }
  if (authStore.effectivePlan === 'professional') return { label: 'Business', key: 'business' }
  return null
})

// Build upgrade features dynamically from plan limits (no hardcoded values)
const upgradeFeatures = computed(() => {
  const features = authStore.plan?.features
  if (!features) return []

  const items: { icon: string, text: string }[] = []

  // Show what the next plan offers compared to current
  if (features.maxCompanies < 999999) {
    items.push({ icon: 'i-lucide-building-2', text: $t('upgrade.moreCompaniesDesc') })
  }
  if (features.maxUsersPerOrg < 999999) {
    items.push({ icon: 'i-lucide-users', text: $t('upgrade.moreUsersDesc') })
  }
  if (features.maxInvoicesPerMonth > 0) {
    items.push({ icon: 'i-lucide-file-text', text: $t('upgrade.unlimitedInvoices') })
  }
  if (!features.pdfGeneration) {
    items.push({ icon: 'i-lucide-file-output', text: $t('plan.pdfGeneration') })
  }
  if (!features.realtimeNotifications) {
    items.push({ icon: 'i-lucide-bell-ring', text: $t('plan.realtimeNotifications') })
  }

  return items
})
</script>

<template>
  <UModal v-model:open="isOpen">
    <template #body>
      <div class="flex flex-col items-center text-center gap-6 py-4">
        <!-- Icon -->
        <div class="size-16 rounded-full bg-primary-50 dark:bg-primary-950/30 flex items-center justify-center">
          <UIcon name="i-lucide-arrow-up-circle" class="size-8 text-primary" />
        </div>

        <!-- Title -->
        <div>
          <h2 class="text-xl font-bold">{{ $t('plan.planLimitReached') }}</h2>
          <p class="text-sm text-(--ui-text-muted) mt-1">
            {{ feature ? $t('upgrade.featureLimit', { feature }) : $t('plan.upgradeToContinue') }}
          </p>
        </div>

        <!-- Current plan badge -->
        <div class="flex items-center gap-2">
          <span class="text-sm text-(--ui-text-muted)">{{ $t('plan.currentPlan') }}:</span>
          <UBadge :color="planBadgeColor" variant="subtle">{{ planLabel }}</UBadge>
          <template v-if="currentLimit">
            <span class="text-xs text-(--ui-text-dimmed)">&mdash; {{ $t('upgrade.limitOf', { limit: currentLimit }) }}</span>
          </template>
        </div>

        <!-- What you get with upgrade -->
        <div v-if="nextPlan && upgradeFeatures.length" class="w-full rounded-lg border border-(--ui-border) bg-(--ui-bg-elevated)/50 p-4 text-left">
          <div class="text-xs font-semibold text-(--ui-text-muted) uppercase tracking-wide mb-3">
            {{ $t('upgrade.whatYouGet', { plan: nextPlan.label }) }}
          </div>
          <div class="grid grid-cols-2 gap-2.5">
            <div v-for="f in upgradeFeatures" :key="f.text" class="flex items-center gap-2">
              <UIcon :name="f.icon" class="size-4 text-primary shrink-0" />
              <span class="text-sm">{{ f.text }}</span>
            </div>
          </div>
        </div>

        <!-- CTA -->
        <div class="flex gap-3 w-full">
          <UButton
            variant="outline"
            color="neutral"
            class="flex-1"
            @click="isOpen = false"
          >
            {{ $t('common.cancel') }}
          </UButton>
          <UButton
            color="primary"
            class="flex-1"
            icon="i-lucide-sparkles"
            to="/pricing"
            @click="isOpen = false"
          >
            {{ $t('upgrade.seePlans') }}
          </UButton>
        </div>
      </div>
    </template>
  </UModal>
</template>
