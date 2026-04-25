import { defineStore } from 'pinia'

// ── Types ────────────────────────────────────────────────────────────────────

export type WidgetSize = 'sm' | 'md' | 'lg' | 'xl'
export type WidgetCategory = 'sales' | 'expenses' | 'clients' | 'activity' | 'charts' | 'system'

export interface WidgetConfig {
  id: string
  position: number
  visible: boolean
}

export interface CatalogWidget {
  id: string
  name_key: string
  description_key: string
  size: WidgetSize
  category: WidgetCategory
}

export interface DashboardConfigResponse {
  widgets: WidgetConfig[]
}

export interface CatalogResponse {
  widgets: CatalogWidget[]
}

// ── Default config matching the original layout ──────────────────────────────

export const WIDGET_CATALOG: CatalogWidget[] = [
  { id: 'sales-card', name_key: 'dashboard.widgets.salesCard.name', description_key: 'dashboard.widgets.salesCard.description', size: 'md', category: 'sales' },
  { id: 'expenses-card', name_key: 'dashboard.widgets.expensesCard.name', description_key: 'dashboard.widgets.expensesCard.description', size: 'md', category: 'expenses' },
  { id: 'client-balance-card', name_key: 'dashboard.widgets.clientBalanceCard.name', description_key: 'dashboard.widgets.clientBalanceCard.description', size: 'md', category: 'clients' },
  { id: 'unpaid-card', name_key: 'dashboard.widgets.unpaidCard.name', description_key: 'dashboard.widgets.unpaidCard.description', size: 'md', category: 'sales' },
  { id: 'amounts-to-pay-card', name_key: 'dashboard.widgets.amountsToPayCard.name', description_key: 'dashboard.widgets.amountsToPayCard.description', size: 'md', category: 'expenses' },
  { id: 'activity-card', name_key: 'dashboard.widgets.activityCard.name', description_key: 'dashboard.widgets.activityCard.description', size: 'md', category: 'activity' },
  { id: 'due-today-card', name_key: 'dashboard.widgets.dueTodayCard.name', description_key: 'dashboard.widgets.dueTodayCard.description', size: 'md', category: 'sales' },
  { id: 'cash-balance-card', name_key: 'dashboard.widgets.cashBalanceCard.name', description_key: 'dashboard.widgets.cashBalanceCard.description', size: 'md', category: 'system' },
  { id: 'recent-invoices-table', name_key: 'dashboard.widgets.recentInvoicesTable.name', description_key: 'dashboard.widgets.recentInvoicesTable.description', size: 'xl', category: 'activity' },
  { id: 'status-breakdown-chart', name_key: 'dashboard.widgets.statusBreakdownChart.name', description_key: 'dashboard.widgets.statusBreakdownChart.description', size: 'lg', category: 'charts' },
  { id: 'monthly-charts', name_key: 'dashboard.widgets.monthlyCharts.name', description_key: 'dashboard.widgets.monthlyCharts.description', size: 'xl', category: 'charts' },
  { id: 'sync-status', name_key: 'dashboard.widgets.syncStatus.name', description_key: 'dashboard.widgets.syncStatus.description', size: 'sm', category: 'system' },
  { id: 'top-clients-revenue', name_key: 'dashboard.widgets.topClientsRevenue.name', description_key: 'dashboard.widgets.topClientsRevenue.description', size: 'lg', category: 'clients' },
  { id: 'top-products-revenue', name_key: 'dashboard.widgets.topProductsRevenue.name', description_key: 'dashboard.widgets.topProductsRevenue.description', size: 'lg', category: 'sales' },
  { id: 'top-outstanding-clients', name_key: 'dashboard.widgets.topOutstandingClients.name', description_key: 'dashboard.widgets.topOutstandingClients.description', size: 'lg', category: 'clients' },
]

// Default config: original 12 widgets visible, 3 new ones hidden
const DEFAULT_CONFIG: WidgetConfig[] = [
  { id: 'sales-card', position: 0, visible: true },
  { id: 'client-balance-card', position: 1, visible: true },
  { id: 'unpaid-card', position: 2, visible: true },
  { id: 'expenses-card', position: 3, visible: true },
  { id: 'amounts-to-pay-card', position: 4, visible: true },
  { id: 'activity-card', position: 5, visible: true },
  { id: 'due-today-card', position: 6, visible: true },
  { id: 'cash-balance-card', position: 7, visible: true },
  { id: 'recent-invoices-table', position: 8, visible: true },
  { id: 'status-breakdown-chart', position: 9, visible: true },
  { id: 'monthly-charts', position: 10, visible: true },
  { id: 'sync-status', position: 11, visible: true },
  { id: 'top-clients-revenue', position: 12, visible: false },
  { id: 'top-products-revenue', position: 13, visible: false },
  { id: 'top-outstanding-clients', position: 14, visible: false },
]

// ── Store ─────────────────────────────────────────────────────────────────────

export const useDashboardConfigStore = defineStore('dashboardConfig', () => {
  const widgets = ref<WidgetConfig[]>([...DEFAULT_CONFIG])
  const catalog = ref<CatalogWidget[]>([...WIDGET_CATALOG])
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)

  // Widgets sorted by position, visible only
  const activeWidgets = computed<WidgetConfig[]>(() =>
    widgets.value
      .filter(w => w.visible)
      .sort((a, b) => a.position - b.position),
  )

  // Widgets that are hidden (can be added)
  const hiddenWidgets = computed<WidgetConfig[]>(() =>
    widgets.value.filter(w => !w.visible),
  )

  // Get catalog entry for a widget id
  function getCatalogEntry(id: string): CatalogWidget | undefined {
    return catalog.value.find(c => c.id === id)
  }

  // Get col-span class for a widget size
  function getColSpanClass(id: string): string {
    const entry = getCatalogEntry(id)
    if (!entry) return 'col-span-1 md:col-span-1 lg:col-span-2'
    const map: Record<WidgetSize, string> = {
      sm: 'col-span-1 md:col-span-1 lg:col-span-1',
      md: 'col-span-1 md:col-span-1 lg:col-span-2',
      lg: 'col-span-1 md:col-span-2 lg:col-span-3',
      xl: 'col-span-1 md:col-span-2 lg:col-span-4',
    }
    return map[entry.size]
  }

  async function loadConfig(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const response = await get<DashboardConfigResponse>('/v1/dashboard/config')
      if (response.widgets?.length) {
        // Merge with catalog to ensure all known widgets are represented
        const serverIds = new Set(response.widgets.map(w => w.id))
        const serverWidgets = [...response.widgets]

        // Add any catalog widgets not in server config as hidden
        for (const catalogItem of WIDGET_CATALOG) {
          if (!serverIds.has(catalogItem.id)) {
            serverWidgets.push({
              id: catalogItem.id,
              position: serverWidgets.length,
              visible: false,
            })
          }
        }

        widgets.value = serverWidgets
      }
    }
    catch {
      // Fall back to default config silently — backend may not be deployed yet
      widgets.value = [...DEFAULT_CONFIG]
    }
    finally {
      loading.value = false
    }
  }

  async function saveConfig(): Promise<void> {
    const { put } = useApi()
    saving.value = true
    error.value = null

    try {
      await put<DashboardConfigResponse>('/v1/dashboard/config', {
        widgets: widgets.value,
      })
    }
    catch (err: any) {
      error.value = err?.data?.error || 'Nu s-a putut salva configuratia.'
    }
    finally {
      saving.value = false
    }
  }

  async function loadCatalog(): Promise<void> {
    const { get } = useApi()

    try {
      const response = await get<CatalogResponse>('/v1/dashboard/widgets/catalog')
      if (response.widgets?.length) {
        catalog.value = response.widgets as CatalogWidget[]
      }
    }
    catch {
      // Use local catalog as fallback
      catalog.value = [...WIDGET_CATALOG]
    }
  }

  function toggleWidget(id: string): void {
    const idx = widgets.value.findIndex(w => w.id === id)
    if (idx === -1) return
    const widget = widgets.value[idx]
    if (!widget) return

    if (widget.visible) {
      // Hide it: keep in list but mark invisible
      widget.visible = false
    }
    else {
      // Show it: append to end of visible widgets
      const maxPos = widgets.value
        .filter(w => w.visible)
        .reduce((max, w) => Math.max(max, w.position), -1)
      widget.visible = true
      widget.position = maxPos + 1
    }
  }

  function reorderWidgets(newOrder: string[]): void {
    // newOrder is an array of widget ids in the new visible order
    const hiddenSet = new Set(widgets.value.filter(w => !w.visible).map(w => w.id))

    // Assign new positions to visible widgets
    for (let i = 0; i < newOrder.length; i++) {
      const id = newOrder[i]
      if (!id) continue
      const widget = widgets.value.find(w => w.id === id)
      if (widget && !hiddenSet.has(id)) {
        widget.position = i
      }
    }
  }

  function addWidget(id: string): void {
    toggleWidget(id)
  }

  function $reset(): void {
    widgets.value = [...DEFAULT_CONFIG]
    catalog.value = [...WIDGET_CATALOG]
    loading.value = false
    saving.value = false
    error.value = null
  }

  return {
    // State
    widgets,
    catalog,
    loading,
    saving,
    error,

    // Computed
    activeWidgets,
    hiddenWidgets,

    // Methods
    getCatalogEntry,
    getColSpanClass,
    loadConfig,
    saveConfig,
    loadCatalog,
    toggleWidget,
    reorderWidgets,
    addWidget,
    $reset,
  }
})
