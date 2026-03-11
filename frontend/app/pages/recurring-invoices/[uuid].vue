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

        <!-- Header: title row + action row -->
        <div class="flex flex-col gap-3">
          <!-- Title row -->
          <div class="flex items-center gap-3 min-w-0">
            <UButton icon="i-lucide-arrow-left" variant="ghost" to="/recurring-invoices" class="shrink-0" />
            <UIcon name="i-lucide-repeat" class="size-5 text-(--ui-text-muted) shrink-0" />
            <h1 class="text-2xl font-bold truncate">
              {{ recurringInvoice.reference || $t('recurringInvoices.title') }}
            </h1>
            <div class="flex items-center gap-1.5 flex-wrap">
              <UBadge :color="recurringInvoice.isActive ? 'success' : 'neutral'" variant="subtle">
                {{ recurringInvoice.isActive ? $t('common.active') : $t('common.inactive') }}
              </UBadge>
              <UBadge color="neutral" variant="subtle">
                {{ $t(`recurringInvoices.frequencies.${recurringInvoice.frequency}`) }}
              </UBadge>
              <UBadge color="neutral" variant="outline">
                {{ $t(`documentType.${recurringInvoice.documentType}`) }}
              </UBadge>
            </div>
          </div>

          <!-- Action row -->
          <div class="flex items-center gap-2 flex-wrap">
            <UButton
              :icon="recurringInvoice.isActive ? 'i-lucide-pause' : 'i-lucide-play'"
              :color="recurringInvoice.isActive ? 'neutral' : 'success'"
              variant="outline"
              :loading="toggling"
              @click="onToggle"
            >
              {{ recurringInvoice.isActive ? $t('common.inactive') : $t('common.active') }}
            </UButton>
            <UButton icon="i-lucide-file-plus" color="primary" variant="outline" :loading="issuing" @click="issueNowModalOpen = true">
              {{ $t('recurringInvoices.issueNow') }}
            </UButton>

            <!-- Separator -->
            <div class="w-px h-6 bg-(--ui-border) hidden sm:block" />

            <UButton icon="i-lucide-pencil" variant="outline" @click="editSlideoverOpen = true">
              <span class="hidden sm:inline">{{ $t('common.edit') }}</span>
            </UButton>
            <UButton icon="i-lucide-trash-2" color="error" variant="outline" @click="deleteModalOpen = true">
              <span class="hidden sm:inline">{{ $t('common.delete') }}</span>
            </UButton>
          </div>
        </div>

        <!-- Last issued banner — shown prominently when a previous invoice exists -->
        <UCard v-if="recurringInvoice.lastIssuedAt" class="border-(--ui-border-accented) bg-(--ui-bg-elevated)">
          <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
              <UIcon name="i-lucide-history" class="size-5 text-(--ui-text-muted) shrink-0" />
              <div class="text-sm">
                <span class="text-(--ui-text-muted)">{{ $t('recurringInvoices.lastIssuedAt') }}:</span>
                <span class="font-semibold ml-1">{{ formatDate(recurringInvoice.lastIssuedAt) }}</span>
                <template v-if="recurringInvoice.lastInvoiceNumber">
                  <span class="text-(--ui-text-muted) ml-3">{{ $t('recurringInvoices.lastInvoiceNumber') }}:</span>
                  <span class="font-semibold ml-1">{{ recurringInvoice.lastInvoiceNumber }}</span>
                </template>
              </div>
            </div>
            <UButton
              v-if="recurringInvoice.lastInvoiceId"
              variant="subtle"
              color="primary"
              size="sm"
              icon="i-lucide-external-link"
              :to="recurringInvoice.lastDocumentType === 'proforma'
                ? `/proforma-invoices/${recurringInvoice.lastInvoiceId}`
                : `/invoices/${recurringInvoice.lastInvoiceId}`"
            >
              {{ $t('common.view') }}
            </UButton>
          </div>
        </UCard>

        <!-- Key metrics row -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <!-- Next issuance -->
          <UCard class="text-center">
            <div class="flex flex-col items-center gap-1.5">
              <UIcon name="i-lucide-calendar-clock" class="size-5 text-primary" />
              <span class="text-xs text-(--ui-text-muted) uppercase tracking-wide font-medium">{{ $t('recurringInvoices.nextIssuanceDate') }}</span>
              <span class="text-base font-bold">{{ formatDate(recurringInvoice.nextIssuanceDate) }}</span>
            </div>
          </UCard>

          <!-- Frequency -->
          <UCard class="text-center">
            <div class="flex flex-col items-center gap-1.5">
              <UIcon name="i-lucide-repeat" class="size-5 text-primary" />
              <span class="text-xs text-(--ui-text-muted) uppercase tracking-wide font-medium">{{ $t('recurringInvoices.frequency') }}</span>
              <span class="text-base font-bold">{{ $t(`recurringInvoices.frequencies.${recurringInvoice.frequency}`) }}</span>
            </div>
          </UCard>

          <!-- Estimated total -->
          <UCard class="text-center">
            <div class="flex flex-col items-center gap-1.5">
              <UIcon name="i-lucide-banknote" class="size-5 text-primary" />
              <span class="text-xs text-(--ui-text-muted) uppercase tracking-wide font-medium">{{ $t('recurringInvoices.estimatedTotal') }}</span>
              <span class="text-base font-bold">{{ formatMoney(recurringInvoice.estimatedTotal || recurringInvoice.total, recurringInvoice.currency) }}</span>
            </div>
          </UCard>

          <!-- Currency -->
          <UCard class="text-center">
            <div class="flex flex-col items-center gap-1.5">
              <UIcon name="i-lucide-coins" class="size-5 text-primary" />
              <span class="text-xs text-(--ui-text-muted) uppercase tracking-wide font-medium">{{ $t('invoices.currency') }}</span>
              <span class="text-base font-bold">{{ recurringInvoice.currency }}</span>
            </div>
          </UCard>
        </div>

        <!-- Schedule & Client row -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Schedule card -->
          <UCard>
            <template #header>
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-calendar-days" class="size-4 text-(--ui-text-muted)" />
                <h3 class="font-semibold">{{ $t('recurringInvoices.schedule') }}</h3>
              </div>
            </template>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('recurringInvoices.frequency') }}</dt>
                <dd class="font-medium mt-0.5">{{ $t(`recurringInvoices.frequencies.${recurringInvoice.frequency}`) }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('recurringInvoices.frequencyDay') }}</dt>
                <dd class="font-medium mt-0.5">
                  <template v-if="recurringInvoice.frequency === 'weekly'">
                    {{ $t(`recurringInvoices.daysOfWeek.${recurringInvoice.frequencyDay}`) }}
                  </template>
                  <template v-else>{{ recurringInvoice.frequencyDay }}</template>
                </dd>
              </div>
              <div v-if="recurringInvoice.frequency === 'yearly' && recurringInvoice.frequencyMonth">
                <dt class="text-(--ui-text-muted)">{{ $t('recurringInvoices.frequencyMonth') }}</dt>
                <dd class="font-medium mt-0.5">{{ $t(`reports.months.${recurringInvoice.frequencyMonth}`) }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('recurringInvoices.nextIssuanceDate') }}</dt>
                <dd class="font-medium mt-0.5">{{ formatDate(recurringInvoice.nextIssuanceDate) }}</dd>
              </div>
              <div v-if="recurringInvoice.stopDate">
                <dt class="text-(--ui-text-muted)">{{ $t('recurringInvoices.stopDate') }}</dt>
                <dd class="font-medium mt-0.5">{{ formatDate(recurringInvoice.stopDate) }}</dd>
              </div>
              <div v-if="recurringInvoice.dueDateType">
                <dt class="text-(--ui-text-muted)">{{ $t('recurringInvoices.dueDateType') }}</dt>
                <dd class="font-medium mt-0.5">
                  {{ $t(`recurringInvoices.dueDateTypes.${recurringInvoice.dueDateType}`) }}
                  <span v-if="recurringInvoice.dueDateType === 'days'" class="text-(--ui-text-muted)">({{ recurringInvoice.dueDateDays }} {{ $t('common.days') }})</span>
                  <span v-if="recurringInvoice.dueDateType === 'fixed_day'" class="text-(--ui-text-muted)">({{ $t('recurringInvoices.dueDateFixedDay') }}: {{ recurringInvoice.dueDateFixedDay }})</span>
                </dd>
              </div>
            </dl>
          </UCard>

          <!-- Client card -->
          <UCard v-if="recurringInvoice.client">
            <template #header>
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                  <UIcon name="i-lucide-building-2" class="size-4 text-(--ui-text-muted)" />
                  <h3 class="font-semibold">{{ $t('invoices.client') }}</h3>
                </div>
                <UButton variant="ghost" size="xs" :to="`/clients/${recurringInvoice.client.id}`">
                  {{ $t('common.view') }}
                </UButton>
              </div>
            </template>
            <div class="space-y-2 text-sm">
              <div class="font-semibold text-base">{{ recurringInvoice.client.name }}</div>
              <div v-if="recurringInvoice.client.cui" class="text-(--ui-text-muted)">CIF: {{ recurringInvoice.client.cui }}</div>
              <div v-if="recurringInvoice.client.address" class="text-(--ui-text-muted)">{{ recurringInvoice.client.address }}</div>
              <div v-if="recurringInvoice.client.email" class="text-(--ui-text-muted)">{{ recurringInvoice.client.email }}</div>
            </div>
          </UCard>

          <!-- No client placeholder -->
          <UCard v-else>
            <template #header>
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-building-2" class="size-4 text-(--ui-text-muted)" />
                <h3 class="font-semibold">{{ $t('invoices.client') }}</h3>
              </div>
            </template>
            <p class="text-sm text-(--ui-text-muted)">-</p>
          </UCard>
        </div>

        <!-- Invoice settings -->
        <UCard>
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-settings-2" class="size-4 text-(--ui-text-muted)" />
              <h3 class="font-semibold">{{ $t('recurringInvoices.templateInfo') }}</h3>
            </div>
          </template>
          <dl class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
            <div>
              <dt class="text-(--ui-text-muted)">{{ $t('common.type') }}</dt>
              <dd class="font-medium mt-0.5">{{ $t(`documentType.${recurringInvoice.documentType}`) }}</dd>
            </div>
            <div v-if="recurringInvoice.invoiceTypeCode">
              <dt class="text-(--ui-text-muted)">{{ $t('recurringInvoices.invoiceTypeCode') }}</dt>
              <dd class="font-medium mt-0.5">{{ $t(`invoiceTypeCodes.${recurringInvoice.invoiceTypeCode}`) }}</dd>
            </div>
            <div>
              <dt class="text-(--ui-text-muted)">{{ $t('invoices.currency') }}</dt>
              <dd class="font-medium mt-0.5">{{ recurringInvoice.currency }}</dd>
            </div>
            <div v-if="recurringInvoice.paymentTerms">
              <dt class="text-(--ui-text-muted)">{{ $t('invoices.paymentTerms') }}</dt>
              <dd class="font-medium mt-0.5">{{ recurringInvoice.paymentTerms }}</dd>
            </div>
          </dl>
        </UCard>

        <!-- Lines table -->
        <UCard>
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-list" class="size-4 text-(--ui-text-muted)" />
              <h3 class="font-semibold">{{ $t('invoices.lines') }}</h3>
            </div>
          </template>

          <UTable :data="recurringInvoice.lines || []" :columns="lineColumns">
            <template #description-cell="{ row }">
              <NuxtLink v-if="row.original.productId" :to="`/products/${row.original.productId}`" class="text-(--ui-primary) hover:underline">
                {{ row.original.description }}
              </NuxtLink>
              <span v-else>{{ row.original.description }}</span>
            </template>
            <template #unitPrice-cell="{ row }">
              {{ formatMoney(row.original.unitPrice, row.original.referenceCurrency || recurringInvoice.currency) }}
            </template>
            <template #vatAmount-cell="{ row }">
              {{ formatMoney(row.original.vatAmount, row.original.referenceCurrency || recurringInvoice.currency) }}
              <span class="text-(--ui-text-muted) text-xs">({{ row.original.vatRate }}%)</span>
            </template>
            <template #lineTotal-cell="{ row }">
              <span class="font-medium">{{ formatMoney(row.original.lineTotal, row.original.referenceCurrency || recurringInvoice.currency) }}</span>
            </template>
            <template #quantity-cell="{ row }">
              {{ row.original.quantity }} {{ row.original.unitOfMeasure }}
            </template>
          </UTable>

          <!-- Totals -->
          <div class="flex justify-end mt-4 pt-4 border-t border-(--ui-border)">
            <div class="space-y-1 text-right">
              <div class="text-sm text-(--ui-text-muted)">
                {{ $t('invoices.subtotal') }}:
                <strong class="text-(--ui-text-highlighted)">{{ formatMoney(recurringInvoice.estimatedSubtotal || recurringInvoice.subtotal, recurringInvoice.currency) }}</strong>
              </div>
              <div class="text-sm text-(--ui-text-muted)">
                TVA:
                <strong class="text-(--ui-text-highlighted)">{{ formatMoney(recurringInvoice.estimatedVatTotal || recurringInvoice.vatTotal, recurringInvoice.currency) }}</strong>
              </div>
              <div class="text-lg font-bold pt-1">
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
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-file-text" class="size-4 text-(--ui-text-muted)" />
              <h3 class="font-semibold">{{ $t('common.notes') }}</h3>
            </div>
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
      <div v-else class="space-y-6">
        <div class="flex items-center gap-3">
          <USkeleton class="size-8 rounded-lg shrink-0" />
          <USkeleton class="h-8 w-64" />
          <USkeleton class="h-6 w-20 rounded-full" />
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <USkeleton v-for="i in 4" :key="i" class="h-24 rounded-xl" />
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <USkeleton class="h-48 rounded-xl" />
          <USkeleton class="h-48 rounded-xl" />
        </div>
        <USkeleton class="h-64 rounded-xl" />
      </div>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
import type { RecurringInvoice } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const intlLocale = useIntlLocale()
const route = useRoute()
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
  { accessorKey: 'unitPrice', header: $t('invoices.unitPrice') },
  { accessorKey: 'vatAmount', header: 'TVA' },
  { accessorKey: 'lineTotal', header: $t('invoices.total') },
]

function formatDate(dateStr?: string | null): string {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleDateString(intlLocale)
}

function formatMoney(amount?: string | number, currency = 'RON') {
  return new Intl.NumberFormat(intlLocale, { style: 'currency', currency }).format(Number(amount || 0))
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
