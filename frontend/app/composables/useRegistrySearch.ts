export interface RegistryCompany {
  denumire: string
  cod_unic: string
  cod_inmatriculare: string | null
  adresa: string | null
  localitate: string | null
  nume_judet: string | null
  radiat: boolean
}

export function useRegistrySearch() {
  const { get } = useApi()
  const results = ref<RegistryCompany[]>([])
  const loading = ref(false)

  const onRegistrySearch = useDebounceFn(async (query: string) => {
    if (!query || query.length < 2) {
      results.value = []
      return
    }

    loading.value = true
    try {
      const res = await get<{ data: RegistryCompany[] }>('/v1/company-registry/search', {
        q: query,
        limit: 20,
      })
      results.value = res.data
    }
    catch {
      results.value = []
    }
    finally {
      loading.value = false
    }
  }, 300)

  function clear() {
    results.value = []
  }

  return {
    results,
    loading,
    onRegistrySearch,
    clear,
  }
}
