export default defineNuxtRouteMiddleware(async (to) => {
  const authStore = useAuthStore()

  if (!authStore.isAuthenticated) {
    return navigateTo('/login')
  }

  // Fetch user profile if not loaded yet (e.g. page refresh with saved token)
  if (!authStore.user) {
    await authStore.fetchUser()

    // If token was invalid (401), fetchUser already cleared the cookies
    if (!authStore.isAuthenticated) {
      return navigateTo('/login')
    }

    // If user is still null but token exists (network error, backend down),
    // let the page render — client-side will retry
    if (!authStore.user && import.meta.server) {
      return
    }

    // On client, if user couldn't be fetched and token exists, don't block navigation
    if (!authStore.user) {
      return
    }
  }

  // Fetch companies if not loaded yet
  const companyStore = useCompanyStore()
  if (!companyStore.companies.length) {
    await companyStore.fetchCompanies()
  }

  // Fetch usage data in background (non-blocking) for upgrade nudges
  if (import.meta.client) {
    const usageStore = useUsageStore()
    usageStore.fetchUsage()
  }

  // Pages that work without a company — everything else requires one
  const companyFreePaths = [
    '/companies',
    '/admin',
    '/settings/profile',
    '/settings/team',
    '/settings/billing',
    '/settings/api-keys',
    '/settings/notifications',
    '/settings/license-keys',
  ]
  const needsCompany = !companyFreePaths.some(p => to.path === p || to.path.startsWith(p + '/'))

  if (!companyStore.hasCompanies && needsCompany) {
    return navigateTo('/companies')
  }
})
