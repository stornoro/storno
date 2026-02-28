export type PresetKey =
  | 'today'
  | 'yesterday'
  | 'currentWeek'
  | 'lastWeek'
  | 'currentMonth'
  | 'lastMonth'
  | 'currentYear'
  | 'lastYear'
  | 'last7Days'
  | 'last30Days'
  | 'custom'

export interface DateRange {
  dateFrom: string
  dateTo: string
}

export function usePeriodSelector(defaultPreset: PresetKey = 'currentMonth') {
  const { t } = useI18n()

  const selectedPreset = ref<PresetKey>(defaultPreset)
  const customDateFrom = ref('')
  const customDateTo = ref('')

  const presets = computed(() => [
    { label: t('period.today'), value: 'today' as PresetKey },
    { label: t('period.yesterday'), value: 'yesterday' as PresetKey },
    { label: t('period.currentWeek'), value: 'currentWeek' as PresetKey },
    { label: t('period.lastWeek'), value: 'lastWeek' as PresetKey },
    { label: t('period.currentMonth'), value: 'currentMonth' as PresetKey },
    { label: t('period.lastMonth'), value: 'lastMonth' as PresetKey },
    { label: t('period.currentYear'), value: 'currentYear' as PresetKey },
    { label: t('period.lastYear'), value: 'lastYear' as PresetKey },
    { label: t('period.last7Days'), value: 'last7Days' as PresetKey },
    { label: t('period.last30Days'), value: 'last30Days' as PresetKey },
    { label: t('period.custom'), value: 'custom' as PresetKey },
  ])

  function resolveRange(): DateRange {
    const now = new Date()
    const y = now.getFullYear()
    const m = now.getMonth()
    const d = now.getDay() // 0 = Sunday

    switch (selectedPreset.value) {
      case 'today': {
        const iso = now.toISOString().slice(0, 10)
        return { dateFrom: iso, dateTo: iso }
      }
      case 'yesterday': {
        const yd = new Date(now)
        yd.setDate(yd.getDate() - 1)
        const iso = yd.toISOString().slice(0, 10)
        return { dateFrom: iso, dateTo: iso }
      }
      case 'currentWeek': {
        // Monday-based week
        const monday = new Date(now)
        monday.setDate(monday.getDate() - ((d + 6) % 7))
        const sunday = new Date(monday)
        sunday.setDate(sunday.getDate() + 6)
        return { dateFrom: monday.toISOString().slice(0, 10), dateTo: sunday.toISOString().slice(0, 10) }
      }
      case 'lastWeek': {
        const monday = new Date(now)
        monday.setDate(monday.getDate() - ((d + 6) % 7) - 7)
        const sunday = new Date(monday)
        sunday.setDate(sunday.getDate() + 6)
        return { dateFrom: monday.toISOString().slice(0, 10), dateTo: sunday.toISOString().slice(0, 10) }
      }
      case 'currentMonth': {
        const from = new Date(y, m, 1)
        const to = new Date(y, m + 1, 0)
        return { dateFrom: from.toISOString().slice(0, 10), dateTo: to.toISOString().slice(0, 10) }
      }
      case 'lastMonth': {
        const from = new Date(y, m - 1, 1)
        const to = new Date(y, m, 0)
        return { dateFrom: from.toISOString().slice(0, 10), dateTo: to.toISOString().slice(0, 10) }
      }
      case 'currentYear':
        return { dateFrom: `${y}-01-01`, dateTo: `${y}-12-31` }
      case 'lastYear':
        return { dateFrom: `${y - 1}-01-01`, dateTo: `${y - 1}-12-31` }
      case 'last7Days': {
        const from = new Date(now)
        from.setDate(from.getDate() - 6)
        return { dateFrom: from.toISOString().slice(0, 10), dateTo: now.toISOString().slice(0, 10) }
      }
      case 'last30Days': {
        const from = new Date(now)
        from.setDate(from.getDate() - 29)
        return { dateFrom: from.toISOString().slice(0, 10), dateTo: now.toISOString().slice(0, 10) }
      }
      case 'custom':
        return { dateFrom: customDateFrom.value, dateTo: customDateTo.value }
      default:
        return { dateFrom: '', dateTo: '' }
    }
  }

  const resolvedRange = computed<DateRange>(() => resolveRange())

  const isCustom = computed(() => selectedPreset.value === 'custom')

  const displayLabel = computed(() => {
    const match = presets.value.find(p => p.value === selectedPreset.value)
    return match?.label ?? ''
  })

  return {
    selectedPreset,
    customDateFrom,
    customDateTo,
    presets,
    resolvedRange,
    isCustom,
    displayLabel,
  }
}
