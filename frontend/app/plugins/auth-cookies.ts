/**
 * Syncs auth tokens and selected company between cookies and Pinia stores.
 *
 * - On SSR: reads cookies from the request, populates stores before middleware runs
 * - On client: reads cookies from document.cookie, populates stores
 * - Watches store changes and writes them back to cookies
 *
 * This avoids using useCookie inside Pinia stores (which causes skipHydrate/serialization issues).
 */
export default defineNuxtPlugin(() => {
  const authStore = useAuthStore()
  const companyStore = useCompanyStore()

  // ── Auth cookies ─────────────────────────────────────────────────
  const tokenCookie = useCookie<string | null>('auth_token', {
    maxAge: 60 * 60 * 24 * 30,
    path: '/',
    sameSite: 'lax',
    default: () => null,
  })
  // ── Impersonation cookie (1h TTL matching JWT) ─────────────────
  const originalTokenCookie = useCookie<string | null>('impersonate_original_token', {
    maxAge: 60 * 60,
    path: '/',
    sameSite: 'lax',
    default: () => null,
  })
  // ── Company cookie ───────────────────────────────────────────────
  const companyCookie = useCookie<string | null>('selected_company_id', {
    maxAge: 60 * 60 * 24 * 365,
    path: '/',
    default: () => null,
  })

  // ── Initialize stores from cookies ───────────────────────────────
  if (tokenCookie.value) {
    authStore.token = tokenCookie.value
  }
  if (originalTokenCookie.value) {
    authStore.originalToken = originalTokenCookie.value
  }
  if (companyCookie.value) {
    companyStore.currentCompanyId = companyCookie.value
  }

  // ── Theme preferences (cookie → appConfig before first render) ──
  // The composable reads the cookie and applies on creation
  useThemePreferences()

  // ── Watch store → cookie sync ────────────────────────────────────
  watch(() => authStore.token, (val) => {
    tokenCookie.value = val
  })
  watch(() => authStore.originalToken, (val) => {
    originalTokenCookie.value = val
  })
  watch(() => companyStore.currentCompanyId, (val) => {
    companyCookie.value = val
  })
})
