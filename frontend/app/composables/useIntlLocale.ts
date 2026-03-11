/**
 * Maps the current i18n locale code to a full Intl locale string.
 * Falls back to 'ro-RO' when i18n is not available (SSR edge-cases).
 */
const LOCALE_MAP: Record<string, string> = {
  ro: 'ro-RO',
  en: 'en-GB',
}

export function useIntlLocale(): string {
  try {
    const { locale } = useI18n()
    return LOCALE_MAP[locale.value] ?? 'ro-RO'
  } catch {
    return 'ro-RO'
  }
}
