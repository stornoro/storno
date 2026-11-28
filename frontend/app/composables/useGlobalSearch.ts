export interface GlobalSearchItem {
  type: 'invoice' | 'proforma' | 'client' | 'supplier' | 'product'
  id: string
  title: string
  subtitle: string | null
  status: string | null
  date: string | null
  href: string
  direction?: string | null
}

type GlobalSearchGroups = Record<string, GlobalSearchItem[]>

const GROUP_ICONS: Record<string, string> = {
  invoices: 'i-lucide-file-text',
  proformas: 'i-lucide-file-check',
  clients: 'i-lucide-users',
  suppliers: 'i-lucide-truck',
  products: 'i-lucide-package',
}

/**
 * Records for the Cmd+K palette: invoices, proformas, clients, suppliers and products of the
 * current company that match what was typed (GET /v1/search). The server does the matching, so
 * the groups are marked `ignoreFilter` and the palette shows them as they come.
 */
export function useGlobalSearch() {
  const { t } = useI18n()
  const { get } = useApi()
  const companyStore = useCompanyStore()

  const searchTerm = ref('')
  const loading = ref(false)
  const results = ref<GlobalSearchGroups>({})
  // Answers can arrive out of order; only the one for the latest text is shown.
  let latest = 0

  const run = useDebounceFn(async (query: string, request: number) => {
    try {
      const response = await get<{ groups: GlobalSearchGroups }>('/v1/search', { q: query, limit: 6 })
      if (request === latest) results.value = response.groups ?? {}
    }
    catch {
      if (request === latest) results.value = {}
    }
    finally {
      if (request === latest) loading.value = false
    }
  }, 200)

  watch(searchTerm, (value) => {
    const query = value.trim()
    const request = ++latest
    if (query.length < 2) {
      results.value = {}
      loading.value = false
      return
    }
    loading.value = true
    run(query, request)
  })

  // Results belong to one company; drop them when the user switches.
  watch(() => companyStore.currentCompanyId, () => {
    latest++
    results.value = {}
    loading.value = false
  })

  function subtitle(item: GlobalSearchItem): string | undefined {
    const parts = [
      item.direction ? t(`globalSearch.direction.${item.direction}`) : null,
      item.subtitle,
      item.date,
    ].filter(Boolean)
    return parts.length ? parts.join(' · ') : undefined
  }

  const groups = computed(() =>
    Object.entries(results.value)
      .filter(([, items]) => items.length > 0)
      .map(([type, items]) => ({
        id: `search-${type}`,
        label: t(`globalSearch.groups.${type}`),
        ignoreFilter: true,
        items: items.map(item => ({
          id: `${item.type}-${item.id}`,
          label: item.title === 'draft' ? t('globalSearch.draft') : item.title,
          suffix: subtitle(item),
          icon: GROUP_ICONS[type] ?? 'i-lucide-search',
          to: item.href,
        })),
      })),
  )

  return { searchTerm, loading, groups }
}
