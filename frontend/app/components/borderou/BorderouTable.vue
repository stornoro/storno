<script setup lang="ts">
const { t: $t } = useI18n()

const props = defineProps<{
  transactions: any[]
  loading: boolean
}>()

const selectedRows = defineModel<string[]>('selected', { default: () => [] })

const emit = defineEmits<{
  edit: [id: string]
}>()

const columns = [
  {
    id: 'select',
    header: '',
    accessorKey: 'id',
    size: 40,
  },
  {
    accessorKey: 'matchConfidence',
    header: '',
    size: 40,
  },
  {
    accessorKey: 'transactionDate',
    header: $t('borderou.colDate'),
  },
  {
    accessorKey: 'clientName',
    header: $t('borderou.colClient'),
  },
  {
    accessorKey: 'explanation',
    header: $t('borderou.colExplanation'),
  },
  {
    accessorKey: 'documentType',
    header: $t('borderou.colDocument'),
  },
  {
    accessorKey: 'amount',
    header: $t('borderou.colAmount'),
  },
  {
    accessorKey: 'matchedInvoiceNumber',
    header: $t('borderou.colAssociated'),
  },
  {
    accessorKey: 'status',
    header: $t('borderou.colStatus'),
  },
  {
    id: 'actions',
    header: '',
    size: 60,
  },
]

const confidenceColors: Record<string, string> = {
  certain: 'bg-blue-500',
  attention: 'bg-gray-400',
  no_match: 'bg-orange-500',
}

const statusColors: Record<string, string> = {
  unsaved: 'warning',
  saved: 'primary',
  duplicate: 'error',
  error: 'error',
}

const statusLabels: Record<string, string> = {
  unsaved: $t('borderou.statusUnsaved'),
  saved: $t('borderou.statusSaved'),
  duplicate: $t('borderou.statusDuplicate'),
  error: $t('borderou.statusError'),
}

const docTypeLabels: Record<string, string> = {
  ramburs: $t('borderou.docTypeRamburs'),
  transfer: $t('borderou.docTypeBankTransfer'),
  card: $t('borderou.docTypeCard'),
}

function formatDate(dateStr: string): string {
  if (!dateStr) return '-'
  const d = new Date(dateStr)
  return d.toLocaleDateString('ro-RO', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function formatAmount(amount: string, currency: string): string {
  return new Intl.NumberFormat('ro-RO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(amount)) + ' ' + currency
}

const allSelected = computed({
  get: () => {
    const unsaved = props.transactions.filter(t => t.status === 'unsaved')
    return unsaved.length > 0 && unsaved.every(t => selectedRows.value.includes(t.id))
  },
  set: (val: boolean) => {
    if (val) {
      selectedRows.value = props.transactions.filter(t => t.status === 'unsaved').map(t => t.id)
    }
    else {
      selectedRows.value = []
    }
  },
})

function toggleRow(id: string) {
  const idx = selectedRows.value.indexOf(id)
  if (idx === -1) {
    selectedRows.value = [...selectedRows.value, id]
  }
  else {
    selectedRows.value = selectedRows.value.filter(r => r !== id)
  }
}

function getActionItems(tx: any) {
  return [[{
    label: $t('borderou.editTitle'),
    icon: 'i-lucide-pencil',
    onSelect: () => emit('edit', tx.id),
  }]]
}
</script>

<template>
  <UTable
    :data="transactions"
    :columns="columns"
    :loading="loading"
    :ui="{
      base: 'table-fixed',
      thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
      tbody: '[&>tr]:last:[&>td]:border-b-0',
      th: 'px-4',
      td: 'px-4 border-b border-default',
    }"
  >
    <!-- Select all header -->
    <template #select-header>
      <input
        v-model="allSelected"
        type="checkbox"
        class="accent-primary"
      >
    </template>

    <!-- Select cell -->
    <template #select-cell="{ row }">
      <input
        :checked="selectedRows.includes(row.original.id)"
        :disabled="row.original.status !== 'unsaved'"
        type="checkbox"
        class="accent-primary"
        @change="toggleRow(row.original.id)"
      >
    </template>

    <!-- Confidence dot -->
    <template #matchConfidence-cell="{ row }">
      <span
        class="inline-block w-2.5 h-2.5 rounded-full"
        :class="confidenceColors[row.original.matchConfidence] || 'bg-gray-300'"
        :title="row.original.matchConfidence"
      />
    </template>

    <!-- Date -->
    <template #transactionDate-cell="{ row }">
      <span class="text-sm">{{ formatDate(row.original.transactionDate) }}</span>
    </template>

    <!-- Client -->
    <template #clientName-cell="{ row }">
      <span class="text-sm">{{ row.original.matchedClientName || row.original.clientName || '-' }}</span>
    </template>

    <!-- Explanation -->
    <template #explanation-cell="{ row }">
      <span class="text-sm truncate max-w-48 block" :title="row.original.explanation">
        {{ row.original.explanation || '-' }}
      </span>
    </template>

    <!-- Document type -->
    <template #documentType-cell="{ row }">
      <span class="text-sm">{{ docTypeLabels[row.original.documentType] || row.original.documentType || '-' }}</span>
    </template>

    <!-- Amount -->
    <template #amount-cell="{ row }">
      <span class="text-sm font-medium tabular-nums">
        {{ formatAmount(row.original.amount, row.original.currency) }}
      </span>
    </template>

    <!-- Matched invoice / proforma -->
    <template #matchedInvoiceNumber-cell="{ row }">
      <div v-if="row.original.matchedInvoiceId" class="flex items-center gap-1.5">
        <UBadge label="F" color="primary" variant="subtle" size="xs" />
        <NuxtLink
          :to="`/invoices/${row.original.matchedInvoiceId}`"
          class="text-sm text-primary hover:underline"
        >
          {{ row.original.matchedInvoiceNumber }}
        </NuxtLink>
      </div>
      <div v-else-if="row.original.matchedProformaInvoiceId" class="flex items-center gap-1.5">
        <UBadge label="PF" :color="('purple' as any)" variant="subtle" size="xs" />
        <NuxtLink
          :to="`/proformas/${row.original.matchedProformaInvoiceId}`"
          class="text-sm text-purple-600 dark:text-purple-400 hover:underline"
        >
          {{ row.original.matchedProformaInvoiceNumber }}
        </NuxtLink>
      </div>
      <span v-else class="text-sm text-(--ui-text-muted)">-</span>
    </template>

    <!-- Status -->
    <template #status-cell="{ row }">
      <UBadge
        :label="statusLabels[row.original.status] || row.original.status"
        :color="(statusColors[row.original.status] as any) || 'neutral'"
        variant="subtle"
        size="sm"
      />
    </template>

    <!-- Actions -->
    <template #actions-cell="{ row }">
      <UDropdownMenu
        v-if="row.original.status === 'unsaved'"
        :items="getActionItems(row.original)"
      >
        <UButton
          icon="i-lucide-ellipsis-vertical"
          variant="ghost"
          size="xs"
          type="button"
        />
      </UDropdownMenu>
    </template>
  </UTable>

  <UEmpty
    v-if="!loading && transactions.length === 0"
    icon="i-lucide-receipt"
    title="Nu exista tranzactii"
    class="py-12"
  />
</template>
