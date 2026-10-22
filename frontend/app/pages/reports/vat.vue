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

      <UDashboardToolbar class="mb-4">
        <div class="flex flex-wrap items-center gap-2 w-full">
          <h2 class="text-lg font-semibold mr-auto">{{ $t('reports.vatReportTitle') }}</h2>
          <USelectMenu v-model="selectedMonth" :items="monthOptions" value-key="value" class="w-full sm:w-40" />
          <USelectMenu v-model="selectedYear" :items="yearOptions" value-key="value" class="w-full sm:w-28" />
          <UButton icon="i-lucide-refresh-cw" :loading="loading" class="w-full sm:w-auto" @click="fetchReport">
            {{ $t('reports.generate') }}
          </UButton>
        </div>
      </UDashboardToolbar>

      <div v-if="loading" class="text-center py-20">
        <UIcon name="i-lucide-loader-2" class="animate-spin h-8 w-8 mx-auto text-(--ui-text-muted)" />
      </div>

      <div v-else-if="report" class="space-y-6">
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
        <UCard>
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
        <UCard>
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

      <!-- OSS (One Stop Shop) quarterly VAT -->
      <UCard class="mt-6">
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
import type { VatReport, VatOssReport } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('reports.title') })
const companyStore = useCompanyStore()
const { formatMoney } = useMoney()

const now = new Date()
const selectedYear = ref(String(now.getFullYear()))
const selectedMonth = ref(String(now.getMonth() + 1))
const loading = ref(false)
const report = ref<VatReport | null>(null)

const ossYear = ref(String(now.getFullYear()))
const ossQuarter = ref(String(Math.ceil((now.getMonth() + 1) / 3)))
const ossLoading = ref(false)
const oss = ref<VatOssReport | null>(null)

const monthOptions = computed(() =>
  Array.from({ length: 12 }, (_, i) => ({
    label: $t(`reports.months.${i + 1}`),
    value: String(i + 1),
  })),
)

const yearOptions = computed(() => {
  const currentYear = now.getFullYear()
  return Array.from({ length: 5 }, (_, i) => ({
    label: String(currentYear - i),
    value: String(currentYear - i),
  }))
})

const quarterOptions = computed(() =>
  [1, 2, 3, 4].map(n => ({ label: $t('reports.oss.quarterLabel', { n }), value: String(n) })),
)

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

const vatColumns = [
  { accessorKey: 'vatRate', header: $t('reports.vatRate') },
  { accessorKey: 'taxableBase', header: $t('reports.taxableBase') },
  { accessorKey: 'vatAmount', header: $t('reports.vatAmount') },
]

const outgoingRows = computed(() => {
  if (!report.value) return []
  return Object.entries(report.value.outgoing).map(([rate, bucket]) => ({
    vatRate: `${rate}%`,
    taxableBase: formatMoney(bucket.taxableBase),
    vatAmount: formatMoney(bucket.vatAmount),
  }))
})

const incomingRows = computed(() => {
  if (!report.value) return []
  return Object.entries(report.value.incoming).map(([rate, bucket]) => ({
    vatRate: `${rate}%`,
    taxableBase: formatMoney(bucket.taxableBase),
    vatAmount: formatMoney(bucket.vatAmount),
  }))
})

async function fetchReport() {
  const { get } = useApi()
  loading.value = true
  try {
    report.value = await get<VatReport>('/v1/reports/vat', {
      year: selectedYear.value,
      month: selectedMonth.value,
    })
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

watch(() => companyStore.currentCompanyId, () => {
  report.value = null
  oss.value = null
  fetchReport()
  fetchOss()
})

onMounted(() => {
  fetchReport()
  fetchOss()
})
</script>
