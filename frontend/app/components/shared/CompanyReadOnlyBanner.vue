<script setup lang="ts">
const { t: $t } = useI18n()
const companyStore = useCompanyStore()
const toast = useToast()

const upgradeModalOpen = ref(false)
const settingActive = ref(false)

async function setActive() {
  if (!companyStore.currentCompany) return
  settingActive.value = true
  const success = await companyStore.setActiveCompany(companyStore.currentCompany.id)
  settingActive.value = false
  if (success) {
    toast.add({ title: $t('company.setActiveSuccess'), color: 'success' })
  }
}
</script>

<template>
  <ClientOnly>
    <div v-if="companyStore.isCurrentCompanyReadOnly" class="mb-4">
      <UAlert
        color="warning"
        variant="subtle"
        :title="$t('company.readOnly')"
        :description="$t('company.readOnlyBanner')"
        icon="i-lucide-lock"
      >
        <template #actions>
          <UButton
            color="warning"
            variant="solid"
            size="sm"
            icon="i-lucide-check-circle"
            :loading="settingActive"
            @click="setActive"
          >
            {{ $t('company.setActive') }}
          </UButton>
          <UButton
            color="warning"
            variant="outline"
            size="sm"
            icon="i-lucide-arrow-up-circle"
            @click="upgradeModalOpen = true"
          >
            Upgrade
          </UButton>
        </template>
      </UAlert>

      <SharedUpgradeModal v-model:open="upgradeModalOpen" />
    </div>
  </ClientOnly>
</template>
