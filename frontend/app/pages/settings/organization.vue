<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('settings.organization') })
const authStore = useAuthStore()
const companyStore = useCompanyStore()
const toast = useToast()

// ── Danger Zone ───────────────────────────────────────────────────
const deleteModalOpen = ref(false)
const resetModalOpen = ref(false)
const deleteConfirmInput = ref('')
const resetConfirmInput = ref('')
const deleting = ref(false)
const resetting = ref(false)

const companyName = computed(() => companyStore.currentCompany?.name ?? '')

const canConfirmDelete = computed(() =>
  deleteConfirmInput.value.trim().toLowerCase() === companyName.value.trim().toLowerCase(),
)

const canConfirmReset = computed(() =>
  resetConfirmInput.value.trim().toLowerCase() === 'confirm',
)

async function onDeleteCompany() {
  if (!companyStore.currentCompany || !canConfirmDelete.value) return
  deleting.value = true
  const success = await companyStore.deleteCompany(companyStore.currentCompany.id)
  deleting.value = false
  if (success) {
    deleteModalOpen.value = false
    toast.add({ title: $t('companies.deleteGracePeriod'), color: 'warning' })
    navigateTo('/companies')
  }
  else {
    toast.add({ title: companyStore.error ?? $t('settings.dangerZone.deleteCompany.error'), color: 'error' })
  }
}

async function onResetCompany() {
  if (!companyStore.currentCompany || !canConfirmReset.value) return
  resetting.value = true
  const success = await companyStore.resetCompany(companyStore.currentCompany.id)
  resetting.value = false
  if (success) {
    resetModalOpen.value = false
    resetConfirmInput.value = ''
    toast.add({ title: $t('settings.dangerZone.resetCompany.success'), color: 'success' })
  }
  else {
    toast.add({ title: companyStore.error ?? $t('settings.dangerZone.resetCompany.error'), color: 'error' })
  }
}

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

const syncIntervalLabel = computed(() => {
  const seconds = authStore.plan?.features?.syncIntervalSeconds
  if (!seconds) return ''
  if (seconds >= 86400) return $t('plan.syncDaily')
  if (seconds >= 3600) { const h = Math.round(seconds / 3600); return $t('plan.syncHours', h, { count: h }) }
  return $t('plan.syncMinutes', { minutes: Math.round(seconds / 60) })
})

const featureList = computed(() => {
  const features = authStore.plan?.features
  if (!features) return []

  return [
    {
      key: 'maxCompanies',
      label: $t('plan.maxCompanies'),
      enabled: true,
      value: features.maxCompanies >= 999999 ? $t('plan.unlimited') : String(features.maxCompanies),
    },
    {
      key: 'maxUsers',
      label: $t('plan.maxUsers'),
      enabled: true,
      value: features.maxUsersPerOrg >= 999999 ? $t('plan.unlimited') : String(features.maxUsersPerOrg),
    },
    {
      key: 'syncInterval',
      label: $t('plan.syncInterval'),
      enabled: true,
      value: syncIntervalLabel.value,
    },
    {
      key: 'maxInvoicesPerMonth',
      label: $t('plan.maxInvoicesPerMonth'),
      enabled: true,
      value: features.maxInvoicesPerMonth === 0 ? $t('plan.unlimited') : String(features.maxInvoicesPerMonth),
    },
    {
      key: 'pdfGeneration',
      label: $t('plan.pdfGeneration'),
      enabled: features.pdfGeneration,
    },
    {
      key: 'signatureVerification',
      label: $t('plan.signatureVerification'),
      enabled: features.signatureVerification,
    },
    {
      key: 'apiAccess',
      label: $t('plan.apiAccess'),
      enabled: features.apiAccess,
    },
    {
      key: 'realtimeNotifications',
      label: $t('plan.realtimeNotifications'),
      enabled: features.realtimeNotifications,
    },
  ]
})

function formatDate(date: string) {
  return new Date(date).toLocaleString('ro-RO', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}
</script>

<template>
  <div>
    <UPageCard
      :title="$t('settings.organization')"
      :description="$t('settings.organizationDescription')"
      variant="naked"
      class="mb-4"
    />

    <!-- Organization details -->
    <UPageCard variant="subtle">
      <UFormField
        :label="$t('settings.organizationName')"
        class="flex max-sm:flex-col justify-between items-start gap-4"
      >
        <span class="font-medium">{{ authStore.organization?.name ?? '-' }}</span>
      </UFormField>
      <USeparator />
      <UFormField
        :label="$t('settings.createdAt')"
        class="flex max-sm:flex-col justify-between items-start gap-4"
      >
        <span class="font-medium">{{ authStore.organization?.createdAt ? formatDate(authStore.organization.createdAt) : '-' }}</span>
      </UFormField>
    </UPageCard>

    <!-- Plan info -->
    <div class="mt-8">
      <UPageCard
        :title="$t('plan.title')"
        variant="naked"
        orientation="horizontal"
        class="mb-4"
      >
        <UBadge :color="planBadgeColor" variant="subtle" size="lg" class="lg:ms-auto">
          {{ planLabel }}
        </UBadge>
      </UPageCard>

      <UPageCard variant="subtle">
        <ClientOnly>
          <!-- Trial banner -->
          <div v-if="authStore.isTrial" class="rounded-lg bg-warning-50 dark:bg-warning-950/20 p-4 border border-warning-200 dark:border-warning-800">
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-clock" class="text-warning-500 shrink-0" />
              <div>
                <div class="font-medium text-warning-700 dark:text-warning-300">
                  {{ $t('plan.trialDaysLeft', authStore.plan?.trialDaysLeft ?? 0, { count: authStore.plan?.trialDaysLeft ?? 0 }) }}
                </div>
                <div class="text-sm text-warning-600 dark:text-warning-400">
                  {{ $t('plan.trialEndsAt') }}: {{ authStore.plan?.trialEndsAt ? formatDate(authStore.plan.trialEndsAt) : '' }}
                </div>
              </div>
            </div>
          </div>

          <!-- Features grid -->
          <div class="grid grid-cols-2 gap-3">
            <div v-for="feature in featureList" :key="feature.key" class="flex items-center gap-2">
              <UIcon
                :name="feature.enabled ? 'i-lucide-check-circle' : 'i-lucide-x-circle'"
                :class="feature.enabled ? 'text-success-500' : 'text-neutral-400'"
              />
              <span class="text-sm">{{ feature.label }}</span>
              <span v-if="feature.value" class="text-sm text-muted ml-auto">{{ feature.value }}</span>
            </div>
          </div>

          <!-- Upgrade CTA -->
          <div v-if="authStore.effectivePlan === 'free' || authStore.effectivePlan === 'starter'" class="pt-4 border-t border-default">
            <UButton color="primary" size="lg" block to="/pricing">
              {{ $t('plan.upgrade') }}
            </UButton>
          </div>

          <template #fallback>
            <div class="grid grid-cols-2 gap-3">
              <USkeleton v-for="i in 8" :key="i" class="h-6 rounded" />
            </div>
          </template>
        </ClientOnly>
      </UPageCard>
    </div>

    <!-- Danger Zone -->
    <div v-if="companyStore.currentCompany" class="mt-8">
      <UPageCard
        :title="$t('settings.dangerZone.title')"
        variant="naked"
        class="mb-4"
      />

      <!-- Warning banner -->
      <div class="rounded-lg bg-warning-50 dark:bg-warning-950/20 p-4 border border-warning-200 dark:border-warning-800 mb-4">
        <div class="flex items-start gap-2">
          <UIcon name="i-lucide-triangle-alert" class="text-warning-500 shrink-0 mt-0.5" />
          <p class="text-sm text-warning-700 dark:text-warning-300">
            {{ $t('settings.dangerZone.warning') }}
          </p>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- Delete company card -->
        <UPageCard variant="subtle" class="border border-error/20">
          <div class="space-y-3">
            <div class="flex items-center gap-2 text-error">
              <UIcon name="i-lucide-trash-2" class="size-4" />
              <span class="font-medium text-sm">{{ $t('settings.dangerZone.deleteCompany.title') }}</span>
            </div>
            <p class="text-sm text-muted">{{ $t('settings.dangerZone.deleteCompany.description') }}</p>
            <UButton
              color="error"
              variant="soft"
              icon="i-lucide-trash-2"
              @click="deleteModalOpen = true; deleteConfirmInput = ''"
            >
              {{ $t('settings.dangerZone.deleteCompany.button') }}
            </UButton>
          </div>
        </UPageCard>

        <!-- Reset company card -->
        <UPageCard variant="subtle" class="border border-error/20">
          <div class="space-y-3">
            <div class="flex items-center gap-2 text-error">
              <UIcon name="i-lucide-rotate-ccw" class="size-4" />
              <span class="font-medium text-sm">{{ $t('settings.dangerZone.resetCompany.title') }}</span>
            </div>
            <p class="text-sm text-muted">{{ $t('settings.dangerZone.resetCompany.description') }}</p>
            <UButton
              color="error"
              variant="soft"
              icon="i-lucide-rotate-ccw"
              @click="resetModalOpen = true; resetConfirmInput = ''"
            >
              {{ $t('settings.dangerZone.resetCompany.button') }}
            </UButton>
          </div>
        </UPageCard>
      </div>
    </div>

    <!-- Delete company confirmation modal -->
    <UModal v-model:open="deleteModalOpen">
      <template #content>
        <div class="p-6 space-y-4">
          <div class="flex items-center gap-2 text-error">
            <UIcon name="i-lucide-alert-triangle" class="size-5" />
            <h3 class="text-lg font-semibold">{{ $t('settings.dangerZone.deleteCompany.modalTitle') }}</h3>
          </div>
          <p class="text-sm text-muted">
            {{ $t('settings.dangerZone.deleteCompany.modalDescription', { name: companyName }) }}
          </p>
          <UFormField :label="$t('settings.dangerZone.deleteCompany.confirmLabel')">
            <UInput
              v-model="deleteConfirmInput"
              :placeholder="$t('settings.dangerZone.deleteCompany.confirmPlaceholder')"
            />
            <template #hint>
              <span class="text-xs">{{ $t('settings.dangerZone.deleteCompany.confirmHint', { name: companyName }) }}</span>
            </template>
          </UFormField>
          <div class="flex justify-end gap-2 pt-2">
            <UButton variant="ghost" @click="deleteModalOpen = false">
              {{ $t('common.cancel') }}
            </UButton>
            <UButton
              color="error"
              :loading="deleting"
              :disabled="!canConfirmDelete"
              @click="onDeleteCompany"
            >
              {{ $t('settings.dangerZone.deleteCompany.button') }}
            </UButton>
          </div>
        </div>
      </template>
    </UModal>

    <!-- Reset company confirmation modal -->
    <UModal v-model:open="resetModalOpen">
      <template #content>
        <div class="p-6 space-y-4">
          <div class="flex items-center gap-2 text-error">
            <UIcon name="i-lucide-alert-triangle" class="size-5" />
            <h3 class="text-lg font-semibold">{{ $t('settings.dangerZone.resetCompany.modalTitle') }}</h3>
          </div>

          <!-- Warning banner inside modal -->
          <div class="rounded-lg bg-warning-50 dark:bg-warning-950/20 p-3 border border-warning-200 dark:border-warning-800">
            <div class="flex items-center gap-2 text-sm font-medium text-warning-700 dark:text-warning-300">
              <UIcon name="i-lucide-triangle-alert" class="size-4 shrink-0" />
              {{ $t('settings.dangerZone.warning') }}
            </div>
          </div>

          <p class="text-sm text-muted">
            {{ $t('settings.dangerZone.resetCompany.modalDescription', { name: companyName }) }}
          </p>

          <ul class="text-sm text-muted space-y-1.5 pl-1">
            <li class="flex items-start gap-2">
              <UIcon name="i-lucide-check" class="size-4 text-success-500 shrink-0 mt-0.5" />
              {{ $t('settings.dangerZone.resetCompany.bulletPoints.access') }}
            </li>
            <li class="flex items-start gap-2">
              <UIcon name="i-lucide-x" class="size-4 text-error shrink-0 mt-0.5" />
              {{ $t('settings.dangerZone.resetCompany.bulletPoints.dataWipe') }}
            </li>
            <li class="flex items-start gap-2">
              <UIcon name="i-lucide-check" class="size-4 text-success-500 shrink-0 mt-0.5" />
              {{ $t('settings.dangerZone.resetCompany.bulletPoints.usersKept') }}
            </li>
            <li class="flex items-start gap-2">
              <UIcon name="i-lucide-rotate-ccw" class="size-4 text-warning-500 shrink-0 mt-0.5" />
              {{ $t('settings.dangerZone.resetCompany.bulletPoints.syncRestart') }}
            </li>
          </ul>

          <UFormField :label="$t('settings.dangerZone.resetCompany.confirmLabel')">
            <UInput
              v-model="resetConfirmInput"
              :placeholder="$t('settings.dangerZone.resetCompany.confirmPlaceholder')"
            />
            <template #hint>
              <span class="text-xs">{{ $t('settings.dangerZone.resetCompany.confirmHint') }}</span>
            </template>
          </UFormField>

          <div class="flex justify-end gap-2 pt-2">
            <UButton variant="ghost" @click="resetModalOpen = false">
              {{ $t('common.cancel') }}
            </UButton>
            <UButton
              color="error"
              :loading="resetting"
              :disabled="!canConfirmReset"
              @click="onResetCompany"
            >
              {{ $t('common.continue') }}
            </UButton>
          </div>
        </div>
      </template>
    </UModal>
  </div>
</template>
