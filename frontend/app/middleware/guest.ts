export default defineNuxtRouteMiddleware(async () => {
  const authStore = useAuthStore()

  if (authStore.isAuthenticated) {
    const { resolve } = usePostLoginRoute()
    return navigateTo(await resolve())
  }
})
