<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('settings.modules.title') })
const companyStore = useCompanyStore()
const toast = useToast()
const { MODULE_KEYS } = useModules()

const saving = ref(false)

const allModules = [
  { key: MODULE_KEYS.DELIVERY_NOTES, label: $t('nav.deliveryNotes'), icon: 'i-lucide-package-check' },
  { key: MODULE_KEYS.RECEIPTS, label: $t('nav.receipts'), icon: 'i-lucide-receipt' },
  { key: MODULE_KEYS.PROFORMA_INVOICES, label: $t('nav.proformaInvoices'), icon: 'i-lucide-file-check' },
  { key: MODULE_KEYS.RECURRING_INVOICES, label: $t('nav.recurringInvoices'), icon: 'i-lucide-repeat' },
  { key: MODULE_KEYS.REPORTS, label: $t('nav.reports'), icon: 'i-lucide-bar-chart-3' },
  { key: MODULE_KEYS.EFACTURA, label: $t('nav.efactura'), icon: 'i-lucide-cloud-download' },
  { key: MODULE_KEYS.SPV_MESSAGES, label: $t('nav.spvMessages'), icon: 'i-lucide-mail' },
]

const toggles = ref<Record<string, boolean>>({})

function loadToggles() {
  const enabled = companyStore.currentCompany?.enabledModules
  for (const mod of allModules) {
    toggles.value[mod.key] = enabled == null ? true : enabled.includes(mod.key)
  }
}

loadToggles()

watch(() => companyStore.currentCompany?.enabledModules, () => {
  loadToggles()
})

const allEnabled = computed(() => allModules.every(m => toggles.value[m.key]))

async function onSave() {
  const companyId = companyStore.currentCompanyId
  if (!companyId) return

  saving.value = true

  const enabledModules = allEnabled.value
    ? null
    : allModules.filter(m => toggles.value[m.key]).map(m => m.key)

  const result = await companyStore.updateCompany(companyId, { enabledModules } as any)
  if (result) {
    toast.add({ title: $t('common.saved'), color: 'success' })
  }
  else {
    toast.add({ title: companyStore.error || $t('common.error'), color: 'error' })
  }

  saving.value = false
}
</script>

<template>
  <div>
    <UPageCard
      :title="$t('settings.modules.title')"
      :description="$t('settings.modules.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    />

    <UPageCard variant="subtle">
      <div class="space-y-1">
        <div
          v-for="mod in allModules"
          :key="mod.key"
          class="flex items-center justify-between py-3 px-1"
        >
          <div class="flex items-center gap-3">
            <UIcon :name="mod.icon" class="size-5 text-(--ui-text-muted)" />
            <span class="text-sm font-medium">{{ mod.label }}</span>
          </div>
          <USwitch v-model="toggles[mod.key]" size="sm" />
        </div>
      </div>

      <div class="flex justify-end pt-4 mt-4 border-t border-default">
        <UButton :loading="saving" @click="onSave">
          {{ $t('common.save') }}
        </UButton>
      </div>
    </UPageCard>
  </div>
</template>
