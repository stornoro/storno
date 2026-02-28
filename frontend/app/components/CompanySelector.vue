<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

const { t: $t } = useI18n()

defineProps<{
  collapsed?: boolean
}>()

const companyStore = useCompanyStore()

const items = computed<DropdownMenuItem[][]>(() => {
  return [companyStore.companies.map(company => ({
    label: company.isReadOnly ? `${company.name} (${$t('company.readOnly')})` : company.name,
    icon: company.isReadOnly ? 'i-lucide-lock' : undefined,
    avatar: company.isReadOnly ? undefined : {
      text: company.name.substring(0, 2).toUpperCase(),
      alt: company.name
    },
    onSelect() {
      companyStore.selectCompany(company.id)
    }
  })), [{
    label: 'Adauga companie',
    icon: 'i-lucide-circle-plus',
    to: '/companies'
  }, {
    label: 'Gestioneaza companii',
    icon: 'i-lucide-cog',
    to: '/companies'
  }]]
})

const selectedCompany = computed(() => companyStore.currentCompany)
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'center', collisionPadding: 12 }"
    :ui="{ content: collapsed ? 'w-40' : 'w-(--reka-dropdown-menu-trigger-width)' }"
  >
    <UButton
      v-bind="{
        label: collapsed ? undefined : selectedCompany?.name ?? 'Selecteaza',
        trailingIcon: collapsed ? undefined : 'i-lucide-chevrons-up-down'
      }"
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      class="data-[state=open]:bg-elevated"
      :class="[!collapsed && 'py-2']"
      :ui="{
        trailingIcon: 'text-dimmed'
      }"
    >
      <template #leading>
        <UAvatar
          :text="selectedCompany?.name?.substring(0, 2)?.toUpperCase() ?? '??'"
          size="2xs"
        />
      </template>
    </UButton>
  </UDropdownMenu>
</template>
