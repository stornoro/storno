<script setup lang="ts">
import QRCode from 'qrcode'
import type { Invoice } from '~/types'

const props = defineProps<{
  invoices: Invoice[]
}>()

const open = defineModel<boolean>('open', { default: false })

const { t: $t } = useI18n()
const toast = useToast()
const intlLocale = useIntlLocale()

const supplier = computed(() => props.invoices[0]?.supplier ?? null)
const supplierName = computed(() => supplier.value?.name || props.invoices[0]?.senderName || '')
const iban = computed(() => normalizeIban(supplier.value?.bankAccount))
const currency = computed(() => props.invoices[0]?.currency || 'RON')

const totalAmount = computed(() => {
  return props.invoices.reduce((sum, inv) => sum + Number(inv.balance || 0), 0)
})

const invoiceNumbers = computed(() => props.invoices.map(i => i.number).filter(Boolean))

const payload = computed(() => {
  if (!iban.value || totalAmount.value <= 0) return ''
  return buildSepaEpcPayload({
    beneficiaryName: supplierName.value,
    iban: iban.value,
    amount: totalAmount.value,
    currency: currency.value,
    remittance: buildInvoiceRemittance(invoiceNumbers.value),
  })
})

const qrSvg = ref('')
watchEffect(async () => {
  if (!payload.value) {
    qrSvg.value = ''
    return
  }
  try {
    qrSvg.value = await QRCode.toString(payload.value, {
      type: 'svg',
      errorCorrectionLevel: 'M',
      margin: 1,
      width: 280,
    })
  }
  catch {
    qrSvg.value = ''
  }
})

function formatMoney(amount: number) {
  return new Intl.NumberFormat(intlLocale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount)
}

async function copyIban() {
  try {
    await navigator.clipboard.writeText(iban.value)
    toast.add({ title: $t('qrPay.ibanCopied'), color: 'success', icon: 'i-lucide-check' })
  }
  catch {
    toast.add({ title: $t('qrPay.ibanCopyFailed'), color: 'error' })
  }
}
</script>

<template>
  <UModal v-model:open="open" :ui="{ content: 'sm:max-w-md' }">
    <template #header>
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-qr-code" class="size-5 shrink-0 text-(--ui-primary)" />
        <h3 class="font-semibold">{{ $t('qrPay.title') }}</h3>
      </div>
    </template>
    <template #body>
      <div class="space-y-4">
        <div v-if="qrSvg" class="flex justify-center p-4 bg-white rounded-lg" v-html="qrSvg" />
        <div v-else class="py-12 text-center text-sm text-(--ui-text-muted)">
          {{ $t('qrPay.unavailable') }}
        </div>

        <p class="text-sm text-center text-(--ui-text-muted)">
          {{ $t('qrPay.scanInstruction') }}
        </p>

        <div class="rounded-lg border border-(--ui-border) divide-y divide-(--ui-border)">
          <div class="px-4 py-2.5 flex items-center justify-between gap-3">
            <span class="text-xs text-(--ui-text-muted) shrink-0">{{ $t('qrPay.beneficiary') }}</span>
            <span class="text-sm font-medium text-right truncate">{{ supplierName }}</span>
          </div>
          <div class="px-4 py-2.5 flex items-center justify-between gap-3">
            <span class="text-xs text-(--ui-text-muted) shrink-0">{{ $t('qrPay.iban') }}</span>
            <div class="flex items-center gap-2 min-w-0">
              <span class="text-sm font-mono truncate">{{ formatIbanGrouped(iban) }}</span>
              <UButton icon="i-lucide-copy" size="xs" color="neutral" variant="ghost" @click="copyIban" />
            </div>
          </div>
          <div class="px-4 py-2.5 flex items-center justify-between gap-3">
            <span class="text-xs text-(--ui-text-muted) shrink-0">{{ $t('qrPay.amount') }}</span>
            <span class="text-base font-semibold tabular-nums">{{ formatMoney(totalAmount) }} {{ currency }}</span>
          </div>
          <div v-if="invoiceNumbers.length" class="px-4 py-2.5">
            <span class="text-xs text-(--ui-text-muted) block mb-1">
              {{ $t(invoiceNumbers.length === 1 ? 'qrPay.invoiceOne' : 'qrPay.invoiceMany', { count: invoiceNumbers.length }) }}
            </span>
            <span class="text-sm break-words">{{ invoiceNumbers.join(', ') }}</span>
          </div>
        </div>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end w-full">
        <UButton :label="$t('common.close')" variant="ghost" @click="open = false" />
      </div>
    </template>
  </UModal>
</template>
