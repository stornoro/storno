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
        <div class="ml-auto flex gap-2">
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
              <div class="flex gap-1">
                <UBadge v-if="row.original.paidAt" color="success" variant="subtle" size="sm">
                  {{ $t('documentStatus.paid') }}
                </UBadge>
                <UBadge v-else :color="statusColor(row.original.status)" variant="subtle" size="sm">
                  {{ $t(`documentStatus.${row.original.status}`) }}
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
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
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
  return new Date(date).toLocaleDateString('ro-RO', { year: 'numeric', month: 'short', day: 'numeric' })
}

function formatMoney(amount: string | number, currency = 'RON') {
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency, minimumFractionDigits: 2 }).format(Number(amount))
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

function onDelete() {
  showDeleteModal.value = true
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
})
</script>
