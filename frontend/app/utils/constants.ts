export const PAGINATION = {
  DEFAULT_LIMIT: 10,
  MAX_LIMIT: 20,
} as const

export const invoiceTypeCodeShort: Record<string, string> = {
  standard: 'F',
  reverse_charge: 'T',
  exempt_with_deduction: 'S',
  services_art_311: 'U',
  sales_art_312: 'H',
  non_taxable: 'n',
  special_regime_art_314_315: 'X',
  non_transfer: 'N',
  simplified: 'M',
  services_art_278: 'P',
  exempt_art_294_ab: 'A',
  exempt_art_294_cd: 'C',
  self_billing: 'B',
}
