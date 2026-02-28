<script setup lang="ts">
import { storeToRefs } from 'pinia'

const { t: $t } = useI18n()
const companyStore = useCompanyStore()
const syncStore = useSyncStore()
const dashboardStore = useDashboardStore()
const bankAccountStore = useBankAccountStore()

// Use storeToRefs for reliable reactivity tracking
const { hasCompanies, currentCompany } = storeToRefs(companyStore)
const { hasValidToken } = storeToRefs(syncStore)
const { lastSyncedAt, outgoingInvoices } = storeToRefs(dashboardStore)
const { items: bankItems } = storeToRefs(bankAccountStore)

// Allow user to dismiss permanently (use localStorage for persistence)
const DISMISS_KEY = 'onboarding_checklist_dismissed'
const dismissed = ref(false)

// Fetch data this component needs (don't rely solely on parent)
onMounted(async () => {
  dismissed.value = localStorage.getItem(DISMISS_KEY) === 'true'

  if (bankItems.value.length === 0) {
    bankAccountStore.fetchBankAccounts()
  }
  if (!syncStore.syncStatus) {
    syncStore.fetchStatus()
  }
})

const anafSettingsLink = computed(() => {
  const id = currentCompany.value?.id
  return id ? `/companies/${id}/anaf` : '/companies'
})

interface OnboardingStep {
  key: string
  label: string
  description: string
  time: string
  done: boolean
  to: string
  isInvoiceStep?: boolean
}

const steps = computed<OnboardingStep[]>(() => [
  {
    key: 'company',
    label: $t('dashboard.onboarding.addCompany'),
    description: $t('dashboard.onboarding.addCompanyDesc'),
    time: $t('dashboard.onboarding.addCompanyTime'),
    done: hasCompanies.value,
    to: '/companies',
  },
  {
    key: 'anaf',
    label: $t('dashboard.onboarding.connectAnaf'),
    description: $t('dashboard.onboarding.connectAnafDesc'),
    time: $t('dashboard.onboarding.connectAnafTime'),
    done: hasValidToken.value,
    to: anafSettingsLink.value,
  },
  {
    key: 'bank',
    label: $t('dashboard.onboarding.addBankAccount'),
    description: $t('dashboard.onboarding.addBankAccountDesc'),
    time: $t('dashboard.onboarding.addBankAccountTime'),
    done: bankItems.value.length > 0,
    to: '/settings/bank-accounts',
  },
  {
    key: 'sync',
    label: $t('dashboard.onboarding.syncInvoices'),
    description: $t('dashboard.onboarding.syncInvoicesDesc'),
    time: $t('dashboard.onboarding.syncInvoicesTime'),
    done: !!lastSyncedAt.value,
    to: '/efactura',
  },
  {
    key: 'invoice',
    label: $t('dashboard.onboarding.firstInvoice'),
    description: $t('dashboard.onboarding.firstInvoiceDesc'),
    time: $t('dashboard.onboarding.firstInvoiceTime'),
    done: (outgoingInvoices.value ?? 0) > 0,
    to: '/invoices?create=true',
    isInvoiceStep: true,
  },
])

const completedCount = computed(() => steps.value.filter(s => s.done).length)
const allDone = computed(() => completedCount.value === steps.value.length)
const progressPercent = computed(() =>
  steps.value.length > 0
    ? Math.round((completedCount.value / steps.value.length) * 100)
    : 0,
)

function dismiss() {
  dismissed.value = true
  localStorage.setItem(DISMISS_KEY, 'true')
}

// ── FirstInvoiceWizard modal ───────────────────────────────────────
const wizardOpen = ref(false)

function openWizard() {
  wizardOpen.value = true
}

function onInvoiceCreated() {
  // Refresh dashboard stats so the "first invoice" step becomes done
  dashboardStore.fetchStats({})
}
</script>

<template>
  <ClientOnly>
    <!-- All done — celebration state -->
    <div
      v-if="allDone && !dismissed"
      class="rounded-lg border border-success-200 dark:border-success-800 bg-success-50/60 dark:bg-success-950/20 p-4 flex items-start gap-4 shrink-0"
    >
      <div class="flex items-center justify-center size-10 rounded-full bg-success-100 dark:bg-success-900/40 shrink-0">
        <UIcon name="i-lucide-party-popper" class="size-5 text-success-600 dark:text-success-400" />
      </div>
      <div class="flex-1 min-w-0">
        <p class="font-semibold text-success-700 dark:text-success-300">{{ $t('onboarding.completed') }}</p>
        <p class="text-sm text-success-600/80 dark:text-success-400/80 mt-0.5">{{ $t('onboarding.completedDescription') }}</p>
      </div>
      <UButton
        icon="i-lucide-x"
        color="neutral"
        variant="ghost"
        size="xs"
        @click="dismiss"
      />
    </div>

    <!-- In-progress checklist -->
    <div
      v-else-if="!allDone && !dismissed"
      class="rounded-lg border border-primary/20 bg-primary-50/50 dark:bg-primary-950/20 overflow-hidden shrink-0"
    >
      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-3 border-b border-primary/10">
        <span class="font-semibold text-sm">{{ $t('dashboard.onboarding.title') }}</span>
        <UButton
          icon="i-lucide-x"
          color="neutral"
          variant="ghost"
          size="xs"
          @click="dismiss"
        />
      </div>

      <!-- Progress bar -->
      <div class="px-4 pt-3 pb-1 space-y-1.5">
        <div class="flex items-center justify-between text-xs text-(--ui-text-muted)">
          <span>{{ $t('onboarding.progress', { completed: completedCount, total: steps.length }) }}</span>
          <span class="font-semibold text-primary">{{ progressPercent }}%</span>
        </div>
        <UProgress
          :model-value="progressPercent"
          :max="100"
          size="sm"
          color="primary"
        />
      </div>

      <!-- Steps -->
      <div class="px-4 py-3 space-y-1">
        <div
          v-for="step in steps"
          :key="step.key"
          class="group"
        >
          <!-- Regular steps (non-invoice): navigate on click -->
          <NuxtLink
            v-if="!step.isInvoiceStep || step.done"
            :to="step.done ? undefined : step.to"
            class="flex items-start gap-3 rounded-md px-2 py-2 transition-colors"
            :class="step.done ? 'cursor-default' : 'cursor-pointer hover:bg-primary/5'"
          >
            <UIcon
              :name="step.done ? 'i-lucide-circle-check' : 'i-lucide-circle'"
              class="size-5 shrink-0 mt-0.5"
              :class="step.done ? 'text-success-500' : 'text-muted group-hover:text-primary'"
            />
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span
                  class="text-sm leading-tight"
                  :class="step.done ? 'line-through text-muted' : 'group-hover:text-primary'"
                >
                  {{ step.label }}
                </span>
                <span
                  v-if="!step.done"
                  class="text-xs text-(--ui-text-dimmed) shrink-0"
                >
                  {{ step.time }}
                </span>
              </div>
              <p
                v-if="!step.done"
                class="text-xs text-(--ui-text-muted) mt-0.5 leading-relaxed"
              >
                {{ step.description }}
              </p>
            </div>
          </NuxtLink>

          <!-- Invoice step (not done): show two action buttons -->
          <div
            v-else
            class="flex items-start gap-3 rounded-md px-2 py-2"
          >
            <UIcon
              name="i-lucide-circle"
              class="size-5 shrink-0 mt-0.5 text-muted"
            />
            <div class="flex-1 min-w-0 space-y-2">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm leading-tight">{{ step.label }}</span>
                <span class="text-xs text-(--ui-text-dimmed) shrink-0">{{ step.time }}</span>
              </div>
              <p class="text-xs text-(--ui-text-muted) leading-relaxed">{{ step.description }}</p>
              <div class="flex items-center gap-2 flex-wrap">
                <UButton
                  size="xs"
                  variant="soft"
                  color="primary"
                  icon="i-lucide-wand-sparkles"
                  @click="openWizard"
                >
                  {{ $t('dashboard.onboarding.stepByStepGuide') }}
                </UButton>
                <NuxtLink :to="step.to">
                  <UButton
                    size="xs"
                    variant="ghost"
                    color="neutral"
                    icon="i-lucide-pencil"
                  >
                    {{ $t('dashboard.onboarding.createManually') }}
                  </UButton>
                </NuxtLink>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- First Invoice Wizard modal -->
    <OnboardingFirstInvoiceWizard
      v-model:open="wizardOpen"
      @invoice-created="onInvoiceCreated"
    />
  </ClientOnly>
</template>
