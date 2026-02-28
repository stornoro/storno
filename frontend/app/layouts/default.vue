<script setup lang="ts">
const { t: $t } = useI18n()

const { isShortcutsModalOpen } = useDashboard()

function truthy<T>(value: T): value is Exclude<T, false | null | undefined | 0 | ''> {
  return !!value
}

const authStore = useAuthStore()
const companyStore = useCompanyStore()
const { can } = usePermissions()
const { isModuleEnabled, MODULE_KEYS } = useModules()
const open = ref(false)

// Primary navigation links (array-of-arrays for grouped display)
const links = computed(() => {
  const close = () => { open.value = false }

  const group1 = [
    {
      label: $t('nav.dashboard'),
      icon: 'i-lucide-layout-dashboard',
      to: '/dashboard',
      onSelect: close,
    },
    can(P.INVOICE_VIEW) && {
      label: $t('nav.invoices'),
      icon: 'i-lucide-file-text',
      to: '/invoices',
      onSelect: close,
    },
    can(P.RECURRING_INVOICE_VIEW) && isModuleEnabled(MODULE_KEYS.RECURRING_INVOICES) && {
      label: $t('nav.recurringInvoices'),
      icon: 'i-lucide-repeat',
      to: '/recurring-invoices',
      onSelect: close,
    },
    can(P.INVOICE_VIEW) && isModuleEnabled(MODULE_KEYS.PROFORMA_INVOICES) && {
      label: $t('nav.proformaInvoices'),
      icon: 'i-lucide-file-check',
      to: '/proforma-invoices',
      onSelect: close,
    },
    can(P.INVOICE_VIEW) && isModuleEnabled(MODULE_KEYS.DELIVERY_NOTES) && {
      label: $t('nav.deliveryNotes'),
      icon: 'i-lucide-package-check',
      to: '/delivery-notes',
      onSelect: close,
    },
    can(P.INVOICE_VIEW) && isModuleEnabled(MODULE_KEYS.RECEIPTS) && {
      label: $t('nav.receipts'),
      icon: 'i-lucide-receipt',
      to: '/receipts',
      onSelect: close,
    },
    can(P.CLIENT_VIEW) && {
      label: $t('nav.clients'),
      icon: 'i-lucide-users',
      to: '/clients',
      onSelect: close,
    },
    can(P.PRODUCT_VIEW) && {
      label: $t('nav.products'),
      icon: 'i-lucide-package',
      to: '/products',
      onSelect: close,
    },
    can(P.CLIENT_VIEW) && {
      label: $t('nav.suppliers'),
      icon: 'i-lucide-truck',
      to: '/suppliers',
      onSelect: close,
    },
  ].filter(truthy)

  // Settings children
  const settingsChildren = [
    {
      label: $t('settings.profile.title'),
      to: '/settings/profile',
      exact: true,
      onSelect: close,
    },
    {
      label: $t('settings.organization'),
      to: '/settings/organization',
      onSelect: close,
    },
    can(P.ORG_MANAGE_MEMBERS) && {
      label: $t('settings.team'),
      to: '/settings/team',
      onSelect: close,
    },
    can(P.SETTINGS_VIEW) && {
      label: $t('bankAccounts.title'),
      to: '/settings/bank-accounts',
      onSelect: close,
    },
    can(P.SERIES_VIEW) && {
      label: $t('documentSeries.title'),
      to: '/settings/document-series',
      onSelect: close,
    },
    can(P.SETTINGS_VIEW) && {
      label: $t('vatRates.title'),
      to: '/settings/vat-rates',
      onSelect: close,
    },
    can(P.EMAIL_TEMPLATE_VIEW) && {
      label: $t('emailTemplates.title'),
      to: '/settings/email-templates',
      onSelect: close,
    },
    can(P.SETTINGS_VIEW) && {
      label: $t('pdfTemplates.title'),
      to: '/settings/pdf-templates',
      onSelect: close,
    },
    can(P.ORG_MANAGE_BILLING) && !authStore.isSelfHosted && {
      label: $t('settings.billingPage.title'),
      to: '/settings/billing',
      onSelect: close,
    },
    can(P.COMPANY_EDIT) && {
      label: $t('settings.modules.title'),
      to: '/settings/modules',
      onSelect: close,
    },
  ].filter(truthy)

  // Data children
  const dataChildren = [
    can(P.IMPORT_MANAGE) && {
      label: $t('importExport.title'),
      to: '/settings/import-export',
      onSelect: close,
    },
    can(P.BORDEROU_VIEW) && {
      label: $t('borderou.title'),
      to: '/settings/borderou',
      onSelect: close,
    },
    can(P.SETTINGS_VIEW) && {
      label: $t('bankStatement.title'),
      to: '/settings/extrase-bancare',
      onSelect: close,
    },
    can(P.BACKUP_MANAGE) && {
      label: $t('backup.title'),
      to: '/settings/backup',
      onSelect: close,
    },
  ].filter(truthy)

  // Integrations children
  const integrationsChildren = [
    {
      label: $t('settings.notifications'),
      to: '/settings/notifications',
      onSelect: close,
    },
    can(P.WEBHOOK_VIEW) && {
      label: $t('webhooks.title'),
      to: '/settings/webhooks',
      onSelect: close,
    },
    can(P.API_KEY_VIEW) && {
      label: $t('apiKeys.title'),
      to: '/settings/api-keys',
      onSelect: close,
    },
    can(P.ORG_MANAGE_BILLING) && !authStore.isSelfHosted && {
      label: $t('settings.billingPage.stripeConnect'),
      to: '/settings/payments',
      onSelect: close,
    },
    can(P.ORG_MANAGE_BILLING) && {
      label: $t('licenseKeys.title'),
      to: '/settings/license-keys',
      onSelect: close,
    },
  ].filter(truthy)

  const group2 = [
    can(P.EFACTURA_VIEW) && isModuleEnabled(MODULE_KEYS.EFACTURA) && {
      label: $t('nav.efactura'),
      icon: 'i-lucide-cloud-download',
      to: '/efactura',
      onSelect: close,
    },
    can(P.COMPANY_VIEW) && {
      label: $t('nav.companies'),
      icon: 'i-lucide-building-2',
      to: '/companies',
      onSelect: close,
    },
    can(P.REPORT_VIEW) && isModuleEnabled(MODULE_KEYS.REPORTS) && {
      label: $t('nav.reports'),
      icon: 'i-lucide-bar-chart-3',
      to: '/reports',
      type: 'trigger' as const,
      defaultOpen: true,
      children: [
        { label: $t('reports.salesAnalysis.title'), to: '/reports/sales', onSelect: close },
        { label: $t('reports.vatReportTitle'), to: '/reports/vat', onSelect: close },
        { label: $t('reports.balanceAnalysis.title'), to: '/reports/balances', onSelect: close },
      ],
    },
    {
      label: $t('nav.settings'),
      icon: 'i-lucide-settings',
      to: '/settings',
      type: 'trigger' as const,
      defaultOpen: true,
      children: settingsChildren,
    },
    dataChildren.length > 0 && {
      label: $t('settings.sections.data'),
      icon: 'i-lucide-database',
      to: '/settings/import-export',
      type: 'trigger' as const,
      defaultOpen: true,
      children: dataChildren,
    },
    integrationsChildren.length > 1 && {
      label: $t('settings.sections.integrations'),
      icon: 'i-lucide-plug',
      to: '/settings/notifications',
      type: 'trigger' as const,
      defaultOpen: true,
      children: integrationsChildren,
    },
  ].filter(truthy)

  const group3 = authStore.isSuperAdmin ? [{
    label: $t('nav.admin'),
    icon: 'i-lucide-shield',
    to: '/admin',
    type: 'trigger' as const,
    defaultOpen: true,
    children: [
      { label: $t('admin.title'), to: '/admin', exact: true, onSelect: close },
      { label: $t('admin.users'), to: '/admin/users', onSelect: close },
      { label: $t('admin.organizations'), to: '/admin/organizations', onSelect: close },
      { label: $t('admin.revenue'), to: '/admin/revenue', onSelect: close },
      { label: $t('admin.auditLogs'), to: '/admin/audit-logs', onSelect: close },
      { label: $t('admin.emailLogs'), to: '/admin/email-logs', onSelect: close },
    ],
  }] : []

  return [group1, group2, group3]
})

// Shortcut keys for nav items (used in Cmd+K search)
const navKbds: Record<string, string[]> = {
  '/dashboard': ['G', 'H'],
  '/invoices': ['G', 'I'],
  '/proforma-invoices': ['G', 'O'],
  '/delivery-notes': ['G', 'A'],
  '/receipts': ['G', 'B'],
  '/recurring-invoices': ['G', 'T'],
  '/clients': ['G', 'C'],
  '/products': ['G', 'P'],
  '/suppliers': ['G', 'F'],
  '/efactura': ['G', 'E'],
  '/settings': ['G', 'S'],
  '/reports': ['G', 'R'],
}

const bottomLinks = [{
  label: 'API Docs',
  icon: 'i-lucide-book-open',
  slot: 'api-docs' as const,
  to: 'https://docs.storno.ro',
  target: '_blank',
}, {
  label: 'Help & Support',
  icon: 'i-lucide-life-buoy',
  to: 'https://storno.ro/contact',
  target: '_blank',
}]

// Search groups for Cmd+K modal
const searchGroups = computed(() => [{
  id: 'navigation',
  label: $t('nav.dashboard'),
  items: links.value.flat().map(item => ({
    ...item,
    kbds: navKbds[item.to as string],
  })),
}])
</script>

<template>
  <UDashboardGroup unit="rem">
    <UDashboardSidebar
      id="default"
      v-model:open="open"
      collapsible
      resizable
      class="bg-elevated/25"
      :ui="{ footer: 'lg:border-t lg:border-default' }"
    >
      <template #header="{ collapsed }">
        <ClientOnly>
          <CompanySelector :collapsed="collapsed" />
        </ClientOnly>
      </template>

      <template #default="{ collapsed }">
        <UDashboardSearchButton :collapsed="collapsed" class="bg-transparent ring-default" />

        <UNavigationMenu
          :collapsed="collapsed"
          :items="links[0]"
          orientation="vertical"
          tooltip
          popover
          highlight
        />

        <UNavigationMenu
          :collapsed="collapsed"
          :items="links[1]"
          orientation="vertical"
          tooltip
          popover
          highlight
        />

        <UNavigationMenu
          v-if="links[2]?.length"
          :collapsed="collapsed"
          :items="links[2]"
          orientation="vertical"
          tooltip
          popover
          highlight
        />

        <div class="mt-auto" />

        <SidebarPlanBadge :collapsed="collapsed" />

        <UNavigationMenu
          :collapsed="collapsed"
          :items="bottomLinks"
          orientation="vertical"
          tooltip
        >
          <template #api-docs-leading>
            <img src="/logo.png" alt="Storno.ro" class="h-4 w-auto shrink-0" />
          </template>
        </UNavigationMenu>
      </template>

      <template #footer="{ collapsed }">
        <div class="flex items-center" :class="collapsed ? 'justify-center' : 'gap-1'">
          <UTooltip :text="$t('shortcuts.title')" :kbds="['?']">
            <UButton
              icon="i-lucide-keyboard"
              color="neutral"
              variant="ghost"
              square
              @click="isShortcutsModalOpen = true"
            />
          </UTooltip>
          <AppNotificationBell />
          <ClientOnly>
            <AppUserMenu :collapsed="collapsed" class="flex-1" />
          </ClientOnly>
        </div>
      </template>
    </UDashboardSidebar>

    <UDashboardSearch :groups="searchGroups" />

    <div :key="companyStore.currentCompanyId || ''" class="min-h-svh min-w-0 flex-1 flex flex-col">
      <AppImpersonationBanner />
      <div class="contents">
        <slot />
      </div>
    </div>

    <NotificationsSlideover />
    <KeyboardShortcutsModal />
  </UDashboardGroup>
</template>
