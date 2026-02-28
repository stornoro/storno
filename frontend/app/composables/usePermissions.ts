/**
 * Reactive permission checks based on the current user's effective permissions.
 * Permissions are server-computed (supports per-member custom overrides).
 * Auto-imported by Nuxt from composables/
 */
export function usePermissions() {
  const authStore = useAuthStore()

  const permissionSet = computed(() => new Set(authStore.user?.permissions ?? []))

  const currentRole = computed(() => authStore.user?.currentRole ?? null)

  /** Check if user has a single permission */
  function can(permission: string): boolean {
    return permissionSet.value.has(permission)
  }

  /** Check if user has ALL of the given permissions */
  function canAll(...permissions: string[]): boolean {
    return permissions.every(p => permissionSet.value.has(p))
  }

  /** Check if user has ANY of the given permissions */
  function canAny(...permissions: string[]): boolean {
    return permissions.some(p => permissionSet.value.has(p))
  }

  return {
    can,
    canAll,
    canAny,
    currentRole,
  }
}
