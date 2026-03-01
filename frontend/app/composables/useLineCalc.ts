interface LineBase {
  quantity: string
  unitPrice: string
  discount: string
  vatRate: string
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
