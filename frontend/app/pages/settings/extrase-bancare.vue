<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const intlLocale = useIntlLocale()
useHead({ title: $t('bankStatement.title') })

const store = useBordereauStore()
const companyStore = useCompanyStore()

const importModalOpen = ref(false)
const importModal = ref<{ openWith: (files: File[]) => Promise<void> } | null>(null)
const dropEnabled = computed(() => !importModalOpen.value)
const { dragging } = usePageFileDrop(files => importModal.value?.openWith(files), { accept: ['.csv', '.xlsx', '.xls'], enabled: dropEnabled })
const editModalOpen = ref(false)
const editTransaction = ref<any>(null)
const selectedRows = ref<string[]>([])

function handleFilter() {
  store.fetchTransactions()
}

function handleEdit(id: string) {
  editTransaction.value = store.transactions.find(t => t.id === id) || null
  editModalOpen.value = true
}

function handleEditUpdated() {
  store.fetchTransactions()
  selectedRows.value = []
}

function handleBulkSaved() {
  selectedRows.value = []
}

watch(importModalOpen, (val) => {
  if (!val) selectedRows.value = []
})

// Total row
const totalAmount = computed(() => {
  const sum = store.transactions.reduce((acc, t) => acc + Number(t.amount), 0)
  return new Intl.NumberFormat(intlLocale, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(sum)
})

watch(() => companyStore.currentCompanyId, () => {
  store.fetchTransactions()
  store.fetchProviders()
})

onMounted(() => {
  store.filters.sourceType = 'bank_statement'
  store.pagination.page = 1
  store.fetchTransactions()
  store.fetchProviders()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Whole-page drop zone: dropping a file opens the import with it already attached -->
    <div
      v-if="dragging"
      class="fixed inset-0 z-50 flex items-center justify-center bg-primary/10 backdrop-blur-[2px] border-4 border-dashed border-primary pointer-events-none"
    >
      <div class="rounded-xl bg-(--ui-bg) px-8 py-6 shadow-lg text-center">
        <UIcon name="i-lucide-file-down" class="size-10 text-primary mx-auto mb-2" />
        <p class="text-lg font-semibold">{{ $t('borderou.dropOverlay') }}</p>
        <p class="text-sm text-(--ui-text-muted)">.csv, .xlsx, .xls</p>
      </div>
    </div>
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold">{{ $t('bankStatement.title') }}</h1>
        <p class="text-sm text-(--ui-text-muted) mt-1">{{ $t('bankStatement.description') }} {{ $t('borderou.dropPageHint') }}</p>
      </div>
      <UButton
        :label="$t('bankStatement.importButton')"
        icon="i-lucide-upload"
        @click="importModalOpen = true"
      />
    </div>

    <!-- Summary card (show after import) -->
    <BorderouSummaryCard
      v-if="store.summary.total > 0"
      :summary="store.summary"
    />

    <!-- Filters -->
    <BorderouFilters @filter="handleFilter" />

    <!-- Bulk actions -->
    <BorderouBulkActions
      :selected-ids="selectedRows"
      @saved="handleBulkSaved"
      @cleared="selectedRows = []"
    />

    <!-- Table -->
    <UPageCard
      variant="subtle"
      :ui="{ container: 'p-0 sm:p-0 gap-y-0', wrapper: 'items-stretch' }"
    >
      <BorderouTable
        v-model:selected="selectedRows"
        :transactions="store.transactions"
        :loading="store.loading"
        @edit="handleEdit"
      />

      <!-- Total footer -->
      <div
        v-if="store.transactions.length > 0"
        class="flex items-center justify-between px-4 py-3 border-t border-default bg-elevated/50"
      >
        <span class="text-sm font-medium">{{ $t('borderou.total') }}</span>
        <span class="text-sm font-semibold tabular-nums">{{ totalAmount }} RON</span>
      </div>
    </UPageCard>

    <!-- Pagination -->
    <div v-if="store.pagination.total > store.pagination.limit" class="flex justify-center">
      <UPagination
        v-model:page="store.pagination.page"
        :total="store.pagination.total"
        :items-per-page="store.pagination.limit"
        @update:page="store.fetchTransactions()"
      />
    </div>

    <!-- Modals -->
    <BorderouImportModal
      ref="importModal"
      v-model:open="importModalOpen"
      source-type="bank_statement"
    />

    <BorderouEditModal
      v-model:open="editModalOpen"
      :transaction="editTransaction"
      @updated="handleEditUpdated"
    />
  </div>
</template>
