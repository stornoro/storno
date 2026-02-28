interface FormatDateOptions {
  /** Locale for Intl.DateTimeFormat. Defaults to 'ro-RO'. */
  locale?: string
  /** Timezone. Defaults to 'Europe/Bucharest'. */
  timeZone?: string
}

/**
 * Date formatting composable for Romanian locale.
 *
 * All date values arrive from the API as ISO-8601 strings and are
 * formatted using Intl.DateTimeFormat.
 */
export function useDate() {
  /**
   * Format as a short date: "10.02.2026"
   */
  function formatDate(
    value: string | Date | null | undefined,
    options?: FormatDateOptions,
  ): string {
    if (!value) return '-'
    const date = typeof value === 'string' ? new Date(value) : value
    if (isNaN(date.getTime())) return '-'

    const { locale = 'ro-RO', timeZone = 'Europe/Bucharest' } = options ?? {}

    return new Intl.DateTimeFormat(locale, {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      timeZone,
    }).format(date)
  }

  /**
   * Format as date + time: "10.02.2026, 14:30"
   */
  function formatDateTime(
    value: string | Date | null | undefined,
    options?: FormatDateOptions,
  ): string {
    if (!value) return '-'
    const date = typeof value === 'string' ? new Date(value) : value
    if (isNaN(date.getTime())) return '-'

    const { locale = 'ro-RO', timeZone = 'Europe/Bucharest' } = options ?? {}

    return new Intl.DateTimeFormat(locale, {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      timeZone,
    }).format(date)
  }

  /**
   * Format as a long date: "10 februarie 2026"
   */
  function formatDateLong(
    value: string | Date | null | undefined,
    options?: FormatDateOptions,
  ): string {
    if (!value) return '-'
    const date = typeof value === 'string' ? new Date(value) : value
    if (isNaN(date.getTime())) return '-'

    const { locale = 'ro-RO', timeZone = 'Europe/Bucharest' } = options ?? {}

    return new Intl.DateTimeFormat(locale, {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
      timeZone,
    }).format(date)
  }

  /**
   * Format as month + year: "februarie 2026"
   */
  function formatMonthYear(
    value: string | Date | null | undefined,
    options?: FormatDateOptions,
  ): string {
    if (!value) return '-'
    const date = typeof value === 'string' ? new Date(value) : value
    if (isNaN(date.getTime())) return '-'

    const { locale = 'ro-RO', timeZone = 'Europe/Bucharest' } = options ?? {}

    return new Intl.DateTimeFormat(locale, {
      month: 'long',
      year: 'numeric',
      timeZone,
    }).format(date)
  }

  /**
   * Relative time description: "acum 3 zile", "in urma cu 2 ore"
   */
  function formatRelative(
    value: string | Date | null | undefined,
    options?: FormatDateOptions,
  ): string {
    if (!value) return '-'
    const date = typeof value === 'string' ? new Date(value) : value
    if (isNaN(date.getTime())) return '-'

    const { locale = 'ro-RO' } = options ?? {}
    const now = new Date()
    const diffMs = now.getTime() - date.getTime()
    const diffSeconds = Math.floor(diffMs / 1000)
    const diffMinutes = Math.floor(diffSeconds / 60)
    const diffHours = Math.floor(diffMinutes / 60)
    const diffDays = Math.floor(diffHours / 24)

    const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' })

    if (Math.abs(diffDays) >= 30) {
      return formatDate(date, options)
    }
    if (Math.abs(diffDays) >= 1) {
      return rtf.format(-diffDays, 'day')
    }
    if (Math.abs(diffHours) >= 1) {
      return rtf.format(-diffHours, 'hour')
    }
    if (Math.abs(diffMinutes) >= 1) {
      return rtf.format(-diffMinutes, 'minute')
    }
    return rtf.format(-diffSeconds, 'second')
  }

  /**
   * Format to ISO date string "2026-02-10" for API params.
   */
  function toISODate(value: string | Date | null | undefined): string | null {
    if (!value) return null
    const date = typeof value === 'string' ? new Date(value) : value
    if (isNaN(date.getTime())) return null
    return date.toISOString().split('T')[0]
  }

  return {
    formatDate,
    formatDateTime,
    formatDateLong,
    formatMonthYear,
    formatRelative,
    toISODate,
  }
}
