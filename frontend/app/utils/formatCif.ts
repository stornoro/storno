/**
 * Formats a CIF/CUI by prepending "RO" for VAT-payable companies.
 * CIFs are stored without the "RO" prefix in the database,
 * so this adds it back for display when the entity is a VAT payer.
 */
export function formatCif(cif: string | null | undefined, isVatPayer?: boolean): string {
  if (!cif) return ''
  if (isVatPayer && /^\d+$/.test(cif)) {
    return `RO${cif}`
  }
  return cif
}
