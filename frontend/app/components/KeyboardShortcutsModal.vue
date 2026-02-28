<script setup lang="ts">
const { isShortcutsModalOpen } = useDashboard()
const { t: $t } = useI18n()

const isMac = computed(() => {
  if (import.meta.server) return false
  return navigator.userAgent.includes('Mac')
})

const metaKey = computed(() => isMac.value ? '\u2318' : 'Ctrl')

const sections = computed(() => [
  {
    title: $t('shortcuts.navigation'),
    shortcuts: [
      { keys: ['G', 'H'], label: $t('shortcuts.goToDashboard') },
      { keys: ['G', 'I'], label: $t('shortcuts.goToInvoices') },
      { keys: ['G', 'O'], label: $t('shortcuts.goToProforma') },
      { keys: ['G', 'T'], label: $t('shortcuts.goToRecurring') },
      { keys: ['G', 'C'], label: $t('shortcuts.goToClients') },
      { keys: ['G', 'P'], label: $t('shortcuts.goToProducts') },
      { keys: ['G', 'F'], label: $t('shortcuts.goToSuppliers') },
      { keys: ['G', 'E'], label: $t('shortcuts.goToEfactura') },
      { keys: ['G', 'S'], label: $t('shortcuts.goToSettings') },
      { keys: ['G', 'R'], label: $t('shortcuts.goToReports') },
      { keys: ['G', 'M'], label: $t('shortcuts.goToSpvMessages') },
    ],
  },
  {
    title: $t('shortcuts.create'),
    shortcuts: [
      { keys: ['C', 'I'], label: $t('shortcuts.createInvoice') },
      { keys: ['C', 'P'], label: $t('shortcuts.createProforma') },
      { keys: ['C', 'R'], label: $t('shortcuts.createRecurring') },
    ],
  },
  {
    title: $t('shortcuts.other'),
    shortcuts: [
      { keys: [metaKey.value, 'K'], label: $t('shortcuts.search') },
      { keys: ['N'], label: $t('shortcuts.toggleNotifications') },
      { keys: ['?'], label: $t('shortcuts.showShortcuts') },
    ],
  },
])
</script>

<template>
  <UModal v-model:open="isShortcutsModalOpen" :ui="{ content: 'sm:max-w-lg' }">
    <template #header>
      <span class="text-lg font-semibold">{{ $t('shortcuts.title') }}</span>
    </template>

    <template #body>
      <div class="space-y-6">
        <div v-for="section in sections" :key="section.title">
          <h3 class="text-sm font-medium text-muted mb-3">{{ section.title }}</h3>
          <div class="space-y-2">
            <div
              v-for="shortcut in section.shortcuts"
              :key="shortcut.label"
              class="flex items-center justify-between py-1"
            >
              <span class="text-sm">{{ shortcut.label }}</span>
              <div class="flex items-center gap-1">
                <kbd
                  v-for="(key, i) in shortcut.keys"
                  :key="i"
                  class="inline-flex items-center justify-center min-w-6 h-6 px-1.5 text-xs font-medium rounded bg-elevated border border-default text-muted"
                >
                  {{ key }}
                </kbd>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </UModal>
</template>
