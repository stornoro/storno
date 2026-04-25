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
      <UPageHeader :title="$t('reports.cashRegister.title')" :description="$t('reports.cashRegister.description')" />

      <UDashboardToolbar class="mb-4">
        <div class="flex flex-wrap items-center gap-2 w-full">
          <h2 class="text-lg font-semibold mr-auto">{{ $t('reports.cashRegister.ledger') }}</h2>
          <UInput v-model="from" type="date" class="w-full sm:w-44" />
          <UInput v-model="to" type="date" class="w-full sm:w-44" />
          <UButton icon="i-lucide-refresh-cw" :loading="loading" class="w-full sm:w-auto" @click="fetchReport">
            {{ $t('reports.generate') }}
          </UButton>
        </div>
      </UDashboardToolbar>

      <div v-if="loading" class="text-center py-20">
        <UIcon name="i-lucide-loader-2" class="animate-spin h-8 w-8 mx-auto text-(--ui-text-muted)" />
      </div>

      <UAlert
        v-else-if="ledger && !ledger.configured"
        icon="i-lucide-info"
        :title="$t('reports.cashRegister.notConfigured')"
        :description="$t('reports.cashRegister.notConfiguredDesc')"
        color="info"
        variant="subtle"
        :actions="[{ label: $t('reports.cashRegister.goToSettings'), color: 'primary', variant: 'subtle', to: '/settings/bank-accounts' }]"
      />

      <div v-else-if="ledger?.days?.length" class="space-y-4">
        <!-- Summary -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <UCard>
            <div class="text-sm text-(--ui-text-muted)">{{ $t('reports.cashRegister.periodIn') }}</div>
            <div class="text-xl font-bold text-success tabular-nums">+{{ formatMoney(periodTotalIn) }} {{ ledger.currency }}</div>
          </UCard>
          <UCard>
            <div class="text-sm text-(--ui-text-muted)">{{ $t('reports.cashRegister.periodOut') }}</div>
            <div class="text-xl font-bold text-error tabular-nums">−{{ formatMoney(periodTotalOut) }} {{ ledger.currency }}</div>
          </UCard>
          <UCard>
            <div class="text-sm text-(--ui-text-muted)">{{ $t('reports.cashRegister.openingPeriod') }}</div>
            <div class="text-xl font-bold tabular-nums">{{ formatMoney(periodOpening) }} {{ ledger.currency }}</div>
          </UCard>
          <UCard>
            <div class="text-sm text-(--ui-text-muted)">{{ $t('reports.cashRegister.closingPeriod') }}</div>
            <div class="text-xl font-bold tabular-nums" :class="Number(periodClosing) >= 0 ? 'text-(--ui-text)' : 'text-error'">
              {{ formatMoney(periodClosing) }} {{ ledger.currency }}
            </div>
          </UCard>
        </div>

        <!-- Daily ledger -->
        <UCard v-for="day in ledger.days" :key="day.date" :ui="{ body: 'p-0' }">
          <template #header>
            <div class="flex items-center justify-between flex-wrap gap-2">
              <h3 class="font-semibold">{{ formatDate(day.date) }}</h3>
              <div class="flex items-center gap-4 text-xs">
                <span class="text-(--ui-text-muted)">{{ $t('reports.cashRegister.opening') }}:</span>
                <span class="tabular-nums">{{ formatMoney(day.opening) }}</span>
                <span class="text-success tabular-nums">+{{ formatMoney(day.totalIn) }}</span>
                <span class="text-error tabular-nums">−{{ formatMoney(day.totalOut) }}</span>
                <span class="text-(--ui-text-muted)">=</span>
                <span class="font-semibold tabular-nums">{{ formatMoney(day.closing) }}</span>
              </div>
            </div>
          </template>
          <UTable v-if="day.entries.length" :data="day.entries" :columns="entryColumns">
            <template #documentType-cell="{ row }">
              <UBadge :color="row.original.in !== '0.00' ? 'success' : 'error'" variant="subtle" size="xs">
                {{ $t(`reports.cashRegister.docType.${row.original.documentType}`) }}
              </UBadge>
            </template>
            <template #in-cell="{ row }">
              <span v-if="Number(row.original.in) > 0" class="text-success tabular-nums">{{ formatMoney(row.original.in) }}</span>
              <span v-else class="text-(--ui-text-muted)">—</span>
            </template>
            <template #out-cell="{ row }">
              <span v-if="Number(row.original.out) > 0" class="text-error tabular-nums">{{ formatMoney(row.original.out) }}</span>
              <span v-else class="text-(--ui-text-muted)">—</span>
            </template>
            <template #balanceAfter-cell="{ row }">
              <span class="tabular-nums font-medium">{{ formatMoney(row.original.balanceAfter) }}</span>
            </template>
          </UTable>
          <p v-else class="px-4 py-3 text-sm text-(--ui-text-muted)">{{ $t('reports.cashRegister.noEntries') }}</p>
        </UCard>
      </div>

      <UEmpty v-else-if="ledger" icon="i-lucide-inbox" :title="$t('reports.cashRegister.noEntries')" class="py-16" />
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
import type { CashRegisterLedger } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('reports.cashRegister.title') })

const companyStore = useCompanyStore()
const intlLocale = useIntlLocale()

const ledger = ref<CashRegisterLedger | null>(null)
const loading = ref(false)

// Default range: this month so far
const now = new Date()
const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10)
const today = now.toISOString().slice(0, 10)
const from = ref(startOfMonth)
const to = ref(today)

function formatMoney(amount: string | number) {
  return new Intl.NumberFormat(intlLocale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(amount || 0))
}

function formatDate(iso: string) {
  const d = new Date(iso + 'T00:00:00')
  return new Intl.DateTimeFormat(intlLocale, { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' }).format(d)
}

const entryColumns = [
  { accessorKey: 'documentType', header: $t('reports.cashRegister.col.type') },
  { accessorKey: 'documentNumber', header: $t('reports.cashRegister.col.docNumber') },
  { accessorKey: 'description', header: $t('reports.cashRegister.col.description') },
  { accessorKey: 'in', header: $t('reports.cashRegister.col.in') },
  { accessorKey: 'out', header: $t('reports.cashRegister.col.out') },
  { accessorKey: 'balanceAfter', header: $t('reports.cashRegister.col.balance') },
]

const periodOpening = computed(() => ledger.value?.days?.[0]?.opening ?? '0')
const periodClosing = computed(() => {
  const days = ledger.value?.days
  if (!days?.length) return '0'
  return days[days.length - 1]?.closing ?? '0'
})
const periodTotalIn = computed(() =>
  (ledger.value?.days ?? []).reduce((s, d) => s + Number(d.totalIn || 0), 0).toFixed(2),
)
const periodTotalOut = computed(() =>
  (ledger.value?.days ?? []).reduce((s, d) => s + Number(d.totalOut || 0), 0).toFixed(2),
)

async function fetchReport() {
  const { get } = useApi()
  loading.value = true
  try {
    ledger.value = await get<CashRegisterLedger>('/v1/cash-register/ledger', { from: from.value, to: to.value })
  }
  catch {
    ledger.value = null
  }
  finally {
    loading.value = false
  }
}

watch(() => companyStore.currentCompanyId, () => {
  ledger.value = null
  fetchReport()
})

onMounted(fetchReport)
</script>
