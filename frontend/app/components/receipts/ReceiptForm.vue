<template>
  <div class="space-y-4">
    <!-- Document header -->
    <div class="space-y-2">
      <div class="grid gap-x-2 gap-y-0.5" style="grid-template-columns: 1.5fr 1fr 1fr;">
        <span v-if="!receipt" class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.selectSeries') }}</span>
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.issueDate') }}</span>
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.currency') }}</span>

        <template v-if="!receipt">
          <USelectMenu
            v-if="seriesOptions.length > 0"
            v-model="form.documentSeriesId"
            :items="seriesOptions"
            value-key="value"
            :placeholder="$t('invoices.selectSeries')"
            size="sm"
          />
          <div v-else class="flex items-center justify-center rounded-md border border-dashed border-amber-300 dark:border-amber-700 bg-amber-50/50 dark:bg-amber-950/20">
            <UIcon name="i-lucide-alert-triangle" class="size-3.5 text-amber-500" />
          </div>
        </template>

        <UInput v-model="form.issueDate" type="date" size="sm" />
        <USelectMenu v-model="form.currency" :items="currencyOptions" value-key="value" size="sm" />
      </div>

      <!-- No series — inline quick-create -->
      <div
        v-if="!receipt && seriesOptions.length === 0"
        class="rounded-lg border border-amber-200 dark:border-amber-800/60 bg-amber-50/80 dark:bg-amber-950/30 overflow-hidden"
      >
        <div class="flex items-center gap-3 px-3 py-2.5">
          <div class="flex items-center justify-center size-8 rounded-full bg-amber-100 dark:bg-amber-900/40 shrink-0">
            <UIcon name="i-lucide-hash" class="size-4 text-amber-600 dark:text-amber-400" />
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">{{ $t('invoices.noSeries') }}</p>
            <p class="text-xs text-amber-600/80 dark:text-amber-400/70 mt-0.5">{{ $t('invoices.noSeriesDescription') }}</p>
          </div>
          <UButton
            v-if="!showQuickSeriesForm"
            size="xs"
            color="warning"
            variant="soft"
            icon="i-lucide-plus"
            @click="showQuickSeriesForm = true"
          >
            {{ $t('invoices.createSeries') }}
          </UButton>
        </div>

        <div v-if="showQuickSeriesForm" class="border-t border-amber-200 dark:border-amber-800/60 px-3 py-3 space-y-3 bg-white/50 dark:bg-gray-900/30">
          <div class="grid grid-cols-2 gap-2">
            <UFormField :label="$t('documentSeries.prefix')" required size="sm">
              <UInput v-model="quickSeriesPrefix" placeholder="ex: CHT" size="sm" />
            </UFormField>
            <UFormField :label="$t('documentSeries.startNumber')" size="sm">
              <UInput v-model.number="quickSeriesStartNumber" type="number" :min="0" size="sm" />
            </UFormField>
          </div>
          <div v-if="quickSeriesPrefix" class="flex items-center gap-2 text-xs text-(--ui-text-muted)">
            <UIcon name="i-lucide-eye" class="size-3.5 shrink-0" />
            <span>{{ $t('documentSeries.nextNumberPreview') }}: <span class="font-mono font-medium text-(--ui-text)">{{ quickSeriesPrefix }}{{ String((quickSeriesStartNumber || 0) + 1).padStart(4, '0') }}</span></span>
          </div>
          <div class="flex items-center gap-2">
            <UButton
              size="xs"
              :loading="quickSeriesSaving"
              :disabled="!quickSeriesPrefix"
              icon="i-lucide-check"
              @click="onQuickCreateSeries"
            >
              {{ $t('invoices.createSeriesAndContinue') }}
            </UButton>
            <UButton
              size="xs"
              variant="ghost"
              color="neutral"
              @click="showQuickSeriesForm = false"
            >
              {{ $t('common.cancel') }}
            </UButton>
          </div>
        </div>
      </div>
    </div>

    <!-- Client -->
    <div class="space-y-2">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <UIcon name="i-lucide-building-2" class="size-4 text-(--ui-text-muted)" />
          <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('invoices.client') }}</span>
        </div>
        <UButton
          v-if="form.clientId"
          icon="i-lucide-x"
          variant="ghost"
          color="neutral"
          size="xs"
          @click="clearClient"
        />
      </div>

      <!-- Selected client display -->
      <div v-if="selectedClient" class="flex items-center gap-3 p-3 rounded-lg bg-(--ui-bg-elevated) border border-(--ui-border)">
        <div class="flex items-center justify-center size-10 rounded-full bg-primary/10 text-primary shrink-0">
          <UIcon name="i-lucide-building-2" class="size-5" />
        </div>
        <div class="flex-1 min-w-0">
          <div class="font-semibold text-sm truncate">{{ selectedClient.name }}</div>
          <div class="text-xs text-(--ui-text-muted)">{{ selectedClient.cui || selectedClient.cnp || '-' }}</div>
          <div v-if="selectedClient.city" class="text-xs text-(--ui-text-muted)">{{ selectedClient.city }}<span v-if="selectedClient.country">, {{ selectedClient.country }}</span></div>
        </div>
        <UButton
          icon="i-lucide-repeat"
          variant="ghost"
          color="neutral"
          size="xs"
          @click="clearClient"
        />
      </div>

      <!-- Unified client search (clients + registry) -->
      <InvoicesRegistrySearchSection
        v-else
        @select-client="onClientSelected"
        @select-registry="onClientSelected"
        @prefill-create="onPrefillCreate"
        @create="showClientCreateModal = true"
      />
    </div>

    <!-- Client Create Modal -->
    <SharedClientFormModal
      v-model:open="showClientCreateModal"
      :prefill="clientPrefill"
      @saved="onClientSelected"
    />

    <!-- Lines -->
    <div class="space-y-2">
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-list" class="size-4 text-(--ui-text-muted)" />
        <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('invoices.invoiceLines') }}</span>
      </div>

      <div class="space-y-4">
        <div v-for="(line, index) in form.lines" :key="index" class="p-4 rounded-lg border border-(--ui-border) space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-(--ui-text-muted)">#{{ index + 1 }}</span>
            <div class="flex items-center gap-1">
              <UButton
                icon="i-lucide-package-search"
                size="xs"
                variant="ghost"
                color="primary"
                @click="openProductPicker(index)"
              />
              <UButton
                v-if="form.lines.length > 1"
                icon="i-lucide-trash-2"
                size="xs"
                color="error"
                variant="ghost"
                @click="removeLine(index)"
              />
            </div>
          </div>

          <UFormField :label="$t('invoices.lineDescription')" class="w-full">
            <UInput v-model="line.description" :placeholder="$t('invoices.lineDescription')" class="w-full" />
          </UFormField>

          <div class="grid gap-3" style="grid-template-columns: 0.6fr 0.8fr 1fr 0.6fr;">
            <UFormField :label="$t('invoices.quantity')">
              <UInput v-model="line.quantity" type="number" step="0.01" min="0.01" />
            </UFormField>
            <UFormField :label="$t('invoices.unit')">
              <USelectMenu
                v-model="line.unitOfMeasure"
                :items="unitOfMeasureOptions"
                value-key="value"
                :search-input="true"
              />
            </UFormField>
            <UFormField :label="$t('invoices.unitPrice')">
              <UInput v-model="line.unitPrice" type="number" step="0.01" min="0" />
            </UFormField>
            <UFormField :label="$t('invoices.discount')">
              <UInput v-model="line.discount" type="number" step="0.01" min="0" />
            </UFormField>
          </div>

          <div>
            <label class="text-xs font-medium text-(--ui-text-muted) block mb-1.5">TVA %</label>
            <div class="flex gap-2 flex-wrap">
              <button
                v-for="vr in vatRateChipOptions"
                :key="vr.value"
                type="button"
                class="px-3 py-1 rounded-full text-xs font-semibold border transition-colors"
                :class="line.vatRate === vr.value
                  ? 'bg-primary/10 border-primary text-primary'
                  : 'bg-(--ui-bg-elevated) border-(--ui-border) text-(--ui-text-muted) hover:border-(--ui-text-muted)'"
                @click="selectVatRate(index, vr)"
              >
                {{ vr.chipLabel }}
              </button>
            </div>
          </div>

          <div class="flex justify-end items-center pt-2 border-t border-(--ui-border)">
            <span class="text-xs text-(--ui-text-muted) mr-2">{{ $t('invoices.total') }}:</span>
            <span class="text-sm font-semibold">{{ formatLineTotal(line, form.currency) }}</span>
          </div>
        </div>

        <button
          type="button"
          class="w-full flex items-center justify-center gap-2 py-3 rounded-lg border border-dashed border-primary/40 text-primary text-sm font-semibold hover:bg-primary/5 transition-colors"
          @click="addLine"
        >
          <UIcon name="i-lucide-plus" class="size-4" />
          {{ $t('invoices.addLine') }}
        </button>
      </div>
    </div>

    <!-- Totals -->
    <div class="p-4 rounded-lg bg-(--ui-bg-elevated) border border-(--ui-border) space-y-1">
      <div class="flex justify-between text-sm">
        <span>{{ $t('invoices.subtotal') }}</span>
        <span class="font-medium">{{ formatMoney(computedTotals.subtotal, form.currency) }}</span>
      </div>
      <div class="flex justify-between text-sm">
        <span>TVA</span>
        <span class="font-medium">{{ formatMoney(computedTotals.vat, form.currency) }}</span>
      </div>
      <div v-if="computedTotals.discount > 0" class="flex justify-between text-sm">
        <span>{{ $t('invoices.discount') }}</span>
        <span class="font-medium">-{{ formatMoney(computedTotals.discount, form.currency) }}</span>
      </div>
      <div class="flex justify-between items-center pt-2 mt-2 border-t border-(--ui-border)">
        <span class="text-base font-bold">{{ $t('invoices.total') }}</span>
        <span class="text-lg font-bold">{{ formatMoney(computedTotals.total, form.currency) }}</span>
      </div>
    </div>

    <!-- Collapsible sections -->
    <div class="space-y-1">
      <!-- Payment Info -->
      <div>
        <button type="button" class="flex items-center gap-2 w-full py-2 group" @click="showPayment = !showPayment">
          <UIcon name="i-lucide-credit-card" class="size-4 text-(--ui-text-muted)" />
          <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('receipts.paymentInfo') }}</span>
          <div class="flex-1 border-t border-(--ui-border) mx-2" />
          <UIcon :name="showPayment ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-3.5 text-(--ui-text-muted) group-hover:text-(--ui-text) transition-colors" />
        </button>
        <div v-if="showPayment" class="pb-3 pl-6 grid grid-cols-1 md:grid-cols-2 gap-4">
          <UFormField :label="$t('receipts.paymentMethod')">
            <USelectMenu
              v-model="form.paymentMethod"
              :items="paymentMethodOptions"
              value-key="value"
              :placeholder="$t('receipts.paymentMethod')"
            />
          </UFormField>
          <UFormField :label="$t('receipts.cashRegisterName')">
            <UInput v-model="form.cashRegisterName" />
          </UFormField>
          <UFormField :label="$t('receipts.fiscalNumber')">
            <UInput v-model="form.fiscalNumber" />
          </UFormField>
          <template v-if="form.paymentMethod === 'mixed'">
            <UFormField :label="$t('receipts.cashPayment')">
              <UInput v-model="form.cashPayment" type="number" step="0.01" min="0" />
            </UFormField>
            <UFormField :label="$t('receipts.cardPayment')">
              <UInput v-model="form.cardPayment" type="number" step="0.01" min="0" />
            </UFormField>
            <UFormField :label="$t('receipts.otherPayment')">
              <UInput v-model="form.otherPayment" type="number" step="0.01" min="0" />
            </UFormField>
          </template>
        </div>
      </div>

      <!-- Customer Info -->
      <div>
        <button type="button" class="flex items-center gap-2 w-full py-2 group" @click="showCustomer = !showCustomer">
          <UIcon name="i-lucide-user" class="size-4 text-(--ui-text-muted)" />
          <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('receipts.customerInfo') }}</span>
          <div class="flex-1 border-t border-(--ui-border) mx-2" />
          <UIcon :name="showCustomer ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-3.5 text-(--ui-text-muted) group-hover:text-(--ui-text) transition-colors" />
        </button>
        <div v-if="showCustomer" class="pb-3 pl-6 grid grid-cols-1 md:grid-cols-2 gap-4">
          <UFormField :label="$t('receipts.customerName')">
            <UInput v-model="form.customerName" />
          </UFormField>
          <UFormField :label="$t('receipts.customerCif')">
            <UInput v-model="form.customerCif" />
          </UFormField>
        </div>
      </div>

      <!-- Notes -->
      <div>
        <button type="button" class="flex items-center gap-2 w-full py-2 group" @click="showNotes = !showNotes">
          <UIcon name="i-lucide-message-square-text" class="size-4 text-(--ui-text-muted)" />
          <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('common.notes') }}</span>
          <div class="flex-1 border-t border-(--ui-border) mx-2" />
          <UIcon :name="showNotes ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-3.5 text-(--ui-text-muted) group-hover:text-(--ui-text) transition-colors" />
        </button>
        <div v-if="showNotes" class="pb-3 pl-6 space-y-3">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <UFormField :label="$t('receipts.projectReference')">
              <UInput v-model="form.projectReference" />
            </UFormField>
            <UFormField :label="$t('receipts.issuerName')">
              <UInput v-model="form.issuerName" />
            </UFormField>
            <UFormField :label="$t('receipts.issuerId')">
              <UInput v-model="form.issuerId" />
            </UFormField>
            <UFormField :label="$t('receipts.salesAgent')">
              <UInput v-model="form.salesAgent" />
            </UFormField>
            <div class="md:col-span-2">
              <UFormField :label="$t('receipts.notes')">
                <UTextarea v-model="form.notes" :rows="4" size="xl" class="w-full" />
              </UFormField>
            </div>
            <div class="md:col-span-2">
              <UFormField :label="$t('receipts.mentions')">
                <UTextarea v-model="form.mentions" :rows="3" size="xl" class="w-full" />
              </UFormField>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer buttons -->
    <div class="space-y-2">
      <UButton class="w-full justify-center" icon="i-lucide-check" :loading="saving" @click="onSave">
        {{ $t('common.save') }}
      </UButton>
      <UButton class="w-full justify-center" variant="ghost" @click="emit('cancel')">
        {{ $t('common.cancel') }}
      </UButton>
    </div>

    <!-- Product Picker Modal -->
    <SharedProductPickerModal
      v-model:open="productPickerOpen"
      @select="onProductSelected"
    />
  </div>
</template>

<script setup lang="ts">
import type { Client, Receipt, CreateReceiptPayload, UpdateReceiptPayload, InvoiceLinePayload, Product } from '~/types'

const props = defineProps<{
  receipt?: Receipt | null
  copyOf?: string
}>()

const emit = defineEmits<{
  saved: [receipt: Receipt]
  cancel: []
}>()

const { t: $t } = useI18n()
const receiptStore = useReceiptStore()
const clientStore = useClientStore()
const {
  fetchDefaults,
  vatRateOptions,
  currencyOptions,
  unitOfMeasureOptions,
  defaultCurrency,
  defaultVatRate,
  defaultUnitOfMeasure,
} = useInvoiceDefaults()
const { formatMoney, formatLineTotal, computeSimpleTotals, normalizeVatRate, normalizeVatCategoryCode } = useLineCalc()
const seriesStore = useDocumentSeriesStore()
const { loadSeries, autoSelectFirst } = useSeriesSelection('receipt')

const sourceOrder: Record<string, number> = { efactura: 0, default: 1, manual: 2 }
const seriesOptions = computed(() =>
  seriesStore.items
    .filter(s => s.active && s.type === 'receipt')
    .sort((a, b) => (sourceOrder[a.source] ?? 2) - (sourceOrder[b.source] ?? 2))
    .map((s) => ({ label: `${s.prefix} — ${s.nextNumber}`, value: s.id })),
)

const saving = ref(false)
const showNotes = ref(false)
const showPayment = ref(false)
const showCustomer = ref(false)
const productPickerOpen = ref(false)
const productPickerLineIndex = ref(0)
const showClientCreateModal = ref(false)

// ── Quick series creation (inline) ──────────────────────────────────
const showQuickSeriesForm = ref(false)
const quickSeriesPrefix = ref('')
const quickSeriesStartNumber = ref(0)
const quickSeriesSaving = ref(false)
const toast = useToast()

async function onQuickCreateSeries() {
  if (!quickSeriesPrefix.value) return
  quickSeriesSaving.value = true
  const result = await seriesStore.createSeries({
    prefix: quickSeriesPrefix.value,
    type: 'receipt',
    currentNumber: quickSeriesStartNumber.value,
  })
  quickSeriesSaving.value = false
  if (result) {
    toast.add({ title: $t('documentSeries.createSuccess'), color: 'success' })
    showQuickSeriesForm.value = false
    quickSeriesPrefix.value = ''
    quickSeriesStartNumber.value = 0
    await nextTick()
    if (seriesOptions.value.length > 0) {
      form.documentSeriesId = seriesOptions.value[0]?.value
    }
  }
  else if (seriesStore.error) {
    toast.add({ title: seriesStore.error, color: 'error' })
  }
}
const clientPrefill = ref<Record<string, any> | null>(null)
const clients = ref<Client[]>([])

const today = new Date().toISOString().split('T')[0]

const paymentMethodOptions = computed(() => [
  { label: $t('receipts.paymentMethodCash'), value: 'cash' },
  { label: $t('receipts.paymentMethodCard'), value: 'card' },
  { label: $t('receipts.paymentMethodMealTicket'), value: 'meal_ticket' },
  { label: $t('receipts.paymentMethodMixed'), value: 'mixed' },
])

interface LineForm {
  description: string
  quantity: string
  unitOfMeasure: string
  unitPrice: string
  vatRate: string
  vatCategoryCode: string
  discount: string
  discountPercent: string
}

function emptyLine(): LineForm {
  return {
    description: '',
    quantity: '1.00',
    unitOfMeasure: defaultUnitOfMeasure.value,
    unitPrice: '0.00',
    vatRate: defaultVatRate.value,
    vatCategoryCode: 'S',
    discount: '0.00',
    discountPercent: '0.00',
  }
}

const form = reactive({
  documentSeriesId: null as string | null,
  clientId: null as string | null,
  issueDate: today,
  currency: 'RON',
  notes: '',
  mentions: '',
  projectReference: '',
  issuerName: '',
  issuerId: '',
  salesAgent: '',
  paymentMethod: null as string | null,
  cashPayment: '',
  cardPayment: '',
  otherPayment: '',
  cashRegisterName: '',
  fiscalNumber: '',
  customerName: '',
  customerCif: '',
  lines: [emptyLine()] as LineForm[],
})

// Populate form from existing receipt (edit mode)
if (props.receipt) {
  form.clientId = props.receipt.client?.id || null
  form.issueDate = props.receipt.issueDate?.split('T')[0] || today
  form.currency = props.receipt.currency
  form.notes = props.receipt.notes || ''
  form.mentions = props.receipt.mentions || ''
  form.projectReference = props.receipt.projectReference || ''
  form.issuerName = props.receipt.issuerName || ''
  form.issuerId = props.receipt.issuerId || ''
  form.salesAgent = props.receipt.salesAgent || ''
  form.paymentMethod = props.receipt.paymentMethod || null
  form.cashPayment = props.receipt.cashPayment || ''
  form.cardPayment = props.receipt.cardPayment || ''
  form.otherPayment = props.receipt.otherPayment || ''
  form.cashRegisterName = props.receipt.cashRegisterName || ''
  form.fiscalNumber = props.receipt.fiscalNumber || ''
  form.customerName = props.receipt.customerName || ''
  form.customerCif = props.receipt.customerCif || ''
  form.lines = props.receipt.lines.length > 0
    ? props.receipt.lines.map(l => ({
        description: l.description,
        quantity: l.quantity,
        unitOfMeasure: l.unitOfMeasure,
        unitPrice: l.unitPrice,
        vatRate: l.vatRate,
        vatCategoryCode: normalizeVatCategoryCode(l.vatCategoryCode, l.vatRate),
        discount: l.discount,
        discountPercent: l.discountPercent,
      }))
    : [emptyLine()]

  if (props.receipt.notes || props.receipt.mentions || props.receipt.projectReference) {
    showNotes.value = true
  }
  if (props.receipt.paymentMethod || props.receipt.cashRegisterName || props.receipt.fiscalNumber) {
    showPayment.value = true
  }
  if (props.receipt.customerName || props.receipt.customerCif) {
    showCustomer.value = true
  }
}

const selectedClient = computed(() =>
  clients.value.find(c => c.id === form.clientId) || null,
)

function clearClient() {
  form.clientId = null
}

function onClientSelected(client: Client) {
  form.clientId = client.id
  if (!clients.value.find(c => c.id === client.id)) {
    clients.value = [client, ...clients.value]
  }
}

function onPrefillCreate(data: Record<string, any>) {
  clientPrefill.value = data
  showClientCreateModal.value = true
}

watch(showClientCreateModal, (isOpen) => {
  if (!isOpen) clientPrefill.value = null
})

const computedTotals = computed(() => computeSimpleTotals(form.lines))

const vatRateChipOptions = computed(() =>
  vatRateOptions.value.map(vr => ({
    value: vr.value,
    chipLabel: `${parseFloat(vr.value)}%`,
    categoryCode: vr.categoryCode,
  })),
)

function selectVatRate(index: number, vr: { value: string, categoryCode: string }) {
  const line = form.lines[index]
  if (line) {
    line.vatRate = vr.value
    line.vatCategoryCode = vr.categoryCode
  }
}

function openProductPicker(index: number) {
  productPickerLineIndex.value = index
  productPickerOpen.value = true
}

function onProductSelected(product: Product) {
  const index = productPickerLineIndex.value
  const line = form.lines[index]
  if (line) {
    line.description = product.description || product.name
    line.unitPrice = product.defaultPrice
    line.vatRate = normalizeVatRate(product.vatRate)
    line.vatCategoryCode = normalizeVatCategoryCode(product.vatCategoryCode, line.vatRate)
    line.unitOfMeasure = product.unitOfMeasure
  }
}

function addLine() {
  form.lines.push(emptyLine())
}

function removeLine(index: number) {
  form.lines.splice(index, 1)
}

async function onSave() {
  saving.value = true

  const lines: InvoiceLinePayload[] = form.lines.map(l => ({
    description: l.description,
    quantity: l.quantity,
    unitOfMeasure: l.unitOfMeasure,
    unitPrice: l.unitPrice,
    vatRate: l.vatRate,
    vatCategoryCode: l.vatCategoryCode,
    discount: l.discount,
    discountPercent: l.discountPercent,
  }))

  let result: Receipt | null = null

  if (props.receipt) {
    const payload: UpdateReceiptPayload = {
      clientId: form.clientId || undefined,
      issueDate: form.issueDate,
      currency: form.currency,
      notes: form.notes || null,
      mentions: form.mentions || null,
      projectReference: form.projectReference || null,
      issuerName: form.issuerName || null,
      issuerId: form.issuerId || null,
      salesAgent: form.salesAgent || null,
      paymentMethod: form.paymentMethod || null,
      cashPayment: form.cashPayment || null,
      cardPayment: form.cardPayment || null,
      otherPayment: form.otherPayment || null,
      cashRegisterName: form.cashRegisterName || null,
      fiscalNumber: form.fiscalNumber || null,
      customerName: form.customerName || null,
      customerCif: form.customerCif || null,
      lines,
    }
    result = await receiptStore.updateReceipt(props.receipt.id, payload)
  }
  else {
    const payload: CreateReceiptPayload = {
      documentSeriesId: form.documentSeriesId || undefined,
      clientId: form.clientId || undefined,
      issueDate: form.issueDate || today,
      currency: form.currency,
      notes: form.notes || undefined,
      mentions: form.mentions || undefined,
      projectReference: form.projectReference || undefined,
      issuerName: form.issuerName || undefined,
      issuerId: form.issuerId || undefined,
      salesAgent: form.salesAgent || undefined,
      paymentMethod: form.paymentMethod || undefined,
      cashPayment: form.cashPayment || undefined,
      cardPayment: form.cardPayment || undefined,
      otherPayment: form.otherPayment || undefined,
      cashRegisterName: form.cashRegisterName || undefined,
      fiscalNumber: form.fiscalNumber || undefined,
      customerName: form.customerName || undefined,
      customerCif: form.customerCif || undefined,
      lines,
    }
    result = await receiptStore.createReceipt(payload)
  }

  saving.value = false

  if (result) {
    emit('saved', result)
  }
  else if (receiptStore.error) {
    toast.add({ title: receiptStore.error, color: 'error' })
  }
}

onMounted(async () => {
  await Promise.all([
    loadSeries(),
    fetchDefaults(),
    clientStore.fetchClients(),
  ])
  if (!props.receipt) {
    autoSelectFirst(form)
    form.currency = defaultCurrency.value
  }
  clients.value = clientStore.items

  const clientForList = props.receipt?.client
  if (clientForList && !clients.value.find(c => c.id === clientForList.id)) {
    clients.value = [clientForList, ...clients.value]
  }

  // Pre-fill from source receipt for copy
  if (props.copyOf && !props.receipt) {
    const source = await receiptStore.fetchReceipt(props.copyOf)
    if (source) {
      form.clientId = source.client?.id || null
      form.currency = source.currency
      form.notes = source.notes || ''
      form.mentions = source.mentions || ''
      form.projectReference = source.projectReference || ''
      form.issuerName = source.issuerName || ''
      form.issuerId = source.issuerId || ''
      form.salesAgent = source.salesAgent || ''
      form.paymentMethod = source.paymentMethod || null
      form.cashPayment = source.cashPayment || ''
      form.cardPayment = source.cardPayment || ''
      form.otherPayment = source.otherPayment || ''
      form.cashRegisterName = source.cashRegisterName || ''
      form.fiscalNumber = source.fiscalNumber || ''
      form.customerName = source.customerName || ''
      form.customerCif = source.customerCif || ''
      if (source.notes || source.mentions || source.projectReference) {
        showNotes.value = true
      }
      if (source.paymentMethod || source.cashRegisterName || source.fiscalNumber) {
        showPayment.value = true
      }
      if (source.customerName || source.customerCif) {
        showCustomer.value = true
      }
      form.lines = source.lines.length > 0
        ? source.lines.map((l: any) => ({
            description: l.description,
            quantity: l.quantity,
            unitOfMeasure: l.unitOfMeasure,
            unitPrice: l.unitPrice,
            vatRate: l.vatRate,
            vatCategoryCode: normalizeVatCategoryCode(l.vatCategoryCode || 'S', l.vatRate),
            discount: l.discount || '0.00',
            discountPercent: l.discountPercent || '0.00',
          }))
        : [emptyLine()]
      if (source.client && !clients.value.find(c => c.id === source.client.id)) {
        clients.value = [source.client, ...clients.value]
      }
    }
  }
})
</script>
