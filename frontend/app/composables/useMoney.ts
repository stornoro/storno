type SupportedCurrency = 'RON' | 'EUR' | 'USD' | 'GBP'

interface FormatMoneyOptions {
  /** Currency code. Defaults to 'RON'. */
  currency?: SupportedCurrency
  /** Locale for Intl.NumberFormat. Defaults to 'ro-RO'. */
  locale?: string
  /** Show the full currency symbol or the narrow one. Defaults to 'symbol'. */
  currencyDisplay?: 'symbol' | 'narrowSymbol' | 'code' | 'name'
  /** Minimum fraction digits. Defaults to 2. */
  minimumFractionDigits?: number
  /** Maximum fraction digits. Defaults to 2. */
  maximumFractionDigits?: number
}

/**
 * Currency formatting composable for Romanian invoicing.
 *
 * All monetary values arrive from the API as decimal strings (e.g. "1234.56")
 * and are formatted using Intl.NumberFormat for the Romanian locale.
 */
export function useMoney() {
  /**
   * Format a decimal string or number as a localised currency value.
   *
   * @example
   * formatMoney('1234.56')          // "1.234,56 RON"
   * formatMoney('99.00', 'EUR')     // "99,00 €"
   * formatMoney(0)                  // "0,00 RON"
   */
  function formatMoney(
    value: string | number | null | undefined,
    currencyOrOptions?: SupportedCurrency | FormatMoneyOptions,
  ): string {
    const numericValue = value === null || value === undefined
      ? 0
      : typeof value === 'string'
        ? parseFloat(value) || 0
        : value

    const opts: FormatMoneyOptions = typeof currencyOrOptions === 'string'
      ? { currency: currencyOrOptions }
      : currencyOrOptions ?? {}

    const {
      currency = 'RON',
      locale = 'ro-RO',
      currencyDisplay = 'symbol',
      minimumFractionDigits = 2,
      maximumFractionDigits = 2,
    } = opts

    return new Intl.NumberFormat(locale, {
      style: 'currency',
      currency,
      currencyDisplay,
      minimumFractionDigits,
      maximumFractionDigits,
    }).format(numericValue)
  }

  /**
   * Format as a plain number (no currency symbol).
   *
   * @example
   * formatNumber('1234.56') // "1.234,56"
   */
  function formatNumber(
    value: string | number | null | undefined,
    locale: string = 'ro-RO',
  ): string {
    const numericValue = value === null || value === undefined
      ? 0
      : typeof value === 'string'
        ? parseFloat(value) || 0
        : value

    return new Intl.NumberFormat(locale, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(numericValue)
  }

  /**
   * Parse a Romanian formatted string back to a number.
   *
   * @example
   * parseMoney('1.234,56 RON') // 1234.56
   */
  function parseMoney(formatted: string): number {
    // Strip currency symbols and whitespace, swap Romanian separators
    const cleaned = formatted
      .replace(/[^\d.,-]/g, '')
      .replace(/\./g, '')      // thousands separator
      .replace(',', '.')       // decimal separator
    return parseFloat(cleaned) || 0
  }

  return {
    formatMoney,
    formatNumber,
    parseMoney,
  }
}
