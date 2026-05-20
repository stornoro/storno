<script setup lang="ts">
const { t: $t } = useI18n()
const intlLocale = useIntlLocale()
const { apiFetch, get } = useApi()
const toast = useToast()

const open = defineModel<boolean>('open', { default: false })

// ── Date presets (via composable) ────────────────────────────────
const {
  selectedPreset,
  customDateFrom,
  customDateTo,
  presets,
  resolvedRange,
  isCustom,
} = usePeriodSelector('lastMonth')

function formatDisplayDate(d: string) {
  if (!d) return '...'
  return new Date(d).toLocaleDateString(intlLocale, { day: '2-digit', month: 'short', year: 'numeric' })
}

// ── Target selector ──────────────────────────────────────────────
interface TargetOption {
  label: string
  value: string
  disabled?: boolean
}

const targets = computed<TargetOption[]>(() => [
  { label: $t('accountingExport.targetSaga'), value: 'saga' },
  { label: $t('accountingExport.targetWinmentor') + ' (in curand)', value: 'winmentor', disabled: true },
  { label: $t('accountingExport.targetCiel') + ' (in curand)', value: 'ciel', disabled: true },
])

const selectedTarget = ref<'saga' | 'winmentor' | 'ciel'>('saga')

// ── Per-target options ───────────────────────────────────────────
const sagaOptions = reactive({
  includeDiscount: false,
  exportAccounts: true,
  exportBnr: false,
})

// SAGA chart-of-accounts overrides, editable per export and pre-filled from
// the stored company defaults via /accounting-export/settings.
const sagaAccounts = reactive({
  cash: '',
  bank: '',
  card: '',
  clients: '',
  suppliers: '',
})

interface CurrencyAccountRow {
  currency: string
  cash: string
  bank: string
  card: string
}

const sagaCurrencyRows = ref<CurrencyAccountRow[]>([])

function addCurrencyRow() {
  sagaCurrencyRows.value.push({ currency: '', cash: '', bank: '', card: '' })
}

function removeCurrencyRow(i: number) {
  sagaCurrencyRows.value.splice(i, 1)
}

interface ExportSettingsResponse {
  saga?: Partial<{
    accountCash: string
    accountBank: string
    accountCard: string
    accountClients: string
    accountSuppliers: string
    currencyAccounts: Record<string, Partial<{ cash: string, bank: string, card: string }>>
  }>
}

async function loadSagaAccountDefaults() {
  try {
    const data = await get<ExportSettingsResponse>('/v1/accounting-export/settings')
    const s = data?.saga ?? {}
    sagaAccounts.cash = s.accountCash ?? ''
    sagaAccounts.bank = s.accountBank ?? ''
    sagaAccounts.card = s.accountCard ?? ''
    sagaAccounts.clients = s.accountClients ?? ''
    sagaAccounts.suppliers = s.accountSuppliers ?? ''

    const stored = s.currencyAccounts && typeof s.currencyAccounts === 'object' && !Array.isArray(s.currencyAccounts)
      ? s.currencyAccounts
      : {}
    sagaCurrencyRows.value = Object.entries(stored).map(([currency, accounts]) => ({
      currency,
      cash: accounts?.cash ?? '',
      bank: accounts?.bank ?? '',
      card: accounts?.card ?? '',
    }))
  }
  catch {
    // Stored settings missing; let user fill in at export time.
  }
}

watch(open, (isOpen) => {
  if (isOpen) loadSagaAccountDefaults()
})

const winmentorOptions = reactive({
  includeCancelled: false,
  includeDiscount: false,
  includeSalePrice: false,
  convertCurrency: false,
  uppercasePartners: false,
})

const cielOptions = reactive({
  includeCancelled: false,
  useComma: true,
})

// ── Export action ────────────────────────────────────────────────
const exporting = ref(false)

async function handleExport() {
  const { dateFrom, dateTo } = resolvedRange.value
  if (!dateFrom || !dateTo) {
    toast.add({ title: $t('accountingExport.title'), description: $t('accountingExport.invalidDateRange'), color: 'error' })
    return
  }

  exporting.value = true
  try {
    const currencyAccounts: Record<string, { cash: string, bank: string, card: string }> = {}
    for (const row of sagaCurrencyRows.value) {
      const code = row.currency.trim().toUpperCase()
      if (!code || code === 'RON') continue
      currencyAccounts[code] = {
        cash: row.cash.trim(),
        bank: row.bank.trim(),
        card: row.card.trim(),
      }
    }

    const options: Record<string, unknown> = selectedTarget.value === 'saga'
      ? {
          ...sagaOptions,
          accounts: {
            cash: sagaAccounts.cash.trim(),
            bank: sagaAccounts.bank.trim(),
            card: sagaAccounts.card.trim(),
            clients: sagaAccounts.clients.trim(),
            suppliers: sagaAccounts.suppliers.trim(),
          },
          ...(Object.keys(currencyAccounts).length > 0 ? { currencyAccounts } : {}),
        }
      : selectedTarget.value === 'winmentor'
        ? { ...winmentorOptions }
        : { ...cielOptions }

    const blob = await apiFetch<Blob>('/v1/accounting-export/zip', {
      method: 'POST',
      body: {
        target: selectedTarget.value,
        dateFrom,
        dateTo,
        options,
      },
      responseType: 'blob',
    })

    downloadBlob(blob, `saga-export_${dateFrom}_${dateTo}.zip`)
    toast.add({ title: $t('accountingExport.exportSuccess'), color: 'success', icon: 'i-lucide-check-circle' })
    open.value = false
  }
  catch {
    toast.add({ title: $t('accountingExport.title'), description: $t('accountingExport.exportError'), color: 'error' })
  }
  finally {
    exporting.value = false
  }
}

function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}

// ── Settings sub-modal ───────────────────────────────────────────
const settingsOpen = ref(false)
</script>

<template>
  <UModal v-model:open="open" :ui="{ content: 'sm:max-w-2xl' }">
    <template #header>
      <div>
        <h3 class="text-lg font-semibold">{{ $t('accountingExport.title') }}</h3>
        <p class="text-sm text-(--ui-text-muted) mt-0.5">{{ $t('accountingExport.description') }}</p>
      </div>
    </template>

    <template #body>
      <div class="space-y-6">
        <!-- 1. Interval -->
        <div>
          <label class="block text-sm font-medium mb-2">{{ $t('accountingExport.intervalLabel') }}</label>
          <USelectMenu
            v-model="selectedPreset"
            :items="presets"
            value-key="value"
            class="w-full"
          />

          <!-- Custom date inputs -->
          <div v-if="isCustom" class="grid grid-cols-2 gap-3 mt-3">
            <div>
              <label class="block text-xs text-(--ui-text-muted) mb-1">{{ $t('period.dateFrom') }}</label>
              <UInput v-model="customDateFrom" type="date" />
            </div>
            <div>
              <label class="block text-xs text-(--ui-text-muted) mb-1">{{ $t('period.dateTo') }}</label>
              <UInput v-model="customDateTo" type="date" />
            </div>
          </div>

          <!-- Display resolved range -->
          <p class="text-xs text-(--ui-text-muted) mt-2">
            {{ $t('accountingExport.selectedRange') }}:
            <span class="font-medium text-(--ui-text)">
              {{ formatDisplayDate(resolvedRange.dateFrom) }} — {{ formatDisplayDate(resolvedRange.dateTo) }}
            </span>
          </p>
        </div>

        <!-- 2. Target selector -->
        <div>
          <label class="block text-sm font-medium mb-2">{{ $t('accountingExport.targetLabel') }}</label>
          <USelectMenu
            v-model="selectedTarget"
            :items="targets"
            value-key="value"
            class="w-full"
          />
        </div>

        <!-- 3. Per-target options -->
        <div>
          <!-- Saga options -->
          <div v-if="selectedTarget === 'saga'" class="space-y-3">
            <div class="flex items-center justify-between">
              <span class="text-sm">{{ $t('accountingExport.sagaIncludeDiscount') }}</span>
              <USwitch v-model="sagaOptions.includeDiscount" />
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm">{{ $t('accountingExport.sagaExportAccounts') }}</span>
              <USwitch v-model="sagaOptions.exportAccounts" />
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm">{{ $t('accountingExport.sagaExportBnr') }}</span>
              <USwitch v-model="sagaOptions.exportBnr" />
            </div>

            <div class="pt-3 border-t border-(--ui-border)">
              <p class="text-sm font-medium mb-1">{{ $t('accountingExport.sagaAccountsTitle') }}</p>
              <p class="text-xs text-(--ui-text-muted) mb-3">{{ $t('accountingExport.sagaAccountsHelp') }}</p>
              <div class="grid grid-cols-2 gap-3">
                <UFormField :label="$t('accountingExport.sagaAccountCash')">
                  <UInput v-model="sagaAccounts.cash" placeholder="5311" class="w-full" />
                </UFormField>
                <UFormField :label="$t('accountingExport.sagaAccountBank')">
                  <UInput v-model="sagaAccounts.bank" placeholder="5121" class="w-full" />
                </UFormField>
                <UFormField :label="$t('accountingExport.sagaAccountCard')">
                  <UInput v-model="sagaAccounts.card" placeholder="5125.2" class="w-full" />
                </UFormField>
                <UFormField v-if="sagaOptions.exportAccounts" :label="$t('accountingExport.sagaAccountClients')">
                  <UInput v-model="sagaAccounts.clients" placeholder="4111" class="w-full" />
                </UFormField>
                <UFormField v-if="sagaOptions.exportAccounts" :label="$t('accountingExport.sagaAccountSuppliers')">
                  <UInput v-model="sagaAccounts.suppliers" placeholder="4011" class="w-full" />
                </UFormField>
              </div>
            </div>

            <div class="pt-3 border-t border-(--ui-border)">
              <div class="flex items-center justify-between mb-1">
                <p class="text-sm font-medium">{{ $t('accountingExport.sagaCurrencyAccountsTitle') }}</p>
                <UButton
                  :label="$t('accountingExport.sagaCurrencyAccountsAdd')"
                  icon="i-lucide-plus"
                  size="xs"
                  variant="ghost"
                  type="button"
                  @click="addCurrencyRow"
                />
              </div>
              <p class="text-xs text-(--ui-text-muted) mb-3">{{ $t('accountingExport.sagaCurrencyAccountsHelp') }}</p>
              <div v-for="(row, i) in sagaCurrencyRows" :key="i" class="grid grid-cols-12 gap-2 mb-2 items-end">
                <UFormField :label="$t('accountingExport.sagaCurrencyCode')" class="col-span-2">
                  <UInput v-model="row.currency" placeholder="USD" class="w-full" :maxlength="3" />
                </UFormField>
                <UFormField :label="$t('accountingExport.sagaAccountCash')" class="col-span-3">
                  <UInput v-model="row.cash" placeholder="5314" class="w-full" />
                </UFormField>
                <UFormField :label="$t('accountingExport.sagaAccountBank')" class="col-span-3">
                  <UInput v-model="row.bank" placeholder="5124" class="w-full" />
                </UFormField>
                <UFormField :label="$t('accountingExport.sagaAccountCard')" class="col-span-3">
                  <UInput v-model="row.card" placeholder="5125.1" class="w-full" />
                </UFormField>
                <UButton
                  icon="i-lucide-trash-2"
                  size="xs"
                  variant="ghost"
                  color="error"
                  type="button"
                  class="col-span-1 mb-1"
                  @click="removeCurrencyRow(i)"
                />
              </div>
            </div>
          </div>

          <!-- WinMentor options -->
          <div v-if="selectedTarget === 'winmentor'" class="space-y-3">
            <div class="flex items-center justify-between">
              <span class="text-sm">{{ $t('accountingExport.winmentorIncludeCancelled') }}</span>
              <USwitch v-model="winmentorOptions.includeCancelled" />
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm">{{ $t('accountingExport.winmentorIncludeDiscount') }}</span>
              <USwitch v-model="winmentorOptions.includeDiscount" />
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm">{{ $t('accountingExport.winmentorIncludeSalePrice') }}</span>
              <USwitch v-model="winmentorOptions.includeSalePrice" />
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm">{{ $t('accountingExport.winmentorConvertCurrency') }}</span>
              <USwitch v-model="winmentorOptions.convertCurrency" />
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm">{{ $t('accountingExport.winmentorUppercasePartners') }}</span>
              <USwitch v-model="winmentorOptions.uppercasePartners" />
            </div>
          </div>

          <!-- Ciel options -->
          <div v-if="selectedTarget === 'ciel'" class="space-y-3">
            <div class="flex items-center justify-between">
              <span class="text-sm">{{ $t('accountingExport.cielIncludeCancelled') }}</span>
              <USwitch v-model="cielOptions.includeCancelled" />
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm">{{ $t('accountingExport.cielUseComma') }}</span>
              <USwitch v-model="cielOptions.useComma" />
            </div>
          </div>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex items-center justify-between w-full">
        <!-- Left side -->
        <div class="flex items-center gap-2">
          <span class="text-xs text-(--ui-text-muted)">{{ $t('accountingExport.needHelp') }}</span>
          <UButton
            :label="$t('accountingExport.settingsButton')"
            icon="i-lucide-settings"
            size="xs"
            variant="ghost"
            type="button"
            @click="settingsOpen = true"
          />
        </div>

        <!-- Right side -->
        <div class="flex items-center gap-2">
          <UButton
            :label="$t('accountingExport.cancel')"
            variant="ghost"
            type="button"
            @click="open = false"
          />
          <UButton
            :label="exporting ? $t('accountingExport.exporting') : $t('accountingExport.export')"
            icon="i-lucide-download"
            :loading="exporting"
            type="button"
            @click="handleExport"
          />
        </div>
      </div>
    </template>
  </UModal>

  <!-- Settings sub-modal -->
  <ExportAccountingExportSettingsModal
    v-model:open="settingsOpen"
    :target="selectedTarget"
  />
</template>
