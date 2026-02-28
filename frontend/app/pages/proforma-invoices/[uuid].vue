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
      <div v-if="proforma" class="space-y-6">
        <div class="flex flex-col gap-3">
          <!-- Title row -->
          <div class="flex items-center gap-3 min-w-0">
            <UButton icon="i-lucide-arrow-left" variant="ghost" to="/proforma-invoices" class="shrink-0" />
            <h1 class="text-2xl font-bold truncate">{{ proforma.number }}</h1>
            <div class="flex items-center gap-1.5 flex-wrap">
              <UBadge :color="statusColor(proforma.status)" variant="subtle">
                {{ $t(`proformaStatus.${proforma.status}`) }}
              </UBadge>
            </div>
          </div>
          <!-- Actions row -->
          <div class="flex flex-wrap items-center gap-2">
            <!-- Primary CTA -->
            <UButton v-if="proforma.status === 'draft'" icon="i-lucide-send" color="primary" :loading="actionLoading" @click="onSend">
              {{ $t('proformaInvoices.send') }}
            </UButton>
            <UButton v-if="proforma.status === 'sent'" icon="i-lucide-check" color="success" :loading="actionLoading" @click="onAccept">
              {{ $t('proformaInvoices.accept') }}
            </UButton>
            <UButton v-if="proforma.status === 'sent' || proforma.status === 'accepted'" icon="i-lucide-file-output" color="primary" :loading="actionLoading" @click="convertModalOpen = true">
              {{ $t('proformaInvoices.convert') }}
            </UButton>
            <UButton v-if="proforma.status === 'converted' && proforma.convertedInvoice" icon="i-lucide-external-link" variant="outline" :to="`/invoices/${proforma.convertedInvoice.id}`">
              {{ $t('proformaInvoices.viewInvoice') }}
            </UButton>
            <UButton v-if="proforma.status === 'sent' || proforma.status === 'accepted'" icon="i-lucide-package" variant="outline" :loading="createDeliveryNoteLoading" @click="onCreateDeliveryNote">
              {{ $t('proformaInvoices.createDeliveryNote') }}
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

        <!-- Converted invoice banner -->
        <UCard v-if="proforma.status === 'converted' && proforma.convertedInvoice" class="border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/30">
          <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 text-sm">
              <UIcon name="i-lucide-file-text" class="text-blue-500 shrink-0 size-5" />
              <span class="text-blue-800 dark:text-blue-200">{{ $t('proformaInvoices.convertedInvoice') }}: #{{ proforma.convertedInvoice.number || proforma.convertedInvoice.id }}</span>
            </div>
            <UButton icon="i-lucide-external-link" variant="subtle" color="info" size="sm" :to="`/invoices/${proforma.convertedInvoice.id}`">
              {{ $t('proformaInvoices.viewInvoice') }}
            </UButton>
          </div>
        </UCard>

        <!-- Status & meta + Client -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <UCard>
            <template #header>
              <h3 class="font-semibold">{{ $t('proformaInvoices.title') }}</h3>
            </template>
            <div class="grid grid-cols-2 md:grid-cols-2 gap-4 text-sm">
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('proformaInvoices.number') }}</dt>
                <dd class="font-medium">{{ proforma.number }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('proformaInvoices.status') }}</dt>
                <dd>
                  <UBadge :color="statusColor(proforma.status)" variant="subtle" size="sm">
                    {{ $t(`proformaStatus.${proforma.status}`) }}
                  </UBadge>
                </dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('proformaInvoices.issueDate') }}</dt>
                <dd class="font-medium">{{ proforma.issueDate ? new Date(proforma.issueDate).toLocaleDateString('ro-RO') : '-' }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('proformaInvoices.dueDate') }}</dt>
                <dd class="font-medium">{{ proforma.dueDate ? new Date(proforma.dueDate).toLocaleDateString('ro-RO') : '-' }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('proformaInvoices.validUntil') }}</dt>
                <dd class="font-medium">{{ proforma.validUntil ? new Date(proforma.validUntil).toLocaleDateString('ro-RO') : '-' }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('proformaInvoices.currency') }}</dt>
                <dd class="font-medium">{{ proforma.currency }}</dd>
              </div>
              <div v-if="proforma.sentAt">
                <dt class="text-(--ui-text-muted)">{{ $t('proformaInvoices.sentAt') }}</dt>
                <dd class="font-medium">{{ new Date(proforma.sentAt).toLocaleString('ro-RO') }}</dd>
              </div>
              <div v-if="proforma.acceptedAt">
                <dt class="text-(--ui-text-muted)">{{ $t('proformaInvoices.acceptedAt') }}</dt>
                <dd class="font-medium">{{ new Date(proforma.acceptedAt).toLocaleString('ro-RO') }}</dd>
              </div>
              <div v-if="proforma.rejectedAt">
                <dt class="text-(--ui-text-muted)">{{ $t('proformaInvoices.rejectedAt') }}</dt>
                <dd class="font-medium">{{ new Date(proforma.rejectedAt).toLocaleString('ro-RO') }}</dd>
              </div>
              <div v-if="proforma.cancelledAt">
                <dt class="text-(--ui-text-muted)">{{ $t('proformaInvoices.cancelledAt') }}</dt>
                <dd class="font-medium">{{ new Date(proforma.cancelledAt).toLocaleString('ro-RO') }}</dd>
              </div>
              <div v-if="proforma.expiredAt">
                <dt class="text-(--ui-text-muted)">{{ $t('proformaInvoices.expiredAt') }}</dt>
                <dd class="font-medium">{{ new Date(proforma.expiredAt).toLocaleString('ro-RO') }}</dd>
              </div>
            </div>
          </UCard>

          <!-- Client info -->
          <UCard>
            <template #header>
              <div class="flex items-center justify-between">
                <h3 class="font-semibold">{{ $t('proformaInvoices.client') }}</h3>
                <UButton v-if="proforma.client" variant="ghost" size="sm" :to="`/clients/${proforma.client.id}`">
                  {{ $t('common.view') }}
                </UButton>
              </div>
            </template>
            <div v-if="proforma.client" class="space-y-2 text-sm">
              <div class="font-medium text-base">{{ proforma.client.name }}</div>
              <div class="text-(--ui-text-muted)">{{ proforma.client.cui || proforma.client.cnp }}</div>
              <div v-if="proforma.client.address" class="text-(--ui-text-muted)">{{ proforma.client.address }}</div>
              <div v-if="proforma.client.email" class="text-(--ui-text-muted)">{{ proforma.client.email }}</div>
            </div>
            <div v-else class="text-sm text-(--ui-text-muted)">-</div>
          </UCard>
        </div>

        <!-- Lines -->
        <UCard>
          <template #header>
            <h3 class="font-semibold">{{ $t('proformaInvoices.lines') }}</h3>
          </template>
          <UTable
            :data="proforma.lines"
            :columns="lineColumns"
          >
            <template #quantity-cell="{ row }">
              {{ row.original.quantity }} {{ row.original.unitOfMeasure }}
            </template>
            <template #unitPrice-cell="{ row }">
              {{ formatMoney(row.original.unitPrice, proforma.currency) }}
            </template>
            <template #vatAmount-cell="{ row }">
              {{ formatMoney(row.original.vatAmount, proforma.currency) }} ({{ row.original.vatRate }}%)
            </template>
            <template #lineTotal-cell="{ row }">
              {{ formatMoney(row.original.lineTotal, proforma.currency) }}
            </template>
          </UTable>

          <!-- Totals -->
          <div class="flex justify-end mt-4 pt-4 border-t border-(--ui-border)">
            <div class="space-y-1 text-right">
              <div class="text-sm">{{ $t('invoices.subtotal') }}: <strong>{{ formatMoney(proforma.subtotal, proforma.currency) }}</strong></div>
              <div class="text-sm">TVA: <strong>{{ formatMoney(proforma.vatTotal, proforma.currency) }}</strong></div>
              <div v-if="Number(proforma.discount) > 0" class="text-sm">{{ $t('invoices.discount') }}: <strong>-{{ formatMoney(proforma.discount, proforma.currency) }}</strong></div>
              <div class="text-lg font-bold">{{ $t('proformaInvoices.total') }}: {{ formatMoney(proforma.total, proforma.currency) }}</div>
            </div>
          </div>
        </UCard>

        <!-- Notes -->
        <UCard v-if="proforma.notes || proforma.paymentTerms">
          <template #header>
            <h3 class="font-semibold">{{ $t('common.notes') }}</h3>
          </template>
          <div class="space-y-2 text-sm">
            <div v-if="proforma.paymentTerms">
              <span class="text-(--ui-text-muted)">{{ $t('proformaInvoices.paymentTerms') }}:</span>
              {{ proforma.paymentTerms }}
            </div>
            <div v-if="proforma.notes">
              <span class="text-(--ui-text-muted)">{{ $t('proformaInvoices.notes') }}:</span>
              {{ proforma.notes }}
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
          <span class="text-lg font-semibold">{{ $t('proformaInvoices.editProforma') }}</span>
        </template>
        <template #body>
          <ProformaInvoicesProformaInvoiceForm
            v-if="editSlideoverOpen"
            :proforma="proforma"
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
          <span class="text-lg font-semibold">{{ $t('proformaInvoices.copyProforma') }}</span>
        </template>
        <template #body>
          <ProformaInvoicesProformaInvoiceForm
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
        :title="$t('proformaInvoices.convert')"
        :description="$t('proformaInvoices.convertConfirmDescription')"
        icon="i-lucide-file-output"
        :confirm-label="$t('proformaInvoices.convert')"
        :loading="actionLoading"
        @confirm="onConvert"
      />

      <!-- Delete modal -->
      <SharedConfirmModal
        v-model:open="deleteModalOpen"
        :title="$t('common.delete')"
        :description="$t('proformaInvoices.deleteConfirmDescription')"
        icon="i-lucide-trash-2"
        color="error"
        :confirm-label="$t('common.delete')"
        :loading="actionLoading"
        @confirm="onDelete"
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
                <UButton icon="i-lucide-download" variant="ghost" size="sm" @click="downloadPdf">
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
import type { ProformaInvoice } from '~/types'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const router = useRouter()
const { t: $t } = useI18n()
const toast = useToast()
const proformaStore = useProformaInvoiceStore()
const deliveryNoteStore = useDeliveryNoteStore()

const uuid = route.params.uuid as string

const proforma = ref<ProformaInvoice | null>(null)
const loading = ref(true)
const editSlideoverOpen = ref(false)
const copySlideoverOpen = ref(false)
const actionLoading = ref(false)
const convertModalOpen = ref(false)
const deleteModalOpen = ref(false)
const viewingPdf = ref(false)
const downloadingPdf = ref(false)
const pdfModalOpen = ref(false)
const pdfPreviewUrl = ref<string | null>(null)
const createDeliveryNoteLoading = ref(false)

useHead({ title: computed(() => proforma.value ? `${$t('proformaInvoices.title')} ${proforma.value.number}` : $t('proformaInvoices.title')) })

const lineColumns = [
  { accessorKey: 'description', header: $t('invoices.lineDescription') },
  { accessorKey: 'quantity', header: $t('invoices.quantity') },
  { accessorKey: 'unitPrice', header: $t('invoices.unitPrice') },
  { accessorKey: 'vatAmount', header: 'TVA' },
  { accessorKey: 'lineTotal', header: $t('proformaInvoices.total') },
]

function formatMoney(amount?: string | number, currency = 'RON') {
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency }).format(Number(amount || 0))
}

type BadgeColor = 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'error' | 'neutral'

function statusColor(status: string): BadgeColor {
  const map: Record<string, BadgeColor> = { draft: 'neutral', sent: 'primary', accepted: 'success', rejected: 'error', converted: 'info', cancelled: 'neutral', expired: 'warning' }
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

  if (proforma.value?.status === 'draft') {
    editGroup.push({ label: $t('common.edit'), icon: 'i-lucide-pencil', onSelect: () => { editSlideoverOpen.value = true } })
  }
  editGroup.push({ label: $t('common.copy'), icon: 'i-lucide-copy', onSelect: () => { copySlideoverOpen.value = true } })
  items.push(editGroup)

  if (proforma.value?.status === 'sent') {
    dangerGroup.push({ label: $t('proformaInvoices.reject'), icon: 'i-lucide-x', onSelect: () => onReject() })
    dangerGroup.push({ label: $t('proformaInvoices.cancel'), icon: 'i-lucide-ban', onSelect: () => onCancel() })
  }
  if (proforma.value?.status === 'accepted') {
    dangerGroup.push({ label: $t('proformaInvoices.cancel'), icon: 'i-lucide-ban', onSelect: () => onCancel() })
  }
  if (proforma.value?.status === 'draft') {
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
    const blob = await apiFetch<Blob>(`/v1/proforma-invoices/${uuid}/pdf`, {
      responseType: 'blob',
    })
    if (pdfPreviewUrl.value) {
      URL.revokeObjectURL(pdfPreviewUrl.value)
    }
    pdfPreviewUrl.value = URL.createObjectURL(blob)
    pdfModalOpen.value = true
  } catch {
    toast.add({ title: $t('invoices.pdfError'), color: 'error' })
  } finally {
    viewingPdf.value = false
  }
}

async function downloadPdf() {
  const { apiFetch } = useApi()
  downloadingPdf.value = true
  try {
    const blob = await apiFetch<Blob>(`/v1/proforma-invoices/${uuid}/pdf`, {
      responseType: 'blob',
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `proforma-${proforma.value?.number || 'download'}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    toast.add({ title: $t('invoices.pdfError'), color: 'error' })
  } finally {
    downloadingPdf.value = false
  }
}

async function fetchProforma() {
  loading.value = true
  proforma.value = await proformaStore.fetchProforma(uuid)
  loading.value = false
}

function onUpdated(updated: ProformaInvoice) {
  proforma.value = updated
  editSlideoverOpen.value = false
  toast.add({ title: $t('proformaInvoices.updateSuccess'), color: 'success', icon: 'i-lucide-check' })
}

function onCopySaved(newProforma: ProformaInvoice) {
  copySlideoverOpen.value = false
  toast.add({ title: $t('proformaInvoices.createSuccess'), color: 'success', icon: 'i-lucide-check' })
  router.push(`/proforma-invoices/${newProforma.id}`)
}

async function onSend() {
  actionLoading.value = true
  const result = await proformaStore.sendProforma(uuid)
  actionLoading.value = false
  if (result) {
    proforma.value = result
    toast.add({ title: $t('proformaInvoices.sendSuccess'), color: 'success', icon: 'i-lucide-check' })
  }
  else {
    toast.add({ title: $t('proformaInvoices.sendError'), color: 'error', icon: 'i-lucide-x' })
  }
}

async function onAccept() {
  actionLoading.value = true
  const result = await proformaStore.acceptProforma(uuid)
  actionLoading.value = false
  if (result) {
    proforma.value = result
    toast.add({ title: $t('proformaInvoices.acceptSuccess'), color: 'success', icon: 'i-lucide-check' })
  }
  else {
    toast.add({ title: $t('proformaInvoices.acceptError'), color: 'error', icon: 'i-lucide-x' })
  }
}

async function onReject() {
  actionLoading.value = true
  const result = await proformaStore.rejectProforma(uuid)
  actionLoading.value = false
  if (result) {
    proforma.value = result
    toast.add({ title: $t('proformaInvoices.rejectSuccess'), color: 'success', icon: 'i-lucide-check' })
  }
  else {
    toast.add({ title: $t('proformaInvoices.rejectError'), color: 'error', icon: 'i-lucide-x' })
  }
}

async function onCancel() {
  actionLoading.value = true
  const result = await proformaStore.cancelProforma(uuid)
  actionLoading.value = false
  if (result) {
    proforma.value = result
    toast.add({ title: $t('proformaInvoices.cancelSuccess'), color: 'success', icon: 'i-lucide-check' })
  }
  else {
    toast.add({ title: $t('proformaInvoices.cancelError'), color: 'error', icon: 'i-lucide-x' })
  }
}

async function onConvert() {
  actionLoading.value = true
  const invoice = await proformaStore.convertToInvoice(uuid)
  actionLoading.value = false
  convertModalOpen.value = false
  if (invoice) {
    toast.add({ title: $t('proformaInvoices.convertSuccess'), color: 'success', icon: 'i-lucide-check' })
    router.push(`/invoices/${invoice.id}`)
  }
  else {
    toast.add({ title: $t('proformaInvoices.convertError'), color: 'error', icon: 'i-lucide-x' })
  }
}

async function onDelete() {
  actionLoading.value = true
  const success = await proformaStore.deleteProforma(uuid)
  actionLoading.value = false
  deleteModalOpen.value = false
  if (success) {
    toast.add({ title: $t('proformaInvoices.deleteSuccess'), color: 'success', icon: 'i-lucide-check' })
    router.push('/proforma-invoices')
  }
  else {
    toast.add({ title: $t('proformaInvoices.deleteError'), color: 'error', icon: 'i-lucide-x' })
  }
}

async function onCreateDeliveryNote() {
  createDeliveryNoteLoading.value = true
  const deliveryNote = await deliveryNoteStore.createFromProforma(uuid)
  createDeliveryNoteLoading.value = false
  if (deliveryNote) {
    toast.add({ title: $t('proformaInvoices.createDeliveryNoteSuccess'), color: 'success', icon: 'i-lucide-check' })
    router.push(`/delivery-notes/${deliveryNote.id}`)
  }
  else {
    toast.add({ title: $t('proformaInvoices.createDeliveryNoteError'), color: 'error', icon: 'i-lucide-x' })
  }
}

onMounted(() => fetchProforma())
</script>
