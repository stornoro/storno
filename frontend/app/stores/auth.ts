import { defineStore } from 'pinia'
import type { User } from '~/types'

export const useAuthStore = defineStore('auth', () => {
  // ── State ──────────────────────────────────────────────────────────
  // Tokens are plain refs. Cookie sync is handled by plugins/auth-cookies.ts
  const token = ref<string | null>(null)
  const user = ref<User | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  // MFA state
  const mfaPending = ref(false)
  const mfaToken = ref<string | null>(null)
  const mfaMethods = ref<string[]>([])

  // Impersonation state
  const originalToken = ref<string | null>(null)

  // ── Getters ────────────────────────────────────────────────────────
  const isAuthenticated = computed(() => !!token.value)

  const fullName = computed(() => {
    if (!user.value) return ''
    const first = user.value.firstName ?? ''
    const last = user.value.lastName ?? ''
    return `${first} ${last}`.trim() || user.value.email
  })

  const initials = computed(() => {
    if (!user.value) return ''
    const first = user.value.firstName?.[0] ?? ''
    const last = user.value.lastName?.[0] ?? ''
    return (first + last).toUpperCase() || user.value.email[0].toUpperCase()
  })

  const organization = computed(() => user.value?.organization ?? null)
  const memberships = computed(() => user.value?.memberships ?? [])
  const hasMultipleOrgs = computed(() => memberships.value.length > 1)
  const plan = computed(() => user.value?.plan ?? null)
  const effectivePlan = computed(() => plan.value?.plan ?? 'free')
  const isTrial = computed(() => plan.value?.trialActive === true)
  const isStarter = computed(() => effectivePlan.value === 'starter')
  const isProfessional = computed(() => effectivePlan.value === 'professional' || effectivePlan.value === 'trial')
  const isFreemium = computed(() => effectivePlan.value === 'freemium')
  const isPaid = computed(() => effectivePlan.value !== 'free' && effectivePlan.value !== 'freemium')
  const isSelfHosted = computed(() => plan.value?.selfHosted === true)
  const isCommunityEdition = computed(() => plan.value?.communityEdition === true)
  const isOwner = computed(() => {
    const orgId = organization.value?.id
    if (!orgId) return false
    return memberships.value.some(m => m.organization.id === orgId && m.role === 'owner')
  })
  const isSuperAdmin = computed(() => user.value?.roles?.includes('ROLE_SUPER_ADMIN') ?? false)
  const permissions = computed(() => user.value?.permissions ?? [])
  const currentRole = computed(() => user.value?.currentRole ?? null)
  const isImpersonating = computed(() => originalToken.value !== null)
  const impersonator = computed(() => user.value?.impersonator ?? null)

  // ── Actions ────────────────────────────────────────────────────────
  async function login(email: string, password: string, turnstileToken?: string): Promise<boolean | 'mfa_required'> {
    const apiBase = useApiBase()
    const fetchFn = useRequestFetch()
    loading.value = true
    error.value = null

    try {
      const response = await fetchFn<{ token?: string; mfa_required?: boolean; mfa_token?: string; mfa_methods?: string[] }>(
        '/auth',
        {
          baseURL: apiBase,
          method: 'POST',
          credentials: 'include',
          body: { email, password, turnstileToken },
        },
      )

      // Check if MFA is required
      if (response.mfa_required && response.mfa_token) {
        mfaPending.value = true
        mfaToken.value = response.mfa_token
        mfaMethods.value = response.mfa_methods ?? []
        return 'mfa_required'
      }

      token.value = response.token!

      // Fetch user profile after login
      await fetchUser()

      return true
    }
    catch (err: any) {
      error.value = (err?.data?.message ?? err?.data?.error) ? translateApiError(err.data.message ?? err.data.error) : 'Autentificarea a esuat.'
      return false
    }
    finally {
      loading.value = false
    }
  }

  async function register(payload: {
    email: string
    password: string
    firstName?: string
    lastName?: string
    organizationName?: string
    turnstileToken?: string
  }): Promise<boolean> {
    const apiBase = useApiBase()
    const fetchFn = useRequestFetch()
    loading.value = true
    error.value = null

    try {
      await fetchFn('/auth/register', {
        baseURL: apiBase,
        method: 'POST',
        body: payload,
      })

      return true
    }
    catch (err: any) {
      const status = err?.statusCode ?? err?.status
      if (status === 409) {
        error.value = 'auth.emailAlreadyExists'
      } else if (status === 429) {
        error.value = 'auth.tooManyAttempts'
      } else {
        error.value = err?.data?.error ?? 'auth.registerError'
      }
      return false
    }
    finally {
      loading.value = false
    }
  }

  async function loginWithGoogle(credential: string): Promise<boolean | 'mfa_required'> {
    const apiBase = useApiBase()
    const fetchFn = useRequestFetch()
    loading.value = true
    error.value = null

    try {
      const response = await fetchFn<{ token?: string; mfa_required?: boolean; mfa_token?: string; mfa_methods?: string[] }>(
        '/auth/google',
        {
          baseURL: apiBase,
          method: 'POST',
          credentials: 'include',
          body: { credential },
        },
      )

      // Check if MFA is required
      if (response.mfa_required && response.mfa_token) {
        mfaPending.value = true
        mfaToken.value = response.mfa_token
        mfaMethods.value = response.mfa_methods ?? []
        return 'mfa_required'
      }

      token.value = response.token!

      await fetchUser()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Autentificarea cu Google a esuat.'
      return false
    }
    finally {
      loading.value = false
    }
  }

  async function fetchUser(): Promise<boolean> {
    if (!token.value) return false

    const apiBase = useApiBase()
    const fetchFn = useRequestFetch()

    try {
      const response = await fetchFn<User>('/v1/me', {
        baseURL: apiBase,
        credentials: 'include',
        headers: {
          Authorization: `Bearer ${token.value}`,
          Accept: 'application/json',
        },
      })

      user.value = response

      // Reconcile impersonation state with backend —
      // clears stale originalToken if the JWT has no impersonator claim
      if (!response.impersonating && originalToken.value) {
        originalToken.value = null
      }

      const { applyFromUser } = useThemePreferences()
      applyFromUser(response)
      return true
    }
    catch (err: any) {
      user.value = null
      // Only clear tokens on explicit 401 (invalid token).
      // Network errors / timeouts should NOT destroy the session.
      if (err?.response?.status === 401) {
        // Attempt token refresh before giving up
        try {
          const refreshResponse = await fetchFn<{ token: string }>('/auth/refresh', {
            baseURL: apiBase,
            method: 'POST',
            credentials: 'include',
          })
          if (refreshResponse.token) {
            token.value = refreshResponse.token
            // Retry fetchUser with new token
            const retryResponse = await fetchFn<User>('/v1/me', {
              baseURL: apiBase,
              credentials: 'include',
              headers: {
                Authorization: `Bearer ${token.value}`,
                Accept: 'application/json',
              },
            })
            user.value = retryResponse
            if (!retryResponse.impersonating && originalToken.value) {
              originalToken.value = null
            }
            const { applyFromUser } = useThemePreferences()
            applyFromUser(retryResponse)
            return true
          }
        }
        catch {
          // Refresh failed — clear token
        }
        token.value = null
        return false
      }
      // For other errors (network, 500, etc.) keep the token — it may still be valid
      return false
    }
  }

  async function deleteAccount(password: string): Promise<void> {
    const { del } = useApi()
    await del('/v1/me', { password })
    logout()
    navigateTo('/login')
  }

  async function completeMfaLogin(code: string, type: 'totp' | 'backup'): Promise<boolean> {
    if (!mfaToken.value) return false

    const apiBase = useApiBase()
    const fetchFn = useRequestFetch()
    loading.value = true
    error.value = null

    try {
      const response = await fetchFn<{ token: string }>(
        '/auth/mfa/verify',
        {
          baseURL: apiBase,
          method: 'POST',
          credentials: 'include',
          body: { mfaToken: mfaToken.value, code, type },
        },
      )

      token.value = response.token
      mfaPending.value = false
      mfaToken.value = null
      mfaMethods.value = []

      await fetchUser()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Codul este invalid.'
      return false
    }
    finally {
      loading.value = false
    }
  }

  function clearMfa() {
    mfaPending.value = false
    mfaToken.value = null
    mfaMethods.value = []
  }

  async function startImpersonation(userId: string): Promise<boolean> {
    const apiBase = useApiBase()
    const fetchFn = useRequestFetch()

    try {
      const response = await fetchFn<{ token: string; user: any }>(`/v1/admin/users/${userId}/impersonate`, {
        baseURL: apiBase,
        method: 'POST',
        headers: { Authorization: `Bearer ${token.value}` },
      })

      originalToken.value = token.value
      token.value = response.token
      await fetchUser()

      // Reset company store and re-fetch for the impersonated user
      const companyStore = useCompanyStore()
      companyStore.companies = []
      companyStore.currentCompanyId = null
      await companyStore.fetchCompanies()

      return true
    }
    catch {
      return false
    }
  }

  async function stopImpersonation() {
    if (!originalToken.value) return
    token.value = originalToken.value
    originalToken.value = null
    await fetchUser()

    // Reset company store and re-fetch for the admin user
    const companyStore = useCompanyStore()
    companyStore.companies = []
    companyStore.currentCompanyId = null
    await companyStore.fetchCompanies()

    navigateTo('/admin/users')
  }

  function logout() {
    user.value = null
    token.value = null
    originalToken.value = null
    error.value = null
    clearMfa()
    const { reset } = useThemePreferences()
    reset()
  }

  return {
    // State
    user,
    token,
    loading,
    error,
    mfaPending,
    mfaToken,
    mfaMethods,
    originalToken,

    // Getters
    isAuthenticated,
    fullName,
    initials,
    organization,
    memberships,
    hasMultipleOrgs,
    plan,
    effectivePlan,
    isTrial,
    isStarter,
    isProfessional,
    isFreemium,
    isPaid,
    isSelfHosted,
    isCommunityEdition,
    isOwner,
    isSuperAdmin,
    permissions,
    currentRole,
    isImpersonating,
    impersonator,

    // Actions
    login,
    loginWithGoogle,
    register,
    fetchUser,
    deleteAccount,
    completeMfaLogin,
    clearMfa,
    startImpersonation,
    stopImpersonation,
    logout,
  }
})
