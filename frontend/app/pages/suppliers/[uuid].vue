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
      <div v-if="supplier" class="space-y-6">
        <div class="flex items-center gap-3">
          <UButton icon="i-lucide-arrow-left" variant="ghost" to="/suppliers" />
          <UUser
            :name="supplier.name"
            :description="supplier.cif ? `CIF: ${supplier.cif}` : ''"
            :avatar="{ icon: 'i-lucide-truck' }"
            size="xl"
          />
          <UBadge v-if="supplier.source" color="blue" variant="subtle">
            {{ $t(`common.sources.${supplier.source}`, supplier.source) }}
          </UBadge>
          <div class="ml-auto flex gap-2">
            <UButton icon="i-lucide-pencil" variant="soft" size="sm" :label="$t('common.edit')" @click="editModalOpen = true" />
            <UButton icon="i-lucide-trash-2" variant="soft" color="error" size="sm" :label="$t('common.delete')" @click="deleteConfirmOpen = true" />
          </div>
        </div>

        <UCard>
          <template #header>
            <h3 class="font-semibold">{{ $t('suppliers.details') }}</h3>
          </template>
          <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
              <dt class="text-muted">{{ $t('common.name') }}</dt>
              <dd class="font-medium">{{ supplier.name }}</dd>
            </div>
            <div>
              <dt class="text-muted">{{ $t('suppliers.cif') }}</dt>
              <dd class="font-medium font-mono flex items-center gap-1">
                {{ supplier.cif || '-' }}
                <UButton v-if="supplier.cif" icon="i-lucide-copy" variant="ghost" size="xs" @click="copy(supplier.cif)" />
              </dd>
            </div>
            <div v-if="supplier.vatCode">
              <dt class="text-muted">{{ $t('suppliers.vatCode') }}</dt>
              <dd>{{ supplier.vatCode }}</dd>
            </div>
            <div v-if="supplier.registrationNumber">
              <dt class="text-muted">{{ $t('suppliers.registrationNumber') }}</dt>
              <dd>{{ supplier.registrationNumber }}</dd>
            </div>
            <div v-if="supplier.address">
              <dt class="text-muted">{{ $t('common.address') }}</dt>
              <dd>{{ supplier.address }}</dd>
            </div>
            <div v-if="supplier.city">
              <dt class="text-muted">{{ $t('common.city') }}</dt>
              <dd>{{ supplier.city }}</dd>
            </div>
            <div v-if="supplier.county">
              <dt class="text-muted">{{ $t('common.county') }}</dt>
              <dd>{{ supplier.county }}</dd>
            </div>
            <div v-if="supplier.phone">
              <dt class="text-muted">{{ $t('common.phone') }}</dt>
              <dd>{{ supplier.phone }}</dd>
            </div>
            <div v-if="supplier.email">
              <dt class="text-muted">{{ $t('common.email') }}</dt>
              <dd>{{ supplier.email }}</dd>
            </div>
            <div v-if="supplier.bankName">
              <dt class="text-muted">{{ $t('suppliers.bankName') }}</dt>
              <dd>{{ supplier.bankName }}</dd>
            </div>
            <div v-if="supplier.bankAccount">
              <dt class="text-muted">{{ $t('suppliers.bankAccount') }}</dt>
              <dd class="flex items-center gap-1">
                {{ supplier.bankAccount }}
                <UButton icon="i-lucide-copy" variant="ghost" size="xs" @click="copy(supplier.bankAccount)" />
              </dd>
            </div>
            <div v-if="supplier.notes">
              <dt class="text-muted">{{ $t('suppliers.notes') }}</dt>
              <dd>{{ supplier.notes }}</dd>
            </div>
          </dl>
        </UCard>

        <UCard>
          <template #header>
            <div class="flex items-center justify-between">
              <h3 class="font-semibold">{{ $t('clients.invoiceHistory') }}</h3>
              <span v-if="invoiceTotal" class="text-sm text-(--ui-text-muted)">
                {{ invoiceTotal }} {{ $t('clients.invoicesTotal') }}
              </span>
            </div>
          </template>
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
        </UCard>
      </div>
      <div v-else class="text-center py-20">
        <USkeleton class="h-8 w-64 mx-auto mb-4" />
        <USkeleton class="h-4 w-48 mx-auto" />
      </div>

      <!-- Edit Supplier Modal -->
      <SharedSupplierFormModal v-model:open="editModalOpen" :supplier="supplier" @saved="onSupplierSaved" />

      <!-- Delete Confirm Modal -->
      <UModal v-model:open="deleteConfirmOpen">
        <template #header>
          <h3 class="text-lg font-semibold">{{ $t('suppliers.deleteSupplier') }}</h3>
        </template>
        <template #body>
          <p class="text-sm">{{ $t('suppliers.deleteSupplierConfirm') }}</p>
          <p class="text-sm text-muted mt-2">{{ $t('suppliers.deleteSupplierNote') }}</p>
        </template>
        <template #footer>
          <div class="flex justify-end gap-2">
            <UButton :label="$t('common.cancel')" variant="ghost" @click="deleteConfirmOpen = false" />
            <UButton :label="$t('common.delete')" color="error" :loading="deleteLoading" @click="handleDelete" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
import type { Supplier } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useSupplierStore()
const toast = useToast()
const { copy } = useClipboard()

const supplier = ref<Supplier | null>(null)
const editModalOpen = ref(false)
const deleteConfirmOpen = ref(false)
const deleteLoading = ref(false)
const invoiceHistory = ref<any[]>([])
const invoiceTotal = ref(0)
const invoicePage = ref(1)
const invoiceLimit = PAGINATION.DEFAULT_LIMIT

const invoiceColumns = [
  { accessorKey: 'number', header: $t('invoices.number') },
  { accessorKey: 'issueDate', header: $t('invoices.issueDate') },
  { accessorKey: 'direction', header: $t('invoices.direction') },
  { accessorKey: 'total', header: $t('invoices.total') },
  { accessorKey: 'status', header: $t('invoices.status') },
]

function onInvoiceClick(_e: Event, row: any) {
  router.push(`/invoices/${row.original.id}`)
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

function onSupplierSaved(updated: Supplier) {
  supplier.value = updated
  toast.add({ title: $t('suppliers.supplierUpdated'), color: 'success' })
  fetchData()
}

async function handleDelete() {
  deleteLoading.value = true
  const success = await store.deleteSupplier(route.params.uuid as string)
  deleteLoading.value = false
  if (success) {
    toast.add({ title: $t('suppliers.supplierDeleted'), color: 'success' })
    router.push('/suppliers')
  }
  deleteConfirmOpen.value = false
}

async function fetchData() {
  const { get } = useApi()
  const response = await get<any>(`/v1/suppliers/${route.params.uuid}`, { page: invoicePage.value, limit: invoiceLimit })
  supplier.value = response.supplier
  invoiceHistory.value = response.invoiceHistory || []
  invoiceTotal.value = response.invoiceTotal ?? response.invoiceCount ?? 0
}

watch(invoicePage, () => fetchData())

onMounted(fetchData)
</script>
