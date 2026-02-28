/**
 * Returns the API base URL with /api suffix.
 * The env var (NUXT_PUBLIC_API_BASE) is the host only (e.g. https://api.storno.ro).
 * This composable appends /api so all fetch calls resolve correctly.
 */
export function useApiBase(): string {
  const config = useRuntimeConfig()
  const base = (config.public.apiBase as string).replace(/\/$/, '')
  if (base.endsWith('/api')) return base
  return `${base}/api`
}
