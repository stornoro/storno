/**
 * Comma-separated list of things the super admin wants hidden from the audit
 * views ("contact@storno.ro, system"). Persisted per browser so it survives
 * navigation between /admin, /admin/audit-logs and /admin/activity.
 */
export function useAdminAuditExclude() {
  return useLocalStorage<string>('admin.auditExclude', '', { initOnMounted: true })
}
