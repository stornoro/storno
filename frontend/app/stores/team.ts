import { defineStore } from 'pinia'
import type { Invitation, PermissionsReference, TeamMember, TeamMeta } from '~/types'

export const useTeamStore = defineStore('team', () => {
  const members = ref<TeamMember[]>([])
  const invitations = ref<Invitation[]>([])
  const meta = ref<TeamMeta>({ canManage: false, maxUsers: 1, currentCount: 0 })
  const permissionsReference = ref<PermissionsReference | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchMembers(): Promise<void> {
    const { get } = useApi()
    loading.value = true
    error.value = null

    try {
      const response = await get<{ data: TeamMember[], meta: TeamMeta }>('/v1/members')
      members.value = response.data
      meta.value = response.meta
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca membrii.'
      members.value = []
    }
    finally {
      loading.value = false
    }
  }

  async function fetchPermissionsReference(): Promise<void> {
    const { get } = useApi()
    try {
      const response = await get<PermissionsReference>('/v1/members/permissions-reference')
      permissionsReference.value = response
    }
    catch {
      permissionsReference.value = null
    }
  }

  async function updateMember(id: string, data: { role?: string, isActive?: boolean, allowedCompanies?: string[], permissions?: string[] | null }): Promise<boolean> {
    const { patch } = useApi()
    error.value = null
    try {
      await patch(`/v1/members/${id}`, data)
      await fetchMembers()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut actualiza membrul.'
      return false
    }
  }

  async function removeMember(id: string): Promise<boolean> {
    const { del } = useApi()
    error.value = null
    try {
      await del(`/v1/members/${id}`)
      await fetchMembers()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut elimina membrul.'
      return false
    }
  }

  async function fetchInvitations(): Promise<void> {
    const { get } = useApi()
    try {
      const response = await get<{ data: Invitation[] }>('/v1/invitations')
      invitations.value = response.data
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-au putut incarca invitatiile.'
      invitations.value = []
    }
  }

  async function createInvitation(data: { email: string, role: string, allowedCompanies?: string[] }): Promise<Invitation | null> {
    const { post } = useApi()
    error.value = null
    try {
      const result = await post<Invitation>('/v1/invitations', data)
      await fetchInvitations()
      return result
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut crea invitatia.'
      return null
    }
  }

  async function cancelInvitation(id: string): Promise<boolean> {
    const { del } = useApi()
    error.value = null
    try {
      await del(`/v1/invitations/${id}`)
      await fetchInvitations()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut anula invitatia.'
      return false
    }
  }

  async function resendInvitation(id: string): Promise<boolean> {
    const { post } = useApi()
    error.value = null
    try {
      await post(`/v1/invitations/${id}/resend`)
      await fetchInvitations()
      return true
    }
    catch (err: any) {
      error.value = err?.data?.error ? translateApiError(err.data.error) : 'Nu s-a putut retrimite invitatia.'
      return false
    }
  }

  function $reset() {
    members.value = []
    invitations.value = []
    meta.value = { canManage: false, maxUsers: 1, currentCount: 0 }
    permissionsReference.value = null
    loading.value = false
    error.value = null
  }

  return {
    members, invitations, meta, permissionsReference, loading, error,
    fetchMembers, fetchPermissionsReference, updateMember, removeMember,
    fetchInvitations, createInvitation, cancelInvitation, resendInvitation,
    $reset,
  }
})
