<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const authStore = useAuthStore()

const links = computed(() => {
  const items: NavigationMenuItem[] = [
    {
      label: $t('settings.profile.title'),
      icon: 'i-lucide-user',
      to: '/settings/profile',
    },
    {
      label: $t('settings.organization'),
      icon: 'i-lucide-building',
      to: '/settings/organization',
    },
    {
      label: $t('settings.team'),
      icon: 'i-lucide-users',
      to: '/settings/team',
    },
    {
      label: $t('bankAccounts.title'),
      icon: 'i-lucide-landmark',
      to: '/settings/bank-accounts',
    },
    {
      label: $t('documentSeries.title'),
      icon: 'i-lucide-hash',
      to: '/settings/document-series',
    },
    {
      label: $t('vatRates.title'),
      icon: 'i-lucide-percent',
      to: '/settings/vat-rates',
    },
    {
      label: $t('storageConfig.title'),
      icon: 'i-lucide-hard-drive',
      to: '/settings/storage',
    },
    {
      label: $t('einvoiceConfig.title'),
      icon: 'i-lucide-file-check',
      to: '/settings/einvoice',
    },
  ]

  if (authStore.isOwner && !authStore.isSelfHosted) {
    items.push({
      label: $t('settings.billingPage.title'),
      icon: 'i-lucide-receipt',
      to: '/settings/billing',
    })
  }

  return [items] satisfies NavigationMenuItem[][]
})
</script>

<template>
  <UDashboardPanel id="settings" :ui="{ body: 'lg:py-12' }">
    <template #header>
      <UDashboardNavbar :title="$t('nav.settings')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <UNavigationMenu :items="links" highlight class="-mx-1 flex-1" />
      </UDashboardToolbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4 sm:gap-6 lg:gap-8 w-full max-w-7xl mx-auto">
        <NuxtPage />
      </div>
    </template>
  </UDashboardPanel>
</template>
