import type { NitroFetchOptions } from 'nitropack'

// Module-level state for token refresh deduplication
let refreshPromise: Promise<boolean> | null = null

/**
 * JWT-authenticated fetch wrapper.
 *
 * - Uses useRequestFetch() so SSR requests route through Nitro's proxy
 * - Attaches the JWT token from the auth store as a Bearer header
 * - Sends the current company ID via the X-Company header
 * - On 401, attempts token refresh via httpOnly cookie before logging out
 */
export function useApi() {
  const apiBase = useApiBase()
  const authStore = useAuthStore()
  const companyStore = useCompanyStore()
  const fetchFn = useRequestFetch()

  async function refreshToken(): Promise<boolean> {
    try {
      const response = await fetchFn<{ token: string; refresh_token?: string }>('/auth/refresh', {
        baseURL: apiBase,
        method: 'POST',
        credentials: 'include',
        body: authStore.refreshToken ? { refresh_token: authStore.refreshToken } : undefined,
      })

      if (response.token) {
        authStore.token = response.token
        if (response.refresh_token) {
          authStore.refreshToken = response.refresh_token
        }
        return true
      }
      return false
    }
    catch {
      return false
    }
  }

  async function apiFetch<T>(
    endpoint: string,
    options: NitroFetchOptions<string> & { skipAuthRedirect?: boolean } = {},
  ): Promise<T> {
    const headers: Record<string, string> = {
      ...(options.responseType === 'blob' ? {} : { Accept: 'application/json' }),
      ...(options.headers as Record<string, string> ?? {}),
    }

    // Attach JWT bearer token
    if (authStore.token) {
      headers.Authorization = `Bearer ${authStore.token}`
    }

    // Attach current organization context
    if (authStore.organization?.id) {
      headers['X-Organization'] = authStore.organization.id
    }

    // Attach current company context
    if (companyStore.currentCompanyId) {
      headers['X-Company'] = companyStore.currentCompanyId
    }

    const { skipAuthRedirect, ...fetchOptions } = options

    try {
      return await fetchFn<T>(endpoint, {
        baseURL: apiBase,
        credentials: 'include',
        ...fetchOptions,
        headers,
      })
    }
    catch (error: any) {
      if (error?.response?.status === 401 && !skipAuthRedirect && authStore.token) {
        // During impersonation, stop impersonation instead of refresh (no refresh token exists)
        if (authStore.isImpersonating) {
          await authStore.stopImpersonation()
          throw error
        }

        // Deduplicate concurrent refresh attempts
        if (!refreshPromise) {
          refreshPromise = refreshToken().finally(() => { refreshPromise = null })
        }

        const refreshed = await refreshPromise

        if (refreshed) {
          // Retry original request with the new token
          headers.Authorization = `Bearer ${authStore.token}`
          return await fetchFn<T>(endpoint, {
            baseURL: apiBase,
            credentials: 'include',
            ...fetchOptions,
            headers,
          })
        }

        // Refresh failed — logout
        authStore.logout()
        navigateTo('/login')
      }
      else if (error?.response?.status === 401 && !skipAuthRedirect) {
        authStore.logout()
        navigateTo('/login')
      }
      throw error
    }
  }

  // Convenience methods
  function get<T>(endpoint: string, params?: Record<string, any>) {
    return apiFetch<T>(endpoint, { method: 'GET', params })
  }

  function post<T>(endpoint: string, body?: any) {
    return apiFetch<T>(endpoint, { method: 'POST', body })
  }

  function put<T>(endpoint: string, body?: any) {
    return apiFetch<T>(endpoint, { method: 'PUT', body })
  }

  function patch<T>(endpoint: string, body?: any) {
    return apiFetch<T>(endpoint, { method: 'PATCH', body })
  }

  function del<T>(endpoint: string, body?: any) {
    return apiFetch<T>(endpoint, { method: 'DELETE', ...(body !== undefined && { body }) })
  }

  return {
    apiFetch,
    get,
    post,
    put,
    patch,
    del,
  }
}
