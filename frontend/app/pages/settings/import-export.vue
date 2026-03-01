<script setup lang="ts">
definePageMeta({ middleware: ['auth', 'permissions'] })

const { t: $t } = useI18n()
useHead({ title: $t('importExport.title') })

const importStore = useImportStore()
const companyStore = useCompanyStore()
const toast = useToast()

const wizardOpen = ref(false)
const wizardImportType = ref<string | undefined>(undefined)
const restoreModalOpen = ref(false)

// Migration steps configuration
const migrationSteps = [
  {
    key: 'clients',
    title: $t('importExport.stepImportClients'),
    description: $t('importExport.stepImportClientsDesc'),
    icon: 'i-lucide-users',
    importType: 'clients',
  },
  {
    key: 'products',
    title: $t('importExport.stepImportProducts'),
    description: $t('importExport.stepImportProductsDesc'),
    icon: 'i-lucide-package',
    importType: 'products',
  },
  {
    key: 'invoices',
    title: $t('importExport.stepImportInvoices'),
    description: $t('importExport.stepImportInvoicesDesc'),
    icon: 'i-lucide-file-text',
    importType: 'invoices_issued',
  },
  {
    key: 'recurring_invoices',
    title: $t('importExport.stepImportRecurringInvoices'),
    description: $t('importExport.stepImportRecurringInvoicesDesc'),
    icon: 'i-lucide-repeat',
    importType: 'recurring_invoices',
  },
]

function openWizardForStep(importType: string) {
  wizardImportType.value = importType
  wizardOpen.value = true
}

function openWizardGeneric() {
  wizardImportType.value = undefined
  wizardOpen.value = true
}

// Import history table columns
const historyColumns = [
  { accessorKey: 'createdAt', header: 'Data' },
  { accessorKey: 'source', header: 'Sursa' },
  { accessorKey: 'importType', header: 'Tip' },
  { accessorKey: 'originalFilename', header: 'Fisier' },
  { accessorKey: 'status', header: 'Status' },
  { accessorKey: 'stats', header: 'Rezultat' },
]

const sourceLabels: Record<string, string> = {
  smartbill: 'SmartBill',
  saga: 'SAGA',
  oblio: 'Oblio',
  fgo: 'FGO',
  facturis_online: 'FacturisOnline',
  easybill: 'EasyBill',
  ciel: 'Ciel',
  factureaza: 'Factureaza',
  facturare_pro: 'FacturarePro',
  icefact: 'IceFact',
  bolt: 'Bolt',
  facturis: 'Facturis',
  emag: 'eMag',
  generic: 'Generic',
}

const importTypeLabels: Record<string, string> = {
  clients: 'Clienti',
  products: 'Produse',
  invoices_issued: 'Facturi emise',
  invoices_received: 'Facturi primite',
  recurring_invoices: 'Facturi recurente',
}

const statusColors: Record<string, string> = {
  pending: 'neutral',
  preview: 'info',
  mapping: 'info',
  processing: 'warning',
  completed: 'success',
  failed: 'error',
}

function formatDate(dateStr: string): string {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleDateString('ro-RO', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function handleWizardComplete() {
  importStore.fetchHistory()
  toast.add({
    title: $t('importExport.completed'),
    color: 'success',
    icon: 'i-lucide-check-circle',
  })
}

// ── Accounting Export Modal ────────────────────────────────────────
const accountingExportOpen = ref(false)

// ── e-Factura Export ──────────────────────────────────────────────
const efacturaExportingKey = ref<string | null>(null)

async function runEfacturaExport(direction: 'outgoing' | 'incoming') {
  const { apiFetch } = useApi()
  efacturaExportingKey.value = direction
  try {
    // Default to current month
    const now = new Date()
    const y = now.getFullYear()
    const m = now.getMonth()
    const dateFrom = new Date(y, m, 1).toISOString().slice(0, 10)
    const dateTo = new Date(y, m + 1, 0).toISOString().slice(0, 10)

    const blob = await apiFetch<Blob>('/v1/invoices/export/efactura-zip', {
      method: 'POST',
      body: { direction, dateFrom, dateTo },
      responseType: 'blob',
    })
    const label = direction === 'outgoing' ? 'clienti' : 'furnizori'
    downloadBlob(blob, `efactura-${label}_${dateFrom}.zip`)
  }
  catch (err: any) {
    const msg = err?.data?.error || $t('importExport.exportError')
    toast.add({ title: $t('accountingExport.efacturaTitle'), description: msg, color: 'error' })
  }
  finally {
    efacturaExportingKey.value = null
  }
}

// ── Individual Export ─────────────────────────────────────────────
interface ExportFormat {
  label: string
  url: string
  filename: () => string
}

interface ExportCategory {
  key: string
  label: string
  icon: string
  iconColor: string
  iconBg: string
  formats: ExportFormat[]
}

const today = () => new Date().toISOString().slice(0, 10)

const exportCategories = computed<ExportCategory[]>(() => [
  {
    key: 'clients',
    label: $t('importExport.exportClients'),
    icon: 'i-lucide-users',
    iconColor: 'text-green-600 dark:text-green-400',
    iconBg: 'bg-green-100 dark:bg-green-900/30',
    formats: [
      { label: 'CSV', url: '/v1/clients/export/csv', filename: () => `clienti-${today()}.csv` },
      { label: 'SAGA XML', url: '/v1/clients/export/saga-xml', filename: () => `CLI_${today()}.xml` },
    ],
  },
  {
    key: 'suppliers',
    label: $t('importExport.exportSuppliers'),
    icon: 'i-lucide-building-2',
    iconColor: 'text-purple-600 dark:text-purple-400',
    iconBg: 'bg-purple-100 dark:bg-purple-900/30',
    formats: [
      { label: 'CSV', url: '/v1/suppliers/export/csv', filename: () => `furnizori-${today()}.csv` },
      { label: 'SAGA XML', url: '/v1/suppliers/export/saga-xml', filename: () => `FRN_${today()}.xml` },
    ],
  },
  {
    key: 'products',
    label: $t('importExport.exportProducts'),
    icon: 'i-lucide-package',
    iconColor: 'text-blue-600 dark:text-blue-400',
    iconBg: 'bg-blue-100 dark:bg-blue-900/30',
    formats: [
      { label: 'CSV', url: '/v1/products/export/csv', filename: () => `produse-${today()}.csv` },
      { label: 'SAGA XML', url: '/v1/products/export/saga-xml', filename: () => `ART_${today()}.xml` },
    ],
  },
  {
    key: 'invoices',
    label: $t('importExport.exportInvoices'),
    icon: 'i-lucide-file-text',
    iconColor: 'text-orange-600 dark:text-orange-400',
    iconBg: 'bg-orange-100 dark:bg-orange-900/30',
    formats: [
      { label: 'CSV', url: '/v1/invoices/export/csv', filename: () => `facturi-${today()}.csv` },
      { label: 'SAGA XML', url: '/v1/invoices/export/saga-xml', filename: () => `FCT_${today()}.xml` },
    ],
  },
  {
    key: 'receipts',
    label: $t('importExport.exportReceipts'),
    icon: 'i-lucide-wallet',
    iconColor: 'text-emerald-600 dark:text-emerald-400',
    iconBg: 'bg-emerald-100 dark:bg-emerald-900/30',
    formats: [
      { label: 'SAGA XML', url: '/v1/invoices/export/receipts-saga-xml', filename: () => `INC_${today()}.xml` },
    ],
  },
  {
    key: 'payments',
    label: $t('importExport.exportPayments'),
    icon: 'i-lucide-banknote',
    iconColor: 'text-red-600 dark:text-red-400',
    iconBg: 'bg-red-100 dark:bg-red-900/30',
    formats: [
      { label: 'SAGA XML', url: '/v1/invoices/export/payments-saga-xml', filename: () => `PLT_${today()}.xml` },
    ],
  },
])

const exportingKey = ref<string | null>(null)

async function runExport(categoryKey: string, format: ExportFormat) {
  const { apiFetch } = useApi()
  const key = `${categoryKey}-${format.label}`
  exportingKey.value = key
  try {
    const blob = await apiFetch<Blob>(format.url, {
      method: 'GET',
      responseType: 'blob',
    })
    downloadBlob(blob, format.filename())
  }
  catch {
    toast.add({ title: $t('importExport.exportTitle'), description: $t('importExport.exportError'), color: 'error' })
  }
  finally {
    exportingKey.value = null
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

watch(() => companyStore.currentCompanyId, () => {
  importStore.fetchHistory()
})

onMounted(() => {
  importStore.fetchHistory()
})
</script>

<template>
  <div class="space-y-8">
    <!-- Section 0: Backup & Restore -->
    <BackupBackupSection />

    <!-- Restore button card -->
    <UPageCard variant="subtle">
      <div class="flex items-center gap-4 p-2">
        <div class="w-10 h-10 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center shrink-0">
          <UIcon name="i-lucide-archive-restore" class="w-5 h-5 text-amber-600 dark:text-amber-400" />
        </div>
        <div class="flex-1">
          <p class="text-sm font-medium">{{ $t('backup.restoreTitle') }}</p>
          <p class="text-xs text-(--ui-text-muted)">{{ $t('backup.restoreDescription') }}</p>
        </div>
        <UButton
          :label="$t('backup.restoreButton')"
          icon="i-lucide-archive-restore"
          variant="soft"
          color="warning"
          type="button"
          @click="restoreModalOpen = true"
        />
      </div>
    </UPageCard>

    <!-- Backup History -->
    <BackupBackupHistoryTable />

    <!-- Section 1: Migration Checklist -->
    <UPageCard variant="subtle">
      <!-- Gradient hero header -->
      <div class="rounded-lg bg-gradient-to-r from-primary/10 via-primary/5 to-transparent p-6 mb-6">
        <div class="flex items-start gap-4">
          <div class="w-12 h-12 rounded-xl bg-primary/20 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-arrow-right-left" class="w-6 h-6 text-primary" />
          </div>
          <div>
            <h2 class="text-lg font-semibold">{{ $t('importExport.migrationTitle') }}</h2>
            <p class="text-sm text-(--ui-text-muted) mt-1">{{ $t('importExport.migrationDescription') }}</p>
          </div>
        </div>
      </div>

      <!-- Sequential steps -->
      <div class="space-y-4">
        <div
          v-for="(step, index) in migrationSteps"
          :key="step.key"
          class="flex items-center gap-4 p-4 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600 transition-colors"
        >
          <!-- Step number -->
          <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
            <span class="text-sm font-semibold text-primary">{{ index + 1 }}</span>
          </div>

          <!-- Icon -->
          <div class="w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0">
            <UIcon :name="step.icon" class="w-5 h-5 text-(--ui-text-muted)" />
          </div>

          <!-- Content -->
          <div class="flex-1 min-w-0">
            <h4 class="text-sm font-medium">{{ step.title }}</h4>
            <p class="text-xs text-(--ui-text-muted)">{{ step.description }}</p>
          </div>

          <!-- Action -->
          <UButton
            :label="$t('importExport.importAction')"
            icon="i-lucide-upload"
            size="sm"
            variant="soft"
            @click="openWizardForStep(step.importType)"
          />
        </div>
      </div>

      <!-- Note -->
      <p class="text-xs text-(--ui-text-muted) mt-4 italic">{{ $t('importExport.migrationNote') }}</p>

      <!-- Generic import button -->
      <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
        <UButton
          :label="$t('importExport.startImport')"
          icon="i-lucide-upload"
          @click="openWizardGeneric"
        />
      </div>
    </UPageCard>

    <!-- Section 2: Importa extras + borderou -->
    <UPageCard variant="subtle">
      <div class="rounded-lg bg-gradient-to-r from-amber-500/10 via-amber-500/5 to-transparent p-6 mb-6">
        <div class="flex items-start gap-4">
          <div class="w-12 h-12 rounded-xl bg-amber-500/20 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-receipt" class="w-6 h-6 text-amber-600 dark:text-amber-400" />
          </div>
          <div>
            <h2 class="text-lg font-semibold">{{ $t('borderou.sectionTitle') }}</h2>
            <p class="text-sm text-(--ui-text-muted) mt-1">{{ $t('borderou.sectionDescription') }}</p>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <!-- Courier borderou card -->
        <NuxtLink
          to="/settings/borderou"
          class="flex items-center gap-4 p-4 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-amber-300 dark:hover:border-amber-600 hover:bg-amber-50/50 dark:hover:bg-amber-900/10 transition-colors"
        >
          <div class="w-10 h-10 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-truck" class="w-5 h-5 text-amber-600 dark:text-amber-400" />
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium">{{ $t('borderou.courierCard') }}</p>
            <p class="text-xs text-(--ui-text-muted)">{{ $t('borderou.courierCardDesc') }}</p>
          </div>
          <UIcon name="i-lucide-chevron-right" class="w-4 h-4 text-(--ui-text-muted) shrink-0" />
        </NuxtLink>

        <!-- Bank statement card -->
        <NuxtLink
          to="/settings/extrase-bancare"
          class="flex items-center gap-4 p-4 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-600 hover:bg-blue-50/50 dark:hover:bg-blue-900/10 transition-colors"
        >
          <div class="w-10 h-10 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-landmark" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium">{{ $t('borderou.bankCard') }}</p>
            <p class="text-xs text-(--ui-text-muted)">{{ $t('borderou.bankCardDesc') }}</p>
          </div>
          <UIcon name="i-lucide-chevron-right" class="w-4 h-4 text-(--ui-text-muted) shrink-0" />
        </NuxtLink>

        <!-- Marketplace card -->
        <NuxtLink
          to="/settings/marketplace"
          class="flex items-center gap-4 p-4 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-orange-300 dark:hover:border-orange-600 hover:bg-orange-50/50 dark:hover:bg-orange-900/10 transition-colors"
        >
          <div class="w-10 h-10 rounded-lg bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-store" class="w-5 h-5 text-orange-600 dark:text-orange-400" />
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium">{{ $t('marketplace.card') }}</p>
            <p class="text-xs text-(--ui-text-muted)">{{ $t('marketplace.cardDesc') }}</p>
          </div>
          <UIcon name="i-lucide-chevron-right" class="w-4 h-4 text-(--ui-text-muted) shrink-0" />
        </NuxtLink>
      </div>
    </UPageCard>

    <!-- Section 3: Exporta Rapoarte Contabile (highlighted) -->
    <UPageCard variant="subtle">
      <div class="rounded-lg bg-gradient-to-r from-emerald-500/10 via-emerald-500/5 to-transparent p-6">
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 rounded-xl bg-emerald-500/20 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-file-archive" class="w-6 h-6 text-emerald-600 dark:text-emerald-400" />
          </div>
          <div class="flex-1">
            <h2 class="text-lg font-semibold">{{ $t('accountingExport.title') }}</h2>
            <p class="text-sm text-(--ui-text-muted) mt-0.5">{{ $t('accountingExport.description') }}</p>
          </div>
          <UButton
            :label="$t('accountingExport.exportButton')"
            icon="i-lucide-download"
            size="lg"
            type="button"
            @click="accountingExportOpen = true"
          />
        </div>
      </div>
    </UPageCard>

    <!-- Section 4: e-Factura Export -->
    <UPageCard variant="subtle">
      <div class="mb-4">
        <h3 class="text-base font-semibold">{{ $t('accountingExport.efacturaTitle') }}</h3>
        <p class="text-sm text-(--ui-text-muted) mt-0.5">{{ $t('accountingExport.efacturaDescription') }}</p>
      </div>

      <div class="space-y-3">
        <!-- e-Facturi Clienti -->
        <div class="flex items-center gap-3 p-4 rounded-lg border border-gray-200 dark:border-gray-700">
          <div class="w-10 h-10 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-send" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium">{{ $t('accountingExport.efacturaClientsLabel') }}</p>
          </div>
          <UButton
            :label="$t('accountingExport.efacturaExport')"
            icon="i-lucide-download"
            size="sm"
            variant="soft"
            :loading="efacturaExportingKey === 'outgoing'"
            type="button"
            @click="runEfacturaExport('outgoing')"
          />
        </div>

        <!-- e-Facturi Furnizori -->
        <div class="flex items-center gap-3 p-4 rounded-lg border border-gray-200 dark:border-gray-700">
          <div class="w-10 h-10 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-inbox" class="w-5 h-5 text-purple-600 dark:text-purple-400" />
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium">{{ $t('accountingExport.efacturaSuppliersLabel') }}</p>
          </div>
          <UButton
            :label="$t('accountingExport.efacturaExport')"
            icon="i-lucide-download"
            size="sm"
            variant="soft"
            :loading="efacturaExportingKey === 'incoming'"
            type="button"
            @click="runEfacturaExport('incoming')"
          />
        </div>
      </div>
    </UPageCard>

    <!-- Section 5: Export Individual -->
    <UPageCard
      :title="$t('importExport.exportTitle')"
      :description="$t('importExport.exportDescription')"
      variant="subtle"
    >
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div
          v-for="category in exportCategories"
          :key="category.key"
          class="flex items-center gap-3 p-4 rounded-lg border border-gray-200 dark:border-gray-700"
        >
          <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" :class="category.iconBg">
            <UIcon :name="category.icon" class="w-5 h-5" :class="category.iconColor" />
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium">{{ category.label }}</p>
            <div class="flex flex-wrap gap-1 mt-1">
              <UButton
                v-for="format in category.formats"
                :key="format.label"
                :label="format.label"
                icon="i-lucide-download"
                size="xs"
                variant="soft"
                :color="format.label === 'CSV' ? 'primary' : 'neutral'"
                :loading="exportingKey === `${category.key}-${format.label}`"
                @click="runExport(category.key, format)"
              />
            </div>
          </div>
        </div>
      </div>
    </UPageCard>

    <!-- Section 6: Import History -->
    <UPageCard
      :title="$t('importExport.historyTitle')"
      variant="subtle"
      :ui="{ container: 'p-0 sm:p-0 gap-y-0', wrapper: 'items-stretch' }"
    >
      <UTable
        :data="importStore.history"
        :columns="historyColumns"
        :loading="importStore.loading"
        :ui="{
          base: 'table-fixed',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'px-4',
          td: 'px-4 border-b border-default',
        }"
      >
        <template #createdAt-cell="{ row }">
          <span class="text-sm">{{ formatDate(row.original.createdAt) }}</span>
        </template>

        <template #source-cell="{ row }">
          <span class="text-sm">{{ sourceLabels[row.original.source] || row.original.source }}</span>
        </template>

        <template #importType-cell="{ row }">
          <span class="text-sm">{{ importTypeLabels[row.original.importType] || row.original.importType }}</span>
        </template>

        <template #originalFilename-cell="{ row }">
          <span class="text-sm font-mono truncate max-w-48 block">{{ row.original.originalFilename || '-' }}</span>
        </template>

        <template #status-cell="{ row }">
          <UBadge
            :label="$t(`importExport.status${row.original.status.charAt(0).toUpperCase() + row.original.status.slice(1)}`)"
            :color="(statusColors[row.original.status] as any) || 'neutral'"
            variant="subtle"
            size="sm"
          />
        </template>

        <template #stats-cell="{ row }">
          <div v-if="row.original.status === 'completed'" class="flex items-center gap-2 text-xs">
            <span class="text-green-600">+{{ row.original.createdCount }}</span>
            <span class="text-blue-600">~{{ row.original.updatedCount }}</span>
            <span v-if="row.original.errorCount > 0" class="text-red-600">!{{ row.original.errorCount }}</span>
          </div>
          <span v-else class="text-sm text-gray-400">-</span>
        </template>
      </UTable>

      <UEmpty
        v-if="!importStore.loading && importStore.history.length === 0"
        icon="i-lucide-inbox"
        :title="$t('importExport.noHistory')"
        class="py-12"
      />
    </UPageCard>

    <!-- Modals -->
    <ExportAccountingExportModal v-model:open="accountingExportOpen" />

    <ImportWizard
      :open="wizardOpen"
      :initial-import-type="wizardImportType"
      @update:open="wizardOpen = $event"
      @completed="handleWizardComplete"
    />

    <BackupRestoreModal v-model:open="restoreModalOpen" />
  </div>
</template>
