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
      <div v-if="deliveryNote" class="space-y-6">
        <div class="flex flex-col gap-3">
          <!-- Title row -->
          <div class="flex items-center gap-3 min-w-0">
            <UButton icon="i-lucide-arrow-left" variant="ghost" to="/delivery-notes" class="shrink-0" />
            <h1 class="text-2xl font-bold truncate">{{ deliveryNote.number }}</h1>
            <div class="flex items-center gap-1.5 flex-wrap">
              <UBadge :color="statusColor(deliveryNote.status)" variant="subtle">
                {{ $t(`deliveryNoteStatus.${deliveryNote.status}`) }}
              </UBadge>
              <UBadge v-if="deliveryNote.etransportStatus" :color="etransportStatusColor(deliveryNote.etransportStatus)" variant="subtle">
                e-Transport: {{ etransportStatusLabel(deliveryNote.etransportStatus) }}
              </UBadge>
              <UBadge v-if="deliveryNote.etransportUit" color="success" variant="solid">
                UIT: {{ deliveryNote.etransportUit }}
              </UBadge>
            </div>
          </div>
          <!-- Actions row -->
          <div class="flex flex-wrap items-center gap-2">
            <!-- Primary CTA -->
            <UButton v-if="deliveryNote.status === 'draft'" icon="i-lucide-check-circle" color="primary" :loading="actionLoading" @click="onIssue">
              {{ $t('deliveryNotes.issue') }}
            </UButton>
            <UButton v-if="deliveryNote.status === 'issued'" icon="i-lucide-file-output" color="primary" :loading="actionLoading" @click="convertModalOpen = true">
              {{ $t('deliveryNotes.convert') }}
            </UButton>
            <UButton v-if="deliveryNote.status === 'converted' && deliveryNote.convertedInvoice" icon="i-lucide-external-link" variant="outline" :to="`/invoices/${deliveryNote.convertedInvoice.id}`">
              {{ $t('deliveryNotes.viewInvoice') }}
            </UButton>
            <UButton v-if="deliveryNote.status === 'cancelled'" icon="i-lucide-rotate-ccw" variant="outline" :loading="actionLoading" @click="restoreModalOpen = true">
              {{ $t('deliveryNotes.restore') }}
            </UButton>
            <UButton v-if="canSubmitETransport && !canRetryETransport" icon="i-lucide-truck" color="primary" variant="outline" :loading="etransportLoading" @click="onSubmitETransport">
              {{ $t('deliveryNotes.etransportSubmit') }}
            </UButton>
            <UButton v-if="canRetryETransport" icon="i-lucide-refresh-cw" color="warning" variant="outline" :loading="etransportLoading" @click="onSubmitETransport">
              {{ $t('deliveryNotes.etransportRetry') }}
            </UButton>
            <UButton v-if="deliveryNote.status === 'issued' || deliveryNote.status === 'converted'" icon="i-lucide-mail" variant="outline" @click="emailModalOpen = true">
              {{ $t('deliveryNotes.sendEmail') }}
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
        <UCard v-if="deliveryNote.status === 'converted' && deliveryNote.convertedInvoice" class="border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/30">
          <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 text-sm">
              <UIcon name="i-lucide-file-text" class="text-blue-500 shrink-0 size-5" />
              <span class="text-blue-800 dark:text-blue-200">{{ $t('deliveryNotes.convertedInvoice') }}: #{{ deliveryNote.convertedInvoice.number || deliveryNote.convertedInvoice.id }}</span>
            </div>
            <UButton icon="i-lucide-external-link" variant="subtle" color="info" size="sm" :to="`/invoices/${deliveryNote.convertedInvoice.id}`">
              {{ $t('deliveryNotes.viewInvoice') }}
            </UButton>
          </div>
        </UCard>

        <!-- Status & meta + Client -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <UCard>
            <template #header>
              <h3 class="font-semibold">{{ $t('deliveryNotes.title') }}</h3>
            </template>
            <div class="grid grid-cols-2 md:grid-cols-2 gap-4 text-sm">
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.number') }}</dt>
                <dd class="font-medium">{{ deliveryNote.number }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.status') }}</dt>
                <dd>
                  <UBadge :color="statusColor(deliveryNote.status)" variant="subtle" size="sm">
                    {{ $t(`deliveryNoteStatus.${deliveryNote.status}`) }}
                  </UBadge>
                </dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.issueDate') }}</dt>
                <dd class="font-medium">{{ deliveryNote.issueDate ? new Date(deliveryNote.issueDate).toLocaleDateString('ro-RO') : '-' }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.dueDate') }}</dt>
                <dd class="font-medium">{{ deliveryNote.dueDate ? new Date(deliveryNote.dueDate).toLocaleDateString('ro-RO') : '-' }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.currency') }}</dt>
                <dd class="font-medium">{{ deliveryNote.currency }}</dd>
              </div>
              <div v-if="deliveryNote.issuedAt">
                <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.issuedAt') }}</dt>
                <dd class="font-medium">{{ new Date(deliveryNote.issuedAt).toLocaleString('ro-RO') }}</dd>
              </div>
              <div v-if="deliveryNote.cancelledAt">
                <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.cancelledAt') }}</dt>
                <dd class="font-medium">{{ new Date(deliveryNote.cancelledAt).toLocaleString('ro-RO') }}</dd>
              </div>
            </div>
          </UCard>

          <!-- Client info -->
          <UCard>
            <template #header>
              <div class="flex items-center justify-between">
                <h3 class="font-semibold">{{ $t('deliveryNotes.client') }}</h3>
                <UButton v-if="deliveryNote.client" variant="ghost" size="sm" :to="`/clients/${deliveryNote.client.id}`">
                  {{ $t('common.view') }}
                </UButton>
              </div>
            </template>
            <div v-if="deliveryNote.client" class="space-y-2 text-sm">
              <div class="font-medium text-base">{{ deliveryNote.client.name }}</div>
              <div class="text-(--ui-text-muted)">{{ deliveryNote.client.cui || deliveryNote.client.cnp }}</div>
              <div v-if="deliveryNote.client.address" class="text-(--ui-text-muted)">{{ deliveryNote.client.address }}</div>
              <div v-if="deliveryNote.client.email" class="text-(--ui-text-muted)">{{ deliveryNote.client.email }}</div>
            </div>
            <div v-else class="text-sm text-(--ui-text-muted)">-</div>
          </UCard>
        </div>

        <!-- Delegate info -->
        <UCard v-if="deliveryNote.deputyName || deliveryNote.deputyIdentityCard || deliveryNote.deputyAuto">
          <template #header>
            <h3 class="font-semibold">{{ $t('deliveryNotes.delegateInfo') }}</h3>
          </template>
          <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
            <div v-if="deliveryNote.deputyName">
              <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.deputyName') }}</dt>
              <dd class="font-medium">{{ deliveryNote.deputyName }}</dd>
            </div>
            <div v-if="deliveryNote.deputyIdentityCard">
              <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.deputyIdentityCard') }}</dt>
              <dd class="font-medium">{{ deliveryNote.deputyIdentityCard }}</dd>
            </div>
            <div v-if="deliveryNote.deputyAuto">
              <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.deputyAuto') }}</dt>
              <dd class="font-medium">{{ deliveryNote.deputyAuto }}</dd>
            </div>
            <div v-if="deliveryNote.issuerName">
              <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.issuerName') }}</dt>
              <dd class="font-medium">{{ deliveryNote.issuerName }}</dd>
            </div>
            <div v-if="deliveryNote.issuerId">
              <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.issuerId') }}</dt>
              <dd class="font-medium">{{ deliveryNote.issuerId }}</dd>
            </div>
            <div v-if="deliveryNote.salesAgent">
              <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.salesAgent') }}</dt>
              <dd class="font-medium">{{ deliveryNote.salesAgent }}</dd>
            </div>
          </div>
        </UCard>

        <!-- e-Transport error banner -->
        <UCard v-if="deliveryNote.etransportErrorMessage" class="border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/30">
          <div class="flex items-start gap-2 text-sm">
            <UIcon name="i-lucide-alert-triangle" class="text-red-500 shrink-0 size-5 mt-0.5" />
            <div>
              <div class="font-semibold text-red-800 dark:text-red-200">{{ $t('deliveryNotes.etransportStatus') }}</div>
              <div class="text-red-700 dark:text-red-300 mt-1 whitespace-pre-wrap">{{ deliveryNote.etransportErrorMessage }}</div>
            </div>
          </div>
        </UCard>

        <!-- e-Transport details -->
        <UCard v-if="deliveryNote.etransportVehicleNumber || deliveryNote.etransportStartCounty || deliveryNote.etransportUit">
          <template #header>
            <h3 class="font-semibold">{{ $t('deliveryNotes.etransport') }}</h3>
          </template>
          <div class="space-y-6">
            <!-- UIT + Status -->
            <div v-if="deliveryNote.etransportUit || deliveryNote.etransportStatus" class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
              <div v-if="deliveryNote.etransportUit">
                <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.etransportUit') }}</dt>
                <dd class="font-bold text-lg text-primary">{{ deliveryNote.etransportUit }}</dd>
              </div>
              <div v-if="deliveryNote.etransportStatus">
                <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.etransportStatus') }}</dt>
                <dd>
                  <UBadge :color="etransportStatusColor(deliveryNote.etransportStatus)" variant="subtle" size="sm">
                    {{ etransportStatusLabel(deliveryNote.etransportStatus) }}
                  </UBadge>
                </dd>
              </div>
              <div v-if="deliveryNote.etransportSubmittedAt">
                <dt class="text-(--ui-text-muted)">Trimis la</dt>
                <dd class="font-medium">{{ new Date(deliveryNote.etransportSubmittedAt).toLocaleString('ro-RO') }}</dd>
              </div>
            </div>

            <!-- Transport data -->
            <div v-if="deliveryNote.etransportVehicleNumber">
              <h4 class="text-sm font-semibold text-(--ui-text-muted) mb-2">{{ $t('deliveryNotes.transportData') }}</h4>
              <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
                <div>
                  <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.vehicleNumber') }}</dt>
                  <dd class="font-medium">{{ deliveryNote.etransportVehicleNumber }}</dd>
                </div>
                <div v-if="deliveryNote.etransportTrailer1">
                  <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.trailer1') }}</dt>
                  <dd class="font-medium">{{ deliveryNote.etransportTrailer1 }}</dd>
                </div>
                <div v-if="deliveryNote.etransportTrailer2">
                  <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.trailer2') }}</dt>
                  <dd class="font-medium">{{ deliveryNote.etransportTrailer2 }}</dd>
                </div>
                <div v-if="deliveryNote.etransportTransporterName">
                  <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.transporterName') }}</dt>
                  <dd class="font-medium">{{ deliveryNote.etransportTransporterName }}</dd>
                </div>
                <div v-if="deliveryNote.etransportTransporterCode">
                  <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.transporterCode') }}</dt>
                  <dd class="font-medium">{{ deliveryNote.etransportTransporterCode }}</dd>
                </div>
                <div v-if="deliveryNote.etransportTransportDate">
                  <dt class="text-(--ui-text-muted)">{{ $t('deliveryNotes.transportDate') }}</dt>
                  <dd class="font-medium">{{ new Date(deliveryNote.etransportTransportDate).toLocaleDateString('ro-RO') }}</dd>
                </div>
              </div>
            </div>

            <!-- Route -->
            <div v-if="deliveryNote.etransportStartCounty || deliveryNote.etransportEndCounty" class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div v-if="deliveryNote.etransportStartCounty">
                <h4 class="text-sm font-semibold text-(--ui-text-muted) mb-2">{{ $t('deliveryNotes.routeStart') }}</h4>
                <div class="text-sm space-y-1">
                  <div><span class="text-(--ui-text-muted)">{{ $t('deliveryNotes.county') }}:</span> {{ countyName(deliveryNote.etransportStartCounty) }}</div>
                  <div v-if="deliveryNote.etransportStartLocality"><span class="text-(--ui-text-muted)">{{ $t('deliveryNotes.locality') }}:</span> {{ deliveryNote.etransportStartLocality }}</div>
                  <div v-if="deliveryNote.etransportStartStreet"><span class="text-(--ui-text-muted)">{{ $t('deliveryNotes.street') }}:</span> {{ deliveryNote.etransportStartStreet }} {{ deliveryNote.etransportStartNumber }}</div>
                  <div v-if="deliveryNote.etransportStartPostalCode"><span class="text-(--ui-text-muted)">{{ $t('deliveryNotes.postalCode') }}:</span> {{ deliveryNote.etransportStartPostalCode }}</div>
                </div>
              </div>
              <div v-if="deliveryNote.etransportEndCounty">
                <h4 class="text-sm font-semibold text-(--ui-text-muted) mb-2">{{ $t('deliveryNotes.routeEnd') }}</h4>
                <div class="text-sm space-y-1">
                  <div><span class="text-(--ui-text-muted)">{{ $t('deliveryNotes.county') }}:</span> {{ countyName(deliveryNote.etransportEndCounty) }}</div>
                  <div v-if="deliveryNote.etransportEndLocality"><span class="text-(--ui-text-muted)">{{ $t('deliveryNotes.locality') }}:</span> {{ deliveryNote.etransportEndLocality }}</div>
                  <div v-if="deliveryNote.etransportEndStreet"><span class="text-(--ui-text-muted)">{{ $t('deliveryNotes.street') }}:</span> {{ deliveryNote.etransportEndStreet }} {{ deliveryNote.etransportEndNumber }}</div>
                  <div v-if="deliveryNote.etransportEndPostalCode"><span class="text-(--ui-text-muted)">{{ $t('deliveryNotes.postalCode') }}:</span> {{ deliveryNote.etransportEndPostalCode }}</div>
                </div>
              </div>
            </div>
          </div>
        </UCard>

        <!-- Lines -->
        <UCard>
          <template #header>
            <h3 class="font-semibold">{{ $t('deliveryNotes.lines') }}</h3>
          </template>
          <UTable
            :data="deliveryNote.lines"
            :columns="lineColumns"
          >
            <template #quantity-cell="{ row }">
              {{ row.original.quantity }} {{ row.original.unitOfMeasure }}
            </template>
            <template #unitPrice-cell="{ row }">
              {{ formatMoney(row.original.unitPrice, deliveryNote.currency) }}
            </template>
            <template #vatAmount-cell="{ row }">
              {{ formatMoney(row.original.vatAmount, deliveryNote.currency) }} ({{ row.original.vatRate }}%)
            </template>
            <template #lineTotal-cell="{ row }">
              {{ formatMoney(row.original.lineTotal, deliveryNote.currency) }}
            </template>
          </UTable>

          <!-- Totals -->
          <div class="flex justify-end mt-4 pt-4 border-t border-(--ui-border)">
            <div class="space-y-1 text-right">
              <div class="text-sm">{{ $t('invoices.subtotal') }}: <strong>{{ formatMoney(deliveryNote.subtotal, deliveryNote.currency) }}</strong></div>
              <div class="text-sm">TVA: <strong>{{ formatMoney(deliveryNote.vatTotal, deliveryNote.currency) }}</strong></div>
              <div v-if="Number(deliveryNote.discount) > 0" class="text-sm">{{ $t('invoices.discount') }}: <strong>-{{ formatMoney(deliveryNote.discount, deliveryNote.currency) }}</strong></div>
              <div class="text-lg font-bold">{{ $t('deliveryNotes.total') }}: {{ formatMoney(deliveryNote.total, deliveryNote.currency) }}</div>
            </div>
          </div>
        </UCard>

        <!-- Notes -->
        <UCard v-if="deliveryNote.notes || deliveryNote.mentions || deliveryNote.deliveryLocation || deliveryNote.projectReference">
          <template #header>
            <h3 class="font-semibold">{{ $t('common.notes') }}</h3>
          </template>
          <div class="space-y-2 text-sm">
            <div v-if="deliveryNote.deliveryLocation">
              <span class="text-(--ui-text-muted)">{{ $t('deliveryNotes.deliveryLocation') }}:</span>
              {{ deliveryNote.deliveryLocation }}
            </div>
            <div v-if="deliveryNote.projectReference">
              <span class="text-(--ui-text-muted)">{{ $t('deliveryNotes.projectReference') }}:</span>
              {{ deliveryNote.projectReference }}
            </div>
            <div v-if="deliveryNote.notes">
              <span class="text-(--ui-text-muted)">{{ $t('deliveryNotes.notes') }}:</span>
              {{ deliveryNote.notes }}
            </div>
            <div v-if="deliveryNote.mentions">
              <span class="text-(--ui-text-muted)">{{ $t('deliveryNotes.mentions') }}:</span>
              {{ deliveryNote.mentions }}
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
          <span class="text-lg font-semibold">{{ $t('deliveryNotes.editDeliveryNote') }}</span>
        </template>
        <template #body>
          <DeliveryNotesDeliveryNoteForm
            v-if="editSlideoverOpen"
            :delivery-note="deliveryNote"
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
          <span class="text-lg font-semibold">{{ $t('deliveryNotes.copyDeliveryNote') }}</span>
        </template>
        <template #body>
          <DeliveryNotesDeliveryNoteForm
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
        :title="$t('deliveryNotes.convert')"
        :description="$t('deliveryNotes.convertConfirmDescription')"
        icon="i-lucide-file-output"
        :confirm-label="$t('deliveryNotes.convert')"
        :loading="actionLoading"
        @confirm="onConvert"
      />

      <!-- Restore modal -->
      <SharedConfirmModal
        v-model:open="restoreModalOpen"
        :title="$t('deliveryNotes.restore')"
        :description="$t('deliveryNotes.restoreConfirmDescription')"
        icon="i-lucide-rotate-ccw"
        :confirm-label="$t('deliveryNotes.restore')"
        :loading="actionLoading"
        @confirm="onRestore"
      />

      <!-- Storno modal -->
      <SharedConfirmModal
        v-model:open="stornoModalOpen"
        :title="$t('deliveryNotes.storno')"
        :description="$t('deliveryNotes.stornoConfirmDescription')"
        icon="i-lucide-file-minus"
        :confirm-label="$t('deliveryNotes.storno')"
        :loading="actionLoading"
        @confirm="onStorno"
      />

      <!-- Delete modal -->
      <SharedConfirmModal
        v-model:open="deleteModalOpen"
        :title="$t('common.delete')"
        :description="$t('deliveryNotes.deleteConfirmDescription')"
        icon="i-lucide-trash-2"
        color="error"
        :confirm-label="$t('common.delete')"
        :loading="actionLoading"
        @confirm="onDelete"
      />

      <!-- Email modal -->
      <DeliveryNotesDeliveryNoteEmailModal
        v-if="deliveryNote && emailModalOpen"
        v-model:open="emailModalOpen"
        :delivery-note="deliveryNote"
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
import type { DeliveryNote } from '~/types'
import { ETRANSPORT_STATUS_COLORS, ETRANSPORT_STATUS_LABELS, ETRANSPORT_COUNTIES } from '~/types/etransport'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const router = useRouter()
const { t: $t } = useI18n()
const toast = useToast()
const deliveryNoteStore = useDeliveryNoteStore()

const uuid = route.params.uuid as string

const deliveryNote = ref<DeliveryNote | null>(null)
const loading = ref(true)
const editSlideoverOpen = ref(false)
const copySlideoverOpen = ref(false)
const actionLoading = ref(false)
const convertModalOpen = ref(false)
const restoreModalOpen = ref(false)
const stornoModalOpen = ref(false)
const deleteModalOpen = ref(false)
const emailModalOpen = ref(false)
const etransportLoading = ref(false)
const viewingPdf = ref(false)
const downloadingPdf = ref(false)
const pdfModalOpen = ref(false)
const pdfPreviewUrl = ref<string | null>(null)

useHead({ title: computed(() => deliveryNote.value ? `${$t('deliveryNotes.title')} ${deliveryNote.value.number}` : $t('deliveryNotes.title')) })

const lineColumns = [
  { accessorKey: 'description', header: $t('invoices.lineDescription') },
  { accessorKey: 'quantity', header: $t('invoices.quantity') },
  { accessorKey: 'unitPrice', header: $t('invoices.unitPrice') },
  { accessorKey: 'vatAmount', header: 'TVA' },
  { accessorKey: 'lineTotal', header: $t('deliveryNotes.total') },
]

function formatMoney(amount?: string | number, currency = 'RON') {
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency }).format(Number(amount || 0))
}

type BadgeColor = 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'error' | 'neutral'

function statusColor(status: string): BadgeColor {
  const map: Record<string, BadgeColor> = { draft: 'neutral', issued: 'primary', converted: 'info', cancelled: 'neutral' }
  return map[status] || 'neutral'
}

const documentMenuItems = computed(() => [
  [
    { label: $t('invoices.viewPdf'), icon: 'i-lucide-eye', onSelect: () => viewPdf() },
    { label: $t('invoices.downloadPdf'), icon: 'i-lucide-download', onSelect: () => downloadPdf() },
  ],
  [
    { label: $t('deliveryNotes.downloadWithoutVat'), icon: 'i-lucide-eye-off', onSelect: () => downloadPdf('?hideVat=1') },
    { label: $t('deliveryNotes.downloadWithoutPrices'), icon: 'i-lucide-eye-off', onSelect: () => downloadPdf('?hidePrices=1') },
  ],
])

const moreActionsItems = computed(() => {
  const items: any[][] = []
  const editGroup: any[] = []
  const dangerGroup: any[] = []

  if (deliveryNote.value?.status === 'draft' || deliveryNote.value?.status === 'issued') {
    editGroup.push({ label: $t('common.edit'), icon: 'i-lucide-pencil', onSelect: () => { editSlideoverOpen.value = true } })
  }
  editGroup.push({ label: $t('common.copy'), icon: 'i-lucide-copy', onSelect: () => { copySlideoverOpen.value = true } })
  items.push(editGroup)

  if (deliveryNote.value?.status === 'issued') {
    const etStatus = deliveryNote.value.etransportStatus
    const isETransportSent = etStatus === 'uploaded' || etStatus === 'ok'
    if (!isETransportSent) {
      dangerGroup.push({ label: $t('deliveryNotes.storno'), icon: 'i-lucide-file-minus', onSelect: () => { stornoModalOpen.value = true } })
      dangerGroup.push({ label: $t('deliveryNotes.cancel'), icon: 'i-lucide-ban', onSelect: () => onCancel() })
    }
  }
  if (deliveryNote.value?.status === 'draft') {
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
    const blob = await apiFetch<Blob>(`/v1/delivery-notes/${uuid}/pdf`, {
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

async function downloadPdf(queryParams = '') {
  const { apiFetch } = useApi()
  downloadingPdf.value = true
  try {
    const blob = await apiFetch<Blob>(`/v1/delivery-notes/${uuid}/pdf${queryParams}`, {
      responseType: 'blob',
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `aviz-${deliveryNote.value?.number || 'download'}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    toast.add({ title: $t('invoices.pdfError'), color: 'error' })
  } finally {
    downloadingPdf.value = false
  }
}

async function fetchDeliveryNote() {
  loading.value = true
  deliveryNote.value = await deliveryNoteStore.fetchDeliveryNote(uuid)
  loading.value = false
}

function onUpdated(updated: DeliveryNote) {
  deliveryNote.value = updated
  editSlideoverOpen.value = false
  toast.add({ title: $t('deliveryNotes.updateSuccess'), color: 'success', icon: 'i-lucide-check' })
}

function onCopySaved(newDeliveryNote: DeliveryNote) {
  copySlideoverOpen.value = false
  toast.add({ title: $t('deliveryNotes.createSuccess'), color: 'success', icon: 'i-lucide-check' })
  router.push(`/delivery-notes/${newDeliveryNote.id}`)
}

async function onIssue() {
  actionLoading.value = true
  const result = await deliveryNoteStore.issueDeliveryNote(uuid)
  actionLoading.value = false
  if (result) {
    deliveryNote.value = result
    toast.add({ title: $t('deliveryNotes.issueSuccess'), color: 'success', icon: 'i-lucide-check' })
  }
  else {
    toast.add({ title: $t('deliveryNotes.issueError'), color: 'error', icon: 'i-lucide-x' })
  }
}

async function onCancel() {
  actionLoading.value = true
  const result = await deliveryNoteStore.cancelDeliveryNote(uuid)
  actionLoading.value = false
  if (result) {
    deliveryNote.value = result
    toast.add({ title: $t('deliveryNotes.cancelSuccess'), color: 'success', icon: 'i-lucide-check' })
  }
  else {
    toast.add({ title: $t('deliveryNotes.cancelError'), color: 'error', icon: 'i-lucide-x' })
  }
}

async function onRestore() {
  actionLoading.value = true
  const result = await deliveryNoteStore.restoreDeliveryNote(uuid)
  actionLoading.value = false
  restoreModalOpen.value = false
  if (result) {
    deliveryNote.value = result
    toast.add({ title: $t('deliveryNotes.restoreSuccess'), color: 'success', icon: 'i-lucide-check' })
  }
  else {
    toast.add({ title: $t('deliveryNotes.restoreError'), color: 'error', icon: 'i-lucide-x' })
  }
}

async function onConvert() {
  actionLoading.value = true
  const invoice = await deliveryNoteStore.convertToInvoice(uuid)
  actionLoading.value = false
  convertModalOpen.value = false
  if (invoice) {
    toast.add({ title: $t('deliveryNotes.convertSuccess'), color: 'success', icon: 'i-lucide-check' })
    router.push(`/invoices/${invoice.id}`)
  }
  else {
    toast.add({ title: $t('deliveryNotes.convertError'), color: 'error', icon: 'i-lucide-x' })
  }
}

async function onStorno() {
  actionLoading.value = true
  const result = await deliveryNoteStore.stornoDeliveryNote(uuid)
  actionLoading.value = false
  stornoModalOpen.value = false
  if (result) {
    toast.add({ title: $t('deliveryNotes.stornoSuccess'), color: 'success', icon: 'i-lucide-check' })
    router.push(`/delivery-notes/${result.id}`)
  }
  else {
    toast.add({ title: $t('deliveryNotes.stornoError'), color: 'error', icon: 'i-lucide-x' })
  }
}

async function onDelete() {
  actionLoading.value = true
  const success = await deliveryNoteStore.deleteDeliveryNote(uuid)
  actionLoading.value = false
  deleteModalOpen.value = false
  if (success) {
    toast.add({ title: $t('deliveryNotes.deleteSuccess'), color: 'success', icon: 'i-lucide-check' })
    router.push('/delivery-notes')
  }
  else {
    toast.add({ title: $t('deliveryNotes.deleteError'), color: 'error', icon: 'i-lucide-x' })
  }
}

function onEmailSent() {
  toast.add({ title: $t('deliveryNotes.emailSent'), color: 'success', icon: 'i-lucide-mail-check' })
}

async function onSubmitETransport() {
  etransportLoading.value = true
  const result = await deliveryNoteStore.submitETransport(uuid)
  etransportLoading.value = false
  if (result) {
    deliveryNote.value = result
    toast.add({ title: $t('deliveryNotes.etransportSubmitSuccess'), color: 'success', icon: 'i-lucide-truck' })
  }
  else {
    toast.add({ title: $t('deliveryNotes.etransportSubmitError'), color: 'error', icon: 'i-lucide-x' })
  }
}

function etransportStatusColor(status: string | null | undefined): BadgeColor {
  if (!status) return 'neutral'
  const map: Record<string, BadgeColor> = {
    uploaded: 'warning',
    ok: 'success',
    nok: 'error',
    validation_failed: 'error',
    upload_failed: 'error',
    pending_timeout: 'warning',
  }
  return map[status] || 'neutral'
}

function etransportStatusLabel(status: string | null | undefined): string {
  if (!status) return '-'
  return ETRANSPORT_STATUS_LABELS[status] || status
}

function countyName(code: number | null | undefined): string {
  if (!code) return '-'
  return ETRANSPORT_COUNTIES.find(c => c.value === code)?.label || String(code)
}

const canSubmitETransport = computed(() => {
  if (!deliveryNote.value) return false
  return deliveryNote.value.status === 'issued'
    && (!deliveryNote.value.etransportStatus || ['validation_failed', 'upload_failed', 'nok', 'pending_timeout'].includes(deliveryNote.value.etransportStatus))
})

const canRetryETransport = computed(() => {
  if (!deliveryNote.value) return false
  return deliveryNote.value.status === 'issued'
    && !!deliveryNote.value.etransportStatus
    && ['validation_failed', 'upload_failed', 'nok', 'pending_timeout'].includes(deliveryNote.value.etransportStatus)
})

onMounted(() => fetchDeliveryNote())
</script>
