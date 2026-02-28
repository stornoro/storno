export function useSeriesSelection(seriesType: string | Ref<string> | (() => string)) {
  const seriesStore = useDocumentSeriesStore()

  const sourceOrder: Record<string, number> = { efactura: 0, default: 1, manual: 2 }

  const resolvedType = computed(() =>
    typeof seriesType === 'function' ? seriesType() : unref(seriesType),
  )

  const seriesOptions = computed(() =>
    seriesStore.items
      .filter(s => s.active && s.type === resolvedType.value)
      .sort((a, b) => (sourceOrder[a.source] ?? 2) - (sourceOrder[b.source] ?? 2))
      .map(s => ({ label: s.prefix, value: s.id })),
  )

  function getSeriesNextNumber(seriesIdGetter: () => string | null) {
    return computed(() => {
      const id = seriesIdGetter()
      if (!id) return null
      const series = seriesStore.items.find(s => s.id === id)
      return series?.nextNumber ?? null
    })
  }

  async function loadSeries() {
    await seriesStore.fetchSeries()
  }

  function autoSelectFirst<T extends { documentSeriesId: string | null | undefined }>(form: T) {
    if (!form.documentSeriesId && seriesOptions.value.length > 0) {
      form.documentSeriesId = seriesOptions.value[0]?.value as T['documentSeriesId']
    }
  }

  return {
    seriesOptions,
    getSeriesNextNumber,
    loadSeries,
    autoSelectFirst,
  }
}
