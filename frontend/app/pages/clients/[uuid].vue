<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="client" class="space-y-6">
      <div class="flex items-center gap-3">
        <UButton icon="i-lucide-arrow-left" variant="ghost" to="/clients" />
        <UUser
          :name="client.name"
          :description="client.cui ? `CIF: ${client.cui}` : $t('clients.typeIndividual')"
          :avatar="{ icon: 'i-lucide-building-2' }"
          size="xl"
        />
        <UBadge v-if="client.source" color="blue" variant="subtle">
          {{ $t(`common.sources.${client.source}`, client.source) }}
        </UBadge>
        <SharedPartnerStatusBadges :partner="client" size="sm" affiliated />
        <div class="ml-auto flex gap-2">
          <UButton icon="i-lucide-refresh-cw" variant="soft" size="sm" @click="showSyncModal = true">
            {{ $t('clients.syncInvoices') }}
          </UButton>
          <UButton icon="i-lucide-pencil" variant="soft" size="sm" @click="showEditModal = true">
            {{ $t('common.edit') }}
          </UButton>
          <UButton icon="i-lucide-trash-2" variant="soft" color="error" size="sm" @click="onDelete">
            {{ $t('common.delete') }}
          </UButton>
        </div>
      </div>

      <UCard>
        <template #header>
          <h3 class="font-semibold">{{ $t('clients.details') }}</h3>
        </template>
        <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
          <div>
            <dt class="text-muted">{{ $t('common.name') }}</dt>
            <dd class="font-medium">{{ client.name }}</dd>
          </div>
          <div>
            <dt class="text-muted">{{ $t('common.type') }}</dt>
            <dd>{{ client.type === 'company' ? $t('clients.typeCompany') : $t('clients.typeIndividual') }}</dd>
          </div>
          <div v-if="client.type === 'company' || client.cui">
            <dt class="text-muted">CIF</dt>
            <dd class="font-medium font-mono flex items-center gap-1">
              {{ client.cui || '-' }}
              <UButton v-if="client.cui" icon="i-lucide-copy" variant="ghost" size="xs" @click="copy(client.cui)" />
            </dd>
          </div>
          <div v-if="client.type === 'individual' && client.cnp">
            <dt class="text-muted">CNP</dt>
            <dd class="font-medium font-mono flex items-center gap-1">
              {{ client.cnp }}
              <UButton icon="i-lucide-copy" variant="ghost" size="xs" @click="copy(client.cnp)" />
            </dd>
          </div>
          <div v-if="client.vatCode">
            <dt class="text-muted">{{ $t('clients.vatCode') }}</dt>
            <dd class="flex items-center gap-2">
              {{ client.vatCode }}
              <UBadge v-if="client.viesValid === true" color="success" variant="subtle" size="xs">VIES</UBadge>
              <UBadge v-else-if="client.viesValid === false" color="error" variant="subtle" size="xs">VIES</UBadge>
            </dd>
          </div>
          <div v-if="client.viesName">
            <dt class="text-muted">{{ $t('clients.viesName') }}</dt>
            <dd>{{ client.viesName }}</dd>
          </div>
          <div v-if="client.viesValidatedAt">
            <dt class="text-muted">{{ $t('clients.viesValidatedAt') }}</dt>
            <dd>{{ formatDate(client.viesValidatedAt) }}</dd>
          </div>
          <div v-if="client.type === 'company'">
            <dt class="text-muted">{{ $t('clients.isVatPayer') }}</dt>
            <dd>
              <UBadge :color="client.isVatPayer ? 'success' : 'neutral'" variant="subtle" size="sm">
                {{ client.isVatPayer ? $t('common.yes') : $t('common.no') }}
              </UBadge>
            </dd>
          </div>
          <div v-if="client.type === 'company' && client.registrationNumber">
            <dt class="text-muted">{{ $t('clients.registrationNumber') }}</dt>
            <dd>{{ client.registrationNumber }}</dd>
          </div>
          <div>
            <dt class="text-muted">{{ $t('clients.country') }}</dt>
            <dd>{{ countryLabel }}</dd>
          </div>
          <div v-if="client.county">
            <dt class="text-muted">{{ $t('common.county') }}</dt>
            <dd>{{ client.county }}</dd>
          </div>
          <div v-if="client.city">
            <dt class="text-muted">{{ $t('common.city') }}</dt>
            <dd>{{ client.city }}</dd>
          </div>
          <div v-if="client.address">
            <dt class="text-muted">{{ $t('clients.address') }}</dt>
            <dd>{{ client.address }}</dd>
          </div>
          <div v-if="client.postalCode">
            <dt class="text-muted">{{ $t('clients.postalCode') }}</dt>
            <dd>{{ client.postalCode }}</dd>
          </div>
          <div v-if="client.phone">
            <dt class="text-muted">{{ $t('clients.phone') }}</dt>
            <dd>{{ client.phone }}</dd>
          </div>
          <div v-if="client.email">
            <dt class="text-muted">{{ $t('clients.email') }}</dt>
            <dd>{{ client.email }}</dd>
          </div>
          <div v-if="client.bankName">
            <dt class="text-muted">{{ $t('clients.bankName') }}</dt>
            <dd>{{ client.bankName }}</dd>
          </div>
          <div v-if="client.bankAccount">
            <dt class="text-muted">{{ $t('clients.bankAccount') }}</dt>
            <dd class="flex items-center gap-1">
              {{ client.bankAccount }}
              <UButton icon="i-lucide-copy" variant="ghost" size="xs" @click="copy(client.bankAccount)" />
            </dd>
          </div>
          <div v-if="client.idNumber">
            <dt class="text-muted">{{ $t('clients.idNumber') }}</dt>
            <dd>{{ client.idNumber }}</dd>
          </div>
          <div v-if="client.currency">
            <dt class="text-muted">{{ $t('clients.currency') }}</dt>
            <dd>{{ client.currency }}</dd>
          </div>
          <div v-if="client.defaultPaymentTermDays">
            <dt class="text-muted">{{ $t('clients.defaultPaymentTermDays') }}</dt>
            <dd>{{ client.defaultPaymentTermDays }} {{ $t('common.days') }}</dd>
          </div>
          <div v-if="client.notes" class="md:col-span-2">
            <dt class="text-muted">{{ $t('common.notes') }}</dt>
            <dd class="whitespace-pre-wrap">{{ client.notes }}</dd>
          </div>
        </dl>
      </UCard>

      <SharedRelatedCard v-if="client" type="client" :id="String(route.params.uuid)" :exclude="['invoices', 'clients']" />

      <!-- Statement of unpaid invoices -->
      <SharedPartnerVerificationCard :partner="client" kind="client" @verified="onVerified" />

      <UCard>
        <template #header>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h3 class="font-semibold">{{ $t('clients.statement.title') }}</h3>
              <p v-if="statement" class="text-xs text-muted">
                {{ $t('clients.statement.asOf') }} {{ formatDate(statement.asOf) }} &middot; {{ $t('clients.statement.unpaidCount', statement.totals.count) }}
              </p>
            </div>
            <div class="flex items-center gap-2">
              <UButton
                icon="i-lucide-download"
                size="xs"
                variant="soft"
                :loading="statementPdfLoading"
                :disabled="!statement"
                @click="downloadStatementPdf"
              >
                {{ $t('clients.statement.downloadPdf') }}
              </UButton>
              <UButton
                v-if="can(P.INVOICE_SEND)"
                icon="i-lucide-mail"
                size="xs"
                :disabled="!statement || Number(statement.balance) <= 0"
                @click="openStatementEmailModal"
              >
                {{ $t('clients.statement.sendEmail') }}
              </UButton>
            </div>
          </div>
        </template>

        <div v-if="statementLoading && !statement" class="space-y-2">
          <USkeleton class="h-6 w-full" />
          <USkeleton class="h-6 w-2/3" />
        </div>

        <template v-else-if="statement">
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <div class="rounded-lg border border-default p-3">
              <p class="text-xs text-muted">{{ $t('clients.statement.balance') }}</p>
              <p class="text-lg font-semibold">{{ formatMoney(statement.balance, statement.currency) }}</p>
            </div>
            <div class="rounded-lg border border-default p-3">
              <p class="text-xs text-muted">{{ $t('clients.statement.overdue') }}</p>
              <p class="text-lg font-semibold" :class="Number(statement.totals.overdue) > 0 ? 'text-error' : ''">{{ formatMoney(statement.totals.overdue, statement.currency) }}</p>
            </div>
            <div v-if="Number(statement.totals.credits) !== 0" class="rounded-lg border border-default p-3">
              <p class="text-xs text-muted">{{ $t('clients.statement.credits') }}</p>
              <p class="text-lg font-semibold text-success">{{ formatMoney(statement.totals.credits, statement.currency) }}</p>
            </div>
          </div>

          <p class="text-xs font-semibold text-muted uppercase tracking-wide mb-2">{{ $t('clients.statement.aging') }}</p>
          <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2 mb-4">
            <div
              v-for="band in agingBandKeys"
              :key="band"
              class="rounded-md border border-default px-2 py-1.5 text-center"
              :class="Number(statement.aging[band]?.amount) > 0 && band !== 'current' ? 'bg-error/5 border-error/30' : ''"
            >
              <p class="text-[10px] text-muted leading-tight">{{ $t(`clients.statement.bands.${band}`) }}</p>
              <p class="text-sm font-medium">{{ formatMoney(statement.aging[band]?.amount ?? 0, statement.currency) }}</p>
              <p class="text-[10px] text-muted">{{ statement.aging[band]?.count ?? 0 }}</p>
            </div>
          </div>

          <UTable
            v-if="statementInvoices.length"
            :data="statementInvoices"
            :columns="statementColumns"
            class="cursor-pointer"
            @select="onStatementInvoiceClick"
          >
            <template #issueDate-cell="{ row }">{{ row.original.issueDate ? formatDate(row.original.issueDate) : '-' }}</template>
            <template #dueDate-cell="{ row }">{{ row.original.dueDate ? formatDate(row.original.dueDate) : '-' }}</template>
            <template #total-cell="{ row }">{{ formatMoney(row.original.total, row.original.currency) }}</template>
            <template #paid-cell="{ row }">{{ formatMoney(row.original.paid, row.original.currency) }}</template>
            <template #outstanding-cell="{ row }">
              <span class="font-medium" :class="Number(row.original.outstanding) < 0 ? 'text-success' : ''">{{ formatMoney(row.original.outstanding, row.original.currency) }}</span>
            </template>
            <template #daysOverdue-cell="{ row }">
              <UBadge v-if="row.original.daysOverdue > 0" color="error" variant="subtle" size="sm">{{ row.original.daysOverdue }}</UBadge>
              <span v-else class="text-muted">-</span>
            </template>
          </UTable>
          <p v-else class="text-sm text-muted">{{ $t('clients.statement.noUnpaid') }}</p>

          <p v-if="Object.keys(statement.otherCurrencies || {}).length" class="text-xs text-muted mt-2">
            {{ $t('clients.statement.otherCurrencies') }}:
            <span v-for="(info, cur) in statement.otherCurrencies" :key="cur" class="mr-2">{{ formatMoney(info.outstanding, String(cur)) }} ({{ info.count }})</span>
          </p>
        </template>
      </UCard>

      <!-- Documents -->
      <UCard>
        <template #header>
          <div class="flex items-center justify-between">
            <UTabs v-model="activeDocTab" :items="docTabs" size="xs" />
            <UButton
              icon="i-lucide-plus"
              size="xs"
              variant="soft"
              :to="newDocRoute"
            >
              {{ newDocLabel }}
            </UButton>
          </div>
        </template>

        <!-- Invoices tab -->
        <template v-if="activeDocTab === 'invoices'">
          <UTable
            v-if="invoiceHistory.length"
            :data="invoiceHistory"
            :columns="invoiceColumns"
            @select="onInvoiceClick"
          >
            <template #issueDate-cell="{ row }">
              {{ formatDate(row.original.issueDate) }}
            </template>
            <template #direction-cell="{ row }">
              <UBadge
                :color="row.original.direction === 'incoming' ? 'info' : 'success'"
                variant="subtle"
                size="sm"
              >
                {{ row.original.direction === 'incoming' ? $t('invoiceDirection.incoming') : $t('invoiceDirection.outgoing') }}
              </UBadge>
            </template>
            <template #total-cell="{ row }">
              <span class="font-medium">{{ formatMoney(row.original.total, row.original.currency) }}</span>
            </template>
            <template #status-cell="{ row }">
              <div class="flex items-center gap-1">
                <UBadge :color="statusColor(row.original.status)" variant="subtle" size="sm">
                  {{ $t(`documentStatus.${row.original.status}`) }}
                </UBadge>
                <UBadge v-if="row.original.status !== 'cancelled' && row.original.paidAt" color="success" variant="subtle" size="sm">
                  {{ $t('documentStatus.paid') }}
                </UBadge>
              </div>
            </template>
          </UTable>
          <div v-else class="text-center py-8 text-muted">
            {{ $t('clients.noInvoices') }}
          </div>

          <div v-if="invoiceTotal > invoiceLimit" class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-4">
            <span class="text-sm text-muted">
              {{ $t('common.showing') }} {{ invoiceHistory.length }} {{ $t('common.of') }} {{ invoiceTotal }}
            </span>
            <UPagination v-model:page="invoicePage" :total="invoiceTotal" :items-per-page="invoiceLimit" />
          </div>
        </template>

        <!-- Delivery Notes tab -->
        <template v-else-if="activeDocTab === 'deliveryNotes'">
          <UTable
            v-if="deliveryNoteHistory.length"
            :data="deliveryNoteHistory"
            :columns="deliveryNoteColumns"
            @select="onDeliveryNoteClick"
          >
            <template #issueDate-cell="{ row }">
              {{ formatDate(row.original.issueDate) }}
            </template>
            <template #total-cell="{ row }">
              <span class="font-medium">{{ formatMoney(row.original.total, row.original.currency) }}</span>
            </template>
            <template #status-cell="{ row }">
              <UBadge :color="statusColor(row.original.status)" variant="subtle" size="sm">
                {{ $t(`deliveryNoteStatus.${row.original.status}`) }}
              </UBadge>
            </template>
          </UTable>
          <div v-else class="text-center py-8 text-muted">
            {{ $t('deliveryNotes.noDeliveryNotes') }}
          </div>
        </template>

        <!-- Receipts tab -->
        <template v-else-if="activeDocTab === 'receipts'">
          <UTable
            v-if="receiptHistory.length"
            :data="receiptHistory"
            :columns="receiptColumns"
            @select="onReceiptClick"
          >
            <template #issueDate-cell="{ row }">
              {{ formatDate(row.original.issueDate) }}
            </template>
            <template #total-cell="{ row }">
              <span class="font-medium">{{ formatMoney(row.original.total, row.original.currency) }}</span>
            </template>
            <template #status-cell="{ row }">
              <UBadge :color="statusColor(row.original.status)" variant="subtle" size="sm">
                {{ $t(`receiptStatus.${row.original.status}`) }}
              </UBadge>
            </template>
          </UTable>
          <div v-else class="text-center py-8 text-muted">
            {{ $t('receipts.noReceipts') }}
          </div>
        </template>
      </UCard>
    </div>
    <div v-else class="text-center py-20">
      <USkeleton class="h-8 w-64 mx-auto mb-4" />
      <USkeleton class="h-4 w-48 mx-auto" />
    </div>

    <!-- Edit Modal -->
    <SharedClientFormModal
      v-model:open="showEditModal"
      :client="client"
      @saved="onClientSaved"
    />

    <!-- Delete Confirm Modal -->
    <SharedConfirmModal
      v-model:open="showDeleteModal"
      :title="$t('clients.deleteClient')"
      :description="$t('clients.deleteClientConfirm')"
      icon="i-lucide-trash-2"
      color="error"
      :confirm-label="$t('common.delete')"
      :loading="deleting"
      @confirm="confirmDelete"
    >
      <p class="text-sm text-(--ui-text-muted) mt-2">{{ $t('clients.deleteClientNote') }}</p>
    </SharedConfirmModal>

    <!-- Sync Invoices Confirm Modal -->
    <SharedConfirmModal
      v-model:open="showSyncModal"
      :title="$t('clients.syncInvoices')"
      :description="$t('clients.syncInvoicesConfirm')"
      icon="i-lucide-refresh-cw"
      :confirm-label="$t('clients.syncInvoices')"
      :loading="syncing"
      @confirm="confirmSyncInvoices"
    >
      <p class="text-sm text-(--ui-text-muted) mt-2">{{ $t('clients.syncInvoicesNote') }}</p>
    </SharedConfirmModal>

    <!-- Statement e-mail modal -->
    <UModal v-model:open="showStatementEmailModal" :title="$t('clients.statement.emailModalTitle')" :description="$t('clients.statement.emailModalDescription')">
      <template #body>
        <div class="space-y-4">
          <UFormField :label="$t('clients.statement.recipient')" :error="!statementEmailTo ? $t('clients.statement.noEmail') : undefined">
            <UInput v-model="statementEmailTo" type="email" class="w-full" />
          </UFormField>
          <UFormField :label="$t('clients.statement.message')">
            <UTextarea v-model="statementEmailMessage" :rows="4" :placeholder="$t('clients.statement.messagePlaceholder')" class="w-full" />
          </UFormField>
          <p v-if="statement" class="text-sm text-muted">
            {{ $t('clients.statement.unpaidCount', statement.totals.count) }} &middot; {{ $t('clients.statement.balance') }}: <strong>{{ formatMoney(statement.balance, statement.currency) }}</strong>
          </p>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2 w-full">
          <UButton :label="$t('common.cancel')" variant="ghost" @click="showStatementEmailModal = false" />
          <UButton :label="$t('clients.statement.send')" icon="i-lucide-send" :loading="statementEmailSending" :disabled="!statementEmailTo" @click="sendStatementEmail" />
        </div>
      </template>
    </UModal>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const intlLocale = useIntlLocale()
const route = useRoute()
const router = useRouter()
const { copy } = useClipboard()
const clientStore = useClientStore()
const toast = useToast()
const { fetchDefaults, countryOptions } = useInvoiceDefaults()

const client = ref<any>(null)
const invoiceHistory = ref<any[]>([])
const invoiceTotal = ref(0)
const invoicePage = ref(1)
const invoiceLimit = PAGINATION.DEFAULT_LIMIT
const deliveryNoteHistory = ref<any[]>([])
const deliveryNoteTotal = ref(0)
const receiptHistory = ref<any[]>([])
const receiptTotal = ref(0)
const activeDocTab = ref('invoices')
const showEditModal = ref(false)
const showDeleteModal = ref(false)
const deleting = ref(false)
const showSyncModal = ref(false)
const syncing = ref(false)

// ── Statement of unpaid invoices ───────────────────────────────────
const { can } = usePermissions()
const statement = ref<any>(null)
const statementLoading = ref(false)
const statementPdfLoading = ref(false)
const showStatementEmailModal = ref(false)
const statementEmailTo = ref('')
const statementEmailMessage = ref('')
const statementEmailSending = ref(false)
const agingBandKeys = ['current', 'days1_30', 'days31_60', 'days61_90', 'days91_120', 'days121_180', 'over180']
const statementInvoices = computed<any[]>(() => statement.value?.invoices ?? [])
const statementColumns = [
  { accessorKey: 'number', header: $t('clients.statement.columns.number') },
  { accessorKey: 'issueDate', header: $t('clients.statement.columns.issueDate') },
  { accessorKey: 'dueDate', header: $t('clients.statement.columns.dueDate') },
  { accessorKey: 'total', header: $t('clients.statement.columns.total') },
  { accessorKey: 'paid', header: $t('clients.statement.columns.paid') },
  { accessorKey: 'outstanding', header: $t('clients.statement.columns.outstanding') },
  { accessorKey: 'daysOverdue', header: $t('clients.statement.columns.daysOverdue') },
]

async function fetchStatement() {
  statementLoading.value = true
  try {
    const { get } = useApi()
    statement.value = await get<any>(`/v1/clients/${route.params.uuid}/statement`)
  }
  catch {
    toast.add({ title: $t('clients.statement.loadError'), color: 'error' })
  }
  finally {
    statementLoading.value = false
  }
}

function onStatementInvoiceClick(_e: Event, row: any) {
  router.push(`/invoices/${row.original.id}`)
}

async function downloadStatementPdf() {
  const { apiFetch } = useApi()
  statementPdfLoading.value = true
  try {
    const blob = await apiFetch<Blob>(`/v1/clients/${route.params.uuid}/statement.pdf`, { responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `situatie-facturi-${statement.value?.asOf || 'client'}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  }
  catch {
    toast.add({ title: $t('clients.statement.pdfError'), color: 'error' })
  }
  finally {
    statementPdfLoading.value = false
  }
}

function openStatementEmailModal() {
  statementEmailTo.value = client.value?.email || ''
  statementEmailMessage.value = ''
  showStatementEmailModal.value = true
}

async function sendStatementEmail() {
  statementEmailSending.value = true
  try {
    const { post } = useApi()
    await post(`/v1/clients/${route.params.uuid}/statement/email`, {
      to: statementEmailTo.value || undefined,
      message: statementEmailMessage.value || undefined,
    })
    showStatementEmailModal.value = false
    toast.add({ title: $t('clients.statement.sent', { email: statementEmailTo.value }), color: 'success' })
  }
  catch (err: any) {
    toast.add({ title: err?.data?.error || $t('clients.statement.sendError'), color: 'error' })
  }
  finally {
    statementEmailSending.value = false
  }
}

const countryLabel = computed(() => {
  if (!client.value?.country) return ''
  const match = countryOptions.value.find((c: any) => c.value === client.value.country)
  return match ? match.label : client.value.country
})

const docTabs = computed(() => [
  { label: `${$t('clients.invoiceHistory')}${invoiceTotal.value ? ` (${invoiceTotal.value})` : ''}`, value: 'invoices' },
  { label: `${$t('nav.deliveryNotes')}${deliveryNoteTotal.value ? ` (${deliveryNoteTotal.value})` : ''}`, value: 'deliveryNotes' },
  { label: `${$t('nav.receipts')}${receiptTotal.value ? ` (${receiptTotal.value})` : ''}`, value: 'receipts' },
])

const invoiceColumns = [
  { accessorKey: 'number', header: $t('invoices.number') },
  { accessorKey: 'issueDate', header: $t('invoices.issueDate') },
  { accessorKey: 'direction', header: $t('invoices.direction') },
  { accessorKey: 'total', header: $t('invoices.total') },
  { accessorKey: 'status', header: $t('invoices.status') },
]

const deliveryNoteColumns = [
  { accessorKey: 'number', header: $t('invoices.number') },
  { accessorKey: 'issueDate', header: $t('invoices.issueDate') },
  { accessorKey: 'total', header: $t('invoices.total') },
  { accessorKey: 'status', header: $t('invoices.status') },
]

const receiptColumns = [
  { accessorKey: 'number', header: $t('invoices.number') },
  { accessorKey: 'issueDate', header: $t('invoices.issueDate') },
  { accessorKey: 'total', header: $t('invoices.total') },
  { accessorKey: 'status', header: $t('invoices.status') },
]

const newDocRoute = computed(() => {
  const clientParam = client.value?.id ? `&clientId=${client.value.id}` : ''
  switch (activeDocTab.value) {
    case 'deliveryNotes': return `/delivery-notes?create=true${clientParam}`
    case 'receipts': return `/receipts?create=true${clientParam}`
    default: return `/invoices?create=true${clientParam}`
  }
})

const newDocLabel = computed(() => {
  switch (activeDocTab.value) {
    case 'deliveryNotes': return $t('deliveryNotes.newDeliveryNote')
    case 'receipts': return $t('receipts.newReceipt')
    default: return $t('invoices.newInvoice')
  }
})

function onInvoiceClick(_e: Event, row: any) {
  router.push(`/invoices/${row.original.id}`)
}

function onDeliveryNoteClick(_e: Event, row: any) {
  router.push(`/delivery-notes/${row.original.id}`)
}

function onReceiptClick(_e: Event, row: any) {
  router.push(`/receipts/${row.original.id}`)
}

function formatDate(date: string) {
  return new Date(date).toLocaleDateString(intlLocale, { year: 'numeric', month: 'short', day: 'numeric' })
}

function formatMoney(amount: string | number, currency = 'RON') {
  return new Intl.NumberFormat(intlLocale, { style: 'currency', currency, minimumFractionDigits: 2 }).format(Number(amount))
}

function statusColor(status: string) {
  const map: Record<string, string> = {
    paid: 'success', validated: 'success', synced: 'info', issued: 'info',
    overdue: 'error', rejected: 'error', cancelled: 'neutral', draft: 'neutral',
    refund: 'warning', refunded: 'warning', sent_to_provider: 'warning',
  }
  return map[status] ?? 'neutral'
}

async function fetchClientData() {
  const { get } = useApi()
  const response = await get<any>(`/v1/clients/${route.params.uuid}`, { page: invoicePage.value, limit: invoiceLimit })
  client.value = response.client
  invoiceHistory.value = response.invoiceHistory || []
  invoiceTotal.value = response.invoiceTotal ?? response.invoiceCount ?? 0
  deliveryNoteHistory.value = response.deliveryNoteHistory || []
  deliveryNoteTotal.value = response.deliveryNoteCount ?? 0
  receiptHistory.value = response.receiptHistory || []
  receiptTotal.value = response.receiptCount ?? 0
}

watch(invoicePage, () => fetchClientData())

async function onClientSaved() {
  toast.add({ title: $t('clients.clientUpdated'), color: 'success' })
  await fetchClientData()
}

function onVerified(updated: Record<string, any>) {
  client.value = { ...client.value, ...updated }
}

function onDelete() {
  showDeleteModal.value = true
}

async function confirmSyncInvoices() {
  syncing.value = true
  try {
    const { post } = useApi()
    const response = await post<{ invoicesUpdated: number }>(`/v1/clients/${route.params.uuid}/sync-invoices`)
    showSyncModal.value = false
    toast.add({ title: $t('clients.syncInvoicesDone', response.invoicesUpdated ?? 0), color: 'success' })
    await fetchClientData()
  }
  catch {
    toast.add({ title: $t('clients.syncInvoicesError'), color: 'error' })
  }
  finally {
    syncing.value = false
  }
}

async function confirmDelete() {
  deleting.value = true
  const success = await clientStore.deleteClient(route.params.uuid as string)
  deleting.value = false
  if (success) {
    showDeleteModal.value = false
    toast.add({ title: $t('clients.clientDeleted'), color: 'success' })
    router.push('/clients')
  }
  else {
    toast.add({ title: clientStore.error || $t('common.error'), color: 'error' })
  }
}

onMounted(() => {
  fetchDefaults()
  fetchClientData()
  fetchStatement()
})
</script>
