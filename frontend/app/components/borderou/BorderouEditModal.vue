<script setup lang="ts">
const { t: $t } = useI18n()
const store = useBordereauStore()
const toast = useToast()

const props = defineProps<{
  transaction: any | null
}>()

const emit = defineEmits<{
  updated: []
}>()

const open = defineModel<boolean>('open', { default: false })
const saving = ref(false)
const loadingInvoices = ref(false)
const searchQuery = ref('')
const typeFilter = ref<string | null>(null)
let searchTimeout: ReturnType<typeof setTimeout> | null = null

const form = ref({
  clientId: null as string | null,
  invoiceId: null as string | null,
  proformaInvoiceId: null as string | null,
  amount: '',
  documentType: null as string | null,
})

const documentTypeOptions = computed(() => [
  { label: $t('borderou.docTypeRamburs'), value: 'ramburs' },
  { label: $t('borderou.docTypeBankTransfer'), value: 'transfer' },
  { label: $t('borderou.docTypeCard'), value: 'card' },
])

const typeFilterOptions = computed(() => [
  { label: $t('borderou.typeAll'), value: 'all' },
  { label: $t('borderou.typeInvoice'), value: 'invoice' },
  { label: $t('borderou.typeProforma'), value: 'proforma' },
])

// Watch for slideover open — reset form from transaction
watch(open, async (val) => {
  if (val && props.transaction) {
    searchQuery.value = ''
    typeFilter.value = null
    form.value = {
      clientId: props.transaction.matchedClientId || null,
      invoiceId: props.transaction.matchedInvoiceId || null,
      proformaInvoiceId: props.transaction.matchedProformaInvoiceId || null,
      amount: props.transaction.amount,
      documentType: props.transaction.documentType || null,
    }
    await loadInvoices()
  }
})

// Debounced search
watch(searchQuery, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => loadInvoices(), 350)
})

watch(typeFilter, () => loadInvoices())

async function loadInvoices() {
  if (!props.transaction) return
  loadingInvoices.value = true
  const type = typeFilter.value && typeFilter.value !== 'all' ? typeFilter.value : undefined
  await store.fetchAvailableInvoices(props.transaction.id, searchQuery.value || undefined, type)
  loadingInvoices.value = false
}

const selectedDocument = computed(() => {
  if (form.value.invoiceId) {
    return store.availableInvoices.find(i => i.id === form.value.invoiceId) || null
  }
  if (form.value.proformaInvoiceId) {
    return store.availableInvoices.find(i => i.id === form.value.proformaInvoiceId) || null
  }
  return null
})

const allocationSummary = computed(() => {
  const txAmount = Number(form.value.amount) || 0
  const doc = selectedDocument.value
  const allocated = doc ? Number(doc.balance) : 0
  return {
    transaction: txAmount,
    allocated: Math.min(txAmount, allocated),
    difference: txAmount - Math.min(txAmount, allocated),
  }
})

const invoiceColumns = [
  { accessorKey: 'type', header: $t('borderou.typeFilterLabel'), size: 80 },
  { accessorKey: 'number', header: $t('borderou.invoiceNumber') },
  { accessorKey: 'clientName', header: $t('borderou.colClient') },
  { accessorKey: 'issueDate', header: $t('borderou.invoiceDate'), size: 90 },
  { accessorKey: 'balance', header: $t('borderou.invoiceBalance') },
  { id: 'actions', header: '', size: 100 },
]

function selectDocument(doc: any) {
  if (doc.type === 'proforma') {
    form.value.proformaInvoiceId = doc.id
    form.value.invoiceId = null
  } else {
    form.value.invoiceId = doc.id
    form.value.proformaInvoiceId = null
  }
}

function clearDocument() {
  form.value.invoiceId = null
  form.value.proformaInvoiceId = null
}

function collectAll(doc: any) {
  selectDocument(doc)
  form.value.amount = doc.balance
}

function formatDate(dateStr: string | null): string {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleDateString('ro-RO', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function formatAmount(amount: string | number, currency?: string): string {
  return new Intl.NumberFormat('ro-RO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(amount)) + (currency ? ' ' + currency : '')
}

function isDocSelected(docId: string): boolean {
  return form.value.invoiceId === docId || form.value.proformaInvoiceId === docId
}

async function handleSave() {
  if (!props.transaction) return
  saving.value = true

  const data: any = {}
  if (form.value.clientId !== props.transaction.matchedClientId) {
    data.clientId = form.value.clientId
  }
  if (form.value.invoiceId !== props.transaction.matchedInvoiceId) {
    data.invoiceId = form.value.invoiceId
  }
  if (form.value.proformaInvoiceId !== props.transaction.matchedProformaInvoiceId) {
    data.proformaInvoiceId = form.value.proformaInvoiceId
  }
  if (form.value.amount !== props.transaction.amount) {
    data.amount = form.value.amount
  }
  if (form.value.documentType !== props.transaction.documentType) {
    data.documentType = form.value.documentType
  }

  if (Object.keys(data).length === 0) {
    open.value = false
    saving.value = false
    return
  }

  const result = await store.updateTransaction(props.transaction.id, data)
  saving.value = false

  if (result) {
    toast.add({ title: $t('common.saved'), color: 'success' })
    open.value = false
    emit('updated')
  } else {
    toast.add({ title: store.error || $t('common.error'), color: 'error' })
  }
}
</script>

<template>
  <USlideover v-model:open="open" :ui="{ content: 'sm:max-w-3xl' }">
    <template #header>
      <h3 class="text-lg font-semibold">{{ $t('borderou.editTitle') }}</h3>
    </template>

    <template #body>
      <div v-if="transaction" class="space-y-5">
        <!-- Transaction summary card (read-only) -->
        <div class="p-4 rounded-lg bg-(--ui-bg-elevated) border border-(--ui-border)">
          <div class="flex items-center justify-between gap-4">
            <div class="min-w-0">
              <p class="text-sm font-medium truncate">
                {{ formatDate(transaction.transactionDate) }}
                <span class="mx-1.5 text-(--ui-text-muted)">|</span>
                {{ transaction.clientName || '-' }}
              </p>
              <p class="text-xs text-(--ui-text-muted) truncate mt-0.5">
                <template v-if="transaction.bankReference">
                  {{ $t('borderou.editExplanation') }}: {{ transaction.bankReference }}
                </template>
                <template v-else-if="transaction.explanation">
                  {{ transaction.explanation }}
                </template>
              </p>
            </div>
            <p class="text-lg font-semibold tabular-nums whitespace-nowrap">
              {{ formatAmount(transaction.amount, transaction.currency) }}
            </p>
          </div>
        </div>

        <!-- Editable fields: amount + document type -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-1">{{ $t('borderou.editAmountLabel') }}</label>
            <UInput v-model="form.amount" type="number" step="0.01" min="0" />
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">{{ $t('borderou.editDocumentTypeLabel') }}</label>
            <USelectMenu
              v-model="form.documentType"
              :items="documentTypeOptions"
              value-key="value"
              :placeholder="$t('borderou.editDocumentTypeLabel')"
            />
          </div>
        </div>

        <!-- Selected document card -->
        <div v-if="selectedDocument" class="p-3 rounded-lg border border-primary/30 bg-primary/5">
          <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 min-w-0">
              <UBadge
                :label="selectedDocument.type === 'proforma' ? $t('borderou.typeBadgeProforma') : $t('borderou.typeBadgeInvoice')"
                :color="(selectedDocument.type === 'proforma' ? 'purple' : 'primary') as any"
                variant="subtle"
                size="sm"
              />
              <span class="text-sm font-medium truncate">{{ selectedDocument.number }}</span>
              <span class="text-sm tabular-nums text-(--ui-text-muted)">{{ formatAmount(selectedDocument.balance, selectedDocument.currency) }}</span>
            </div>
            <UButton
              icon="i-lucide-x"
              variant="ghost"
              size="xs"
              type="button"
              @click="clearDocument"
            />
          </div>
        </div>

        <!-- Search + Type filter -->
        <div class="flex items-center gap-3">
          <UInput
            v-model="searchQuery"
            :placeholder="$t('borderou.searchPlaceholder')"
            icon="i-lucide-search"
            class="flex-1"
          />
          <USelectMenu
            v-model="typeFilter"
            :items="typeFilterOptions"
            value-key="value"
            :placeholder="$t('borderou.typeAll')"
            class="w-36"
          />
        </div>

        <!-- Available documents table -->
        <div v-if="loadingInvoices" class="flex items-center justify-center py-6">
          <UIcon name="i-lucide-loader-2" class="w-5 h-5 animate-spin text-(--ui-text-muted)" />
        </div>
        <template v-else-if="store.availableInvoices.length > 0">
          <UTable
            :data="store.availableInvoices"
            :columns="invoiceColumns"
            :ui="{
              base: 'table-fixed',
              thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
              tbody: '[&>tr]:last:[&>td]:border-b-0',
              th: 'px-3 text-xs',
              td: 'px-3 py-2 border-b border-default',
            }"
          >
            <template #type-cell="{ row }">
              <UBadge
                :label="row.original.type === 'proforma' ? $t('borderou.typeBadgeProforma') : $t('borderou.typeBadgeInvoice')"
                :color="(row.original.type === 'proforma' ? 'purple' : 'primary') as any"
                variant="subtle"
                size="sm"
              />
            </template>

            <template #number-cell="{ row }">
              <button
                type="button"
                class="text-sm font-medium hover:text-primary"
                :class="isDocSelected(row.original.id) ? 'text-primary' : ''"
                @click="selectDocument(row.original)"
              >
                {{ row.original.number }}
              </button>
            </template>

            <template #clientName-cell="{ row }">
              <span class="text-sm truncate block max-w-32" :title="row.original.clientName ?? undefined">{{ row.original.clientName || '-' }}</span>
            </template>

            <template #issueDate-cell="{ row }">
              <span class="text-xs">{{ formatDate(row.original.issueDate) }}</span>
            </template>

            <template #balance-cell="{ row }">
              <span class="text-xs tabular-nums font-medium">{{ formatAmount(row.original.balance, row.original.currency) }}</span>
            </template>

            <template #actions-cell="{ row }">
              <UButton
                :label="$t('borderou.editCollectAll')"
                size="xs"
                variant="soft"
                type="button"
                @click="collectAll(row.original)"
              />
            </template>
          </UTable>
        </template>
        <div v-else class="py-6 text-center">
          <UIcon name="i-lucide-inbox" class="w-8 h-8 mx-auto text-(--ui-text-muted) mb-2" />
          <p class="text-sm text-(--ui-text-muted)">{{ $t('borderou.noUnpaidInvoices') }}</p>
        </div>

        <!-- Allocation summary bar -->
        <div v-if="selectedDocument" class="flex items-center gap-4 p-3 rounded-lg bg-(--ui-bg-elevated) border border-(--ui-border) text-sm tabular-nums">
          <div>
            <span class="text-(--ui-text-muted)">{{ $t('borderou.allocationTransaction') }}:</span>
            <span class="ml-1 font-medium">{{ formatAmount(allocationSummary.transaction, transaction?.currency) }}</span>
          </div>
          <span class="text-(--ui-text-muted)">|</span>
          <div>
            <span class="text-(--ui-text-muted)">{{ $t('borderou.allocationAllocated') }}:</span>
            <span class="ml-1 font-medium">{{ formatAmount(allocationSummary.allocated, transaction?.currency) }}</span>
          </div>
          <template v-if="allocationSummary.difference > 0">
            <span class="text-(--ui-text-muted)">|</span>
            <div>
              <span class="text-(--ui-text-muted)">{{ $t('borderou.allocationDifference') }}:</span>
              <span class="ml-1 font-medium text-orange-500">+{{ formatAmount(allocationSummary.difference, transaction?.currency) }}</span>
            </div>
          </template>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton :label="$t('common.cancel')" variant="ghost" @click="open = false" />
        <UButton
          :label="$t('common.save')"
          :loading="saving"
          @click="handleSave"
        />
      </div>
    </template>
  </USlideover>
</template>
