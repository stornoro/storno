<template>
  <div class="space-y-4">
    <!-- Invoice header -->
    <div class="space-y-2">
      <div class="grid gap-x-2 gap-y-0.5" style="grid-template-columns: 1.2fr 1fr 1fr 1fr 0.7fr 1fr;">
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.invoiceTypeCode') }}</span>
        <span v-if="!proforma" class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.selectSeries') }}</span>
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.issueDate') }}</span>
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.dueDate') }}</span>
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.currency') }}</span>
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.documentLanguage') }}</span>

        <USelectMenu
          v-model="form.invoiceTypeCode"
          :items="invoiceTypeCodeOptions"
          value-key="value"
          :search-input="true"
          :placeholder="$t('invoices.invoiceTypeCode')"
          size="sm"
        >
          <template #leading="{ modelValue }">
            <span v-if="modelValue" class="text-xs font-bold text-primary tabular-nums">{{ invoiceTypeCodeShort[modelValue as string] || 'F' }}</span>
          </template>
          <template #item="{ item }">
            <div class="flex items-center gap-3">
              <span class="text-xs font-bold text-(--ui-text-muted) w-4 text-center tabular-nums">{{ invoiceTypeCodeShort[item.value] || '?' }}</span>
              <span>{{ item.label }}</span>
            </div>
          </template>
        </USelectMenu>

        <template v-if="!proforma">
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
        <UInput v-model="form.dueDate" type="date" size="sm" />
        <USelectMenu
          v-model="form.currency"
          :items="currencyOptions"
          value-key="value"
          size="sm"
        />
        <USelectMenu
          v-model="form.language"
          :items="documentLanguageOptions"
          value-key="value"
          size="sm"
        />
      </div>

      <!-- No series — inline quick-create -->
      <div
        v-if="!proforma && seriesOptions.length === 0"
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
              <UInput v-model="quickSeriesPrefix" placeholder="ex: PF" size="sm" />
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

      <!-- Valid until — single field below -->
      <div class="grid gap-x-2 gap-y-0.5" style="grid-template-columns: 1fr 5fr;">
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('proformaInvoices.validUntil') }}</span>
        <span></span>
        <UInput v-model="form.validUntil" type="date" size="sm" />
      </div>

      <!-- Exchange rate info -->
      <div v-if="form.currency !== 'RON' && exchangeRates[form.currency]" class="flex items-center gap-2 px-2.5 py-1 rounded-md bg-blue-50/50 dark:bg-blue-950/20 border border-blue-200/50 dark:border-blue-800/30">
        <UIcon name="i-lucide-info" class="size-3.5 text-blue-500 shrink-0" />
        <span class="text-xs text-blue-600 dark:text-blue-400">1 {{ form.currency }} = {{ exchangeRates[form.currency]?.toFixed(4) }} RON (BNR {{ defaults?.exchangeRateDate ?? '' }})</span>
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
      <SharedClientRecentDocuments v-if="form.clientId" :client-id="form.clientId" />

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
          <!-- Line header: #N + product picker + delete -->
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

          <!-- Description full width -->
          <UFormField :label="$t('invoices.lineDescription')" class="w-full">
            <UInput v-model="line.description" :placeholder="$t('invoices.lineDescription')" class="w-full" />
          </UFormField>

          <!-- Qty / Price / Unit in 3-col row -->
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

          <!-- VAT rate chips -->
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

          <!-- Line total with separator -->
          <div class="flex justify-end items-center pt-2 border-t border-(--ui-border)">
            <span class="text-xs text-(--ui-text-muted) mr-2">{{ $t('invoices.total') }}:</span>
            <span class="text-sm font-semibold">{{ formatLineTotal(line, form.currency) }}</span>
          </div>
        </div>

        <!-- Add line button (dashed border) -->
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

      <!-- RON equivalent for foreign currencies -->
      <div v-if="form.currency !== 'RON' && exchangeRates[form.currency] && computedTotals.total > 0" class="flex justify-between text-xs text-(--ui-text-muted) pt-1">
        <span>{{ $t('invoices.ronEquivalent') }}</span>
        <span>~ {{ formatMoney(computedTotals.total * (exchangeRates[form.currency] ?? 0), 'RON') }}</span>
      </div>
    </div>

    <!-- Notes (collapsible) -->
    <div>
      <button type="button" class="flex items-center gap-2 w-full py-2 group" @click="showNotes = !showNotes">
        <UIcon name="i-lucide-message-square-text" class="size-4 text-(--ui-text-muted)" />
        <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('common.notes') }}</span>
        <div class="flex-1 border-t border-(--ui-border) mx-2" />
        <UIcon
          :name="showNotes ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
          class="size-3.5 text-(--ui-text-muted) group-hover:text-(--ui-text) transition-colors"
        />
      </button>
      <div v-if="showNotes" class="pb-3 pl-6 space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <UFormField :label="$t('invoices.paymentTerms')">
            <UInput v-model="form.paymentTerms" />
          </UFormField>
          <UFormField :label="$t('invoices.deliveryLocation')">
            <UInput v-model="form.deliveryLocation" />
          </UFormField>
          <UFormField :label="$t('invoices.projectReference')">
            <UInput v-model="form.projectReference" />
          </UFormField>
          <div class="md:col-span-2">
            <UFormField :label="$t('common.notes')">
              <UTextarea v-model="form.notes" :rows="6" size="xl" class="w-full" />
            </UFormField>
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
import type { ProformaInvoice, CreateProformaPayload, UpdateProformaPayload, InvoiceLinePayload, Product } from '~/types'

const props = defineProps<{
  proforma?: ProformaInvoice | null
  copyOf?: string
}>()

const emit = defineEmits<{
  saved: [proforma: ProformaInvoice]
  cancel: []
}>()

const { t: $t } = useI18n()
const proformaStore = useProformaInvoiceStore()
const clientStore = useClientStore()
const {
  defaults,
  fetchDefaults,
  vatRateOptions,
  currencyOptions,
  unitOfMeasureOptions,
  defaultCurrency,
  defaultVatRate,
  defaultUnitOfMeasure,
  exchangeRates,
} = useInvoiceDefaults()
const { formatMoney, formatLineTotal, computeSimpleTotals, normalizeVatRate, normalizeVatCategoryCode } = useLineCalc()
const seriesStore = useDocumentSeriesStore()
const { loadSeries, autoSelectFirst } = useSeriesSelection('proforma')

const seriesOptions = computed(() =>
  seriesStore.items
    .filter(s => s.active && s.type === 'proforma')
    .map((s) => ({ label: `${s.prefix} — ${s.nextNumber}`, value: s.id })),
)

const documentLanguageOptions = [
  { label: 'Romana', value: 'ro' },
  { label: 'English', value: 'en' },
  { label: 'Deutsch', value: 'de' },
  { label: 'Français', value: 'fr' },
]

const saving = ref(false)
const showNotes = ref(false)
const productPickerOpen = ref(false)
const productPickerLineIndex = ref(0)

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
    type: 'proforma',
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

// Client
const showClientCreateModal = ref(false)
const clientPrefill = ref<Record<string, any>>({})

const today = new Date().toISOString().split('T')[0]

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
  documentSeriesId: undefined as string | undefined,
  invoiceTypeCode: 'standard' as string | undefined,
  clientId: null as string | null,
  issueDate: today,
  dueDate: '',
  validUntil: '',
  currency: 'RON',
  language: 'ro',
  notes: '',
  paymentTerms: '',
  deliveryLocation: '',
  projectReference: '',
  lines: [emptyLine()] as LineForm[],
})

// Populate form from existing proforma (edit mode)
if (props.proforma) {
  form.invoiceTypeCode = props.proforma.invoiceTypeCode || 'standard'
  form.clientId = props.proforma.client?.id || null
  form.issueDate = props.proforma.issueDate?.split('T')[0] || today
  form.dueDate = props.proforma.dueDate?.split('T')[0] || ''
  form.validUntil = props.proforma.validUntil?.split('T')[0] || ''
  form.currency = props.proforma.currency
  form.language = props.proforma.language || 'ro'
  form.notes = props.proforma.notes || ''
  form.paymentTerms = props.proforma.paymentTerms || ''
  form.deliveryLocation = props.proforma.deliveryLocation || ''
  form.projectReference = props.proforma.projectReference || ''
  form.lines = props.proforma.lines.length > 0
    ? props.proforma.lines.map(l => ({
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

  // Auto-expand notes if editing and any notes field has data
  if (props.proforma.notes || props.proforma.paymentTerms || props.proforma.deliveryLocation || props.proforma.projectReference) {
    showNotes.value = true
  }
}

const selectedClient = computed(() => {
  if (!form.clientId) return null
  const fromStore = clientStore.items.find(c => c.id === form.clientId)
  if (fromStore) return fromStore
  if (props.proforma?.client?.id === form.clientId) return props.proforma.client
  return null
})

const computedTotals = computed(() => computeSimpleTotals(form.lines))

const invoiceTypeCodeOptions = computed(() => [
  { label: $t('invoiceTypeCodes.standard'), value: 'standard' },
  { label: $t('invoiceTypeCodes.reverse_charge'), value: 'reverse_charge' },
  { label: $t('invoiceTypeCodes.exempt_with_deduction'), value: 'exempt_with_deduction' },
  { label: $t('invoiceTypeCodes.services_art_311'), value: 'services_art_311' },
  { label: $t('invoiceTypeCodes.sales_art_312'), value: 'sales_art_312' },
  { label: $t('invoiceTypeCodes.non_taxable'), value: 'non_taxable' },
  { label: $t('invoiceTypeCodes.special_regime_art_314_315'), value: 'special_regime_art_314_315' },
  { label: $t('invoiceTypeCodes.non_transfer'), value: 'non_transfer' },
  { label: $t('invoiceTypeCodes.simplified'), value: 'simplified' },
  { label: $t('invoiceTypeCodes.services_art_278'), value: 'services_art_278' },
  { label: $t('invoiceTypeCodes.exempt_art_294_ab'), value: 'exempt_art_294_ab' },
  { label: $t('invoiceTypeCodes.exempt_art_294_cd'), value: 'exempt_art_294_cd' },
  { label: $t('invoiceTypeCodes.self_billing'), value: 'self_billing' },
])

// VAT rate chip options
const vatRateChipOptions = computed(() =>
  vatRateOptions.value.map(vr => ({
    value: vr.value,
    chipLabel: `${parseFloat(vr.value)}%`,
    categoryCode: vr.categoryCode,
  })),
)

function selectVatRate(index: number, vr: { value: string, categoryCode: string }) {
  const line = form.lines[index]
  if (!line) return
  line.vatRate = vr.value
  line.vatCategoryCode = vr.categoryCode
  syncInvoiceTypeFromVat()
}

function syncInvoiceTypeFromVat() {
  form.invoiceTypeCode = resolveInvoiceTypeCode(form.lines, form.invoiceTypeCode)
}

// Reverse sync: when invoice regime changes, update VAT category codes on 0% lines
watch(() => form.invoiceTypeCode, (newTypeCode) => {
  const targetCategory = typeCodeToVatCategory[newTypeCode!]
  for (const line of form.lines) {
    const rate = parseFloat(line.vatRate) || 0
    if (rate === 0) {
      line.vatCategoryCode = targetCategory || 'Z'
    }
  }
})

// Product picker
function openProductPicker(index: number) {
  productPickerLineIndex.value = index
  productPickerOpen.value = true
}

function onProductSelected(product: Product) {
  const index = productPickerLineIndex.value
  const line = form.lines[index]
  if (!line) return
  line.description = product.description || product.name
  line.unitPrice = product.defaultPrice
  line.vatRate = normalizeVatRate(product.vatRate)
  line.vatCategoryCode = normalizeVatCategoryCode(product.vatCategoryCode, line.vatRate)
  line.unitOfMeasure = product.unitOfMeasure
  syncInvoiceTypeFromVat()
}

function addLine() {
  form.lines.push(emptyLine())
}

function removeLine(index: number) {
  form.lines.splice(index, 1)
}

function clearClient() {
  form.clientId = null
}

function onClientSelected(client: any) {
  form.clientId = client.id
}

function onPrefillCreate(data: Record<string, any>) {
  clientPrefill.value = data
  showClientCreateModal.value = true
}

watch(showClientCreateModal, (isOpen) => {
  if (!isOpen) clientPrefill.value = {}
})

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

  let result: ProformaInvoice | null = null

  if (props.proforma) {
    const payload: UpdateProformaPayload = {
      invoiceTypeCode: form.invoiceTypeCode || null,
      clientId: form.clientId || undefined,
      issueDate: form.issueDate || '',
      dueDate: form.dueDate || undefined,
      validUntil: form.validUntil || undefined,
      currency: form.currency,
      language: form.language,
      notes: form.notes || null,
      paymentTerms: form.paymentTerms || null,
      deliveryLocation: form.deliveryLocation || null,
      projectReference: form.projectReference || null,
      lines,
    }
    result = await proformaStore.updateProforma(props.proforma.id, payload)
  }
  else {
    const payload: CreateProformaPayload = {
      documentSeriesId: form.documentSeriesId || undefined,
      invoiceTypeCode: form.invoiceTypeCode || null,
      clientId: form.clientId || undefined,
      issueDate: form.issueDate || '',
      dueDate: form.dueDate || undefined,
      validUntil: form.validUntil || undefined,
      currency: form.currency,
      language: form.language,
      notes: form.notes || undefined,
      paymentTerms: form.paymentTerms || undefined,
      deliveryLocation: form.deliveryLocation || undefined,
      projectReference: form.projectReference || undefined,
      lines,
    }
    result = await proformaStore.createProforma(payload)
  }

  saving.value = false

  if (result) {
    emit('saved', result)
  }
  else if (proformaStore.error) {
    toast.add({ title: proformaStore.error, color: 'error' })
  }
}

// Load clients, series, and defaults on mount
onMounted(async () => {
  await Promise.all([
    loadSeries(),
    fetchDefaults(),
    clientStore.fetchClients(),
  ])
  if (!props.proforma) {
    autoSelectFirst(form)
    form.currency = defaultCurrency.value
  }

  // Pre-fill from source proforma for copy
  if (props.copyOf && !props.proforma) {
    const source = await proformaStore.fetchProforma(props.copyOf)
    if (source) {
      form.clientId = source.client?.id || null
      form.currency = source.currency
      form.language = source.language || 'ro'
      form.invoiceTypeCode = source.invoiceTypeCode || ''
      form.notes = source.notes || ''
      form.paymentTerms = source.paymentTerms || ''
      if (source.notes || source.paymentTerms) {
        showNotes.value = true
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
    }
  }
})
</script>
