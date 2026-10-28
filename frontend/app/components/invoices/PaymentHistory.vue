<template>
  <div class="space-y-4">
    <!-- Summary bar -->
    <div class="flex items-center justify-between p-3 rounded-lg bg-(--ui-bg-elevated)">
      <div class="flex items-center gap-4 text-sm">
        <span>{{ $t('invoices.amountPaid') }}: <strong>{{ formatMoney(invoice.amountPaid, invoice.currency) }}</strong></span>
        <span class="text-(--ui-text-muted)">/</span>
        <span>{{ $t('invoices.total') }}: <strong>{{ formatMoney(invoice.total, invoice.currency) }}</strong></span>
      </div>
      <div>
        <UBadge v-if="Number(invoice.balance) === 0" color="success" variant="subtle">
          {{ $t('documentStatus.paid') }}
        </UBadge>
        <UBadge v-else-if="Number(invoice.amountPaid) > 0" color="warning" variant="subtle">
          {{ $t('invoices.balance') }}: {{ formatMoney(invoice.balance, invoice.currency) }}
        </UBadge>
        <UBadge v-else color="neutral" variant="subtle">
          {{ $t('invoices.balance') }}: {{ formatMoney(invoice.balance, invoice.currency) }}
        </UBadge>
      </div>
    </div>

    <!-- Progress bar -->
    <div v-if="Number(invoice.total) > 0" class="w-full bg-(--ui-bg-elevated) rounded-full h-2">
      <div
        class="h-2 rounded-full transition-all"
        :class="progressPercent >= 100 ? 'bg-green-500' : 'bg-amber-500'"
        :style="{ width: `${Math.min(progressPercent, 100)}%` }"
      />
    </div>

    <!-- Payment list -->
    <div v-if="payments.length" class="space-y-2">
      <div
        v-for="payment in payments"
        :key="payment.id"
        class="flex items-center justify-between p-3 rounded border border-(--ui-border)"
      >
        <div class="flex items-center gap-3">
          <UIcon name="i-lucide-banknote" class="text-(--ui-text-muted)" />
          <div>
            <div class="font-medium text-sm">
              {{ formatMoney(payment.amount, payment.currency) }}
            </div>
            <div class="text-xs text-(--ui-text-muted) flex items-center gap-2">
              <span>{{ formatDate(payment.paymentDate) }}</span>
              <UBadge variant="subtle" size="xs">{{ $t(`invoices.paymentMethods.${payment.paymentMethod}`) }}</UBadge>
              <span v-if="payment.reference">{{ payment.reference }}</span>
              <UBadge v-if="payment.receiptNumber" color="success" variant="subtle" size="xs" icon="i-lucide-receipt">
                {{ $t('invoices.receipt.badge', { number: payment.receiptNumber, date: formatDate(payment.receiptDate!) }) }}
              </UBadge>
            </div>
          </div>
        </div>
        <div class="flex items-center gap-1">
          <UButton
            v-if="payment.receiptNumber"
            icon="i-lucide-file-down"
            variant="ghost"
            size="sm"
            :loading="receiptPdfId === payment.id"
            :title="$t('invoices.receipt.downloadPdf')"
            @click="downloadReceipt(payment)"
          />
          <UButton
            v-else-if="payment.paymentMethod === 'cash' && invoice.direction === 'outgoing' && props.canDelete !== false"
            icon="i-lucide-receipt"
            variant="soft"
            size="sm"
            @click="openReceiptModal(payment)"
          >
            {{ $t('invoices.receipt.issue') }}
          </UButton>
        <UButton
          v-if="props.canDelete !== false"
          icon="i-lucide-trash-2"
          color="error"
          variant="ghost"
          size="sm"
          :loading="deletingId === payment.id"
          @click="confirmDelete(payment)"
        />
        </div>
      </div>
    </div>
    <div v-else class="text-center py-4 text-(--ui-text-muted)">
      {{ $t('invoices.noPayments') }}
    </div>

    <!-- Issue receipt (chitanță) modal -->
    <UModal v-model:open="receiptModalOpen" :title="$t('invoices.receipt.issueTitle')">
      <template #body>
        <div class="space-y-4">
          <p class="text-sm text-(--ui-text-muted)">{{ $t('invoices.receipt.issueDescription') }}</p>
          <div v-if="paymentForReceipt" class="p-3 rounded border border-(--ui-border) bg-(--ui-bg-elevated) text-sm">
            <div class="font-medium">{{ formatMoney(paymentForReceipt.amount, paymentForReceipt.currency as 'RON' | 'EUR' | 'USD' | 'GBP') }}</div>
            <div class="text-xs text-(--ui-text-muted)">{{ formatDate(paymentForReceipt.paymentDate) }} &middot; {{ $t('invoices.paymentMethods.cash') }}</div>
          </div>
          <UFormField :label="$t('invoices.receipt.series')" :help="receiptSeriesOptions.length ? undefined : $t('invoices.receipt.noSeries')">
            <USelectMenu v-model="receiptSeriesId" :items="receiptSeriesOptions" value-key="value" class="w-full" />
          </UFormField>
          <UFormField :label="$t('invoices.receipt.date')">
            <UInput v-model="receiptDate" type="date" class="w-full" />
          </UFormField>
          <div class="flex justify-end gap-2">
            <UButton variant="outline" color="neutral" @click="receiptModalOpen = false">{{ $t('common.cancel') }}</UButton>
            <UButton icon="i-lucide-receipt" :loading="issuingReceipt" :disabled="!receiptSeriesOptions.length" @click="issueReceipt">{{ $t('invoices.receipt.issue') }}</UButton>
          </div>
        </div>
      </template>
    </UModal>

    <!-- Delete confirmation modal -->
    <UModal v-model:open="deleteModalOpen">
      <template #content>
        <div class="p-6 space-y-4">
          <div class="flex items-center gap-3">
            <div class="flex items-center justify-center w-10 h-10 rounded-full bg-error-100 dark:bg-error-900/30">
              <UIcon name="i-lucide-trash-2" class="size-5 text-error-500" />
            </div>
            <div>
              <h3 class="font-semibold">{{ $t('invoices.deletePaymentTitle') }}</h3>
              <p class="text-sm text-(--ui-text-muted)">{{ $t('invoices.deletePaymentDescription') }}</p>
            </div>
          </div>

          <div v-if="paymentToDelete" class="p-3 rounded border border-(--ui-border) bg-(--ui-bg-elevated) text-sm">
            <div class="font-medium">{{ formatMoney(paymentToDelete.amount, paymentToDelete.currency) }}</div>
            <div class="text-xs text-(--ui-text-muted)">
              {{ formatDate(paymentToDelete.paymentDate) }} &middot; {{ $t(`invoices.paymentMethods.${paymentToDelete.paymentMethod}`) }}
            </div>
          </div>

          <div class="flex justify-end gap-2">
            <UButton variant="outline" color="neutral" @click="deleteModalOpen = false">
              {{ $t('common.cancel') }}
            </UButton>
            <UButton color="error" :loading="deletingId !== null" @click="onDelete">
              {{ $t('common.delete') }}
            </UButton>
          </div>
        </div>
      </template>
    </UModal>
  </div>
</template>

<script setup lang="ts">
import type { Payment, Invoice } from '~/types'

const props = defineProps<{
  payments: Payment[]
  invoice: Invoice
  canDelete?: boolean
}>()

const emit = defineEmits<{
  deleted: []
  receiptIssued: []
}>()

const { t: $t } = useI18n()
const { formatMoney } = useMoney()
const { formatDate } = useDate()
const invoiceStore = useInvoiceStore()

const deletingId = ref<string | null>(null)

// Chitanță for a cash payment: number from the receipt series, dated, documents the existing payment
const seriesStore = useDocumentSeriesStore()
const receiptModalOpen = ref(false)
const paymentForReceipt = ref<Payment | null>(null)
const receiptSeriesId = ref('')
const receiptDate = ref('')
const issuingReceipt = ref(false)
const receiptPdfId = ref<string | null>(null)

const receiptSeriesOptions = computed(() =>
  seriesStore.items.filter(s => s.type === 'receipt' && s.active !== false).map(s => ({ label: `${s.prefix} (${s.nextNumber})`, value: s.id })),
)

async function openReceiptModal(payment: Payment) {
  paymentForReceipt.value = payment
  receiptDate.value = payment.paymentDate.slice(0, 10)
  if (!seriesStore.items.length) await seriesStore.fetchSeries()
  const def = seriesStore.items.find(s => s.type === 'receipt' && s.isDefault) ?? seriesStore.items.find(s => s.type === 'receipt')
  receiptSeriesId.value = def?.id ?? ''
  receiptModalOpen.value = true
}

async function issueReceipt() {
  if (!paymentForReceipt.value) return
  issuingReceipt.value = true
  const result = await invoiceStore.issuePaymentReceipt(props.invoice.id, paymentForReceipt.value.id, {
    seriesId: receiptSeriesId.value || undefined,
    date: receiptDate.value || undefined,
  })
  issuingReceipt.value = false
  if (result) {
    receiptModalOpen.value = false
    useToast().add({ title: $t('invoices.receipt.issued', { number: result.receiptNumber }), color: 'success' })
    emit('receiptIssued')
  }
  else {
    useToast().add({ title: invoiceStore.error || $t('invoices.paymentError'), color: 'error' })
  }
}

async function downloadReceipt(payment: Payment) {
  receiptPdfId.value = payment.id
  const blob = await invoiceStore.downloadPaymentReceiptPdf(props.invoice.id, payment.id)
  receiptPdfId.value = null
  if (!blob) {
    useToast().add({ title: invoiceStore.error || $t('invoices.paymentError'), color: 'error' })
    return
  }
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `chitanta-${payment.receiptNumber}.pdf`
  a.click()
  URL.revokeObjectURL(url)
}
const deleteModalOpen = ref(false)
const paymentToDelete = ref<Payment | null>(null)

const progressPercent = computed(() => {
  const total = Number(props.invoice.total)
  if (total === 0) return 0
  return (Number(props.invoice.amountPaid) / total) * 100
})

function confirmDelete(payment: Payment) {
  paymentToDelete.value = payment
  deleteModalOpen.value = true
}

async function onDelete() {
  if (!paymentToDelete.value) return
  deletingId.value = paymentToDelete.value.id
  const ok = await invoiceStore.deletePayment(props.invoice.id, paymentToDelete.value.id)
  deletingId.value = null
  deleteModalOpen.value = false
  paymentToDelete.value = null

  if (ok) {
    useToast().add({ title: $t('invoices.paymentDeleted'), color: 'success' })
    emit('deleted')
  }
  else {
    useToast().add({ title: $t('invoices.paymentError'), color: 'error' })
  }
}
</script>
