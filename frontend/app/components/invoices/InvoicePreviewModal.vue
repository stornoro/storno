<template>
  <UModal v-model:open="open" fullscreen :title="$t('invoices.previewTitle')">
    <UButton icon="i-lucide-eye" variant="outline">
      {{ $t('invoices.preview') }}
    </UButton>

    <template #body>
      <div class="invoice-print-root max-w-4xl mx-auto p-8">
        <!-- Header -->
        <div class="flex items-start justify-between mb-8">
          <div>
            <h1 class="text-2xl font-bold">{{ $t('invoices.invoiceTitle') }} {{ invoice.number }}</h1>
            <div class="text-sm text-(--ui-text-muted) mt-1 space-y-0.5">
              <div>{{ $t('invoices.issueDate') }}: {{ formatDate(invoice.issueDate) }}</div>
              <div v-if="invoice.dueDate">{{ $t('invoices.dueDate') }}: {{ formatDate(invoice.dueDate) }}</div>
              <div>{{ $t('invoices.currency') }}: {{ invoice.currency }}</div>
            </div>
          </div>
          <div class="flex gap-2 print-hidden">
            <UButton icon="i-lucide-printer" variant="outline" @click="printInvoice">
              {{ $t('invoices.print') }}
            </UButton>
          </div>
        </div>

        <!-- Parties -->
        <div class="grid grid-cols-2 gap-8 mb-8">
          <div>
            <h3 class="text-sm font-semibold text-(--ui-text-muted) uppercase tracking-wide mb-2">{{ $t('invoices.seller') }}</h3>
            <div class="text-base font-medium">{{ invoice.senderName || '-' }}</div>
            <div class="text-sm text-(--ui-text-muted)">CIF: {{ invoice.senderCif || '-' }}</div>
          </div>
          <div>
            <h3 class="text-sm font-semibold text-(--ui-text-muted) uppercase tracking-wide mb-2">{{ $t('invoices.buyer') }}</h3>
            <div class="text-base font-medium">{{ invoice.receiverName || '-' }}</div>
            <div class="text-sm text-(--ui-text-muted)">CIF: {{ invoice.receiverCif || '-' }}</div>
          </div>
        </div>

        <!-- Lines table -->
        <table class="w-full text-sm border-collapse mb-8">
          <thead>
            <tr class="border-b-2 border-(--ui-border)">
              <th class="text-left py-2 pr-2 w-8">#</th>
              <th class="text-left py-2 px-2">{{ $t('invoices.lineDescription') }}</th>
              <th class="text-right py-2 px-2 w-20">{{ $t('invoices.quantity') }}</th>
              <th class="text-left py-2 px-2 w-16">{{ $t('invoices.unit') }}</th>
              <th class="text-right py-2 px-2 w-28">{{ $t('invoices.unitPrice') }}</th>
              <th class="text-right py-2 px-2 w-16">TVA %</th>
              <th class="text-right py-2 pl-2 w-28">{{ $t('invoices.total') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(line, idx) in invoice.lines" :key="line.id" class="border-b border-(--ui-border)">
              <td class="py-2 pr-2 text-(--ui-text-muted)">{{ idx + 1 }}</td>
              <td class="py-2 px-2">{{ line.description }}</td>
              <td class="py-2 px-2 text-right">{{ formatNumber(line.quantity) }}</td>
              <td class="py-2 px-2">{{ line.unitOfMeasure }}</td>
              <td class="py-2 px-2 text-right">{{ formatNumber(line.unitPrice) }}</td>
              <td class="py-2 px-2 text-right">{{ line.vatRate }}%</td>
              <td class="py-2 pl-2 text-right font-medium">{{ formatMoney(line.lineTotal, invoice.currency) }}</td>
            </tr>
          </tbody>
        </table>

        <!-- Totals -->
        <div class="flex justify-end mb-8">
          <div class="w-64 space-y-1">
            <div class="flex justify-between text-sm">
              <span>{{ $t('invoices.subtotal') }}</span>
              <span class="font-medium">{{ formatMoney(invoice.subtotal, invoice.currency) }}</span>
            </div>
            <div class="flex justify-between text-sm">
              <span>TVA</span>
              <span class="font-medium">{{ formatMoney(invoice.vatTotal, invoice.currency) }}</span>
            </div>
            <div v-if="Number(invoice.discount)" class="flex justify-between text-sm">
              <span>{{ $t('invoices.discount') }}</span>
              <span class="font-medium">-{{ formatMoney(invoice.discount, invoice.currency) }}</span>
            </div>
            <div class="flex justify-between text-lg font-bold border-t border-(--ui-border) pt-2 mt-2">
              <span>{{ $t('invoices.total') }}</span>
              <span>{{ formatMoney(invoice.total, invoice.currency) }}</span>
            </div>
          </div>
        </div>

        <!-- Notes & Payment terms -->
        <div v-if="invoice.notes || invoice.paymentTerms" class="border-t border-(--ui-border) pt-6 space-y-3 text-sm">
          <div v-if="invoice.paymentTerms">
            <span class="font-medium">{{ $t('invoices.paymentTerms') }}:</span> {{ invoice.paymentTerms }}
          </div>
          <div v-if="invoice.notes">
            <span class="font-medium">{{ $t('common.notes') }}:</span> {{ invoice.notes }}
          </div>
        </div>
      </div>
    </template>
  </UModal>
</template>

<script setup lang="ts">
import type { Invoice } from '~/types'

defineProps<{
  invoice: Invoice
}>()

const { t: $t } = useI18n()
const { formatMoney, formatNumber } = useMoney()
const { formatDate } = useDate()

const open = ref(false)

function printInvoice() {
  window.print()
}
</script>
