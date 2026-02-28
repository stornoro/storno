/**
 * Authentication composable.
 *
 * Thin wrapper around the auth store that exposes a clean API
 * for components. Delegates all state management and HTTP calls
 * to the auth Pinia store.
 */
export function useAuth() {
  const authStore = useAuthStore()
  const { resolve: resolvePostLogin } = usePostLoginRoute()

  /** Log in with email + password. Returns true on success. */
  async function login(email: string, password: string): Promise<boolean> {
    const success = await authStore.login(email, password)
    if (success) {
      navigateTo(await resolvePostLogin())
    }
    return success
  }

  /** Register a new account. Returns true on success. */
  async function register(payload: {
    email: string
    password: string
    firstName?: string
    lastName?: string
    organizationName?: string
  }): Promise<boolean> {
    return await authStore.register(payload)
  }

  /** Log the current user out, clearing stored tokens. */
  function logout(): void {
    authStore.logout()
    navigateTo('/login')
  }

  /** Fetch and refresh the currently authenticated user profile. */
  async function getCurrentUser(): Promise<void> {
    await authStore.fetchUser()
  }

  return {
    login,
    register,
    logout,
    getCurrentUser,

    // Re-expose reactive state for convenience
    user: computed(() => authStore.user),
    isAuthenticated: computed(() => authStore.isAuthenticated),
    fullName: computed(() => authStore.fullName),
    initials: computed(() => authStore.initials),
    loading: computed(() => authStore.loading),
    error: computed(() => authStore.error),
  }
}
