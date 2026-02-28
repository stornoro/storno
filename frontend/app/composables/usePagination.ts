interface UsePaginationOptions {
  /** Default items per page. Defaults to 25. */
  defaultLimit?: number
  /** Allowed per-page options shown in the UI. */
  limitOptions?: number[]
}

/**
 * Server-side pagination composable with URL query synchronisation.
 *
 * Reads `page` and `limit` from the current route query and keeps
 * the URL in sync whenever the user navigates between pages.
 */
export function usePagination(options: UsePaginationOptions = {}) {
  const {
    defaultLimit = 25,
    limitOptions = [10, 25, 50, 100],
  } = options

  const route = useRoute()
  const router = useRouter()

  // Reactive state derived from URL query
  const page = computed<number>({
    get: () => {
      const raw = Number(route.query.page)
      return raw > 0 ? raw : 1
    },
    set: (value: number) => {
      updateQuery({ page: value === 1 ? undefined : String(value) })
    },
  })

  const limit = computed<number>({
    get: () => {
      const raw = Number(route.query.limit)
      return limitOptions.includes(raw) ? raw : defaultLimit
    },
    set: (value: number) => {
      updateQuery({
        limit: value === defaultLimit ? undefined : String(value),
        page: undefined, // reset to first page when limit changes
      })
    },
  })

  // Total items + computed last page (set by the consuming component)
  const total = ref(0)

  const lastPage = computed(() =>
    Math.max(1, Math.ceil(total.value / limit.value)),
  )

  const offset = computed(() => (page.value - 1) * limit.value)

  // Helpers
  function goToPage(p: number) {
    const clamped = Math.min(Math.max(1, p), lastPage.value)
    page.value = clamped
  }

  function nextPage() {
    if (page.value < lastPage.value) {
      goToPage(page.value + 1)
    }
  }

  function prevPage() {
    if (page.value > 1) {
      goToPage(page.value - 1)
    }
  }

  function setLimit(newLimit: number) {
    limit.value = newLimit
  }

  /** Query params ready to be spread into an API call. */
  const queryParams = computed(() => ({
    page: page.value,
    limit: limit.value,
  }))

  // Internal helper to push query changes without full navigation
  function updateQuery(patch: Record<string, string | undefined>) {
    const query = { ...route.query, ...patch }
    // Remove keys with undefined values
    for (const key of Object.keys(query)) {
      if (query[key] === undefined) {
        delete query[key]
      }
    }
    router.replace({ query })
  }

  return {
    page,
    limit,
    total,
    lastPage,
    offset,
    limitOptions,
    queryParams,
    goToPage,
    nextPage,
    prevPage,
    setLimit,
  }
}
