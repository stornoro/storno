<template>
  <div class="space-y-4">
    <!-- Edit mode: show document number -->
    <div v-if="deliveryNote" class="flex items-center gap-3 p-3 rounded-lg bg-(--ui-bg-elevated) border border-(--ui-border)">
      <div class="flex items-center justify-center size-10 rounded-full bg-primary/10 text-primary shrink-0">
        <UIcon name="i-lucide-file-text" class="size-5" />
      </div>
      <div class="flex-1 min-w-0">
        <div class="font-semibold text-sm">{{ deliveryNote.number }}</div>
        <div class="text-xs text-(--ui-text-muted)">{{ deliveryNote.documentSeries?.prefix ?? '' }}</div>
      </div>
    </div>

    <!-- Document header -->
    <div class="space-y-2">
      <div class="grid gap-x-2 gap-y-0.5" style="grid-template-columns: 1.2fr 1fr 1fr 0.7fr;">
        <span v-if="!deliveryNote || deliveryNote.status === 'draft'" class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.selectSeries') }}</span>
        <span v-else class="text-[10px] text-(--ui-text-dimmed)" />
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.issueDate') }}</span>
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.dueDate') }}</span>
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.currency') }}</span>

        <template v-if="!deliveryNote || deliveryNote.status === 'draft'">
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
        <span v-else />

        <UInput v-model="form.issueDate" type="date" size="sm" />
        <UInput v-model="form.dueDate" type="date" size="sm" />
        <USelectMenu
          v-model="form.currency"
          :items="currencyOptions"
          value-key="value"
          size="sm"
        />
      </div>

      <!-- No series — inline quick-create -->
      <div
        v-if="(!deliveryNote || deliveryNote.status === 'draft') && seriesOptions.length === 0"
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
              <UInput v-model="quickSeriesPrefix" placeholder="ex: AVZ" size="sm" />
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

          <!-- e-Transport line fields -->
          <div v-if="showETransport" class="grid grid-cols-2 md:grid-cols-3 gap-3 pt-3 border-t border-(--ui-border)">
            <UFormField :label="$t('deliveryNotes.tariffCode')">
              <UInput v-model="line.tariffCode" placeholder="08031010" maxlength="8" />
            </UFormField>
            <UFormField :label="$t('deliveryNotes.purposeCode')">
              <USelectMenu v-model="line.purposeCode" :items="purposeCodeOptions" value-key="value" />
            </UFormField>
            <UFormField :label="$t('deliveryNotes.unitOfMeasure')">
              <USelectMenu v-model="line.unitOfMeasureCode" :items="uomCodeOptions" value-key="value" :search-input="true" />
            </UFormField>
            <UFormField :label="$t('deliveryNotes.netWeight')">
              <UInput v-model="line.netWeight" type="number" step="0.01" min="0" />
            </UFormField>
            <UFormField :label="$t('deliveryNotes.grossWeight')">
              <UInput v-model="line.grossWeight" type="number" step="0.01" min="0" />
            </UFormField>
            <UFormField :label="$t('deliveryNotes.valueWithoutVat')">
              <UInput v-model="line.valueWithoutVat" type="number" step="0.01" min="0" />
            </UFormField>
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
      <!-- Delegate info (collapsible) -->
      <div>
        <button type="button" class="flex items-center gap-2 w-full py-2 group" @click="showDelegate = !showDelegate">
          <UIcon name="i-lucide-user" class="size-4 text-(--ui-text-muted)" />
          <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('deliveryNotes.delegateInfo') }}</span>
          <div class="flex-1 border-t border-(--ui-border) mx-2" />
          <UIcon
            :name="showDelegate ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
            class="size-3.5 text-(--ui-text-muted) group-hover:text-(--ui-text) transition-colors"
          />
        </button>
        <div v-if="showDelegate" class="pb-3 pl-6 grid grid-cols-1 md:grid-cols-2 gap-4">
          <UFormField :label="$t('deliveryNotes.deputyName')">
            <UInput v-model="form.deputyName" />
          </UFormField>
          <UFormField :label="$t('deliveryNotes.deputyIdentityCard')">
            <UInput v-model="form.deputyIdentityCard" />
          </UFormField>
          <UFormField :label="$t('deliveryNotes.deputyAuto')">
            <UInput v-model="form.deputyAuto" />
          </UFormField>
          <UFormField :label="$t('deliveryNotes.issuerName')">
            <UInput v-model="form.issuerName" />
          </UFormField>
          <UFormField :label="$t('deliveryNotes.issuerId')">
            <UInput v-model="form.issuerId" />
          </UFormField>
          <UFormField :label="$t('deliveryNotes.salesAgent')">
            <UInput v-model="form.salesAgent" />
          </UFormField>
        </div>
      </div>

      <!-- e-Transport (collapsible) -->
      <div>
        <button type="button" class="flex items-center gap-2 w-full py-2 group" @click="showETransport = !showETransport">
          <UIcon name="i-lucide-truck" class="size-4 text-(--ui-text-muted)" />
          <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('deliveryNotes.etransport') }}</span>
          <div class="flex-1 border-t border-(--ui-border) mx-2" />
          <UIcon
            :name="showETransport ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
            class="size-3.5 text-(--ui-text-muted) group-hover:text-(--ui-text) transition-colors"
          />
        </button>
        <div v-if="showETransport" class="pb-3 pl-6 space-y-6">
          <!-- Transport Data -->
          <div>
            <h4 class="text-sm font-semibold text-(--ui-text-muted) mb-3">{{ $t('deliveryNotes.transportData') }}</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <UFormField :label="$t('deliveryNotes.operationType')">
                <USelectMenu
                  v-model="form.etransportOperationType"
                  :items="operationTypeOptions"
                  value-key="value"
                />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.transportDate')">
                <UInput v-model="form.etransportTransportDate" type="date" />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.vehicleNumber')">
                <UInput v-model="form.etransportVehicleNumber" placeholder="B01ABC" />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.trailer1')">
                <UInput v-model="form.etransportTrailer1" />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.trailer2')">
                <UInput v-model="form.etransportTrailer2" />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.transporterCountry')">
                <UInput v-model="form.etransportTransporterCountry" maxlength="2" placeholder="RO" />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.transporterCode')">
                <UInput v-model="form.etransportTransporterCode" placeholder="CUI transportator" />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.transporterName')">
                <UInput v-model="form.etransportTransporterName" />
              </UFormField>
            </div>
          </div>

          <!-- Route Start -->
          <div>
            <h4 class="text-sm font-semibold text-(--ui-text-muted) mb-3">{{ $t('deliveryNotes.routeStart') }}</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <UFormField :label="$t('deliveryNotes.county')">
                <USelectMenu
                  v-model="form.etransportStartCounty"
                  :items="countyOptions"
                  value-key="value"
                  :search-input="true"
                  :placeholder="$t('deliveryNotes.county')"
                />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.locality')">
                <UInput v-model="form.etransportStartLocality" />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.street')">
                <UInput v-model="form.etransportStartStreet" />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.streetNumber')">
                <UInput v-model="form.etransportStartNumber" />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.postalCode')">
                <UInput v-model="form.etransportStartPostalCode" />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.otherInfo')">
                <UInput v-model="form.etransportStartOtherInfo" />
              </UFormField>
            </div>
          </div>

          <!-- Route End -->
          <div>
            <h4 class="text-sm font-semibold text-(--ui-text-muted) mb-3">{{ $t('deliveryNotes.routeEnd') }}</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <UFormField :label="$t('deliveryNotes.county')">
                <USelectMenu
                  v-model="form.etransportEndCounty"
                  :items="countyOptions"
                  value-key="value"
                  :search-input="true"
                  :placeholder="$t('deliveryNotes.county')"
                />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.locality')">
                <UInput v-model="form.etransportEndLocality" />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.street')">
                <UInput v-model="form.etransportEndStreet" />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.streetNumber')">
                <UInput v-model="form.etransportEndNumber" />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.postalCode')">
                <UInput v-model="form.etransportEndPostalCode" />
              </UFormField>
              <UFormField :label="$t('deliveryNotes.otherInfo')">
                <UInput v-model="form.etransportEndOtherInfo" />
              </UFormField>
            </div>
          </div>
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
            <UFormField :label="$t('deliveryNotes.deliveryLocation')">
              <UInput v-model="form.deliveryLocation" />
            </UFormField>
            <UFormField :label="$t('deliveryNotes.projectReference')">
              <UInput v-model="form.projectReference" />
            </UFormField>
          </div>
          <UFormField :label="$t('deliveryNotes.notes')">
            <UTextarea v-model="form.notes" :rows="4" size="xl" class="w-full" />
          </UFormField>
          <UFormField :label="$t('deliveryNotes.mentions')">
            <UTextarea v-model="form.mentions" :rows="3" size="xl" class="w-full" />
          </UFormField>
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
import type { DeliveryNote, CreateDeliveryNotePayload, UpdateDeliveryNotePayload, DeliveryNoteLinePayload, Client, Product } from '~/types'
import { ETRANSPORT_OPERATION_TYPES, ETRANSPORT_PURPOSE_CODES_TTN, ETRANSPORT_COUNTIES, ETRANSPORT_COMMON_UOM_CODES } from '~/types/etransport'

const props = defineProps<{
  deliveryNote?: DeliveryNote | null
  copyOf?: string
}>()

const emit = defineEmits<{
  saved: [deliveryNote: DeliveryNote]
  cancel: []
}>()

const { t: $t } = useI18n()
const deliveryNoteStore = useDeliveryNoteStore()
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
const { formatMoney, formatLineTotal, computeSimpleTotals } = useLineCalc()
const { loadSeries, autoSelectFirst } = useSeriesSelection('delivery_note')
const seriesStore = useDocumentSeriesStore()
const seriesOptions = computed(() =>
  seriesStore.items
    .filter(s => s.active && s.type === 'delivery_note')
    .map((s) => ({ label: `${s.prefix} — ${s.nextNumber}`, value: s.id })),
)

const saving = ref(false)
const showNotes = ref(false)
const showDelegate = ref(false)
const showETransport = ref(false)
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
    type: 'delivery_note',
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

// Client modal
const showClientCreateModal = ref(false)
const clientPrefill = ref<Record<string, any> | null>(null)
const clients = ref<Client[]>([])

watch(showClientCreateModal, (isOpen) => {
  if (!isOpen) clientPrefill.value = null
})

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

const today = new Date().toISOString().split('T')[0] ?? ''

const countyOptions = ETRANSPORT_COUNTIES.map(c => ({ label: c.label, value: c.value }))
const operationTypeOptions = ETRANSPORT_OPERATION_TYPES.map(o => ({ label: o.label, value: o.value }))
const purposeCodeOptions = ETRANSPORT_PURPOSE_CODES_TTN.map(p => ({ label: p.label, value: p.value }))
const uomCodeOptions = ETRANSPORT_COMMON_UOM_CODES.map(u => ({ label: u.label, value: u.value }))

type ETransportUomCode = typeof ETRANSPORT_COMMON_UOM_CODES[number]['value']
type ETransportOperationType = typeof ETRANSPORT_OPERATION_TYPES[number]['value']
type ETransportPurposeCode = typeof ETRANSPORT_PURPOSE_CODES_TTN[number]['value']
type ETransportCounty = typeof ETRANSPORT_COUNTIES[number]['value']

interface LineForm {
  description: string
  quantity: string
  unitOfMeasure: string
  unitPrice: string
  vatRate: string
  vatCategoryCode: string
  discount: string
  discountPercent: string
  tariffCode: string
  purposeCode: ETransportPurposeCode | undefined
  unitOfMeasureCode: ETransportUomCode
  netWeight: string
  grossWeight: string
  valueWithoutVat: string
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
    tariffCode: '',
    purposeCode: 101 as ETransportPurposeCode | undefined,
    unitOfMeasureCode: 'KGM' as ETransportUomCode,
    netWeight: '',
    grossWeight: '',
    valueWithoutVat: '',
  }
}

const form = reactive({
  documentSeriesId: undefined as string | undefined,
  clientId: null as string | null,
  issueDate: today,
  dueDate: '',
  currency: 'RON',
  notes: '',
  mentions: '',
  deliveryLocation: '',
  projectReference: '',
  issuerName: '',
  issuerId: '',
  salesAgent: '',
  deputyName: '',
  deputyIdentityCard: '',
  deputyAuto: '',
  // e-Transport
  etransportOperationType: 30 as ETransportOperationType | undefined,
  etransportVehicleNumber: '',
  etransportTrailer1: '',
  etransportTrailer2: '',
  etransportTransporterCountry: 'RO',
  etransportTransporterCode: '',
  etransportTransporterName: '',
  etransportTransportDate: '',
  etransportStartCounty: undefined as ETransportCounty | undefined,
  etransportStartLocality: '',
  etransportStartStreet: '',
  etransportStartNumber: '',
  etransportStartOtherInfo: '',
  etransportStartPostalCode: '',
  etransportEndCounty: undefined as ETransportCounty | undefined,
  etransportEndLocality: '',
  etransportEndStreet: '',
  etransportEndNumber: '',
  etransportEndOtherInfo: '',
  etransportEndPostalCode: '',
  lines: [emptyLine()] as LineForm[],
})

// Populate form from existing delivery note (edit mode)
if (props.deliveryNote) {
  form.documentSeriesId = props.deliveryNote.documentSeries?.id || undefined
  form.clientId = props.deliveryNote.client?.id || null
  form.issueDate = props.deliveryNote.issueDate?.split('T')[0] || today
  form.dueDate = props.deliveryNote.dueDate?.split('T')[0] || ''
  form.currency = props.deliveryNote.currency
  form.notes = props.deliveryNote.notes || ''
  form.mentions = props.deliveryNote.mentions || ''
  form.deliveryLocation = props.deliveryNote.deliveryLocation || ''
  form.projectReference = props.deliveryNote.projectReference || ''
  form.issuerName = props.deliveryNote.issuerName || ''
  form.issuerId = props.deliveryNote.issuerId || ''
  form.salesAgent = props.deliveryNote.salesAgent || ''
  form.deputyName = props.deliveryNote.deputyName || ''
  form.deputyIdentityCard = props.deliveryNote.deputyIdentityCard || ''
  form.deputyAuto = props.deliveryNote.deputyAuto || ''
  form.etransportOperationType = (props.deliveryNote.etransportOperationType ?? 30) as ETransportOperationType
  form.etransportVehicleNumber = props.deliveryNote.etransportVehicleNumber || ''
  form.etransportTrailer1 = props.deliveryNote.etransportTrailer1 || ''
  form.etransportTrailer2 = props.deliveryNote.etransportTrailer2 || ''
  form.etransportTransporterCountry = props.deliveryNote.etransportTransporterCountry || 'RO'
  form.etransportTransporterCode = props.deliveryNote.etransportTransporterCode || ''
  form.etransportTransporterName = props.deliveryNote.etransportTransporterName || ''
  form.etransportTransportDate = props.deliveryNote.etransportTransportDate?.split('T')[0] || ''
  form.etransportStartCounty = (props.deliveryNote.etransportStartCounty ?? undefined) as ETransportCounty | undefined
  form.etransportStartLocality = props.deliveryNote.etransportStartLocality || ''
  form.etransportStartStreet = props.deliveryNote.etransportStartStreet || ''
  form.etransportStartNumber = props.deliveryNote.etransportStartNumber || ''
  form.etransportStartOtherInfo = props.deliveryNote.etransportStartOtherInfo || ''
  form.etransportStartPostalCode = props.deliveryNote.etransportStartPostalCode || ''
  form.etransportEndCounty = (props.deliveryNote.etransportEndCounty ?? undefined) as ETransportCounty | undefined
  form.etransportEndLocality = props.deliveryNote.etransportEndLocality || ''
  form.etransportEndStreet = props.deliveryNote.etransportEndStreet || ''
  form.etransportEndNumber = props.deliveryNote.etransportEndNumber || ''
  form.etransportEndOtherInfo = props.deliveryNote.etransportEndOtherInfo || ''
  form.etransportEndPostalCode = props.deliveryNote.etransportEndPostalCode || ''
  form.lines = props.deliveryNote.lines.length > 0
    ? props.deliveryNote.lines.map(l => ({
        description: l.description,
        quantity: l.quantity,
        unitOfMeasure: l.unitOfMeasure,
        unitPrice: l.unitPrice,
        vatRate: l.vatRate,
        vatCategoryCode: l.vatCategoryCode,
        discount: l.discount,
        discountPercent: l.discountPercent,
        tariffCode: l.tariffCode || '',
        purposeCode: (l.purposeCode ?? 101) as ETransportPurposeCode,
        unitOfMeasureCode: (l.unitOfMeasureCode || 'KGM') as ETransportUomCode,
        netWeight: l.netWeight || '',
        grossWeight: l.grossWeight || '',
        valueWithoutVat: l.valueWithoutVat || '',
      }))
    : [emptyLine()]

  if (props.deliveryNote.notes || props.deliveryNote.mentions || props.deliveryNote.deliveryLocation || props.deliveryNote.projectReference) {
    showNotes.value = true
  }
  if (props.deliveryNote.deputyName || props.deliveryNote.deputyIdentityCard || props.deliveryNote.deputyAuto || props.deliveryNote.issuerName || props.deliveryNote.issuerId || props.deliveryNote.salesAgent) {
    showDelegate.value = true
  }
  if (props.deliveryNote.etransportVehicleNumber || props.deliveryNote.etransportStartCounty || props.deliveryNote.etransportUit) {
    showETransport.value = true
  }
}

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
  if (!line) return
  line.vatRate = vr.value
  line.vatCategoryCode = vr.categoryCode
}

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
  const line = form.lines[index]
  if (!line) return
  line.description = product.description || product.name
  line.unitPrice = product.defaultPrice
  line.vatRate = normalizeVatRate(product.vatRate)
  line.vatCategoryCode = product.vatCategoryCode
  line.unitOfMeasure = product.unitOfMeasure
}

function addLine() {
  form.lines.push(emptyLine())
}

function removeLine(index: number) {
  form.lines.splice(index, 1)
}

async function onSave() {
  saving.value = true

  const lines: DeliveryNoteLinePayload[] = form.lines.map(l => ({
    description: l.description,
    quantity: l.quantity,
    unitOfMeasure: l.unitOfMeasure,
    unitPrice: l.unitPrice,
    vatRate: l.vatRate,
    vatCategoryCode: l.vatCategoryCode,
    discount: l.discount,
    discountPercent: l.discountPercent,
    tariffCode: l.tariffCode || null,
    purposeCode: l.purposeCode,
    unitOfMeasureCode: l.unitOfMeasureCode || null,
    netWeight: l.netWeight || null,
    grossWeight: l.grossWeight || null,
    valueWithoutVat: l.valueWithoutVat || null,
  }))

  let result: { deliveryNote: DeliveryNote, validation: any } | null = null

  if (props.deliveryNote) {
    const payload: UpdateDeliveryNotePayload = {
      clientId: form.clientId || undefined,
      issueDate: form.issueDate,
      dueDate: form.dueDate || undefined,
      currency: form.currency,
      notes: form.notes || null,
      mentions: form.mentions || null,
      deliveryLocation: form.deliveryLocation || null,
      projectReference: form.projectReference || null,
      issuerName: form.issuerName || null,
      issuerId: form.issuerId || null,
      salesAgent: form.salesAgent || null,
      deputyName: form.deputyName || null,
      deputyIdentityCard: form.deputyIdentityCard || null,
      deputyAuto: form.deputyAuto || null,
      etransportOperationType: form.etransportOperationType,
      etransportVehicleNumber: form.etransportVehicleNumber || null,
      etransportTrailer1: form.etransportTrailer1 || null,
      etransportTrailer2: form.etransportTrailer2 || null,
      etransportTransporterCountry: form.etransportTransporterCountry || null,
      etransportTransporterCode: form.etransportTransporterCode || null,
      etransportTransporterName: form.etransportTransporterName || null,
      etransportTransportDate: form.etransportTransportDate || null,
      etransportStartCounty: form.etransportStartCounty,
      etransportStartLocality: form.etransportStartLocality || null,
      etransportStartStreet: form.etransportStartStreet || null,
      etransportStartNumber: form.etransportStartNumber || null,
      etransportStartOtherInfo: form.etransportStartOtherInfo || null,
      etransportStartPostalCode: form.etransportStartPostalCode || null,
      etransportEndCounty: form.etransportEndCounty,
      etransportEndLocality: form.etransportEndLocality || null,
      etransportEndStreet: form.etransportEndStreet || null,
      etransportEndNumber: form.etransportEndNumber || null,
      etransportEndOtherInfo: form.etransportEndOtherInfo || null,
      etransportEndPostalCode: form.etransportEndPostalCode || null,
      lines,
    }
    result = await deliveryNoteStore.updateDeliveryNote(props.deliveryNote.id, payload)
  }
  else {
    const payload: CreateDeliveryNotePayload = {
      documentSeriesId: form.documentSeriesId || undefined,
      clientId: form.clientId || undefined,
      issueDate: form.issueDate,
      dueDate: form.dueDate || undefined,
      currency: form.currency,
      notes: form.notes || undefined,
      mentions: form.mentions || undefined,
      deliveryLocation: form.deliveryLocation || undefined,
      projectReference: form.projectReference || undefined,
      issuerName: form.issuerName || undefined,
      issuerId: form.issuerId || undefined,
      salesAgent: form.salesAgent || undefined,
      deputyName: form.deputyName || undefined,
      deputyIdentityCard: form.deputyIdentityCard || undefined,
      deputyAuto: form.deputyAuto || undefined,
      etransportOperationType: form.etransportOperationType,
      etransportVehicleNumber: form.etransportVehicleNumber || null,
      etransportTrailer1: form.etransportTrailer1 || null,
      etransportTrailer2: form.etransportTrailer2 || null,
      etransportTransporterCountry: form.etransportTransporterCountry || null,
      etransportTransporterCode: form.etransportTransporterCode || null,
      etransportTransporterName: form.etransportTransporterName || null,
      etransportTransportDate: form.etransportTransportDate || null,
      etransportStartCounty: form.etransportStartCounty,
      etransportStartLocality: form.etransportStartLocality || null,
      etransportStartStreet: form.etransportStartStreet || null,
      etransportStartNumber: form.etransportStartNumber || null,
      etransportStartOtherInfo: form.etransportStartOtherInfo || null,
      etransportStartPostalCode: form.etransportStartPostalCode || null,
      etransportEndCounty: form.etransportEndCounty,
      etransportEndLocality: form.etransportEndLocality || null,
      etransportEndStreet: form.etransportEndStreet || null,
      etransportEndNumber: form.etransportEndNumber || null,
      etransportEndOtherInfo: form.etransportEndOtherInfo || null,
      etransportEndPostalCode: form.etransportEndPostalCode || null,
      lines,
    }
    result = await deliveryNoteStore.createDeliveryNote(payload)
  }

  saving.value = false

  if (result) {
    emit('saved', result.deliveryNote)
  }
  else if (deliveryNoteStore.error) {
    toast.add({ title: deliveryNoteStore.error, color: 'error' })
  }
}

onMounted(async () => {
  await Promise.all([
    loadSeries(),
    fetchDefaults(),
    clientStore.fetchClients(),
  ])
  clients.value = clientStore.items
  if (!props.deliveryNote) {
    autoSelectFirst(form)
    form.currency = defaultCurrency.value
  }
  if (props.deliveryNote?.client && !clients.value.find(c => c.id === props.deliveryNote!.client!.id)) {
    clients.value = [props.deliveryNote.client, ...clients.value]
  }

  // Pre-fill from source delivery note for copy
  if (props.copyOf && !props.deliveryNote) {
    const source = await deliveryNoteStore.fetchDeliveryNote(props.copyOf)
    if (source) {
      form.clientId = source.client?.id || null
      form.currency = source.currency
      form.notes = source.notes || ''
      form.mentions = source.mentions || ''
      form.deliveryLocation = source.deliveryLocation || ''
      form.projectReference = source.projectReference || ''
      form.issuerName = source.issuerName || ''
      form.issuerId = source.issuerId || ''
      form.salesAgent = source.salesAgent || ''
      form.deputyName = source.deputyName || ''
      form.deputyIdentityCard = source.deputyIdentityCard || ''
      form.deputyAuto = source.deputyAuto || ''
      form.etransportOperationType = (source.etransportOperationType ?? 30) as ETransportOperationType
      form.etransportVehicleNumber = source.etransportVehicleNumber || ''
      form.etransportTrailer1 = source.etransportTrailer1 || ''
      form.etransportTrailer2 = source.etransportTrailer2 || ''
      form.etransportTransporterCountry = source.etransportTransporterCountry || 'RO'
      form.etransportTransporterCode = source.etransportTransporterCode || ''
      form.etransportTransporterName = source.etransportTransporterName || ''
      form.etransportTransportDate = source.etransportTransportDate?.split('T')[0] || ''
      form.etransportStartCounty = (source.etransportStartCounty ?? undefined) as ETransportCounty | undefined
      form.etransportStartLocality = source.etransportStartLocality || ''
      form.etransportStartStreet = source.etransportStartStreet || ''
      form.etransportStartNumber = source.etransportStartNumber || ''
      form.etransportStartOtherInfo = source.etransportStartOtherInfo || ''
      form.etransportStartPostalCode = source.etransportStartPostalCode || ''
      form.etransportEndCounty = (source.etransportEndCounty ?? undefined) as ETransportCounty | undefined
      form.etransportEndLocality = source.etransportEndLocality || ''
      form.etransportEndStreet = source.etransportEndStreet || ''
      form.etransportEndNumber = source.etransportEndNumber || ''
      form.etransportEndOtherInfo = source.etransportEndOtherInfo || ''
      form.etransportEndPostalCode = source.etransportEndPostalCode || ''
      if (source.notes || source.mentions || source.deliveryLocation || source.projectReference) {
        showNotes.value = true
      }
      if (source.deputyName || source.deputyIdentityCard || source.deputyAuto || source.issuerName || source.issuerId || source.salesAgent) {
        showDelegate.value = true
      }
      if (source.etransportVehicleNumber || source.etransportStartCounty || source.etransportUit) {
        showETransport.value = true
      }
      form.lines = source.lines.length > 0
        ? source.lines.map((l: any) => ({
            description: l.description,
            quantity: l.quantity,
            unitOfMeasure: l.unitOfMeasure,
            unitPrice: l.unitPrice,
            vatRate: l.vatRate,
            vatCategoryCode: l.vatCategoryCode || 'S',
            discount: l.discount || '0.00',
            discountPercent: l.discountPercent || '0.00',
            tariffCode: l.tariffCode || '',
            purposeCode: (l.purposeCode ?? 101) as ETransportPurposeCode,
            unitOfMeasureCode: (l.unitOfMeasureCode || 'KGM') as ETransportUomCode,
            netWeight: l.netWeight || '',
            grossWeight: l.grossWeight || '',
            valueWithoutVat: l.valueWithoutVat || '',
          }))
        : [emptyLine()]
      if (source.client && !clients.value.find((c: Client) => c.id === source.client!.id)) {
        clients.value = [source.client, ...clients.value]
      }
    }
  }
})
</script>
