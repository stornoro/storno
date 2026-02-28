/**
 * Named middleware — checks user permissions before allowing page access.
 * Pages opt in via: definePageMeta({ middleware: ['auth', 'permissions'] })
 *
 * Checks (in order):
 * 1. ROUTE_PERMISSIONS map (route path → required permission)
 * 2. meta.permission on the route
 */
export default defineNuxtRouteMiddleware((to) => {
  const authStore = useAuthStore()

  // If user isn't loaded yet (SSR fetch failed, network error), skip permission
  // checks — the auth middleware already handles the "no user" case gracefully.
  if (!authStore.user) return

  const { canAll } = usePermissions()

  // 1. Check ROUTE_PERMISSIONS map
  const required = ROUTE_PERMISSIONS[to.path]
  if (required) {
    const perms = Array.isArray(required) ? required : [required]
    if (!canAll(...perms)) {
      return navigateTo('/dashboard')
    }
  }

  // 2. Check meta.permission (single string or array)
  const metaPerm = to.meta.permission as string | string[] | undefined
  if (metaPerm) {
    const perms = Array.isArray(metaPerm) ? metaPerm : [metaPerm]
    if (!canAll(...perms)) {
      return navigateTo('/dashboard')
    }
  }
})
