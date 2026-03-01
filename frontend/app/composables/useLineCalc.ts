interface LineBase {
  quantity: string
  unitPrice: string
  discount: string
  vatRate: string
}

/**
 * Maps VAT category codes to their corresponding invoice type codes.
 * Used to auto-update invoiceTypeCode when the dominant VAT category changes.
 */
export const vatCategoryToTypeCode: Record<string, string> = {
  AE: 'reverse_charge',
  K: 'exempt_art_294_ab',
  G: 'exempt_art_294_cd',
  E: 'exempt_with_deduction',
  O: 'non_taxable',
}

const autoSetTypeCodes = new Set(Object.values(vatCategoryToTypeCode))

/** Reverse mapping: invoiceTypeCode → vatCategoryCode for 0% lines */
export const typeCodeToVatCategory: Record<string, string> = Object.fromEntries(
  Object.entries(vatCategoryToTypeCode).map(([cat, type]) => [type, cat]),
)

/**
 * Determines the appropriate invoiceTypeCode based on VAT categories across all lines.
 *
 * Rules:
 * - If ALL lines share a special category (AE/K/G/E/O), set the matching type code
 * - If lines are mixed or all standard (S/Z), reset to 'standard' only if the
 *   current type was previously auto-set (avoids overwriting manual choices like
 *   self_billing, simplified, etc.)
 *
 * @param lines - Array of line objects with a vatCategoryCode string field
 * @param currentTypeCode - The current invoiceTypeCode value
 * @returns The new invoiceTypeCode to use
 */
export function resolveInvoiceTypeCode(
  lines: { vatCategoryCode: string }[],
  currentTypeCode: string | undefined,
): string {
  if (!lines.length) return currentTypeCode ?? 'standard'

  // Collect unique VAT category codes across all lines
  const categories = new Set(lines.map(l => l.vatCategoryCode))

  // If all lines share a single special category, auto-set the type code
  if (categories.size === 1) {
    const only = [...categories][0]
    const mapped = vatCategoryToTypeCode[only]
    if (mapped) return mapped
  }

  // Lines are mixed or all standard (S/Z) — reset only if previously auto-set
  if (autoSetTypeCodes.has(currentTypeCode!)) {
    return 'standard'
  }
  return currentTypeCode ?? 'standard'
}

export function useLineCalc() {
  function formatMoney(amount: number, currency = 'RON') {
    return new Intl.NumberFormat('ro-RO', { style: 'currency', currency }).format(amount)
  }

  function lineNet(line: LineBase): number {
    const qty = parseFloat(line.quantity) || 0
    const price = parseFloat(line.unitPrice) || 0
    const disc = parseFloat(line.discount) || 0
    return (qty * price) - disc
  }

  function lineVat(line: LineBase): number {
    return lineNet(line) * ((parseFloat(line.vatRate) || 0) / 100)
  }

  function formatLineTotal(line: LineBase, currency = 'RON'): string {
    const net = lineNet(line)
    const vat = lineVat(line)
    return formatMoney(net + vat, currency)
  }

  function computeSimpleTotals(lines: LineBase[]) {
    let subtotal = 0
    let vat = 0
    let discount = 0
    for (const line of lines) {
      subtotal += lineNet(line)
      vat += lineVat(line)
      discount += parseFloat(line.discount) || 0
    }
    return { subtotal, vat, discount, total: subtotal + vat }
  }

  function normalizeVatRate(rate: string | number): string {
    const num = parseFloat(String(rate))
    return isNaN(num) ? '21.00' : num.toFixed(2)
  }

  function normalizeVatCategoryCode(code: string, rate: string): string {
    const r = parseFloat(rate)
    if (code === 'S' && r === 0) return 'Z'
    if (['Z', 'E', 'AE', 'K', 'G'].includes(code) && r > 0) return 'S'
    return code
  }

  return {
    formatMoney,
    lineNet,
    lineVat,
    formatLineTotal,
    computeSimpleTotals,
    normalizeVatRate,
    normalizeVatCategoryCode,
  }
}
