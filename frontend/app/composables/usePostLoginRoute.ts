/**
 * Resolves the correct route after login.
 *
 * Checks for a `redirect` query parameter first (set by auth middleware
 * when the user was redirected to login from another page).
 * Otherwise fetches companies and returns '/companies' when the user
 * has none, avoiding the visible /dashboard → /companies double redirect.
 */
export function usePostLoginRoute() {
  async function resolve(): Promise<string> {
    // Check for redirect query param (e.g. /login?redirect=/invoices)
    const route = useRoute()
    const redirect = route.query.redirect as string | undefined
    if (redirect && redirect.startsWith('/') && !redirect.startsWith('/login')) {
      return redirect
    }

    const companyStore = useCompanyStore()

    if (!companyStore.companies.length) {
      await companyStore.fetchCompanies()
    }

    return companyStore.hasCompanies ? '/dashboard' : '/companies'
  }

  return { resolve }
}
