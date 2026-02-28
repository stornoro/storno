<template>
  <div class="max-w-4xl w-full mx-auto px-4 py-8">
      <!-- Loading -->
      <div v-if="loading" class="flex flex-col items-center gap-4 py-16">
        <UIcon name="i-lucide-loader-2" class="animate-spin h-8 w-8 text-(--ui-primary)" />
        <p class="text-sm text-(--ui-text-muted)">{{ $t('common.loading') }}</p>
      </div>

      <!-- Error -->
      <UCard v-else-if="error" variant="outline" class="max-w-md mx-auto">
        <div class="flex flex-col items-center gap-4 py-8">
          <UIcon name="i-lucide-x-circle" class="h-12 w-12 text-(--ui-error)" />
          <p class="text-center font-medium">{{ $t('share.invalidTitle') }}</p>
          <p class="text-sm text-(--ui-text-muted) text-center">{{ $t('share.invalidDescription') }}</p>
        </div>
      </UCard>

      <!-- Invoice Content -->
      <div v-else-if="invoice" class="space-y-6">
        <!-- Company header -->
        <div class="text-center space-y-1">
          <h2 class="text-2xl font-bold">{{ invoice.companyName }}</h2>
          <p class="text-(--ui-text-muted)">{{ $t('share.invoiceNumber') }}: {{ invoice.invoiceNumber }}</p>
        </div>

        <!-- Summary card -->
        <UCard variant="outline">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <p class="text-xs text-(--ui-text-muted) uppercase tracking-wide">{{ $t('share.sender') }}</p>
              <p class="font-medium">{{ invoice.senderName }}</p>
              <p class="text-sm text-(--ui-text-muted)">{{ invoice.senderCif }}</p>
            </div>
            <div>
              <p class="text-xs text-(--ui-text-muted) uppercase tracking-wide">{{ $t('share.receiver') }}</p>
              <p class="font-medium">{{ invoice.receiverName }}</p>
              <p class="text-sm text-(--ui-text-muted)">{{ invoice.receiverCif }}</p>
            </div>
            <div>
              <p class="text-xs text-(--ui-text-muted) uppercase tracking-wide">{{ $t('share.issueDate') }}</p>
              <p class="font-medium">{{ formatDate(invoice.issueDate) }}</p>
            </div>
            <div v-if="invoice.dueDate">
              <p class="text-xs text-(--ui-text-muted) uppercase tracking-wide">{{ $t('share.dueDate') }}</p>
              <p class="font-medium">{{ formatDate(invoice.dueDate) }}</p>
            </div>
            <div class="sm:col-span-2">
              <p class="text-xs text-(--ui-text-muted) uppercase tracking-wide">{{ $t('share.total') }}</p>
              <div class="flex items-center gap-3">
                <p class="text-2xl font-bold">{{ invoice.total }} {{ invoice.currency }}</p>
                <UBadge v-if="paid" color="success" variant="subtle" size="lg">
                  <UIcon name="i-lucide-check-circle" class="size-4 mr-1" />
                  {{ $t('share.paid') }}
                </UBadge>
                <UBadge v-else-if="Number(invoice.amountPaid) > 0" color="warning" variant="subtle" size="lg">
                  {{ $t('share.partiallyPaid', { amount: invoice.amountPaid, currency: invoice.currency }) }}
                </UBadge>
              </div>
            </div>
          </div>
        </UCard>

        <!-- PDF Viewer -->
        <UCard v-if="invoice.hasPdf" variant="outline" :ui="{ body: 'p-0' }">
          <iframe
            :src="pdfUrl"
            class="w-full border-0 rounded-[calc(var(--ui-radius)*2)]"
            style="height: 70vh;"
          />
        </UCard>

        <!-- Payment section -->
        <UCard v-if="invoice.paymentEnabled && Number(invoice.amountDue) > 0" variant="outline" class="bg-primary-50 dark:bg-primary-950/20 border-primary-200 dark:border-primary-800">
          <div class="flex flex-col gap-4">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
              <div>
                <p class="font-semibold text-lg">{{ $t('share.payNow') }}</p>
                <p class="text-sm text-(--ui-text-muted)">
                  {{ $t('share.amountDue') }}: {{ invoice.amountDue }} {{ invoice.currency }}
                </p>
              </div>
              <UButton
                icon="i-lucide-credit-card"
                size="lg"
                color="primary"
                :loading="paymentLoading"
                @click="onPay"
              >
                {{ invoice.allowPartialPayments && partialAmount
                  ? $t('share.payAmount', { amount: partialAmount, currency: invoice.currency })
                  : $t('share.payNow') }}
              </UButton>
            </div>
            <div v-if="invoice.allowPartialPayments" class="flex flex-col gap-1">
              <label class="text-sm font-medium">{{ $t('share.partialPaymentLabel') }}</label>
              <UInput
                v-model="partialAmount"
                type="number"
                :placeholder="invoice.amountDue"
                :min="0.01"
                :max="Number(invoice.amountDue)"
                step="0.01"
              />
              <p class="text-xs text-(--ui-text-muted)">
                {{ $t('share.partialPaymentMin', { min: '0.01', currency: invoice.currency }) }}
                &mdash;
                {{ $t('share.partialPaymentMax', { max: invoice.amountDue, currency: invoice.currency }) }}
              </p>
            </div>
          </div>
        </UCard>

        <!-- Payment success (fully paid) -->
        <UCard v-if="paid" variant="outline" class="bg-success-50 dark:bg-success-950/20 border-success-200 dark:border-success-800">
          <div class="flex flex-col items-center gap-2 py-2">
            <div class="flex items-center gap-3">
              <UIcon name="i-lucide-check-circle" class="size-6 text-success-500" />
              <p class="font-medium text-success-700 dark:text-success-300">{{ $t('share.paymentSuccess') }}</p>
            </div>
            <p v-if="invoice.successMessage" class="text-sm text-success-600 dark:text-success-400 text-center">
              {{ invoice.successMessage }}
            </p>
          </div>
        </UCard>

        <!-- Payment canceled -->
        <UCard v-if="route.query.payment === 'canceled'" variant="outline" class="bg-warning-50 dark:bg-warning-950/20 border-warning-200 dark:border-warning-800">
          <div class="flex items-center gap-3 justify-center py-2">
            <UIcon name="i-lucide-alert-triangle" class="size-6 text-warning-500" />
            <p class="font-medium text-warning-700 dark:text-warning-300">{{ $t('share.paymentCanceled') }}</p>
          </div>
        </UCard>

        <!-- Payment error -->
        <UCard v-if="paymentError" variant="outline" class="bg-error-50 dark:bg-error-950/20 border-error-200 dark:border-error-800">
          <div class="flex items-center gap-3 justify-center py-2">
            <UIcon name="i-lucide-x-circle" class="size-6 text-error-500" />
            <p class="font-medium text-error-700 dark:text-error-300">{{ $t('share.paymentFailed') }}</p>
          </div>
        </UCard>

        <!-- Download buttons -->
        <div class="flex flex-wrap gap-3 justify-center">
          <UButton
            v-if="invoice.hasPdf"
            icon="i-lucide-file-text"
            size="lg"
            variant="solid"
            :href="pdfUrl"
            target="_blank"
          >
            {{ $t('share.downloadPdf') }}
          </UButton>
          <UButton
            v-if="invoice.hasXml"
            icon="i-lucide-file-code"
            size="lg"
            variant="outline"
            :href="xmlUrl"
            target="_blank"
          >
            {{ $t('share.downloadXml') }}
          </UButton>
        </div>

        <!-- Footer -->
        <div class="text-center text-xs text-(--ui-text-muted) space-y-1 pt-4 border-t border-(--ui-border)">
          <p>{{ $t('share.validUntil') }}: {{ formatDateTime(invoice.expiresAt) }}</p>
          <p>{{ $t('share.poweredBy') }}</p>
        </div>
      </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'minimal' })

const { t: $t } = useI18n()
const route = useRoute()
const config = useRuntimeConfig()

const token = computed(() => route.params.token as string)
const loading = ref(true)
const error = ref(false)
const invoice = ref<any>(null)

const apiBase = useApiBase()
const fetchFn = useRequestFetch()
const pdfUrl = computed(() => `${apiBase}/v1/share/${token.value}/pdf`)
const xmlUrl = computed(() => `${apiBase}/v1/share/${token.value}/xml`)

const paymentLoading = ref(false)
const paymentError = ref(false)
const partialAmount = ref('')
const paid = computed(() => !!invoice.value?.paidAt)

async function onPay() {
  paymentLoading.value = true
  paymentError.value = false
  try {
    const body: Record<string, any> = {}
    if (invoice.value?.allowPartialPayments && partialAmount.value) {
      body.amount = partialAmount.value
    }
    const data = await fetchFn<{ url: string }>(`/v1/share/${token.value}/pay`, {
      baseURL: apiBase,
      method: 'POST',
      body,
    })
    if (data.url) {
      window.location.href = data.url
    }
  }
  catch {
    paymentError.value = true
  }
  finally {
    paymentLoading.value = false
  }
}

function formatDate(dateStr: string | null): string {
  if (!dateStr) return '-'
  const d = new Date(dateStr)
  return d.toLocaleDateString('ro-RO', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function formatDateTime(dateStr: string | null): string {
  if (!dateStr) return '-'
  const d = new Date(dateStr)
  return d.toLocaleDateString('ro-RO', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

async function fetchInvoice() {
  return await fetchFn<any>(`/v1/share/${token.value}`, { baseURL: apiBase })
}

onMounted(async () => {
  try {
    invoice.value = await fetchInvoice()

    // After returning from Stripe payment, re-fetch to get updated amountDue
    if (route.query.payment === 'success' && Number(invoice.value.amountDue) > 0) {
      // Give the webhook a moment to process, then poll until paid or timeout
      let attempts = 0
      const poll = setInterval(async () => {
        attempts++
        try {
          const fresh = await fetchInvoice()
          invoice.value = fresh
          if (Number(fresh.amountDue) <= 0 || attempts >= 5) {
            clearInterval(poll)
          }
        } catch {
          clearInterval(poll)
        }
      }, 2000)
    }
  }
  catch {
    error.value = true
  }
  finally {
    loading.value = false
  }
})
</script>
