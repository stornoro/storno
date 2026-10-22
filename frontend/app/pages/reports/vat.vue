<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('reports.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UPageHeader :title="$t('reports.title')" :description="$t('reports.description')" />

      <!-- Filters: period mode + the period itself, then the optional filters -->
      <UCard class="mb-4 shrink-0" :ui="{ body: 'space-y-3' }">
        <div class="flex flex-wrap items-end gap-2">
          <h2 class="text-lg font-semibold mr-auto self-center">{{ $t('reports.vatReportTitle') }}</h2>
          <UFormField :label="$t('reports.filters.periodMode')" size="sm">
            <USelectMenu v-model="periodMode" :items="periodModeOptions" value-key="value" class="w-full sm:w-36" />
          </UFormField>
          <UFormField v-if="periodMode === 'month'" :label="$t('reports.month')" size="sm">
            <USelectMenu v-model="selectedMonth" :items="monthOptions" value-key="value" class="w-full sm:w-40" />
          </UFormField>
          <UFormField v-if="periodMode === 'quarter'" :label="$t('reports.oss.quarter')" size="sm">
            <USelectMenu v-model="selectedQuarter" :items="quarterOptions" value-key="value" class="w-full sm:w-28" />
          </UFormField>
          <UFormField v-if="periodMode !== 'range'" :label="$t('reports.year')" size="sm">
            <USelectMenu v-model="selectedYear" :items="yearOptions" value-key="value" class="w-full sm:w-28" />
          </UFormField>
          <UFormField v-if="periodMode === 'range'" :label="$t('period.dateFrom')" size="sm">
            <UInput v-model="rangeFrom" type="date" class="w-full sm:w-40" />
          </UFormField>
          <UFormField v-if="periodMode === 'range'" :label="$t('period.dateTo')" size="sm">
            <UInput v-model="rangeTo" type="date" class="w-full sm:w-40" />
          </UFormField>
          <UButton icon="i-lucide-refresh-cw" :loading="loading" class="w-full sm:w-auto" @click="fetchReport">
            {{ $t('reports.generate') }}
          </UButton>
        </div>

        <div class="flex flex-wrap items-end gap-2">
          <UFormField :label="$t('reports.filters.direction')" size="sm">
            <USelectMenu v-model="filterDirection" :items="directionOptions" value-key="value" class="w-full sm:w-44" />
          </UFormField>
          <UFormField :label="$t('reports.filters.client')" size="sm">
            <USelectMenu
              v-model="filterClientId"
              :items="clientOptions"
              value-key="value"
              :search-input="{ placeholder: $t('common.search') }"
              class="w-full sm:w-56"
              @update:open="loadPartners"
            />
          </UFormField>
          <UFormField :label="$t('reports.filters.supplier')" size="sm">
            <USelectMenu
              v-model="filterSupplierId"
              :items="supplierOptions"
              value-key="value"
              :search-input="{ placeholder: $t('common.search') }"
              class="w-full sm:w-56"
              @update:open="loadPartners"
            />
          </UFormField>
          <UFormField :label="$t('reports.filters.currency')" size="sm">
            <USelectMenu v-model="filterCurrency" :items="currencyOptions" value-key="value" class="w-full sm:w-28" />
          </UFormField>
          <UFormField :label="$t('reports.vatRate')" size="sm">
            <USelectMenu v-model="filterVatRate" :items="vatRateOptions" value-key="value" class="w-full sm:w-28" />
          </UFormField>
          <UFormField :label="$t('reports.filters.documentType')" size="sm">
            <USelectMenu v-model="filterDocumentType" :items="documentTypeOptions" value-key="value" class="w-full sm:w-40" />
          </UFormField>
          <UFormField :label="$t('reports.filters.status')" size="sm">
            <USelectMenu v-model="filterFinalOnly" :items="statusOptions" value-key="value" class="w-full sm:w-52" />
          </UFormField>
          <UButton v-if="activeFilterCount" variant="ghost" color="neutral" icon="i-lucide-x" class="w-full sm:w-auto" @click="resetFilters">
            {{ $t('reports.filters.reset') }}
          </UButton>
        </div>
      </UCard>

      <div v-if="loading" class="text-center py-20">
        <UIcon name="i-lucide-loader-2" class="animate-spin h-8 w-8 mx-auto text-(--ui-text-muted)" />
      </div>

      <div v-else-if="report" class="space-y-6">
        <div class="text-sm text-(--ui-text-muted)">
          {{ $t('reports.filters.showing', { from: formatDate(report.from), to: formatDate(report.to) }) }}
          <span v-if="report.currencies.length > 1" class="ml-2">· {{ $t('reports.filters.mixedCurrencies', { currencies: report.currencies.join(', ') }) }}</span>
        </div>

        <!-- Summary cards -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
          <UCard>
            <div class="text-sm text-(--ui-text-muted)">{{ $t('reports.totalOutgoingVat') }}</div>
            <div class="text-xl font-bold text-green-600">{{ formatMoney(report.totals.outgoing.vatAmount) }}</div>
          </UCard>
          <UCard>
            <div class="text-sm text-(--ui-text-muted)">{{ $t('reports.totalIncomingVat') }}</div>
            <div class="text-xl font-bold text-blue-600">{{ formatMoney(report.totals.incoming.vatAmount) }}</div>
          </UCard>
          <UCard>
            <div class="text-sm text-(--ui-text-muted)">{{ $t('reports.netVat') }}</div>
            <div class="text-xl font-bold" :class="Number(report.netVat) >= 0 ? 'text-red-600' : 'text-green-600'">
              {{ formatMoney(report.netVat) }}
            </div>
          </UCard>
          <UCard>
            <div class="text-sm text-(--ui-text-muted)">{{ $t('reports.invoiceCount') }} ({{ $t('common.outgoing') }})</div>
            <div class="text-xl font-bold">{{ report.invoiceCount.outgoing }}</div>
          </UCard>
          <UCard>
            <div class="text-sm text-(--ui-text-muted)">{{ $t('reports.invoiceCount') }} ({{ $t('common.incoming') }})</div>
            <div class="text-xl font-bold">{{ report.invoiceCount.incoming }}</div>
          </UCard>
        </div>

        <!-- Outgoing VAT table -->
        <UCard v-if="filterDirection !== 'incoming'">
          <template #header>
            <h3 class="font-semibold">{{ $t('reports.outgoing') }}</h3>
          </template>
          <UTable :data="outgoingRows" :columns="vatColumns" />
          <div class="flex justify-end mt-3 text-sm font-semibold">
            <span class="mr-4">{{ $t('reports.taxableBase') }}: {{ formatMoney(report.totals.outgoing.taxableBase) }}</span>
            <span>TVA: {{ formatMoney(report.totals.outgoing.vatAmount) }}</span>
            <span class="ml-4">{{ $t('common.total') }}: {{ formatMoney(report.totals.outgoing.total) }}</span>
          </div>
        </UCard>

        <!-- Incoming VAT table -->
        <UCard v-if="filterDirection !== 'outgoing'">
          <template #header>
            <h3 class="font-semibold">{{ $t('reports.incoming') }}</h3>
          </template>
          <UTable :data="incomingRows" :columns="vatColumns" />
          <div class="flex justify-end mt-3 text-sm font-semibold">
            <span class="mr-4">{{ $t('reports.taxableBase') }}: {{ formatMoney(report.totals.incoming.taxableBase) }}</span>
            <span>TVA: {{ formatMoney(report.totals.incoming.vatAmount) }}</span>
            <span class="ml-4">{{ $t('common.total') }}: {{ formatMoney(report.totals.incoming.total) }}</span>
          </div>
        </UCard>
      </div>

      <div v-else class="text-center py-20 text-(--ui-text-muted)">
        {{ $t('reports.noData') }}
      </div>

      <!-- OSS (One Stop Shop) quarterly VAT — shrink-0: a card placed directly in the panel body is otherwise flex-squeezed to its header -->
      <UCard class="mt-6 shrink-0">
        <template #header>
          <div class="flex flex-wrap items-center gap-2">
            <div class="mr-auto">
              <h3 class="font-semibold">{{ $t('reports.oss.title') }}</h3>
              <p class="text-sm text-(--ui-text-muted)">{{ $t('reports.oss.description') }}</p>
            </div>
            <USelectMenu v-model="ossQuarter" :items="quarterOptions" value-key="value" class="w-full sm:w-28" />
            <USelectMenu v-model="ossYear" :items="yearOptions" value-key="value" class="w-full sm:w-28" />
            <UButton icon="i-lucide-refresh-cw" :loading="ossLoading" variant="soft" class="w-full sm:w-auto" @click="fetchOss">
              {{ $t('reports.generate') }}
            </UButton>
          </div>
        </template>

        <div v-if="ossLoading" class="text-center py-10">
          <UIcon name="i-lucide-loader-2" class="animate-spin h-6 w-6 mx-auto text-(--ui-text-muted)" />
        </div>

        <div v-else-if="oss" class="space-y-4">
          <UAlert v-if="!oss.ossEnabled" color="neutral" variant="soft" icon="i-lucide-info" :description="$t('reports.oss.notEnabled')" />
          <UAlert v-if="oss.exchange.missing.length" color="warning" variant="soft" icon="i-lucide-alert-triangle" :description="$t('reports.oss.rateMissing', { currencies: oss.exchange.missing.join(', ') })" />

          <div v-if="oss.countries.length" class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <UCard>
              <div class="text-sm text-(--ui-text-muted)">{{ $t('reports.oss.totalVatEur') }}</div>
              <div class="text-xl font-bold text-red-600">{{ formatEur(oss.totals.vatAmountEur) }}</div>
            </UCard>
            <UCard>
              <div class="text-sm text-(--ui-text-muted)">{{ $t('reports.oss.baseEur') }}</div>
              <div class="text-xl font-bold">{{ formatEur(oss.totals.taxableBaseEur) }}</div>
            </UCard>
            <UCard>
              <div class="text-sm text-(--ui-text-muted)">{{ $t('reports.oss.invoices') }}</div>
              <div class="text-xl font-bold">{{ oss.totals.invoiceCount }}</div>
            </UCard>
            <UCard>
              <div class="text-sm text-(--ui-text-muted)">{{ $t('reports.oss.dueDate') }}</div>
              <div class="text-xl font-bold">{{ formatDate(oss.payment.dueDate) }}</div>
            </UCard>
          </div>

          <UTable v-if="oss.countries.length" :data="ossRows" :columns="ossColumns" />

          <div v-else class="text-center py-8 text-(--ui-text-muted)">
            {{ $t('reports.oss.noData') }}
          </div>

          <div v-if="oss.countries.length" class="text-sm text-(--ui-text-muted) space-y-1">
            <div v-if="oss.exchange.date">
              {{ $t('reports.oss.exchangeRate', { date: formatDate(oss.exchange.date) }) }}:
              <span v-for="(r, cur) in oss.exchange.rates" :key="cur" class="ml-2">1 EUR = {{ r.unitsPerEur }} {{ cur }}</span>
            </div>
            <div>{{ $t('reports.oss.reference') }}: <span class="font-mono font-semibold text-(--ui-text)">{{ oss.payment.reference }}</span></div>
            <div>{{ $t('reports.oss.payTo') }} <span class="font-mono">{{ oss.payment.iban }}</span> ({{ oss.payment.bic }}, {{ oss.payment.bank }}), {{ oss.payment.beneficiary }}, CIF {{ oss.payment.beneficiaryCif }}.</div>
          </div>
        </div>
      </UCard>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
import type { VatReport, VatOssReport, Client, Supplier } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('reports.title') })
const companyStore = useCompanyStore()
const { formatMoney } = useMoney()

type PeriodMode = 'month' | 'quarter' | 'year' | 'range'

const now = new Date()
const periodMode = ref<PeriodMode>('month')
const selectedYear = ref(String(now.getFullYear()))
const selectedMonth = ref(String(now.getMonth() + 1))
const selectedQuarter = ref(String(Math.ceil((now.getMonth() + 1) / 3)))
const rangeFrom = ref('')
const rangeTo = ref('')

const filterDirection = ref<'' | 'outgoing' | 'incoming'>('')
const filterClientId = ref('')
const filterSupplierId = ref('')
const filterCurrency = ref('')
const filterVatRate = ref('')
const filterDocumentType = ref<'' | 'invoice' | 'credit_note'>('')
const filterFinalOnly = ref<'all' | 'final'>('all')

const loading = ref(false)
const report = ref<VatReport | null>(null)

const ossYear = ref(String(now.getFullYear()))
const ossQuarter = ref(String(Math.ceil((now.getMonth() + 1) / 3)))
const ossLoading = ref(false)
const oss = ref<VatOssReport | null>(null)

const periodModeOptions = computed(() => [
  { label: $t('reports.filters.modeMonth'), value: 'month' },
  { label: $t('reports.filters.modeQuarter'), value: 'quarter' },
  { label: $t('reports.filters.modeYear'), value: 'year' },
  { label: $t('reports.filters.modeRange'), value: 'range' },
])

const monthOptions = computed(() =>
  Array.from({ length: 12 }, (_, i) => ({
    label: $t(`reports.months.${i + 1}`),
    value: String(i + 1),
  })),
)

const quarterOptions = computed(() =>
  [1, 2, 3, 4].map(n => ({ label: $t('reports.oss.quarterLabel', { n }), value: String(n) })),
)

const yearOptions = computed(() => {
  const currentYear = now.getFullYear()
  return Array.from({ length: 5 }, (_, i) => ({
    label: String(currentYear - i),
    value: String(currentYear - i),
  }))
})

const directionOptions = computed(() => [
  { label: $t('reports.filters.bothDirections'), value: '' },
  { label: $t('common.outgoing'), value: 'outgoing' },
  { label: $t('common.incoming'), value: 'incoming' },
])

const currencyOptions = computed(() => {
  const fromReport = report.value?.currencies ?? []
  const codes = Array.from(new Set(['RON', 'EUR', 'USD', 'GBP', ...fromReport])).sort()
  return [{ label: $t('common.all'), value: '' }, ...codes.map(c => ({ label: c, value: c }))]
})

const vatRateOptions = computed(() => [
  { label: $t('common.all'), value: '' },
  ...['21', '19', '11', '9', '5', '0'].map(r => ({ label: `${r}%`, value: r })),
])

const documentTypeOptions = computed(() => [
  { label: $t('common.all'), value: '' },
  { label: $t('reports.filters.invoices'), value: 'invoice' },
  { label: $t('reports.filters.creditNotes'), value: 'credit_note' },
])

const statusOptions = computed(() => [
  { label: $t('reports.filters.statusAll'), value: 'all' },
  { label: $t('reports.filters.statusFinal'), value: 'final' },
])

// Partners for the client / supplier filters, loaded once when a picker opens
const clients = ref<Client[]>([])
const suppliers = ref<Supplier[]>([])
let partnersLoaded = false
async function loadPartners(open?: boolean) {
  if (open === false || partnersLoaded) return
  partnersLoaded = true
  const { get } = useApi()
  try {
    const [c, s] = await Promise.all([
      get<{ data: Client[] }>('/v1/clients', { limit: 200, sort: 'name' }),
      get<{ data: Supplier[] }>('/v1/suppliers', { limit: 200, sort: 'name' }),
    ])
    clients.value = c.data ?? []
    suppliers.value = s.data ?? []
  }
  catch {
    partnersLoaded = false
  }
}
const clientOptions = computed(() => [
  { label: $t('common.all'), value: '' },
  ...clients.value.map(c => ({ label: c.name, value: c.id })),
])
const supplierOptions = computed(() => [
  { label: $t('common.all'), value: '' },
  ...suppliers.value.map(s => ({ label: s.name, value: s.id })),
])

const activeFilterCount = computed(() =>
  [filterDirection.value, filterClientId.value, filterSupplierId.value, filterCurrency.value, filterVatRate.value, filterDocumentType.value].filter(Boolean).length
  + (filterFinalOnly.value === 'final' ? 1 : 0),
)

function resetFilters() {
  filterDirection.value = ''
  filterClientId.value = ''
  filterSupplierId.value = ''
  filterCurrency.value = ''
  filterVatRate.value = ''
  filterDocumentType.value = ''
  filterFinalOnly.value = 'all'
}

const vatColumns = [
  { accessorKey: 'vatRate', header: $t('reports.vatRate') },
  { accessorKey: 'taxableBase', header: $t('reports.taxableBase') },
  { accessorKey: 'vatAmount', header: $t('reports.vatAmount') },
]

const ossColumns = [
  { accessorKey: 'country', header: $t('reports.oss.country') },
  { accessorKey: 'vatRate', header: $t('reports.vatRate') },
  { accessorKey: 'taxableBase', header: $t('reports.taxableBase') },
  { accessorKey: 'vatAmount', header: $t('reports.vatAmount') },
  { accessorKey: 'taxableBaseEur', header: $t('reports.oss.baseEur') },
  { accessorKey: 'vatAmountEur', header: $t('reports.oss.vatEur') },
  { accessorKey: 'invoices', header: $t('reports.oss.invoices') },
]

const eurFormatter = new Intl.NumberFormat('ro-RO', { style: 'currency', currency: 'EUR' })
function formatEur(value: string | null | undefined): string {
  return value == null ? '—' : eurFormatter.format(Number(value))
}
function formatDate(value: string): string {
  return new Date(value).toLocaleDateString('ro-RO')
}

function bucketRows(buckets: Record<string, { taxableBase: string, vatAmount: string }>) {
  return Object.entries(buckets)
    .filter(([rate, bucket]) => !filterVatRate.value || Number(rate) === Number(filterVatRate.value) || Number(bucket.taxableBase) !== 0)
    .map(([rate, bucket]) => ({
      vatRate: `${rate}%`,
      taxableBase: formatMoney(bucket.taxableBase),
      vatAmount: formatMoney(bucket.vatAmount),
    }))
}
const outgoingRows = computed(() => report.value ? bucketRows(report.value.outgoing) : [])
const incomingRows = computed(() => report.value ? bucketRows(report.value.incoming) : [])

const ossRows = computed(() => {
  if (!oss.value) return []
  return oss.value.countries.flatMap(c => c.rates.map((r, i) => ({
    country: i === 0 ? c.country : '',
    vatRate: `${r.vatRate}%`,
    taxableBase: `${r.taxableBase} ${r.currency}`,
    vatAmount: `${r.vatAmount} ${r.currency}`,
    taxableBaseEur: formatEur(r.taxableBaseEur),
    vatAmountEur: formatEur(r.vatAmountEur),
    invoices: i === 0 ? c.invoiceCount : '',
  })))
})

function periodParams(): Record<string, string> | null {
  switch (periodMode.value) {
    case 'month':
      return { year: selectedYear.value, month: selectedMonth.value }
    case 'quarter':
      return { year: selectedYear.value, quarter: selectedQuarter.value }
    case 'year':
      return { year: selectedYear.value, period: 'year' }
    case 'range':
      if (!rangeFrom.value || !rangeTo.value || rangeFrom.value > rangeTo.value) return null
      return { from: rangeFrom.value, to: rangeTo.value }
  }
}

async function fetchReport() {
  const period = periodParams()
  if (!period) return
  const params: Record<string, string> = { ...period }
  if (filterDirection.value) params.direction = filterDirection.value
  if (filterClientId.value) params.clientId = filterClientId.value
  if (filterSupplierId.value) params.supplierId = filterSupplierId.value
  if (filterCurrency.value) params.currency = filterCurrency.value
  if (filterVatRate.value) params.vatRate = filterVatRate.value
  if (filterDocumentType.value) params.documentType = filterDocumentType.value
  if (filterFinalOnly.value === 'final') params.finalOnly = '1'

  const { get } = useApi()
  loading.value = true
  try {
    report.value = await get<VatReport>('/v1/reports/vat', params)
  }
  catch {
    report.value = null
  }
  finally {
    loading.value = false
  }
}

async function fetchOss() {
  const { get } = useApi()
  ossLoading.value = true
  try {
    oss.value = await get<VatOssReport>('/v1/reports/vat/oss', {
      year: ossYear.value,
      quarter: ossQuarter.value,
    })
  }
  catch {
    oss.value = null
  }
  finally {
    ossLoading.value = false
  }
}

// Filters and the period selects apply as soon as they change; a date range applies on "Generate"
watch([filterDirection, filterClientId, filterSupplierId, filterCurrency, filterVatRate, filterDocumentType, filterFinalOnly], () => fetchReport())
watch([periodMode, selectedYear, selectedMonth, selectedQuarter], () => fetchReport())

watch(() => companyStore.currentCompanyId, () => {
  report.value = null
  oss.value = null
  partnersLoaded = false
  clients.value = []
  suppliers.value = []
  fetchReport()
  fetchOss()
})

onMounted(() => {
  fetchReport()
  fetchOss()
})
</script>
