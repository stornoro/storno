<script setup lang="ts">
import Sortable from 'sortablejs'
import { storeToRefs } from 'pinia'
import type { DropdownMenuItem } from '@nuxt/ui'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('dashboard.title') })
const { isNotificationsSlideoverOpen } = useDashboard()
const dashboardStore = useDashboardStore()
const configStore = useDashboardConfigStore()
const syncStore = useSyncStore()
const companyStore = useCompanyStore()
const {
  loading,
  isSyncEnabled,
  lastSyncedAt,
} = storeToRefs(dashboardStore)

const { syncing } = storeToRefs(syncStore)
const { activeWidgets, saving } = storeToRefs(configStore)

// ── Period selector ───────────────────────────────────────────────
const {
  selectedPreset,
  customDateFrom,
  customDateTo,
  presets,
  resolvedRange,
  isCustom,
  displayLabel,
} = usePeriodSelector('currentMonth')

function fetchWithPeriod() {
  const { dateFrom, dateTo } = resolvedRange.value
  if (dateFrom && dateTo) {
    dashboardStore.fetchStats({ dateFrom, dateTo })
  }
}

// Debounce for custom date inputs
let customDebounceTimer: ReturnType<typeof setTimeout> | null = null
watch([customDateFrom, customDateTo], () => {
  if (!isCustom.value) return
  if (customDebounceTimer) clearTimeout(customDebounceTimer)
  customDebounceTimer = setTimeout(() => fetchWithPeriod(), 500)
})

// Immediate fetch when preset changes (non-custom presets resolve instantly)
watch(selectedPreset, () => {
  if (!isCustom.value) {
    fetchWithPeriod()
  }
})

// Quick actions dropdown
const quickActions = computed<DropdownMenuItem[][]>(() => [[
  {
    label: $t('invoices.newInvoice'),
    icon: 'i-lucide-file-text',
    kbds: ['C', 'I'],
    onSelect: () => navigateTo('/invoices?create=true'),
  },
  {
    label: $t('nav.proformaInvoices'),
    icon: 'i-lucide-file-check',
    kbds: ['C', 'P'],
    to: '/proforma-invoices/new',
  },
], [
  {
    label: $t('dashboard.syncNow'),
    icon: 'i-lucide-refresh-cw',
    onSelect: () => triggerSync(),
  },
]])

async function triggerSync() {
  await syncStore.triggerSync()
}

// Drive NuxtLoadingIndicator with sync progress
const { start: startLoading, finish: finishLoading, set: setLoading } = useLoadingIndicator()

watch(() => syncStore.syncing, (isSyncing) => {
  if (isSyncing) startLoading()
  else finishLoading()
})

watch(() => syncStore.syncProgress?.processed, () => {
  const p = syncStore.syncProgress
  if (p && p.total > 0) {
    setLoading(Math.round((p.processed / p.total) * 100))
  }
})

// Refresh dashboard stats only when sync completes (not on each progress event)
watch(() => syncStore.lastSyncResult, (result) => {
  if (result?.success) {
    fetchWithPeriod()
  }
})

const invoiceRealtime = useInvoiceRealtime(() => fetchWithPeriod())
const syncRealtime = useSyncRealtime()

onMounted(async () => {
  fetchWithPeriod()
  syncStore.fetchStatus()
  invoiceRealtime.start()
  syncRealtime.start()
  await configStore.loadConfig()
})

onUnmounted(() => {
  invoiceRealtime.stop()
  syncRealtime.stop()
})

watch(() => companyStore.currentCompanyId, () => {
  fetchWithPeriod()
  configStore.loadConfig()
  invoiceRealtime.stop()
  invoiceRealtime.start()
  syncRealtime.stop()
  syncRealtime.start()
})

// ── Edit mode ─────────────────────────────────────────────────────
const editMode = ref(false)
const showAddWidgetModal = ref(false)

function enterEditMode() {
  editMode.value = true
}

async function exitEditMode() {
  editMode.value = false
  await configStore.saveConfig()
}

function handleHideWidget(id: string) {
  configStore.toggleWidget(id)
}

function handleAddWidget(id: string) {
  configStore.addWidget(id)
  showAddWidgetModal.value = false
}

// ── Sortable (drag and drop) ──────────────────────────────────────
const gridRef = ref<HTMLElement | null>(null)
let sortableInstance: ReturnType<typeof Sortable.create> | null = null

function initSortable() {
  if (!gridRef.value) return
  sortableInstance = Sortable.create(gridRef.value, {
    animation: 150,
    handle: '.dashboard-drag-handle',
    ghostClass: 'opacity-40',
    chosenClass: 'ring-2 ring-primary rounded-lg',
    dragClass: 'shadow-2xl',
    onEnd(evt) {
      // Build new order array from DOM
      const items = gridRef.value?.querySelectorAll('[data-widget-id]')
      if (!items) return
      const newOrder: string[] = []
      items.forEach((el) => {
        const id = el.getAttribute('data-widget-id')
        if (id) newOrder.push(id)
      })
      // Apply the reorder (evt.newIndex used as reference — DOM is source of truth)
      void evt
      configStore.reorderWidgets(newOrder)
    },
  })
}

function destroySortable() {
  sortableInstance?.destroy()
  sortableInstance = null
}

watch(editMode, async (isEdit) => {
  if (isEdit) {
    await nextTick()
    initSortable()
  }
  else {
    destroySortable()
  }
})

onUnmounted(() => {
  destroySortable()
})

// Resolved period for widgets that need it
const resolvedDateFrom = computed(() => resolvedRange.value.dateFrom)
const resolvedDateTo = computed(() => resolvedRange.value.dateTo)
</script>

<template>
  <UDashboardPanel id="home">
    <template #header>
      <UDashboardNavbar :title="$t('dashboard.title')" :ui="{ right: 'gap-3' }">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <!-- Edit mode actions -->
          <template v-if="editMode">
            <UButton
              color="neutral"
              variant="soft"
              icon="i-lucide-plus"
              size="md"
              @click="showAddWidgetModal = true"
            >
              {{ $t('dashboard.edit.addWidget') }}
            </UButton>
            <UButton
              color="primary"
              icon="i-lucide-check"
              size="md"
              :loading="saving"
              @click="exitEditMode"
            >
              {{ $t('dashboard.edit.done') }}
            </UButton>
          </template>

          <!-- Normal mode actions -->
          <template v-else>
            <UTooltip :text="$t('notifications.title')" :kbds="['N']">
              <UButton
                color="neutral"
                variant="ghost"
                square
                @click="isNotificationsSlideoverOpen = true"
              >
                <UIcon name="i-lucide-bell" class="size-5 shrink-0" />
              </UButton>
            </UTooltip>

            <UTooltip :text="$t('dashboard.edit.editDashboard')">
              <UButton
                color="neutral"
                variant="ghost"
                square
                @click="enterEditMode"
              >
                <UIcon name="i-lucide-layout-grid" class="size-5 shrink-0" />
              </UButton>
            </UTooltip>

            <UDropdownMenu :items="quickActions">
              <UButton icon="i-lucide-plus" size="md" class="rounded-full" />
            </UDropdownMenu>
          </template>
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <template #left>
          <DashboardPeriodSelector
            v-model:selected-preset="selectedPreset"
            v-model:custom-date-from="customDateFrom"
            v-model:custom-date-to="customDateTo"
            :display-label="displayLabel"
            :is-custom="isCustom"
            :presets="presets"
          />
        </template>
        <template #right>
          <div class="flex items-center gap-2">
            <UBadge v-if="isSyncEnabled" color="success" variant="subtle" size="sm">
              e-Factura {{ $t('common.active').toLowerCase() }}
            </UBadge>
            <UBadge v-else color="neutral" variant="subtle" size="sm">
              e-Factura {{ $t('common.inactive').toLowerCase() }}
            </UBadge>

            <UButton
              icon="i-lucide-refresh-cw"
              color="neutral"
              variant="ghost"
              :loading="syncing"
              @click="triggerSync"
            >
              {{ $t('dashboard.syncNow') }}
            </UButton>
          </div>
        </template>
      </UDashboardToolbar>
    </template>

    <template #body>
      <!-- Update available banner (admins only) -->
      <AppUpdateBanner />

      <!-- Usage nudge banner (non-paid plans at ≥80% usage) -->
      <SharedUsageBanner />

      <!-- Read-only company banner -->
      <SharedCompanyReadOnlyBanner />

      <!-- Sync progress stats (live during sync) -->
      <UCard v-if="syncStore.syncProgress?.stats">
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="font-semibold">{{ $t('efactura.syncProgressTitle') }}</h3>
            <span v-if="syncStore.syncProgress.total > 0" class="text-sm text-muted">{{ syncStore.syncProgress.processed }} / {{ syncStore.syncProgress.total }}</span>
          </div>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="text-center">
              <div class="text-lg font-bold">{{ syncStore.syncProgress.stats.newInvoices }}</div>
              <div class="text-xs text-muted">{{ $t('efactura.newInvoices') }}</div>
            </div>
            <div class="text-center">
              <div class="text-lg font-bold">{{ syncStore.syncProgress.stats.newClients }}</div>
              <div class="text-xs text-muted">{{ $t('efactura.newClients') }}</div>
            </div>
            <div class="text-center">
              <div class="text-lg font-bold">{{ syncStore.syncProgress.stats.skippedDuplicates }}</div>
              <div class="text-xs text-muted">{{ $t('efactura.skippedDuplicates') }}</div>
            </div>
            <div class="text-center">
              <div class="text-lg font-bold">{{ syncStore.syncProgress.stats.newProducts }}</div>
              <div class="text-xs text-muted">{{ $t('efactura.newProducts') }}</div>
            </div>
          </div>
        </div>
      </UCard>

      <!-- Onboarding checklist -->
      <DashboardOnboardingChecklist />

      <!-- Edit mode banner -->
      <div v-if="editMode" class="rounded-lg border border-primary/40 bg-primary/5 px-4 py-3 flex items-center gap-3">
        <UIcon name="i-lucide-layout-grid" class="size-5 text-primary shrink-0" />
        <p class="text-sm text-(--ui-text)">
          {{ $t('dashboard.edit.editModeHint') }}
        </p>
      </div>

      <!-- Widget grid -->
      <div
        ref="gridRef"
        class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5"
        :class="{ 'select-none': editMode }"
      >
        <div
          v-for="widget in activeWidgets"
          :key="widget.id"
          :data-widget-id="widget.id"
          :class="configStore.getColSpanClass(widget.id)"
        >
          <DashboardWidget
            :id="widget.id"
            :edit-mode="editMode"
            :date-from="resolvedDateFrom"
            :date-to="resolvedDateTo"
            @hide="handleHideWidget"
          />
        </div>
      </div>

      <!-- Empty state when all widgets hidden -->
      <div v-if="activeWidgets.length === 0" class="flex flex-col items-center justify-center py-20 text-center">
        <UIcon name="i-lucide-layout-grid" class="size-16 text-(--ui-text-muted) mb-4" />
        <p class="text-lg font-semibold text-(--ui-text) mb-2">{{ $t('dashboard.edit.noWidgets') }}</p>
        <p class="text-sm text-(--ui-text-muted) mb-6">{{ $t('dashboard.edit.noWidgetsDesc') }}</p>
        <UButton color="primary" icon="i-lucide-plus" @click="showAddWidgetModal = true">
          {{ $t('dashboard.edit.addWidget') }}
        </UButton>
      </div>
    </template>
  </UDashboardPanel>

  <!-- Add Widget Modal -->
  <DashboardAddWidgetModal
    v-model="showAddWidgetModal"
    @add="handleAddWidget"
  />
</template>
