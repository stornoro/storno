<script setup lang="ts">
/**
 * CancellationModal — multi-step subscription cancellation flow.
 *
 * Expected backend endpoint (created separately):
 *   POST /api/v1/billing/cancel-reason
 *   Body: {
 *     reason: string,          // e.g. 'too_expensive'
 *     detail: string | null,   // free-text for 'other'
 *     offeredDiscount: boolean,
 *     accepted: boolean,       // true = user accepted save offer, false = proceeded to cancel
 *   }
 *   Response: { ok: true }
 */

type Step = 'survey' | 'offer' | 'confirm'
type Reason = 'too_expensive' | 'not_used_enough' | 'found_alternative' | 'missing_features' | 'other'

const isOpen = defineModel<boolean>('open', { required: true })

const { t: $t } = useI18n()
const billingStore = useBillingStore()
const authStore = useAuthStore()
const toast = useToast()
const { post } = useApi()

// ── State ──────────────────────────────────────────────────────────
const step = ref<Step>('survey')
const selectedReason = ref<Reason | null>(null)
const otherDetail = ref('')
const submitting = ref(false)
const trackingReason = ref(false)

// ── Reason options ────────────────────────────────────────────────
const reasonOptions = computed(() => [
  { label: $t('cancellation.reasons.tooExpensive'), value: 'too_expensive' as Reason },
  { label: $t('cancellation.reasons.notUsedEnough'), value: 'not_used_enough' as Reason },
  { label: $t('cancellation.reasons.foundAlternative'), value: 'found_alternative' as Reason },
  { label: $t('cancellation.reasons.missingFeatures'), value: 'missing_features' as Reason },
  { label: $t('cancellation.reasons.other'), value: 'other' as Reason },
])

// ── Offer logic ───────────────────────────────────────────────────
const hasOffer = computed(() => {
  return selectedReason.value === 'too_expensive'
    || selectedReason.value === 'not_used_enough'
    || selectedReason.value === 'missing_features'
})

const offerTitle = computed(() => {
  switch (selectedReason.value) {
    case 'too_expensive':
      return $t('cancellation.offerDiscount')
    case 'not_used_enough':
      return $t('cancellation.offerPause')
    case 'missing_features':
      return $t('cancellation.offerFeatures')
    default:
      return null
  }
})

const offerDescription = computed(() => {
  switch (selectedReason.value) {
    case 'too_expensive':
      return $t('cancellation.offerDiscountDesc')
    case 'not_used_enough':
      return $t('cancellation.offerPauseDesc')
    case 'missing_features':
      return $t('cancellation.offerFeaturesDesc')
    default:
      return null
  }
})

const offerIcon = computed(() => {
  switch (selectedReason.value) {
    case 'too_expensive': return 'i-lucide-tag'
    case 'not_used_enough': return 'i-lucide-pause-circle'
    case 'missing_features': return 'i-lucide-sparkles'
    default: return 'i-lucide-heart'
  }
})

// ── Navigation ────────────────────────────────────────────────────
function goToOffer() {
  if (!selectedReason.value) return
  if (hasOffer.value) {
    step.value = 'offer'
  }
  else {
    step.value = 'confirm'
  }
}

function goToConfirm() {
  step.value = 'confirm'
}

function goBack() {
  if (step.value === 'offer') {
    step.value = 'survey'
  }
  else if (step.value === 'confirm') {
    step.value = hasOffer.value ? 'offer' : 'survey'
  }
}

// ── Track cancel reason (fire-and-forget) ─────────────────────────
async function trackCancelReason(accepted: boolean): Promise<void> {
  if (!selectedReason.value) return
  try {
    await post('/v1/billing/cancel-reason', {
      reason: selectedReason.value,
      detail: selectedReason.value === 'other' ? otherDetail.value : null,
      offeredDiscount: hasOffer.value,
      accepted,
    })
  }
  catch {
    // Non-critical — don't surface this error to user
  }
}

// ── Accept offer ─────────────────────────────────────────────────
async function onAcceptOffer() {
  trackingReason.value = true
  await trackCancelReason(true)
  trackingReason.value = false
  isOpen.value = false
  toast.add({
    title: $t('cancellation.offerAccepted'),
    color: 'success',
  })
}

// ── Proceed to cancel ─────────────────────────────────────────────
async function onConfirmCancel() {
  submitting.value = true
  try {
    await trackCancelReason(false)
    await billingStore.cancel()
    await authStore.fetchUser()
    isOpen.value = false
    toast.add({
      title: $t('settings.billingPage.cancelSuccess'),
      color: 'success',
    })
  }
  catch {
    toast.add({
      title: billingStore.error ?? $t('settings.billingPage.error'),
      color: 'error',
    })
  }
  finally {
    submitting.value = false
  }
}

// ── Reset state when modal closes ─────────────────────────────────
watch(isOpen, (open) => {
  if (!open) {
    step.value = 'survey'
    selectedReason.value = null
    otherDetail.value = ''
  }
})
</script>

<template>
  <UModal v-model:open="isOpen" :ui="{ width: 'sm:max-w-lg' }">
    <template #content>
      <div class="p-6 space-y-5">
        <!-- Header -->
        <div class="flex items-start gap-3">
          <div class="size-10 rounded-full bg-error/10 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-alert-triangle" class="size-5 text-error" />
          </div>
          <div>
            <h3 class="text-lg font-semibold">{{ $t('cancellation.title') }}</h3>
            <p class="text-sm text-(--ui-text-muted) mt-0.5">
              <template v-if="step === 'survey'">{{ $t('cancellation.whyLeaving') }}</template>
              <template v-else-if="step === 'offer'">{{ $t('cancellation.specialOffer') }}</template>
              <template v-else>{{ $t('cancellation.areYouSure') }}</template>
            </p>
          </div>
        </div>

        <!-- Step 1: Survey -->
        <template v-if="step === 'survey'">
          <div class="space-y-2">
            <label
              v-for="option in reasonOptions"
              :key="option.value"
              class="flex items-center gap-3 p-3 rounded-lg border border-(--ui-border) cursor-pointer transition-colors"
              :class="selectedReason === option.value
                ? 'border-primary bg-primary-50 dark:bg-primary-950/20'
                : 'hover:border-(--ui-border-accented) hover:bg-(--ui-bg-elevated)'"
            >
              <input
                type="radio"
                :value="option.value"
                :checked="selectedReason === option.value"
                class="text-primary"
                @change="selectedReason = option.value"
              />
              <span class="text-sm font-medium">{{ option.label }}</span>
            </label>
          </div>

          <!-- Other: free-text -->
          <div v-if="selectedReason === 'other'" class="space-y-1.5">
            <label class="text-sm font-medium text-(--ui-text-muted)">
              {{ $t('cancellation.otherPlaceholder') }}
            </label>
            <UTextarea
              v-model="otherDetail"
              :placeholder="$t('cancellation.otherPlaceholder')"
              :rows="3"
              resize
            />
          </div>

          <div class="flex justify-end gap-2 pt-2">
            <UButton variant="ghost" @click="isOpen = false">
              {{ $t('cancellation.keepSubscription') }}
            </UButton>
            <UButton
              color="error"
              :disabled="!selectedReason"
              @click="goToOffer"
            >
              {{ $t('common.continue') }}
            </UButton>
          </div>
        </template>

        <!-- Step 2: Save offer -->
        <template v-else-if="step === 'offer'">
          <div class="rounded-xl border border-(--ui-border) bg-(--ui-bg-elevated) p-5 space-y-3">
            <div class="flex items-center gap-3">
              <div class="size-10 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
                <UIcon :name="offerIcon" class="size-5 text-primary" />
              </div>
              <div>
                <p class="font-semibold">{{ offerTitle }}</p>
              </div>
            </div>
            <p class="text-sm text-(--ui-text-muted)">{{ offerDescription }}</p>
          </div>

          <div class="flex flex-col gap-2 pt-2">
            <UButton
              color="primary"
              block
              :loading="trackingReason"
              icon="i-lucide-check"
              @click="onAcceptOffer"
            >
              {{ $t('cancellation.acceptOffer') }}
            </UButton>
            <div class="flex gap-2">
              <UButton variant="ghost" class="flex-1" @click="goBack">
                {{ $t('common.back') }}
              </UButton>
              <UButton
                color="error"
                variant="soft"
                class="flex-1"
                @click="goToConfirm"
              >
                {{ $t('cancellation.continueToCancel') }}
              </UButton>
            </div>
          </div>
        </template>

        <!-- Step 3: Final confirmation -->
        <template v-else>
          <div class="rounded-lg bg-error/5 border border-error/20 p-4 space-y-2">
            <p class="text-sm font-medium text-error">
              {{ $t('cancellation.confirmWarning') }}
            </p>
            <p class="text-sm text-(--ui-text-muted)">
              {{ $t('settings.billingPage.cancelConfirm') }}
            </p>
          </div>

          <div class="flex gap-2 pt-2">
            <UButton variant="ghost" class="flex-1" @click="goBack">
              {{ $t('cancellation.keepSubscription') }}
            </UButton>
            <UButton
              color="error"
              class="flex-1"
              :loading="submitting"
              @click="onConfirmCancel"
            >
              {{ $t('cancellation.confirmCancel') }}
            </UButton>
          </div>
        </template>
      </div>
    </template>
  </UModal>
</template>
