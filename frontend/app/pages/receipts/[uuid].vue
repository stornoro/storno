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
      <div v-if="receipt" class="space-y-6">
        <div class="flex flex-col gap-3">
          <!-- Title row -->
          <div class="flex items-center gap-3 min-w-0">
            <UButton icon="i-lucide-arrow-left" variant="ghost" to="/receipts" class="shrink-0" />
            <h1 class="text-2xl font-bold truncate">{{ receipt.number }}</h1>
            <div class="flex items-center gap-1.5 flex-wrap">
              <UBadge :color="statusColor(receipt.status)" variant="subtle">
                {{ $t(`receiptStatus.${receipt.status}`) }}
              </UBadge>
            </div>
          </div>
          <!-- Actions row -->
          <div class="flex flex-wrap items-center gap-2">
            <!-- Primary CTA -->
            <UButton v-if="receipt.status === 'draft'" icon="i-lucide-check-circle" color="primary" :loading="actionLoading" @click="onIssue">
              {{ $t('receipts.issue') }}
            </UButton>
            <UButton v-if="receipt.status === 'issued'" icon="i-lucide-file-output" color="primary" :loading="actionLoading" @click="convertModalOpen = true">
              {{ $t('receipts.convert') }}
            </UButton>
            <UButton v-if="receipt.status === 'invoiced' && receipt.convertedInvoice" icon="i-lucide-external-link" variant="outline" :to="`/invoices/${receipt.convertedInvoice.id}`">
              {{ $t('receipts.viewInvoice') }}
            </UButton>
            <UButton v-if="receipt.status === 'cancelled'" icon="i-lucide-rotate-ccw" variant="outline" :loading="actionLoading" @click="restoreModalOpen = true">
              {{ $t('receipts.restore') }}
            </UButton>
            <UButton v-if="receipt.status === 'issued' || receipt.status === 'invoiced'" icon="i-lucide-mail" variant="outline" @click="emailModalOpen = true">
              {{ $t('receipts.sendEmail') }}
            </UButton>

            <!-- Separator -->
            <div class="w-px h-6 bg-gray-200 dark:bg-gray-700 hidden sm:block" />

            <!-- Documents dropdown -->
            <UDropdownMenu :items="documentMenuItems">
              <UButton icon="i-lucide-file-down" variant="outline" :loading="viewingPdf || downloadingPdf">
                {{ $t('invoices.documents') }}
                <UIcon name="i-lucide-chevron-down" class="size-3.5" />
              </UButton>
            </UDropdownMenu>

            <!-- More actions dropdown -->
            <UDropdownMenu v-if="moreActionsItems.length > 0" :items="moreActionsItems">
              <UButton icon="i-lucide-ellipsis" variant="outline" />
            </UDropdownMenu>
          </div>
        </div>

        <!-- Invoiced banner -->
        <UCard v-if="receipt.status === 'invoiced' && receipt.convertedInvoice" class="border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/30">
          <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 text-sm">
              <UIcon name="i-lucide-file-text" class="text-blue-500 shrink-0 size-5" />
              <span class="text-blue-800 dark:text-blue-200">{{ $t('receipts.convertedInvoice') }}: #{{ receipt.convertedInvoice.number || receipt.convertedInvoice.id }}</span>
            </div>
            <UButton icon="i-lucide-external-link" variant="subtle" color="info" size="sm" :to="`/invoices/${receipt.convertedInvoice.id}`">
              {{ $t('receipts.viewInvoice') }}
            </UButton>
          </div>
        </UCard>

        <!-- Status & meta + Client -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <UCard>
            <template #header>
              <h3 class="font-semibold">{{ $t('receipts.title') }}</h3>
            </template>
            <div class="grid grid-cols-2 md:grid-cols-2 gap-4 text-sm">
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('receipts.number') }}</dt>
                <dd class="font-medium">{{ receipt.number }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('receipts.status') }}</dt>
                <dd>
                  <UBadge :color="statusColor(receipt.status)" variant="subtle" size="sm">
                    {{ $t(`receiptStatus.${receipt.status}`) }}
                  </UBadge>
                </dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('receipts.issueDate') }}</dt>
                <dd class="font-medium">{{ receipt.issueDate ? new Date(receipt.issueDate).toLocaleDateString('ro-RO') : '-' }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('receipts.currency') }}</dt>
                <dd class="font-medium">{{ receipt.currency }}</dd>
              </div>
              <div v-if="receipt.issuedAt">
                <dt class="text-(--ui-text-muted)">{{ $t('receipts.issuedAt') }}</dt>
                <dd class="font-medium">{{ new Date(receipt.issuedAt).toLocaleString('ro-RO') }}</dd>
              </div>
              <div v-if="receipt.cancelledAt">
                <dt class="text-(--ui-text-muted)">{{ $t('receipts.cancelledAt') }}</dt>
                <dd class="font-medium">{{ new Date(receipt.cancelledAt).toLocaleString('ro-RO') }}</dd>
              </div>
            </div>
          </UCard>

          <!-- Client info -->
          <UCard>
            <template #header>
              <div class="flex items-center justify-between">
                <h3 class="font-semibold">{{ $t('receipts.client') }}</h3>
                <UButton v-if="receipt.client" variant="ghost" size="sm" :to="`/clients/${receipt.client.id}`">
                  {{ $t('common.view') }}
                </UButton>
              </div>
            </template>
            <div v-if="receipt.client" class="space-y-2 text-sm">
              <div class="font-medium text-base">{{ receipt.client.name }}</div>
              <div class="text-(--ui-text-muted)">{{ receipt.client.cui || receipt.client.cnp }}</div>
              <div v-if="receipt.client.address" class="text-(--ui-text-muted)">{{ receipt.client.address }}</div>
              <div v-if="receipt.client.email" class="text-(--ui-text-muted)">{{ receipt.client.email }}</div>
            </div>
            <div v-else-if="receipt.customerName || receipt.customerCif" class="space-y-2 text-sm">
              <div v-if="receipt.customerName" class="font-medium text-base">{{ receipt.customerName }}</div>
              <div v-if="receipt.customerCif" class="text-(--ui-text-muted)">{{ $t('receipts.customerCif') }}: {{ receipt.customerCif }}</div>
            </div>
            <div v-else class="text-sm text-(--ui-text-muted)">-</div>
          </UCard>
        </div>

        <!-- Payment info -->
        <UCard v-if="receipt.paymentMethod || receipt.cashRegisterName || receipt.fiscalNumber">
          <template #header>
            <h3 class="font-semibold">{{ $t('receipts.paymentInfo') }}</h3>
          </template>
          <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
            <div v-if="receipt.paymentMethod">
              <dt class="text-(--ui-text-muted)">{{ $t('receipts.paymentMethod') }}</dt>
              <dd class="font-medium">{{ formatPaymentMethod(receipt.paymentMethod) }}</dd>
            </div>
            <div v-if="receipt.cashRegisterName">
              <dt class="text-(--ui-text-muted)">{{ $t('receipts.cashRegisterName') }}</dt>
              <dd class="font-medium">{{ receipt.cashRegisterName }}</dd>
            </div>
            <div v-if="receipt.fiscalNumber">
              <dt class="text-(--ui-text-muted)">{{ $t('receipts.fiscalNumber') }}</dt>
              <dd class="font-medium">{{ receipt.fiscalNumber }}</dd>
            </div>
            <div v-if="receipt.cashPayment && receipt.paymentMethod === 'mixed'">
              <dt class="text-(--ui-text-muted)">{{ $t('receipts.cashPayment') }}</dt>
              <dd class="font-medium">{{ formatMoney(receipt.cashPayment, receipt.currency) }}</dd>
            </div>
            <div v-if="receipt.cardPayment && receipt.paymentMethod === 'mixed'">
              <dt class="text-(--ui-text-muted)">{{ $t('receipts.cardPayment') }}</dt>
              <dd class="font-medium">{{ formatMoney(receipt.cardPayment, receipt.currency) }}</dd>
            </div>
            <div v-if="receipt.otherPayment && receipt.paymentMethod === 'mixed'">
              <dt class="text-(--ui-text-muted)">{{ $t('receipts.otherPayment') }}</dt>
              <dd class="font-medium">{{ formatMoney(receipt.otherPayment, receipt.currency) }}</dd>
            </div>
          </div>
        </UCard>

        <!-- Lines -->
        <UCard>
          <template #header>
            <h3 class="font-semibold">{{ $t('receipts.lines') }}</h3>
          </template>
          <UTable
            :data="receipt.lines"
            :columns="lineColumns"
          >
            <template #quantity-cell="{ row }">
              {{ row.original.quantity }} {{ row.original.unitOfMeasure }}
            </template>
            <template #unitPrice-cell="{ row }">
              {{ formatMoney(row.original.unitPrice, receipt.currency) }}
            </template>
            <template #vatAmount-cell="{ row }">
              {{ formatMoney(row.original.vatAmount, receipt.currency) }} ({{ row.original.vatRate }}%)
            </template>
            <template #lineTotal-cell="{ row }">
              {{ formatMoney(row.original.lineTotal, receipt.currency) }}
            </template>
          </UTable>

          <!-- Totals -->
          <div class="flex justify-end mt-4 pt-4 border-t border-(--ui-border)">
            <div class="space-y-1 text-right">
              <div class="text-sm">{{ $t('invoices.subtotal') }}: <strong>{{ formatMoney(receipt.subtotal, receipt.currency) }}</strong></div>
              <div class="text-sm">TVA: <strong>{{ formatMoney(receipt.vatTotal, receipt.currency) }}</strong></div>
              <div v-if="Number(receipt.discount) > 0" class="text-sm">{{ $t('invoices.discount') }}: <strong>-{{ formatMoney(receipt.discount, receipt.currency) }}</strong></div>
              <div class="text-lg font-bold">{{ $t('receipts.total') }}: {{ formatMoney(receipt.total, receipt.currency) }}</div>
            </div>
          </div>
        </UCard>

        <!-- Notes -->
        <UCard v-if="receipt.notes || receipt.mentions || receipt.projectReference">
          <template #header>
            <h3 class="font-semibold">{{ $t('common.notes') }}</h3>
          </template>
          <div class="space-y-2 text-sm">
            <div v-if="receipt.projectReference">
              <span class="text-(--ui-text-muted)">{{ $t('receipts.projectReference') }}:</span>
              {{ receipt.projectReference }}
            </div>
            <div v-if="receipt.notes">
              <span class="text-(--ui-text-muted)">{{ $t('receipts.notes') }}:</span>
              {{ receipt.notes }}
            </div>
            <div v-if="receipt.mentions">
              <span class="text-(--ui-text-muted)">{{ $t('receipts.mentions') }}:</span>
              {{ receipt.mentions }}
            </div>
          </div>
        </UCard>
      </div>

      <div v-else class="text-center py-20">
        <USkeleton class="h-8 w-64 mx-auto mb-4" />
        <USkeleton class="h-4 w-48 mx-auto" />
      </div>

      <!-- Edit Slideover -->
      <USlideover
        v-model:open="editSlideoverOpen"
        :ui="{ content: 'sm:max-w-2xl' }"
      >
        <template #header>
          <span class="text-lg font-semibold">{{ $t('receipts.editReceipt') }}</span>
        </template>
        <template #body>
          <ReceiptsReceiptForm
            v-if="editSlideoverOpen"
            :receipt="receipt"
            @saved="onUpdated"
            @cancel="editSlideoverOpen = false"
          />
        </template>
      </USlideover>

      <!-- Copy Slideover -->
      <USlideover
        v-model:open="copySlideoverOpen"
        :ui="{ content: 'sm:max-w-2xl' }"
      >
        <template #header>
          <span class="text-lg font-semibold">{{ $t('receipts.copyReceipt') }}</span>
        </template>
        <template #body>
          <ReceiptsReceiptForm
            v-if="copySlideoverOpen"
            :copy-of="uuid"
            @saved="onCopySaved"
            @cancel="copySlideoverOpen = false"
          />
        </template>
      </USlideover>

      <!-- Convert modal -->
      <SharedConfirmModal
        v-model:open="convertModalOpen"
        :title="$t('receipts.convert')"
        :description="$t('receipts.convertConfirmDescription')"
        icon="i-lucide-file-output"
        :confirm-label="$t('receipts.convert')"
        :loading="actionLoading"
        @confirm="onConvert"
      />

      <!-- Restore modal -->
      <SharedConfirmModal
        v-model:open="restoreModalOpen"
        :title="$t('receipts.restore')"
        :description="$t('receipts.restoreConfirmDescription')"
        icon="i-lucide-rotate-ccw"
        :confirm-label="$t('receipts.restore')"
        :loading="actionLoading"
        @confirm="onRestore"
      />

      <!-- Delete modal -->
      <SharedConfirmModal
        v-model:open="deleteModalOpen"
        :title="$t('common.delete')"
        :description="$t('receipts.deleteConfirmDescription')"
        icon="i-lucide-trash-2"
        color="error"
        :confirm-label="$t('common.delete')"
        :loading="actionLoading"
        @confirm="onDelete"
      />

      <!-- Email modal -->
      <ReceiptsReceiptEmailModal
        v-if="receipt && emailModalOpen"
        v-model:open="emailModalOpen"
        :receipt="receipt"
        @sent="onEmailSent"
      />

      <!-- PDF Preview Modal -->
      <UModal
        v-model:open="pdfModalOpen"
        fullscreen
        @after:leave="cleanupPdfPreview"
      >
        <template #content>
          <div class="flex flex-col h-screen">
            <div class="flex items-center justify-between p-4 border-b border-(--ui-border)">
              <h3 class="font-semibold">{{ $t('invoices.viewPdf') }}</h3>
              <div class="flex items-center gap-2">
                <UButton icon="i-lucide-download" variant="ghost" size="sm" @click="downloadPdf()">
                  {{ $t('invoices.downloadPdf') }}
                </UButton>
                <UButton icon="i-lucide-x" variant="ghost" size="sm" @click="pdfModalOpen = false" />
              </div>
            </div>
            <iframe
              v-if="pdfPreviewUrl"
              :src="pdfPreviewUrl"
              class="flex-1 w-full border-0"
            />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
import type { Receipt } from '~/types'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const router = useRouter()
const { t: $t } = useI18n()
const toast = useToast()
const receiptStore = useReceiptStore()

const uuid = route.params.uuid as string

const receipt = ref<Receipt | null>(null)
const loading = ref(true)
const editSlideoverOpen = ref(false)
const copySlideoverOpen = ref(false)
const actionLoading = ref(false)
const convertModalOpen = ref(false)
const restoreModalOpen = ref(false)
const deleteModalOpen = ref(false)
const emailModalOpen = ref(false)
const viewingPdf = ref(false)
const downloadingPdf = ref(false)
const pdfModalOpen = ref(false)
const pdfPreviewUrl = ref<string | null>(null)

useHead({ title: computed(() => receipt.value ? `${$t('receipts.title')} ${receipt.value.number}` : $t('receipts.title')) })

const lineColumns = [
  { accessorKey: 'description', header: $t('invoices.lineDescription') },
  { accessorKey: 'quantity', header: $t('invoices.quantity') },
  { accessorKey: 'unitPrice', header: $t('invoices.unitPrice') },
  { accessorKey: 'vatAmount', header: 'TVA' },
  { accessorKey: 'lineTotal', header: $t('receipts.total') },
]

function formatMoney(amount?: string | number, currency = 'RON') {
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency }).format(Number(amount || 0))
}

function formatPaymentMethod(method: string | null): string {
  if (!method) return '-'
  const methodMap: Record<string, string> = {
    cash: $t('receipts.paymentMethodCash'),
    card: $t('receipts.paymentMethodCard'),
    meal_ticket: $t('receipts.paymentMethodMealTicket'),
    mixed: $t('receipts.paymentMethodMixed'),
  }
  return methodMap[method] || method
}

type BadgeColor = 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'error' | 'neutral'

function statusColor(status: string): BadgeColor {
  const map: Record<string, BadgeColor> = { draft: 'neutral', issued: 'primary', invoiced: 'info', cancelled: 'neutral' }
  return map[status] || 'neutral'
}

const documentMenuItems = computed(() => [
  [
    { label: $t('invoices.viewPdf'), icon: 'i-lucide-eye', onSelect: () => viewPdf() },
    { label: $t('invoices.downloadPdf'), icon: 'i-lucide-download', onSelect: () => downloadPdf() },
  ],
])

const moreActionsItems = computed(() => {
  const items: any[][] = []
  const editGroup: any[] = []
  const dangerGroup: any[] = []

  if (receipt.value?.status === 'draft' || receipt.value?.status === 'issued') {
    editGroup.push({ label: $t('common.edit'), icon: 'i-lucide-pencil', onSelect: () => { editSlideoverOpen.value = true } })
  }
  editGroup.push({ label: $t('common.copy'), icon: 'i-lucide-copy', onSelect: () => { copySlideoverOpen.value = true } })
  items.push(editGroup)

  if (receipt.value?.status === 'issued') {
    dangerGroup.push({ label: $t('receipts.cancel'), icon: 'i-lucide-ban', onSelect: () => onCancel() })
  }
  if (receipt.value?.status === 'draft') {
    dangerGroup.push({ label: $t('common.delete'), icon: 'i-lucide-trash-2', onSelect: () => { deleteModalOpen.value = true } })
  }
  if (dangerGroup.length > 0) items.push(dangerGroup)

  return items
})

function cleanupPdfPreview() {
  if (pdfPreviewUrl.value) {
    URL.revokeObjectURL(pdfPreviewUrl.value)
    pdfPreviewUrl.value = null
  }
}

async function viewPdf() {
  const { apiFetch } = useApi()
  viewingPdf.value = true
  try {
    const blob = await apiFetch<Blob>(`/v1/receipts/${uuid}/pdf`, {
      responseType: 'blob',
    })
    if (pdfPreviewUrl.value) {
      URL.revokeObjectURL(pdfPreviewUrl.value)
    }
    pdfPreviewUrl.value = URL.createObjectURL(blob)
    pdfModalOpen.value = true
  }
  catch {
    toast.add({ title: $t('invoices.pdfError'), color: 'error' })
  }
  finally {
    viewingPdf.value = false
  }
}

async function downloadPdf() {
  const { apiFetch } = useApi()
  downloadingPdf.value = true
  try {
    const blob = await apiFetch<Blob>(`/v1/receipts/${uuid}/pdf`, {
      responseType: 'blob',
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `bon-${receipt.value?.number || 'download'}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  }
  catch {
    toast.add({ title: $t('invoices.pdfError'), color: 'error' })
  }
  finally {
    downloadingPdf.value = false
  }
}

async function fetchReceipt() {
  loading.value = true
  receipt.value = await receiptStore.fetchReceipt(uuid)
  loading.value = false
}

function onUpdated(updated: Receipt) {
  receipt.value = updated
  editSlideoverOpen.value = false
  toast.add({ title: $t('receipts.updateSuccess'), color: 'success', icon: 'i-lucide-check' })
}

function onCopySaved(newReceipt: Receipt) {
  copySlideoverOpen.value = false
  toast.add({ title: $t('receipts.createSuccess'), color: 'success', icon: 'i-lucide-check' })
  router.push(`/receipts/${newReceipt.id}`)
}

async function onIssue() {
  actionLoading.value = true
  const result = await receiptStore.issueReceipt(uuid)
  actionLoading.value = false
  if (result) {
    receipt.value = result
    toast.add({ title: $t('receipts.issueSuccess'), color: 'success', icon: 'i-lucide-check' })
  }
  else {
    toast.add({ title: $t('receipts.issueError'), color: 'error', icon: 'i-lucide-x' })
  }
}

async function onCancel() {
  actionLoading.value = true
  const result = await receiptStore.cancelReceipt(uuid)
  actionLoading.value = false
  if (result) {
    receipt.value = result
    toast.add({ title: $t('receipts.cancelSuccess'), color: 'success', icon: 'i-lucide-check' })
  }
  else {
    toast.add({ title: $t('receipts.cancelError'), color: 'error', icon: 'i-lucide-x' })
  }
}

async function onRestore() {
  actionLoading.value = true
  const result = await receiptStore.restoreReceipt(uuid)
  actionLoading.value = false
  restoreModalOpen.value = false
  if (result) {
    receipt.value = result
    toast.add({ title: $t('receipts.restoreSuccess'), color: 'success', icon: 'i-lucide-check' })
  }
  else {
    toast.add({ title: $t('receipts.restoreError'), color: 'error', icon: 'i-lucide-x' })
  }
}

async function onConvert() {
  actionLoading.value = true
  const invoice = await receiptStore.convertToInvoice(uuid)
  actionLoading.value = false
  convertModalOpen.value = false
  if (invoice) {
    toast.add({ title: $t('receipts.convertSuccess'), color: 'success', icon: 'i-lucide-check' })
    router.push(`/invoices/${invoice.id}`)
  }
  else {
    toast.add({ title: $t('receipts.convertError'), color: 'error', icon: 'i-lucide-x' })
  }
}

async function onDelete() {
  actionLoading.value = true
  const success = await receiptStore.deleteReceipt(uuid)
  actionLoading.value = false
  deleteModalOpen.value = false
  if (success) {
    toast.add({ title: $t('receipts.deleteSuccess'), color: 'success', icon: 'i-lucide-check' })
    router.push('/receipts')
  }
  else {
    toast.add({ title: $t('receipts.deleteError'), color: 'error', icon: 'i-lucide-x' })
  }
}

function onEmailSent() {
  toast.add({ title: $t('receipts.emailSent'), color: 'success', icon: 'i-lucide-mail-check' })
}

onMounted(() => fetchReceipt())
</script>
