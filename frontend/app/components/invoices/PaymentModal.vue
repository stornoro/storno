<template>
  <UModal v-model:open="open">
    <template #header>
      <h3 class="font-semibold">{{ $t('invoices.recordPayment') }}</h3>
    </template>
    <template #body>
      <div class="space-y-5">
        <!-- Balance summary -->
        <div class="rounded-lg bg-(--ui-bg-elevated) p-4">
          <div class="flex items-center justify-between text-sm text-(--ui-text-muted) mb-1">
            <span>{{ $t('invoices.total') }}</span>
            <span>{{ formatMoney(invoice.total, invoice.currency as any) }}</span>
          </div>
          <div v-if="Number(invoice.amountPaid) > 0" class="flex items-center justify-between text-sm text-(--ui-text-muted) mb-1">
            <span>{{ $t('invoices.amountPaid') }}</span>
            <span class="text-green-600 dark:text-green-400">-{{ formatMoney(invoice.amountPaid, invoice.currency as any) }}</span>
          </div>
          <div class="border-t border-(--ui-border) my-2" />
          <div class="flex items-center justify-between font-semibold text-lg">
            <span>{{ $t('invoices.balance') }}</span>
            <span>{{ formatMoney(invoice.balance, invoice.currency as any) }}</span>
          </div>
        </div>

        <!-- Amount input + quick shortcuts -->
        <UFormField :label="$t('invoices.paymentAmount')">
          <UInput
            v-model="form.amount"
            type="number"
            step="0.01"
            min="0.01"
            :max="invoice.balance"
            placeholder="0.00"
            class="text-lg"
          />
          <template #hint>
            <div class="flex items-center gap-1.5 mt-2">
              <UButton
                v-for="shortcut in amountShortcuts"
                :key="shortcut.pct"
                size="xs"
                :variant="isActiveShortcut(shortcut.value) ? 'solid' : 'soft'"
                :color="isActiveShortcut(shortcut.value) ? 'primary' : 'neutral'"
                @click="form.amount = shortcut.value"
              >
                {{ shortcut.label }}
              </UButton>
            </div>
          </template>
        </UFormField>

        <!-- Payment method + date side by side -->
        <div class="grid grid-cols-2 gap-3">
          <UFormField :label="$t('invoices.paymentMethod')">
            <USelectMenu v-model="form.paymentMethod" :items="paymentMethodOptions" value-key="value" />
          </UFormField>
          <UFormField :label="$t('invoices.paymentDate')">
            <UInput v-model="form.paymentDate" type="date" />
          </UFormField>
        </div>

        <!-- Reference -->
        <UFormField :label="$t('invoices.paymentReference')">
          <UInput v-model="form.reference" :placeholder="'OP-001'" />
        </UFormField>

        <!-- Notes -->
        <UFormField :label="$t('common.notes')">
          <UTextarea v-model="form.notes" :rows="2" />
        </UFormField>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton variant="ghost" @click="open = false">{{ $t('common.cancel') }}</UButton>
        <UButton :loading="saving" :disabled="!isValid" @click="onSave">
          {{ $t('invoices.recordPayment') }}
        </UButton>
      </div>
    </template>
  </UModal>
</template>

<script setup lang="ts">
import type { Invoice } from '~/types'

const props = defineProps<{
  invoice: Invoice
}>()

const emit = defineEmits<{
  recorded: []
}>()

const { t: $t } = useI18n()
const { formatMoney } = useMoney()
const { fetchDefaults, paymentMethodOptions } = useInvoiceDefaults()

const open = defineModel<boolean>('open', { default: false })
const saving = ref(false)
const invoiceStore = useInvoiceStore()

fetchDefaults()

const form = ref({
  amount: '',
  paymentMethod: 'bank_transfer',
  paymentDate: new Date().toISOString().split('T')[0],
  reference: '',
  notes: '',
})

const amountShortcuts = computed(() => {
  const balance = Number(props.invoice.balance)
  return [
    { pct: 25, label: '25%', value: (balance * 0.25).toFixed(2) },
    { pct: 50, label: '50%', value: (balance * 0.5).toFixed(2) },
    { pct: 75, label: '75%', value: (balance * 0.75).toFixed(2) },
    { pct: 100, label: '100%', value: balance.toFixed(2) },
  ]
})

function isActiveShortcut(value: string): boolean {
  return Number(form.value.amount).toFixed(2) === value
}

const isValid = computed(() => {
  const amount = Number(form.value.amount)
  return amount > 0 && amount <= Number(props.invoice.balance) && form.value.paymentMethod
})

watch(open, (val) => {
  if (val) {
    form.value.amount = props.invoice.balance
    form.value.paymentMethod = 'bank_transfer'
    form.value.paymentDate = new Date().toISOString().split('T')[0]
    form.value.reference = ''
    form.value.notes = ''
  }
})

async function onSave() {
  saving.value = true
  const result = await invoiceStore.recordPayment(props.invoice.id, {
    amount: form.value.amount,
    paymentMethod: form.value.paymentMethod,
    paymentDate: form.value.paymentDate || undefined,
    reference: form.value.reference || undefined,
    notes: form.value.notes || undefined,
  })
  saving.value = false

  if (result) {
    open.value = false
    useToast().add({ title: $t('invoices.paymentRecorded'), color: 'success' })
    emit('recorded')
  }
  else {
    useToast().add({ title: invoiceStore.error || $t('invoices.paymentError'), color: 'error' })
  }
}
</script>
