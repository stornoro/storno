<template>
  <div class="space-y-4">
    <!-- Header: Template Info (compact, no card) -->
    <div class="space-y-2">
      <!-- Reference: full width standalone -->
      <UInput v-model="form.reference" :placeholder="$t('recurringInvoices.referencePlaceholder')" />

      <!-- Doc type chips -->
      <div class="flex gap-2">
        <button
          v-for="dt in documentTypeOptions"
          :key="dt.value"
          type="button"
          class="px-3 py-1 rounded-full text-xs font-semibold border transition-colors cursor-pointer"
          :class="form.documentType === dt.value
            ? 'bg-primary/10 border-primary text-primary'
            : 'bg-(--ui-bg-elevated) border-(--ui-border) text-(--ui-text-muted) hover:border-(--ui-text-muted)'"
          @click="form.documentType = dt.value"
        >
          {{ dt.label }}
        </button>
      </div>

      <!-- Compact grid: invoiceTypeCode | series | currency -->
      <div class="grid gap-x-2 gap-y-0.5" style="grid-template-columns: 1.2fr 1fr 0.7fr;">
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.invoiceTypeCode') }}</span>
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.selectSeries') }}</span>
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.currency') }}</span>

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

        <USelectMenu
          v-model="form.currency"
          :items="currencyOptions"
          value-key="value"
          :search-input="true"
          size="sm"
        />
      </div>

      <!-- No series — inline quick-create -->
      <div
        v-if="seriesOptions.length === 0"
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
              <UInput v-model="quickSeriesPrefix" placeholder="ex: FACT" size="sm" />
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

    <!-- Schedule -->
    <div class="space-y-2">
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-calendar-clock" class="size-4 text-(--ui-text-muted)" />
        <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('recurringInvoices.schedule') }}</span>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <UFormField :label="$t('recurringInvoices.frequency')">
          <USelectMenu
            v-model="form.frequency"
            :items="frequencyOptions"
            value-key="value"
          />
        </UFormField>
        <UFormField v-if="form.frequency === 'weekly'" :label="$t('recurringInvoices.frequencyDayOfWeek')">
          <USelectMenu
            v-model="form.frequencyDay"
            :items="dayOfWeekOptions"
            value-key="value"
          />
        </UFormField>
        <UFormField v-if="form.frequency !== 'weekly'" :label="$t('recurringInvoices.frequencyDay')">
          <UInput v-model.number="form.frequencyDay" type="number" min="1" max="28" />
        </UFormField>
        <UFormField v-if="form.frequency === 'yearly'" :label="$t('recurringInvoices.frequencyMonth')">
          <USelectMenu
            v-model="form.frequencyMonth"
            :items="monthOptions"
            value-key="value"
          />
        </UFormField>
        <UFormField :label="$t('recurringInvoices.nextIssuanceDate')">
          <UInput v-model="form.nextIssuanceDate" type="date" :min="todayISO" />
        </UFormField>
        <UFormField v-if="form.frequency !== 'once'" :label="$t('recurringInvoices.stopDate')">
          <UInput v-model="form.stopDate" type="date" />
        </UFormField>
      </div>
    </div>

    <!-- Due Date Config -->
    <div class="space-y-2">
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-calendar-check" class="size-4 text-(--ui-text-muted)" />
        <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('recurringInvoices.dueDateConfig') }}</span>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <UFormField :label="$t('recurringInvoices.dueDateType')">
          <USelectMenu
            v-model="form.dueDateType"
            :items="dueDateTypeOptions"
            value-key="value"
          />
        </UFormField>
        <UFormField v-if="form.dueDateType === 'days'" :label="$t('recurringInvoices.dueDateDays')">
          <UInput v-model.number="form.dueDateDays" type="number" min="1" />
        </UFormField>
        <UFormField v-if="form.dueDateType === 'fixed_day'" :label="$t('recurringInvoices.dueDateFixedDay')">
          <UInput v-model.number="form.dueDateFixedDay" type="number" min="1" max="28" />
        </UFormField>
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

      <div class="space-y-4 overflow-visible">
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

          <!-- Description full width with template variables -->
          <UFormField :label="$t('invoices.lineDescription')" class="w-full">
            <UInput v-model="line.description" :placeholder="$t('invoices.lineDescription')" class="w-full" />
            <div class="flex items-center gap-1.5 mt-1.5 flex-wrap">

              <UTooltip v-for="v in templateVariables" :key="v.token" :text="`${v.token} \u2192 ${v.example}`">
                <button
                  type="button"
                  class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-(--ui-bg-elevated) text-(--ui-text-muted) hover:text-(--ui-text) hover:bg-(--ui-bg-accented) transition-colors cursor-pointer border border-(--ui-border)"
                  @click="line.description += v.token"
                >
                  <span class="font-mono">{{ v.token }}</span>
                  <span class="text-(--ui-text-dimmed)">&rarr; {{ v.example }}</span>
                </button>
              </UTooltip>
            </div>
          </UFormField>

          <!-- Qty / Unit / Price / Discount -->
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
            <UFormField :label="line.referenceCurrency ? `${$t('invoices.unitPrice')} (${line.referenceCurrency})` : $t('invoices.unitPrice')">
              <UInput v-model="line.unitPrice" type="number" step="0.01" min="0" />
            </UFormField>
            <UFormField :label="line.referenceCurrency ? `${$t('invoices.discount')} (${line.referenceCurrency})` : $t('invoices.discount')">
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

          <!-- Price rule -->
          <UFormField :label="$t('recurringInvoices.priceRule')">
            <USelectMenu
              v-model="line.priceRule"
              :items="priceRuleOptions"
              value-key="value"
            />
          </UFormField>

          <!-- Reference currency (for bnr_rate / bnr_rate_markup) -->
          <template v-if="(line.priceRule === 'bnr_rate' || line.priceRule === 'bnr_rate_markup') && form.currency === 'RON'">
            <div class="grid grid-cols-2 gap-3">
              <UFormField :label="$t('recurringInvoices.lineReferenceCurrency')">
                <USelectMenu
                  v-model="line.referenceCurrency"
                  :items="referenceCurrencyOptions"
                  value-key="value"
                />
              </UFormField>
              <UFormField v-if="line.priceRule === 'bnr_rate_markup'" :label="$t('recurringInvoices.markupPercent')">
                <UInput v-model="line.markupPercent" type="number" step="0.01" min="0" placeholder="0.00" />
              </UFormField>
            </div>
          </template>

          <!-- Line total with separator -->
          <div class="flex justify-end items-center pt-2 border-t border-(--ui-border)">
            <span class="text-xs text-(--ui-text-muted) mr-2">{{ $t('invoices.total') }}:</span>
            <span class="text-sm font-semibold">{{ formatLineTotalConverted(line) }}</span>
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
        <span class="font-medium">{{ formatMoney(computedTotals.subtotalRON, 'RON') }}</span>
      </div>
      <div class="flex justify-between text-sm">
        <span>TVA</span>
        <span class="font-medium">{{ formatMoney(computedTotals.vatRON, 'RON') }}</span>
      </div>
      <div v-if="computedTotals.discountRON > 0" class="flex justify-between text-sm">
        <span>{{ $t('invoices.discount') }}</span>
        <span class="font-medium">-{{ formatMoney(computedTotals.discountRON, 'RON') }}</span>
      </div>
      <div class="flex justify-between items-center pt-2 mt-2 border-t border-(--ui-border)">
        <span class="text-base font-bold">{{ $t('invoices.total') }}</span>
        <span class="text-lg font-bold">{{ formatMoney(computedTotals.totalRON, form.currency) }}</span>
      </div>
      <!-- Per-currency breakdown -->
      <template v-for="(info, cur) in computedTotals.currencyBreakdown" :key="cur">
        <div class="flex justify-between text-xs text-(--ui-text-muted) pt-1">
          <span>Curs BNR: 1 {{ cur }} = {{ info.rate.toFixed(4) }} RON</span>
          <span v-if="info.markup > 0">+ adaos {{ info.markup }}%</span>
        </div>
      </template>
    </div>

    <!-- Auto-Email -->
    <div>
      <button type="button" class="flex items-center gap-2 w-full py-2 group" @click="showAutoEmail = !showAutoEmail">
        <UIcon name="i-lucide-mail" class="size-4 text-(--ui-text-muted)" />
        <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('recurringInvoices.autoEmail') }}</span>
        <div class="flex-1 border-t border-(--ui-border) mx-2" />
        <UIcon :name="showAutoEmail ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-3.5 text-(--ui-text-muted) group-hover:text-(--ui-text) transition-colors" />
      </button>
      <div v-if="showAutoEmail" class="pb-3 pl-6 space-y-3">
        <div class="flex items-center gap-3">
          <USwitch v-model="form.autoEmailEnabled" />
          <span class="text-sm">{{ $t('recurringInvoices.autoEmailEnabled') }}</span>
        </div>
        <template v-if="form.autoEmailEnabled">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <UFormField :label="$t('recurringInvoices.autoEmailTime')">
              <UInput v-model="form.autoEmailTime" type="time" />
            </UFormField>
            <UFormField :label="$t('recurringInvoices.autoEmailDayOffset')">
              <UInput v-model.number="form.autoEmailDayOffset" type="number" min="0" />
            </UFormField>
          </div>
        </template>
      </div>
    </div>

    <!-- Penalties -->
    <div>
      <button type="button" class="flex items-center gap-2 w-full py-2 group" @click="showPenalties = !showPenalties">
        <UIcon name="i-lucide-triangle-alert" class="size-4 text-(--ui-text-muted)" />
        <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('recurringInvoices.penalties') }}</span>
        <div class="flex-1 border-t border-(--ui-border) mx-2" />
        <UIcon :name="showPenalties ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-3.5 text-(--ui-text-muted) group-hover:text-(--ui-text) transition-colors" />
      </button>
      <div v-if="showPenalties" class="pb-3 pl-6 space-y-3">
        <div class="flex items-center gap-3">
          <USwitch v-model="form.penaltyEnabled" />
          <span class="text-sm">{{ $t('recurringInvoices.penaltyEnabled') }}</span>
        </div>
        <template v-if="form.penaltyEnabled">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <UFormField :label="$t('recurringInvoices.penaltyPercentPerDay')">
              <UInput v-model="form.penaltyPercentPerDay" type="number" step="0.01" min="0" placeholder="0.00" />
            </UFormField>
            <UFormField :label="$t('recurringInvoices.penaltyGraceDays')">
              <UInput v-model.number="form.penaltyGraceDays" type="number" min="0" />
            </UFormField>
          </div>
        </template>
      </div>
    </div>

    <!-- Notes (collapsible) -->
    <div>
      <button type="button" class="flex items-center gap-2 w-full py-2 group" @click="showNotes = !showNotes">
        <UIcon name="i-lucide-message-square-text" class="size-4 text-(--ui-text-muted)" />
        <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('common.notes') }}</span>
        <div class="flex-1 border-t border-(--ui-border) mx-2" />
        <UIcon :name="showNotes ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-3.5 text-(--ui-text-muted) group-hover:text-(--ui-text) transition-colors" />
      </button>
      <div v-if="showNotes" class="pb-3 pl-6 space-y-3">
        <UFormField :label="$t('invoices.paymentTerms')">
          <UInput v-model="form.paymentTerms" />
        </UFormField>
        <UFormField :label="$t('common.notes')">
          <UTextarea v-model="form.notes" :rows="6" class="w-full" />
          <div class="flex items-center gap-1.5 mt-1.5 flex-wrap">
            <UTooltip v-for="v in templateVariables" :key="v.token" :text="`${v.token} \u2192 ${v.example}`">
              <button
                type="button"
                class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-(--ui-bg-elevated) text-(--ui-text-muted) hover:text-(--ui-text) hover:bg-(--ui-bg-accented) transition-colors cursor-pointer border border-(--ui-border)"
                @click="form.notes += v.token"
              >
                <span class="font-mono">{{ v.token }}</span>
                <span class="text-(--ui-text-dimmed)">&rarr; {{ v.example }}</span>
              </button>
            </UTooltip>
          </div>
        </UFormField>
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
import type { RecurringInvoice, RecurringInvoiceLinePayload, Product } from '~/types'

const props = defineProps<{
  recurringInvoice?: RecurringInvoice | null
}>()

const emit = defineEmits<{
  saved: [ri: RecurringInvoice]
  cancel: []
}>()

const { t: $t } = useI18n()
const store = useRecurringInvoiceStore()
const {
  fetchDefaults,
  vatRateOptions,
  currencyOptions,
  unitOfMeasureOptions,
  defaultCurrency,
  defaultVatRate,
  defaultUnitOfMeasure,
  exchangeRates,
} = useInvoiceDefaults()
const { formatMoney, lineNet } = useLineCalc()
const { getSelectedClient, loadClients, ensureClientInList } = useClientSearch()
const { loadSeries } = useSeriesSelection(() => form.documentType === 'proforma' ? 'proforma' : 'invoice')
const seriesStore = useDocumentSeriesStore()
const sourceOrder: Record<string, number> = { efactura: 0, default: 1, manual: 2 }
const seriesOptions = computed(() =>
  seriesStore.items
    .filter(s => s.active && s.type === (form.documentType === 'proforma' ? 'proforma' : 'invoice'))
    .sort((a, b) => (sourceOrder[a.source] ?? 2) - (sourceOrder[b.source] ?? 2))
    .map((s) => ({ label: `${s.prefix} — ${s.nextNumber}`, value: s.id })),
)

const saving = ref(false)
const showNotes = ref(false)
const showAutoEmail = ref(false)
const showPenalties = ref(false)
const productPickerOpen = ref(false)
const productPickerLineIndex = ref(0)

// ── Quick series creation (inline) ──────────────────────────────────
const showQuickSeriesForm = ref(false)
const quickSeriesPrefix = ref('')
const quickSeriesStartNumber = ref(0)
const quickSeriesSaving = ref(false)
const toast = useToast()
const todayISO = computed(() => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
})
const tomorrowISO = computed(() => {
  const d = new Date()
  d.setDate(d.getDate() + 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
})

async function onQuickCreateSeries() {
  if (!quickSeriesPrefix.value) return
  quickSeriesSaving.value = true
  const seriesType = form.documentType === 'proforma' ? 'proforma' : 'invoice'
  const result = await seriesStore.createSeries({
    prefix: quickSeriesPrefix.value,
    type: seriesType,
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

interface LineForm {
  description: string
  quantity: string
  unitOfMeasure: string
  unitPrice: string
  vatRate: string
  vatCategoryCode: string
  discount: string
  discountPercent: string
  referenceCurrency: string | null
  markupPercent: string
  priceRule: string
  productId: string
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
    referenceCurrency: null,
    markupPercent: '',
    priceRule: 'fixed',
    productId: '',
  }
}

const form = reactive({
  reference: '',
  documentSeriesId: undefined as string | undefined,
  documentType: 'invoice' as string,
  invoiceTypeCode: 'standard' as string | undefined,
  clientId: null as string | null,
  receiverName: '' as string,
  receiverCif: '' as string,
  currency: 'RON',
  frequency: 'monthly' as string,
  frequencyDay: 1 as number,
  frequencyMonth: 1 as number,
  nextIssuanceDate: '' as string,
  stopDate: '',
  dueDateType: 'days' as string | undefined,
  dueDateDays: 30 as number,
  dueDateFixedDay: 15 as number,
  notes: '',
  paymentTerms: '',
  autoEmailEnabled: false,
  autoEmailTime: '09:00',
  autoEmailDayOffset: 0 as number,
  penaltyEnabled: false,
  penaltyPercentPerDay: '',
  penaltyGraceDays: 0 as number,
  lines: [emptyLine()] as LineForm[],
})

const isInitializing = ref(true)

// Populate form from existing recurring invoice (edit mode)
if (props.recurringInvoice) {
  const ri = props.recurringInvoice
  form.reference = ri.reference || ''
  form.documentType = ri.documentType
  form.documentSeriesId = ri.documentSeries?.id || undefined
  form.invoiceTypeCode = ri.invoiceTypeCode || 'standard'
  form.clientId = ri.client?.id || null
  form.receiverName = ri.receiverName || ''
  form.receiverCif = ri.receiverCif || ''
  form.currency = ri.currency
  form.frequency = ri.frequency
  form.frequencyDay = ri.frequencyDay
  form.frequencyMonth = ri.frequencyMonth || 1
  form.nextIssuanceDate = ri.nextIssuanceDate?.split('T')[0] || ''
  form.stopDate = ri.stopDate?.split('T')[0] || ''
  form.dueDateType = ri.dueDateType || undefined
  form.dueDateDays = ri.dueDateDays || 30
  form.dueDateFixedDay = ri.dueDateFixedDay || 15
  form.notes = ri.notes || ''
  form.paymentTerms = ri.paymentTerms || ''
  form.autoEmailEnabled = ri.autoEmailEnabled ?? false
  form.autoEmailTime = ri.autoEmailTime || '09:00'
  form.autoEmailDayOffset = ri.autoEmailDayOffset ?? 0
  form.penaltyEnabled = ri.penaltyEnabled ?? false
  form.penaltyPercentPerDay = ri.penaltyPercentPerDay || ''
  form.penaltyGraceDays = ri.penaltyGraceDays ?? 0
  form.lines = ri.lines.length > 0
    ? ri.lines.map(l => ({
        description: l.description,
        quantity: l.quantity,
        unitOfMeasure: l.unitOfMeasure,
        unitPrice: l.unitPrice,
        vatRate: l.vatRate,
        vatCategoryCode: l.vatCategoryCode,
        discount: l.discount,
        discountPercent: l.discountPercent,
        referenceCurrency: l.referenceCurrency || null,
        markupPercent: l.markupPercent || '',
        priceRule: l.priceRule || 'fixed',
        productId: l.productId || '',
      }))
    : [emptyLine()]

  // Auto-expand notes if editing and any notes field has data
  if (ri.notes || ri.paymentTerms) {
    showNotes.value = true
  }
}

// Reset series when document type changes (invoice vs proforma use different series)
watch(() => form.documentType, () => {
  if (isInitializing.value) return
  form.documentSeriesId = undefined
  nextTick(() => {
    if (seriesOptions.value.length > 0) {
      form.documentSeriesId = seriesOptions.value[0]?.value
    }
  })
})

const selectedClient = getSelectedClient(() => form.clientId)
const showClientCreateModal = ref(false)
const clientPrefill = ref<Record<string, any> | null>(null)

function onPrefillCreate(data: Record<string, any>) {
  clientPrefill.value = data
  showClientCreateModal.value = true
}

watch(showClientCreateModal, (isOpen) => {
  if (!isOpen) clientPrefill.value = null
})

function clearClient() {
  form.clientId = null
  form.receiverName = ''
  form.receiverCif = ''
}

function onClientSelected(client: { id: string, name?: string, cui?: string | null }) {
  form.clientId = client.id
  form.receiverCif = ''
  form.receiverName = ''
  ensureClientInList(client as any)
}


const documentTypeOptions = [
  { label: $t('documentType.invoice'), value: 'invoice' },
  { label: $t('documentType.proforma'), value: 'proforma' },
]

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

const currentMonth = new Date().getMonth()
const currentYear = new Date().getFullYear()
const romanianMonths = ['Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie', 'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie']

const templateVariables = [
  { token: '[[luna]]', example: romanianMonths[currentMonth] },
  { token: '[[an]]', example: String(currentYear) },
  { token: '[[luna_nr]]', example: String(currentMonth + 1).padStart(2, '0') },
  { token: '[[curs]]', example: 'Curs BNR' },
]

const frequencyOptions = computed(() => [
  { label: $t('recurringInvoices.frequencies.once'), value: 'once' },
  { label: $t('recurringInvoices.frequencies.weekly'), value: 'weekly' },
  { label: $t('recurringInvoices.frequencies.monthly'), value: 'monthly' },
  { label: $t('recurringInvoices.frequencies.bimonthly'), value: 'bimonthly' },
  { label: $t('recurringInvoices.frequencies.quarterly'), value: 'quarterly' },
  { label: $t('recurringInvoices.frequencies.semi_annually'), value: 'semi_annually' },
  { label: $t('recurringInvoices.frequencies.yearly'), value: 'yearly' },
])

const dayOfWeekOptions = computed(() =>
  [1, 2, 3, 4, 5, 6, 7].map(d => ({
    label: $t(`recurringInvoices.daysOfWeek.${d}`),
    value: d,
  })),
)

const monthOptions = computed(() =>
  [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12].map(m => ({
    label: $t(`reports.months.${m}`),
    value: m,
  })),
)

const referenceCurrencyOptions = computed(() => {
  const options: { label: string, value: string | null }[] = [{ label: $t('recurringInvoices.noConversion'), value: null }]
  for (const c of currencyOptions.value) {
    const code = typeof c === 'string' ? c : c.value
    if (code !== 'RON') {
      const rate = exchangeRates.value[code]
      options.push({
        label: rate ? `${code} (1 ${code} = ${rate.toFixed(4)} RON)` : code,
        value: code,
      })
    }
  }
  return options
})

const priceRuleOptions = computed(() => [
  { label: $t('priceRules.fixed'), value: 'fixed' },
  { label: $t('priceRules.updated_product'), value: 'updated_product' },
  { label: $t('priceRules.bnr_rate'), value: 'bnr_rate' },
  { label: $t('priceRules.bnr_rate_markup'), value: 'bnr_rate_markup' },
])

const dueDateTypeOptions = computed(() => [
  { label: $t('recurringInvoices.dueDateTypes.days'), value: 'days' },
  { label: $t('recurringInvoices.dueDateTypes.fixed_day'), value: 'fixed_day' },
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
}

// Product picker
function openProductPicker(index: number) {
  productPickerLineIndex.value = index
  productPickerOpen.value = true
}

function normalizeVatRate(rate: string | number): string {
  const num = parseFloat(String(rate))
  return isNaN(num) ? '21.00' : num.toFixed(2)
}

function onProductSelected(product: Product) {
  const index = productPickerLineIndex.value
  if (index >= 0 && index < form.lines.length) {
    const line = form.lines[index]
    if (!line) return
    line.productId = product.id
    line.description = product.description || product.name
    line.unitPrice = product.defaultPrice
    line.vatRate = normalizeVatRate(product.vatRate)
    line.vatCategoryCode = product.vatCategoryCode
    line.unitOfMeasure = product.unitOfMeasure
  }
}

// Auto-adjust nextIssuanceDate when frequency/day/month changes
watch(
  [() => form.frequency, () => form.frequencyDay, () => form.frequencyMonth],
  ([frequency, day, month]) => {
    if (!day || day < 1) return
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    let next: Date | null = null

    if (frequency === 'weekly') {
      // day is 1-7 (Mon-Sun), JS getDay() is 0-6 (Sun-Sat)
      const jsDay = day === 7 ? 0 : day
      next = new Date(today)
      const diff = (jsDay - today.getDay() + 7) % 7
      next.setDate(today.getDate() + (diff === 0 ? 0 : diff))
    } else if (frequency === 'yearly') {
      const m = (month || 1) - 1 // 0-indexed
      const d = Math.min(day, 28)
      next = new Date(today.getFullYear(), m, d)
      if (next <= today) {
        next = new Date(today.getFullYear() + 1, m, d)
      }
    } else {
      // monthly, bimonthly, quarterly, semi_annually, once
      const d = Math.min(day, 28)
      next = new Date(today.getFullYear(), today.getMonth(), d)
      if (next <= today) {
        next.setMonth(next.getMonth() + 1)
      }
    }

    if (next) {
      form.nextIssuanceDate = next.toISOString().split('T')[0]!
    }
  },
)

// Clear per-line reference currencies when switching away from RON
watch(() => form.currency, (val) => {
  if (val !== 'RON') {
    for (const line of form.lines) {
      line.referenceCurrency = null
      line.markupPercent = ''
    }
  }
})

// Converted line calculations (unique to recurring — handles per-line currency conversion)
function lineConvertedNet(line: LineForm): number {
  const net = lineNet(line)
  if (line.referenceCurrency && exchangeRates.value[line.referenceCurrency]) {
    const rate = exchangeRates.value[line.referenceCurrency] ?? 0
    const markup = parseFloat(line.markupPercent) || 0
    return net * rate * (1 + markup / 100)
  }
  return net
}

function lineConvertedVat(line: LineForm): number {
  const net = lineConvertedNet(line)
  return net * ((parseFloat(line.vatRate) || 0) / 100)
}

function formatLineTotalConverted(line: LineForm): string {
  const total = lineConvertedNet(line) + lineConvertedVat(line)
  return formatMoney(total, form.currency === 'RON' ? 'RON' : form.currency)
}

const computedTotals = computed(() => {
  let subtotalRON = 0
  let vatRON = 0
  let discountRON = 0
  const currencyBreakdown: Record<string, { rate: number; markup: number }> = {}

  for (const line of form.lines) {
    const convertedNet = lineConvertedNet(line)
    const convertedVat = lineConvertedVat(line)
    subtotalRON += convertedNet
    vatRON += convertedVat

    const disc = parseFloat(line.discount) || 0
    if (line.referenceCurrency && exchangeRates.value[line.referenceCurrency]) {
      const rate = exchangeRates.value[line.referenceCurrency] ?? 0
      const markup = parseFloat(line.markupPercent) || 0
      discountRON += disc * rate * (1 + markup / 100)
      if (!currencyBreakdown[line.referenceCurrency]) {
        currencyBreakdown[line.referenceCurrency] = { rate, markup }
      }
    } else {
      discountRON += disc
    }
  }

  return {
    subtotalRON,
    vatRON,
    discountRON,
    totalRON: subtotalRON + vatRON,
    currencyBreakdown,
  }
})

function addLine() {
  form.lines.push(emptyLine())
}

function removeLine(index: number) {
  form.lines.splice(index, 1)
}

async function onSave() {
  if (form.nextIssuanceDate && form.nextIssuanceDate < todayISO.value) {
    toast.add({ title: $t('recurringInvoices.nextIssuanceDatePast'), color: 'error' })
    return
  }
  saving.value = true

  const lines: RecurringInvoiceLinePayload[] = form.lines.map(l => ({
    description: l.description,
    quantity: l.quantity,
    unitOfMeasure: l.unitOfMeasure,
    unitPrice: l.unitPrice,
    vatRate: l.vatRate,
    vatCategoryCode: l.vatCategoryCode,
    discount: l.discount,
    discountPercent: l.discountPercent,
    referenceCurrency: l.referenceCurrency || null,
    markupPercent: l.markupPercent || null,
    priceRule: l.priceRule || 'fixed',
    productId: l.productId || null,
  }))

  const basePayload = {
    reference: form.reference || null,
    documentType: form.documentType,
    invoiceTypeCode: form.invoiceTypeCode || null,
    clientId: form.clientId || null,
    receiverName: !form.clientId && form.receiverName ? form.receiverName : undefined,
    receiverCif: !form.clientId && form.receiverCif ? form.receiverCif : undefined,
    currency: form.currency,
    frequency: form.frequency,
    frequencyDay: form.frequencyDay,
    frequencyMonth: form.frequency === 'yearly' ? form.frequencyMonth : null,
    nextIssuanceDate: form.nextIssuanceDate,
    stopDate: form.stopDate || null,
    dueDateType: form.dueDateType || null,
    dueDateDays: form.dueDateType === 'days' ? form.dueDateDays : null,
    dueDateFixedDay: form.dueDateType === 'fixed_day' ? form.dueDateFixedDay : null,
    documentSeriesId: form.documentSeriesId ?? null,
    notes: form.notes || null,
    paymentTerms: form.paymentTerms || null,
    autoEmailEnabled: form.autoEmailEnabled,
    autoEmailTime: form.autoEmailEnabled ? form.autoEmailTime || null : null,
    autoEmailDayOffset: form.autoEmailEnabled ? form.autoEmailDayOffset : 0,
    penaltyEnabled: form.penaltyEnabled,
    penaltyPercentPerDay: form.penaltyEnabled ? form.penaltyPercentPerDay || null : null,
    penaltyGraceDays: form.penaltyEnabled ? form.penaltyGraceDays : null,
    lines,
  }

  let result: RecurringInvoice | null = null

  if (props.recurringInvoice) {
    result = await store.updateRecurringInvoice(props.recurringInvoice.id, basePayload)
  }
  else {
    result = await store.createRecurringInvoice(basePayload)
  }

  saving.value = false

  if (result) {
    emit('saved', result)
  }
  else if (store.error) {
    toast.add({ title: store.error, color: 'error' })
  }
}

// Load clients, series, and defaults on mount
onMounted(async () => {
  await Promise.all([
    loadSeries(),
    fetchDefaults(),
    loadClients(),
  ])
  // Auto-select first series if none is set (both create and edit)
  if (!form.documentSeriesId && seriesOptions.value.length > 0) {
    form.documentSeriesId = seriesOptions.value[0]?.value
  }
  if (!props.recurringInvoice) {
    form.currency = defaultCurrency.value
    if (!form.nextIssuanceDate) {
      form.nextIssuanceDate = tomorrowISO.value
    }
  }
  ensureClientInList(props.recurringInvoice?.client)
  isInitializing.value = false
})
</script>
