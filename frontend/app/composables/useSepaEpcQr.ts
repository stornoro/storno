export interface SepaEpcQrInput {
  beneficiaryName: string
  iban: string
  amount: number
  currency?: string
  remittance?: string
  reference?: string
  purpose?: string
}

const NAME_MAX = 70
const REMITTANCE_MAX = 140
const REFERENCE_MAX = 35
const PURPOSE_MAX = 4

export function normalizeIban(value: string | null | undefined): string {
  return (value || '').replace(/\s+/g, '').toUpperCase()
}

export function isValidIban(value: string | null | undefined): boolean {
  const iban = normalizeIban(value)
  if (iban.length < 15 || iban.length > 34) return false
  if (!/^[A-Z0-9]+$/.test(iban)) return false
  return true
}

export function formatIbanGrouped(value: string | null | undefined): string {
  return normalizeIban(value).replace(/(.{4})/g, '$1 ').trim()
}

export function buildInvoiceRemittance(invoiceNumbers: string[], prefix = 'Plata facturi'): string {
  const numbers = invoiceNumbers.filter(Boolean)
  if (numbers.length === 0) return ''
  if (numbers.length === 1) {
    const base = `Plata factura ${numbers[0]}`
    return base.slice(0, REMITTANCE_MAX)
  }
  const full = `${prefix} ${numbers.join(', ')}`
  if (full.length <= REMITTANCE_MAX) return full
  let included = 0
  for (let i = 1; i <= numbers.length; i++) {
    const remaining = numbers.length - i
    const candidate = `${prefix} ${numbers.slice(0, i).join(', ')}${remaining > 0 ? ` +${remaining}` : ''}`
    if (candidate.length > REMITTANCE_MAX) break
    included = i
  }
  if (included === 0) {
    return `${prefix} +${numbers.length}`.slice(0, REMITTANCE_MAX)
  }
  const remaining = numbers.length - included
  return `${prefix} ${numbers.slice(0, included).join(', ')}${remaining > 0 ? ` +${remaining}` : ''}`
}

export function buildSepaEpcPayload(input: SepaEpcQrInput): string {
  const name = (input.beneficiaryName || '').trim().slice(0, NAME_MAX)
  const iban = normalizeIban(input.iban)
  const currency = (input.currency || 'RON').toUpperCase()
  const amount = Number.isFinite(input.amount) && input.amount > 0
    ? `${currency}${input.amount.toFixed(2)}`
    : ''
  const purpose = (input.purpose || '').slice(0, PURPOSE_MAX)
  const reference = (input.reference || '').slice(0, REFERENCE_MAX)
  const remittance = (input.remittance || '').slice(0, REMITTANCE_MAX)

  return [
    'BCD',
    '002',
    '1',
    'SCT',
    '',
    name,
    iban,
    amount,
    purpose,
    reference,
    remittance,
  ].join('\n')
}
