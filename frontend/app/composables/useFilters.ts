/**
 * Filter state composable with URL query synchronisation.
 *
 * Pass in a schema describing your filter fields together with their
 * defaults. The composable will initialise from the current query string
 * and keep it in sync as filters change.
 *
 * @example
 * const { filters, setFilter, resetFilters, queryParams } = useFilters({
 *   search: '',
 *   status: null as string | null,
 *   direction: null as string | null,
 *   dateFrom: null as string | null,
 *   dateTo: null as string | null,
 * })
 */
export function useFilters<T extends Record<string, any>>(defaults: T) {
  const route = useRoute()
  const router = useRouter()

  // Build initial values from URL, falling back to defaults
  const initial: Record<string, any> = {}
  for (const key of Object.keys(defaults)) {
    const queryVal = route.query[key]
    if (queryVal !== undefined && queryVal !== null && queryVal !== '') {
      initial[key] = String(queryVal)
    }
    else {
      initial[key] = defaults[key]
    }
  }

  const filters = reactive<T>({ ...defaults, ...initial } as T)

  /** Set a single filter value and sync to URL. */
  function setFilter<K extends keyof T>(key: K, value: T[K]) {
    (filters as any)[key] = value
    syncToUrl()
  }

  /** Reset all filters back to their defaults. */
  function resetFilters() {
    for (const key of Object.keys(defaults)) {
      (filters as any)[key] = defaults[key]
    }
    syncToUrl()
  }

  /** Check whether any filter differs from its default. */
  const hasActiveFilters = computed(() =>
    Object.keys(defaults).some((key) => {
      const current = (filters as any)[key]
      const def = defaults[key]
      if (def === null || def === '') {
        return current !== null && current !== '' && current !== undefined
      }
      return current !== def
    }),
  )

  /** The number of active (non-default) filters. */
  const activeFilterCount = computed(() =>
    Object.keys(defaults).reduce((count, key) => {
      const current = (filters as any)[key]
      const def = defaults[key]
      if (def === null || def === '') {
        return current !== null && current !== '' && current !== undefined
          ? count + 1
          : count
      }
      return current !== def ? count + 1 : count
    }, 0),
  )

  /** Flat record suitable for spreading into API query params. */
  const queryParams = computed(() => {
    const params: Record<string, string> = {}
    for (const key of Object.keys(defaults)) {
      const val = (filters as any)[key]
      if (val !== null && val !== undefined && val !== '' && val !== defaults[key]) {
        params[key] = String(val)
      }
    }
    return params
  })

  // ── Internal ──────────────────────────────────────────────────────
  function syncToUrl() {
    const query: Record<string, string | undefined> = { ...route.query }

    // Reset pagination when filters change
    delete query.page

    for (const key of Object.keys(defaults)) {
      const val = (filters as any)[key]
      if (val === null || val === undefined || val === '' || val === defaults[key]) {
        delete query[key]
      }
      else {
        query[key] = String(val)
      }
    }

    router.replace({ query: query as Record<string, string> })
  }

  return {
    filters,
    setFilter,
    resetFilters,
    hasActiveFilters,
    activeFilterCount,
    queryParams,
  }
}
