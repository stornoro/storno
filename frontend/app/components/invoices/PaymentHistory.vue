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
            </div>
          </div>
        </div>
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
    <div v-else class="text-center py-4 text-(--ui-text-muted)">
      {{ $t('invoices.noPayments') }}
    </div>

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
}>()

const { t: $t } = useI18n()
const { formatMoney } = useMoney()
const { formatDate } = useDate()
const invoiceStore = useInvoiceStore()

const deletingId = ref<string | null>(null)
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
