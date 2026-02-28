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
      <div v-if="recurringInvoice" class="space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="flex items-center gap-3 min-w-0">
            <UButton icon="i-lucide-arrow-left" variant="ghost" to="/recurring-invoices" />
            <h1 class="text-2xl font-bold truncate">{{ recurringInvoice.reference || $t('recurringInvoices.title') }}</h1>
            <UBadge :color="recurringInvoice.isActive ? 'success' : 'neutral'" variant="subtle" class="shrink-0">
              {{ recurringInvoice.isActive ? $t('common.active') : $t('common.inactive') }}
            </UBadge>
          </div>
          <div class="flex gap-2 shrink-0">
            <UButton
              :icon="recurringInvoice.isActive ? 'i-lucide-pause' : 'i-lucide-play'"
              variant="outline"
              :loading="toggling"
              @click="onToggle"
            >
              <span class="hidden sm:inline">{{ recurringInvoice.isActive ? $t('common.inactive') : $t('common.active') }}</span>
            </UButton>
            <UButton icon="i-lucide-file-plus" variant="outline" :loading="issuing" @click="issueNowModalOpen = true">
              <span class="hidden sm:inline">{{ $t('recurringInvoices.issueNow') }}</span>
            </UButton>
            <UButton icon="i-lucide-pencil" variant="outline" @click="editSlideoverOpen = true">
              <span class="hidden sm:inline">{{ $t('common.edit') }}</span>
            </UButton>
            <UButton icon="i-lucide-trash-2" color="error" variant="outline" @click="deleteModalOpen = true">
              <span class="hidden sm:inline">{{ $t('common.delete') }}</span>
            </UButton>
          </div>
        </div>

          <!-- Schedule info -->
          <UCard>
            <template #header>
              <h3 class="font-semibold">{{ $t('recurringInvoices.schedule') }}</h3>
            </template>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('recurringInvoices.frequency') }}</dt>
                <dd class="font-medium">{{ $t(`recurringInvoices.frequencies.${recurringInvoice.frequency}`) }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('recurringInvoices.frequencyDay') }}</dt>
                <dd class="font-medium">
                  <template v-if="recurringInvoice.frequency === 'weekly'">
                    {{ $t(`recurringInvoices.daysOfWeek.${recurringInvoice.frequencyDay}`) }}
                  </template>
                  <template v-else>{{ recurringInvoice.frequencyDay }}</template>
                </dd>
              </div>
              <div v-if="recurringInvoice.frequency === 'yearly' && recurringInvoice.frequencyMonth">
                <dt class="text-(--ui-text-muted)">{{ $t('recurringInvoices.frequencyMonth') }}</dt>
                <dd class="font-medium">{{ $t(`reports.months.${recurringInvoice.frequencyMonth}`) }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('recurringInvoices.nextIssuanceDate') }}</dt>
                <dd class="font-medium">{{ formatDate(recurringInvoice.nextIssuanceDate) }}</dd>
              </div>
              <div v-if="recurringInvoice.stopDate">
                <dt class="text-(--ui-text-muted)">{{ $t('recurringInvoices.stopDate') }}</dt>
                <dd class="font-medium">{{ formatDate(recurringInvoice.stopDate) }}</dd>
              </div>
              <div v-if="recurringInvoice.dueDateType">
                <dt class="text-(--ui-text-muted)">{{ $t('recurringInvoices.dueDateType') }}</dt>
                <dd class="font-medium">
                  {{ $t(`recurringInvoices.dueDateTypes.${recurringInvoice.dueDateType}`) }}
                  <span v-if="recurringInvoice.dueDateType === 'days'">({{ recurringInvoice.dueDateDays }} {{ $t('common.days') }})</span>
                  <span v-if="recurringInvoice.dueDateType === 'fixed_day'">({{ $t('recurringInvoices.dueDateFixedDay') }}: {{ recurringInvoice.dueDateFixedDay }})</span>
                </dd>
              </div>
            </div>
          </UCard>

          <!-- Last issued info -->
          <UCard v-if="recurringInvoice.lastIssuedAt">
            <div class="flex items-center gap-4 text-sm">
              <div>
                <span class="text-(--ui-text-muted)">{{ $t('recurringInvoices.lastIssuedAt') }}:</span>
                <span class="font-medium ml-1">{{ formatDate(recurringInvoice.lastIssuedAt) }}</span>
              </div>
              <div v-if="recurringInvoice.lastInvoiceNumber">
                <span class="text-(--ui-text-muted)">{{ $t('recurringInvoices.lastInvoiceNumber') }}:</span>
                <span class="font-medium ml-1">{{ recurringInvoice.lastInvoiceNumber }}</span>
              </div>
            </div>
          </UCard>

          <!-- Client -->
          <UCard v-if="recurringInvoice.client">
            <template #header>
              <h3 class="font-semibold">{{ $t('invoices.client') }}</h3>
            </template>
            <dl class="grid grid-cols-2 gap-3 text-sm">
              <div><dt class="text-(--ui-text-muted)">{{ $t('common.name') }}</dt><dd class="font-medium">{{ recurringInvoice.client.name }}</dd></div>
              <div v-if="recurringInvoice.client.cui"><dt class="text-(--ui-text-muted)">CIF</dt><dd class="font-medium">{{ recurringInvoice.client.cui }}</dd></div>
            </dl>
          </UCard>

          <!-- Invoice metadata -->
          <UCard>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('common.type') }}</dt>
                <dd class="font-medium">{{ $t(`documentType.${recurringInvoice.documentType}`) }}</dd>
              </div>
              <div v-if="recurringInvoice.invoiceTypeCode">
                <dt class="text-(--ui-text-muted)">{{ $t('recurringInvoices.invoiceTypeCode') }}</dt>
                <dd class="font-medium">{{ $t(`invoiceTypeCodes.${recurringInvoice.invoiceTypeCode}`) }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('invoices.currency') }}</dt>
                <dd class="font-medium">{{ recurringInvoice.currency }}</dd>
              </div>
              <div v-if="recurringInvoice.paymentTerms">
                <dt class="text-(--ui-text-muted)">{{ $t('invoices.paymentTerms') }}</dt>
                <dd class="font-medium">{{ recurringInvoice.paymentTerms }}</dd>
              </div>
            </div>
          </UCard>

          <!-- Lines -->
          <UCard>
            <template #header>
              <h3 class="font-semibold">{{ $t('invoices.lines') }}</h3>
            </template>
            <UTable :data="recurringInvoice.lines || []" :columns="lineColumns" />
            <div class="flex justify-end mt-4 space-y-1 text-right">
              <div class="space-y-1">
                <div class="text-sm">{{ $t('invoices.subtotal') }}: <strong>{{ formatMoney(recurringInvoice.estimatedSubtotal || recurringInvoice.subtotal, recurringInvoice.currency) }}</strong></div>
                <div class="text-sm">TVA: <strong>{{ formatMoney(recurringInvoice.estimatedVatTotal || recurringInvoice.vatTotal, recurringInvoice.currency) }}</strong></div>
                <div class="text-lg font-bold">
                  {{ $t('invoices.total') }}: {{ formatMoney(recurringInvoice.estimatedTotal || recurringInvoice.total, recurringInvoice.currency) }}
                </div>
                <div v-if="recurringInvoice.estimatedTotal && recurringInvoice.estimatedTotal !== recurringInvoice.total" class="flex items-center justify-end gap-1 mt-1">
                  <UIcon name="i-lucide-info" class="size-3.5 text-(--ui-text-muted)" />
                  <span class="text-xs text-(--ui-text-muted)">{{ $t('recurringInvoices.estimatedTotalInfo') }}</span>
                </div>
              </div>
            </div>
          </UCard>

          <!-- Notes -->
          <UCard v-if="recurringInvoice.notes">
            <template #header>
              <h3 class="font-semibold">{{ $t('common.notes') }}</h3>
            </template>
            <p class="text-sm">{{ recurringInvoice.notes }}</p>
          </UCard>

        <!-- Issue Now Modal -->
        <SharedConfirmModal
          v-model:open="issueNowModalOpen"
          :title="$t('recurringInvoices.issueNow')"
          :description="$t('recurringInvoices.issueNowDescription')"
          icon="i-lucide-file-plus"
          color="primary"
          :confirm-label="$t('recurringInvoices.issueNow')"
          :loading="issuing"
          @confirm="onIssueNow"
        />

        <!-- Delete Modal -->
        <SharedConfirmModal
          v-model:open="deleteModalOpen"
          :title="$t('common.delete')"
          :description="$t('recurringInvoices.deleteConfirmDescription')"
          icon="i-lucide-trash-2"
          color="error"
          :confirm-label="$t('common.delete')"
          :loading="deleting"
          @confirm="onDelete"
        />

        <!-- Edit Slideover -->
        <USlideover
          v-model:open="editSlideoverOpen"
          :ui="{ content: 'sm:max-w-2xl' }"
        >
          <template #header>
            <span class="text-lg font-semibold">{{ $t('common.edit') }}</span>
          </template>
          <template #body>
            <RecurringInvoicesRecurringInvoiceForm
              v-if="editSlideoverOpen"
              :recurring-invoice="recurringInvoice"
              @saved="onSaved"
              @cancel="editSlideoverOpen = false"
            />
          </template>
        </USlideover>
      </div>

      <!-- Loading skeleton -->
      <div v-else class="text-center py-20">
        <USkeleton class="h-8 w-64 mx-auto mb-4" />
        <USkeleton class="h-4 w-48 mx-auto" />
      </div>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
import type { RecurringInvoice } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const store = useRecurringInvoiceStore()

const recurringInvoice = ref<RecurringInvoice | null>(null)
const editSlideoverOpen = ref(false)
const toggling = ref(false)
const issueNowModalOpen = ref(false)
const issuing = ref(false)
const deleteModalOpen = ref(false)
const deleting = ref(false)

const lineColumns = [
  { accessorKey: 'description', header: $t('invoices.lineDescription') },
  { accessorKey: 'quantity', header: $t('invoices.quantity') },
  { accessorKey: 'unitOfMeasure', header: $t('invoices.unit') },
  { accessorKey: 'unitPrice', header: $t('invoices.unitPrice') },
  { accessorKey: 'vatRate', header: 'TVA %' },
  { accessorKey: 'vatAmount', header: $t('invoices.vatTotal') },
  { accessorKey: 'lineTotal', header: $t('invoices.total') },
]

function formatDate(dateStr?: string | null): string {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleDateString('ro-RO')
}

function formatMoney(amount?: string | number, currency = 'RON') {
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency }).format(Number(amount || 0))
}

async function onToggle() {
  if (!recurringInvoice.value) return
  toggling.value = true
  const result = await store.toggleRecurringInvoice(recurringInvoice.value.id)
  toggling.value = false
  if (result) {
    recurringInvoice.value = result
    toast.add({
      title: result.isActive ? $t('recurringInvoices.activated') : $t('recurringInvoices.deactivated'),
      color: 'success',
    })
  }
  else {
    toast.add({ title: $t('recurringInvoices.toggleError'), color: 'error' })
  }
}

async function onIssueNow() {
  if (!recurringInvoice.value) return
  issuing.value = true
  const result = await store.issueNow(recurringInvoice.value.id)
  issuing.value = false
  if (result) {
    issueNowModalOpen.value = false
    toast.add({
      title: $t('recurringInvoices.issueNowSuccess', { number: result.invoiceNumber }),
      color: 'success',
      icon: 'i-lucide-check',
    })
    // Refresh the recurring invoice to get updated lastIssuedAt/nextIssuanceDate
    recurringInvoice.value = await store.fetchRecurringInvoice(route.params.uuid as string)
    // Navigate to the created document
    if (result.invoiceId) {
      const path = result.documentType === 'proforma'
        ? `/proforma-invoices/${result.invoiceId}`
        : `/invoices/${result.invoiceId}`
      navigateTo(path)
    }
  }
  else {
    toast.add({ title: $t('recurringInvoices.issueNowError'), color: 'error' })
  }
}

function onSaved(ri: RecurringInvoice) {
  recurringInvoice.value = ri
  editSlideoverOpen.value = false
  toast.add({
    title: $t('recurringInvoices.updateSuccess'),
    color: 'success',
    icon: 'i-lucide-check',
  })
}

async function onDelete() {
  deleting.value = true
  const ok = await store.deleteRecurringInvoice(route.params.uuid as string)
  deleting.value = false
  if (ok) {
    deleteModalOpen.value = false
    toast.add({ title: $t('recurringInvoices.deleteSuccess'), color: 'success' })
    navigateTo('/recurring-invoices')
  }
  else {
    toast.add({ title: $t('recurringInvoices.deleteError'), color: 'error' })
  }
}

onMounted(async () => {
  recurringInvoice.value = await store.fetchRecurringInvoice(route.params.uuid as string)
})
</script>
