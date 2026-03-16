<script setup lang="ts">
import { DeclarationStatusColor, DeclarationTypeLabel } from '~/types/enums'
import type { DeclarationType, DeclarationStatus } from '~/types/enums'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const { can } = usePermissions()
const store = useDeclarationStore()
const toast = useToast()
const router = useRouter()

useHead({ title: $t('declarations.title') })

onMounted(() => {
  store.fetchDeclarations()
})

watch([() => store.filters, () => store.page], () => {
  store.fetchDeclarations()
}, { deep: true })

// ── Selection ──────────────────────────────────────────────────────
const { selectedIds, allSelected, toggle, isSelected, clear: clearSelection, count: selectionCount } = useTableSelection(
  computed(() => store.items),
  { canSelect: (item: any) => ['draft', 'validated'].includes(item.status) },
)
const bulkLoading = ref(false)

// ── Create Slideover ───────────────────────────────────────────────
const createOpen = ref(false)
const submitting = ref(false)

const now = new Date()
const prevMonth = now.getMonth()
const defaultYear = prevMonth === 0 ? now.getFullYear() - 1 : now.getFullYear()
const defaultMonth = prevMonth === 0 ? 12 : prevMonth

const createForm = reactive({
  type: 'd394',
  year: defaultYear,
  month: defaultMonth,
  periodType: 'monthly',
})

const createTypeOptions = [
  { label: '── Auto-populate ──', value: '', disabled: true },
  { label: 'D394 - Declaratie informativa livr/achiz', value: 'd394' },
  { label: 'D300 - Decont TVA', value: 'd300' },
  { label: 'D390 - Declaratie recapitulativa VIES', value: 'd390' },
  { label: 'D392 - Operatiuni intracomunitare', value: 'd392' },
  { label: 'D393 - VIES servicii', value: 'd393' },
  { label: '── Manual ──', value: '', disabled: true },
  { label: 'D100 - Obligatii plata buget de stat', value: 'd100' },
  { label: 'D101 - Impozit pe profit', value: 'd101' },
  { label: 'D106 - Impozit specific activitati', value: 'd106' },
  { label: 'D112 - Declaratie unica (CAS/CASS)', value: 'd112' },
  { label: 'D120 - Impozit microintreprinderi', value: 'd120' },
  { label: 'D130 - Impozit retinut la sursa', value: 'd130' },
  { label: 'D180 - Impozit pe dividende', value: 'd180' },
  { label: 'D205 - Informativa retineri la sursa', value: 'd205' },
  { label: 'D208 - Declaratie informativa', value: 'd208' },
  { label: 'D212 - Declaratie unica PF', value: 'd212' },
  { label: 'D301 - Decont special TVA', value: 'd301' },
  { label: 'D311 - TVA regim special', value: 'd311' },
]

async function onCreate() {
  submitting.value = true
  try {
    const declaration = await store.createDeclaration({
      type: createForm.type,
      year: createForm.year,
      month: createForm.month,
      periodType: createForm.periodType,
    })
    toast.add({ title: $t('declarations.createSuccess'), color: 'success' })
    createOpen.value = false
    router.push(`/declarations/${declaration.id}`)
  } catch (e: any) {
    toast.add({ title: e?.message ?? $t('declarations.createError'), color: 'error' })
  } finally {
    submitting.value = false
  }
}

async function handleBulkSubmit() {
  bulkLoading.value = true
  try {
    const result = await store.bulkSubmit(selectedIds.value)
    toast.add({ title: $t('declarations.bulkSubmitSuccess', { count: result.submitted }), color: 'success' })
    clearSelection()
    store.fetchDeclarations()
  } catch (e: any) {
    toast.add({ title: e?.message ?? $t('declarations.submitError'), color: 'error' })
  } finally {
    bulkLoading.value = false
  }
}

// ── Sync & Refresh ──────────────────────────────────────────────────
const refreshing = ref(false)
const syncing = ref(false)
const syncPopoverOpen = ref(false)
const syncYear = ref(new Date().getFullYear())

async function handleRefreshStatuses() {
  refreshing.value = true
  try {
    await store.refreshStatuses()
    toast.add({ title: $t('declarations.refreshSuccess'), color: 'success' })
    store.fetchDeclarations()
  } catch (e: any) {
    toast.add({ title: e?.message ?? $t('declarations.syncError'), color: 'error' })
  } finally {
    refreshing.value = false
  }
}

async function handleSyncFromAnaf() {
  syncing.value = true
  try {
    await store.syncFromAnaf(syncYear.value)
    toast.add({ title: $t('declarations.syncSuccess'), color: 'success' })
    syncPopoverOpen.value = false
    store.fetchDeclarations()
  } catch (e: any) {
    toast.add({ title: e?.message ?? $t('declarations.syncError'), color: 'error' })
  } finally {
    syncing.value = false
  }
}

// ── Upload Slideover ───────────────────────────────────────────────
const uploadOpen = ref(false)
const uploadFiles = ref<File[]>([])
const uploading = ref(false)
const uploadResults = ref<Array<{ file: string; success: boolean; id?: string; error?: string }>>([])

function onUploadFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  if (input.files) {
    uploadFiles.value = [...uploadFiles.value, ...Array.from(input.files)]
  }
}

function removeUploadFile(index: number) {
  uploadFiles.value.splice(index, 1)
}

function onUploadDrop(event: DragEvent) {
  event.preventDefault()
  if (event.dataTransfer?.files) {
    const xmlFiles = Array.from(event.dataTransfer.files).filter(f => f.name.endsWith('.xml'))
    uploadFiles.value = [...uploadFiles.value, ...xmlFiles]
  }
}

function onUploadDragOver(event: DragEvent) {
  event.preventDefault()
}

async function uploadAll() {
  if (!uploadFiles.value.length) return
  uploading.value = true
  uploadResults.value = []

  for (const file of uploadFiles.value) {
    try {
      const declaration = await store.uploadXml(file)
      uploadResults.value.push({ file: file.name, success: true, id: declaration.id })
    } catch (e: any) {
      uploadResults.value.push({ file: file.name, success: false, error: e?.message ?? 'Upload failed' })
    }
  }

  uploading.value = false

  const successCount = uploadResults.value.filter(r => r.success).length
  if (successCount > 0) {
    toast.add({ title: $t('declarations.uploadSuccess', { count: successCount }), color: 'success' })
    store.fetchDeclarations()
  }
}

function resetUpload() {
  uploadFiles.value = []
  uploadResults.value = []
}

// ── Filters ────────────────────────────────────────────────────────
const currentYear = new Date().getFullYear()
const yearOptions = Array.from({ length: 5 }, (_, i) => ({
  label: String(currentYear - i),
  value: currentYear - i,
}))

const monthOptions = Array.from({ length: 12 }, (_, i) => ({
  label: String(i + 1).padStart(2, '0'),
  value: i + 1,
}))

const typeOptions = [
  { label: 'D394', value: 'd394' },
  { label: 'D300', value: 'd300' },
  { label: 'D390', value: 'd390' },
  { label: 'D392', value: 'd392' },
  { label: 'D393', value: 'd393' },
  { label: 'D100', value: 'd100' },
  { label: 'D101', value: 'd101' },
  { label: 'D106', value: 'd106' },
  { label: 'D112', value: 'd112' },
  { label: 'D120', value: 'd120' },
  { label: 'D130', value: 'd130' },
  { label: 'D180', value: 'd180' },
  { label: 'D205', value: 'd205' },
  { label: 'D208', value: 'd208' },
  { label: 'D212', value: 'd212' },
  { label: 'D301', value: 'd301' },
  { label: 'D311', value: 'd311' },
]

const statusOptions = [
  { label: $t('declarations.statuses.draft'), value: 'draft' },
  { label: $t('declarations.statuses.validated'), value: 'validated' },
  { label: $t('declarations.statuses.submitted'), value: 'submitted' },
  { label: $t('declarations.statuses.processing'), value: 'processing' },
  { label: $t('declarations.statuses.accepted'), value: 'accepted' },
  { label: $t('declarations.statuses.rejected'), value: 'rejected' },
  { label: $t('declarations.statuses.error'), value: 'error' },
]

const tableColumns = [
  { id: 'select', header: '', accessorKey: 'id', size: 40 },
  { accessorKey: 'type', header: $t('declarations.type') },
  { accessorKey: 'period', header: $t('declarations.period') },
  { accessorKey: 'status', header: $t('declarations.status') },
  { accessorKey: 'submittedAt', header: $t('declarations.submittedAt') },
  { accessorKey: 'actions', header: '' },
]

const tableData = computed(() =>
  store.items.map((d: any) => ({
    ...d,
    period: `${d.year}-${String(d.month).padStart(2, '0')}`,
  }))
)

function onRowClick(_e: Event, row: any) {
  router.push(`/declarations/${row.original.id}`)
}
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('declarations.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            v-if="can(P.DECLARATION_SUBMIT)"
            :label="$t('declarations.refreshStatuses')"
            icon="i-lucide-refresh-cw"
            variant="outline"
            :loading="refreshing"
            @click="handleRefreshStatuses"
          />
          <UPopover v-if="can(P.DECLARATION_SUBMIT)" v-model:open="syncPopoverOpen">
            <UButton
              :label="$t('declarations.syncFromAnaf')"
              icon="i-lucide-cloud-download"
              variant="outline"
              :loading="syncing"
            />
            <template #content>
              <div class="p-4 space-y-3 w-56">
                <UFormField :label="$t('declarations.selectYear')">
                  <USelectMenu
                    v-model="syncYear"
                    :items="yearOptions"
                    value-key="value"
                  />
                </UFormField>
                <UButton
                  :label="$t('declarations.syncFromAnaf')"
                  icon="i-lucide-cloud-download"
                  color="primary"
                  :loading="syncing"
                  block
                  @click="handleSyncFromAnaf"
                />
              </div>
            </template>
          </UPopover>
          <UButton
            v-if="can(P.DECLARATION_SUBMIT)"
            :label="$t('declarations.uploadXml')"
            icon="i-lucide-upload"
            variant="outline"
            @click="uploadOpen = true; resetUpload()"
          />
          <UButton
            v-if="can(P.DECLARATION_SUBMIT)"
            :label="$t('declarations.create')"
            icon="i-lucide-plus"
            @click="createOpen = true"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <!-- Filters -->
      <div class="flex flex-wrap items-center gap-1.5">
        <USelectMenu
          v-model="(store.filters.type as any)"
          :items="typeOptions"
          value-key="value"
          :placeholder="$t('declarations.filterType')"
          class="w-32"
          nullable
        />
        <USelectMenu
          v-model="(store.filters.status as any)"
          :items="statusOptions"
          value-key="value"
          :placeholder="$t('declarations.filterStatus')"
          class="w-40"
          nullable
        />
        <USelectMenu
          v-model="(store.filters.year as any)"
          :items="yearOptions"
          value-key="value"
          :placeholder="$t('declarations.filterYear')"
          class="w-28"
          nullable
        />
        <USelectMenu
          v-model="(store.filters.month as any)"
          :items="monthOptions"
          value-key="value"
          :placeholder="$t('declarations.filterMonth')"
          class="w-24"
          nullable
        />
      </div>

      <!-- Bulk Actions Bar -->
      <SharedTableBulkBar :count="selectionCount" :loading="bulkLoading" @clear="clearSelection">
        <template #actions>
          <UButton
            :label="$t('declarations.submit')"
            icon="i-lucide-send"
            color="primary"
            variant="soft"
            size="sm"
            :loading="bulkLoading"
            @click="handleBulkSubmit"
          />
        </template>
      </SharedTableBulkBar>

      <!-- Table -->
      <UTable
        v-if="!store.isEmpty"
        :data="tableData"
        :columns="tableColumns"
        :loading="store.loading"
        class="shrink-0"
        :ui="{
          base: 'table-fixed',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
          td: 'border-b border-default',
        }"
        @select="onRowClick"
      >
        <template #select-header>
          <input v-model="allSelected" type="checkbox" class="accent-primary">
        </template>
        <template #select-cell="{ row }">
          <input
            v-if="['draft', 'validated'].includes(row.original.status)"
            :checked="isSelected(row.original.id)"
            type="checkbox"
            class="accent-primary"
            @click.stop
            @change="toggle(row.original.id)"
          >
        </template>

        <template #type-cell="{ row }">
          <span class="font-mono font-medium">{{ row.original.type.toUpperCase() }}</span>
        </template>

        <template #status-cell="{ row }">
          <UBadge :color="(DeclarationStatusColor[row.original.status as DeclarationStatus] ?? 'neutral') as any" variant="subtle" size="xs">
            {{ $t(`declarations.statuses.${row.original.status}`) }}
          </UBadge>
        </template>

        <template #submittedAt-cell="{ row }">
          <span v-if="row.original.submittedAt" class="text-sm text-muted">
            {{ new Date(row.original.submittedAt).toLocaleDateString() }}
          </span>
          <span v-else class="text-sm text-dimmed">—</span>
        </template>

        <template #actions-cell="{ row }">
          <UButton
            variant="ghost"
            icon="i-lucide-eye"
            size="xs"
            :to="`/declarations/${row.original.id}`"
          />
        </template>
      </UTable>

      <!-- Empty State -->
      <UEmpty v-if="!store.loading && store.isEmpty" icon="i-lucide-file-text" :title="$t('declarations.empty')">
        <UButton v-if="can(P.DECLARATION_SUBMIT)" :label="$t('declarations.create')" icon="i-lucide-plus" @click="createOpen = true" />
      </UEmpty>

      <!-- Pagination -->
      <div v-if="store.totalPages > 1" class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <span class="text-sm text-muted">
          {{ $t('common.showing') }} {{ store.items.length }} {{ $t('common.of') }} {{ store.total }}
        </span>
        <UPagination v-model:page="store.page" :total="store.total" :items-per-page="store.limit" />
      </div>

      <!-- Upload XML Slideover -->
      <USlideover v-model:open="uploadOpen">
        <template #header>
          <h3 class="text-lg font-semibold">{{ $t('declarations.uploadTitle') }}</h3>
        </template>
        <template #body>
          <div class="space-y-6">
            <!-- Drop zone -->
            <div
              class="border-2 border-dashed border-default rounded-lg p-6 text-center cursor-pointer hover:border-primary transition-colors"
              @drop="onUploadDrop"
              @dragover="onUploadDragOver"
              @click="($refs.uploadFileInput as HTMLInputElement)?.click()"
            >
              <UIcon name="i-lucide-upload" class="text-2xl text-dimmed mb-2" />
              <p class="text-sm text-muted">{{ $t('declarations.dropZone') }}</p>
              <input
                ref="uploadFileInput"
                type="file"
                accept=".xml"
                multiple
                class="hidden"
                @change="onUploadFileChange"
              >
            </div>

            <!-- File list -->
            <div v-if="uploadFiles.length" class="space-y-2">
              <div v-for="(file, i) in uploadFiles" :key="i" class="flex items-center justify-between p-2 rounded-lg bg-elevated">
                <div class="flex items-center gap-2">
                  <UIcon name="i-lucide-file-code" class="text-muted" />
                  <span class="text-sm">{{ file.name }}</span>
                  <span class="text-xs text-dimmed">({{ (file.size / 1024).toFixed(1) }} KB)</span>
                </div>
                <UButton icon="i-lucide-x" variant="ghost" color="error" size="xs" @click="removeUploadFile(i)" />
              </div>
            </div>

            <!-- Upload button -->
            <UButton
              v-if="uploadFiles.length"
              :label="$t('declarations.uploadAll', { count: uploadFiles.length })"
              icon="i-lucide-upload"
              color="primary"
              :loading="uploading"
              block
              @click="uploadAll"
            />

            <!-- Results -->
            <div v-if="uploadResults.length" class="space-y-2">
              <div v-for="(r, i) in uploadResults" :key="i" class="flex items-center justify-between p-2 rounded-lg" :class="r.success ? 'bg-green-50 dark:bg-green-900/20' : 'bg-red-50 dark:bg-red-900/20'">
                <span class="text-sm">{{ r.file }}</span>
                <div class="flex items-center gap-2">
                  <UBadge v-if="r.success" color="success" variant="subtle" size="xs">OK</UBadge>
                  <UBadge v-else color="error" variant="subtle" size="xs">{{ r.error }}</UBadge>
                  <UButton v-if="r.success" icon="i-lucide-eye" variant="ghost" size="xs" :to="`/declarations/${r.id}`" @click="uploadOpen = false" />
                </div>
              </div>
            </div>
          </div>
        </template>
      </USlideover>

      <!-- Create Declaration Slideover -->
      <USlideover v-model:open="createOpen">
        <template #header>
          <h3 class="text-lg font-semibold">{{ $t('declarations.createTitle') }}</h3>
        </template>
        <template #body>
          <form class="space-y-4" @submit.prevent="onCreate">
            <UFormField :label="$t('declarations.type')">
              <USelectMenu
                v-model="createForm.type"
                :items="createTypeOptions"
                value-key="value"
              />
            </UFormField>

            <div class="grid grid-cols-2 gap-4">
              <UFormField :label="$t('declarations.year')">
                <USelectMenu
                  v-model="createForm.year"
                  :items="yearOptions"
                  value-key="value"
                />
              </UFormField>

              <UFormField :label="$t('declarations.month')">
                <USelectMenu
                  v-model="createForm.month"
                  :items="monthOptions"
                  value-key="value"
                />
              </UFormField>
            </div>

            <UButton
              type="submit"
              :label="$t('declarations.createAndPopulate')"
              icon="i-lucide-plus"
              color="primary"
              :loading="submitting"
              block
            />
          </form>
        </template>
      </USlideover>
    </template>
  </UDashboardPanel>
</template>
