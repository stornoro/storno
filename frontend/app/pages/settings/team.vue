<script setup lang="ts">
import type { Invitation, TeamMember } from '~/types'

definePageMeta({ middleware: ['auth', 'permissions'] })

const { t: $t } = useI18n()
useHead({ title: $t('settings.team') })
const teamStore = useTeamStore()
const toast = useToast()

const loading = computed(() => teamStore.loading)
const members = computed(() => teamStore.members)
const invitations = computed(() => teamStore.invitations)
const meta = computed(() => teamStore.meta)

const inviteModalOpen = ref(false)
const editModalOpen = ref(false)
const editingMember = ref<TeamMember | null>(null)

const memberColumns = [
  { id: 'user', header: $t('settings.teamManagement.user') },
  { accessorKey: 'role', header: $t('settings.role') },
  { accessorKey: 'isActive', header: $t('settings.status') },
  { id: 'allowedCompanies', header: $t('settings.teamManagement.companies') },
  { id: 'actions', header: $t('common.actions') },
]

const invitationColumns = [
  { accessorKey: 'email', header: $t('settings.invitations.emailLabel') },
  { id: 'invRole', header: $t('settings.invitations.roleLabel') },
  { id: 'invCompanies', header: $t('settings.teamManagement.companies') },
  { id: 'invExpires', header: $t('settings.invitations.expiresAt') },
  { id: 'invActions', header: $t('common.actions') },
]

function getInitials(member: TeamMember): string {
  const first = member.user.firstName?.[0] ?? ''
  const last = member.user.lastName?.[0] ?? ''
  return (first + last).toUpperCase() || member.user.email[0]?.toUpperCase() || '?'
}

function getFullName(member: TeamMember): string {
  const name = [member.user.firstName, member.user.lastName].filter(Boolean).join(' ')
  return name || member.user.email
}

type BadgeColor = 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'error' | 'neutral'

function getRoleColor(role: string): BadgeColor {
  const map: Record<string, BadgeColor> = { owner: 'error', admin: 'warning', accountant: 'info', employee: 'neutral' }
  return map[role] || 'neutral'
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('ro-RO', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

function getMemberActions(member: TeamMember) {
  const items = [
    {
      label: $t('settings.teamManagement.editMember'),
      icon: 'i-lucide-pencil',
      onSelect: () => openEditMember(member),
    },
  ]
  if (member.isActive) {
    items.push({
      label: $t('settings.teamManagement.removeMember'),
      icon: 'i-lucide-user-x',
      onSelect: () => openRemoveMember(member),
    })
  }
  else {
    items.push({
      label: $t('settings.teamManagement.reactivateMember'),
      icon: 'i-lucide-user-check',
      onSelect: () => onReactivateMember(member),
    })
  }
  return items
}

function openEditMember(member: TeamMember) {
  editingMember.value = member
  editModalOpen.value = true
}

const removeModalOpen = ref(false)
const removingMember = ref<TeamMember | null>(null)
const removing = ref(false)

function openRemoveMember(member: TeamMember) {
  removingMember.value = member
  removeModalOpen.value = true
}

async function onRemoveMember() {
  if (!removingMember.value) return
  removing.value = true
  const ok = await teamStore.removeMember(removingMember.value.id)
  if (ok) {
    toast.add({ title: $t('settings.teamManagement.removeSuccess'), color: 'success' })
    removeModalOpen.value = false
  }
  else {
    toast.add({ title: teamStore.error || $t('settings.teamManagement.removeError'), color: 'error' })
  }
  removing.value = false
}

async function onReactivateMember(member: TeamMember) {
  const ok = await teamStore.updateMember(member.id, { isActive: true })
  if (ok) {
    toast.add({ title: $t('settings.teamManagement.updateSuccess'), color: 'success' })
  }
  else if (teamStore.error) {
    toast.add({ title: teamStore.error, color: 'error' })
  }
}

const cancelInvitationModalOpen = ref(false)
const cancellingInvitation = ref<Invitation | null>(null)
const cancelling = ref(false)

function openCancelInvitation(invitation: Invitation) {
  cancellingInvitation.value = invitation
  cancelInvitationModalOpen.value = true
}

async function onCancelInvitation() {
  if (!cancellingInvitation.value) return
  cancelling.value = true
  const ok = await teamStore.cancelInvitation(cancellingInvitation.value.id)
  if (ok) {
    toast.add({ title: $t('settings.invitations.cancelSuccess'), color: 'success' })
    cancelInvitationModalOpen.value = false
  }
  else {
    toast.add({ title: teamStore.error || $t('settings.invitations.cancelError'), color: 'error' })
  }
  cancelling.value = false
}

async function onResendInvitation(invitation: Invitation) {
  const ok = await teamStore.resendInvitation(invitation.id)
  if (ok) {
    toast.add({ title: $t('settings.invitations.resendSuccess'), color: 'success' })
  }
  else {
    toast.add({ title: teamStore.error || $t('settings.invitations.resendError'), color: 'error' })
  }
}

function onInvitationCreated() {
  inviteModalOpen.value = false
  toast.add({ title: $t('settings.invitations.createSuccess'), color: 'success' })
}

function onMemberUpdated() {
  editModalOpen.value = false
  toast.add({ title: $t('settings.teamManagement.updateSuccess'), color: 'success' })
}

onMounted(async () => {
  await teamStore.fetchMembers()
  if (meta.value.canManage) {
    teamStore.fetchInvitations()
  }
})
</script>

<template>
  <div>
    <UPageCard
      :title="$t('settings.team')"
      :description="$t('settings.teamDescription')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        v-if="meta.canManage"
        :label="$t('settings.inviteMember')"
        color="neutral"
        icon="i-lucide-user-plus"
        class="w-fit lg:ms-auto"
        @click="inviteModalOpen = true"
      />
    </UPageCard>

    <UPageCard
      variant="subtle"
      :ui="{ container: 'p-0 sm:p-0 gap-y-0', wrapper: 'items-stretch' }"
    >
      <UTable
        :data="members"
        :columns="memberColumns"
        :loading="loading"
        :ui="{
          base: 'table-fixed',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'px-4',
          td: 'px-4 border-b border-default',
        }"
      >
        <template #user-cell="{ row }">
          <div class="flex items-center gap-3">
            <UAvatar :text="getInitials(row.original)" size="sm" />
            <div>
              <div class="font-medium">{{ getFullName(row.original) }}</div>
              <div class="text-sm text-muted">{{ row.original.user.email }}</div>
            </div>
          </div>
        </template>
        <template #role-cell="{ row }">
          <div class="flex items-center gap-1.5">
            <UBadge :color="getRoleColor(row.original.role)" variant="subtle" size="sm">
              {{ $t(`settings.roles.${row.original.role}`) }}
            </UBadge>
            <UIcon
              v-if="row.original.hasCustomPermissions"
              name="i-lucide-shield-check"
              class="size-4 text-(--ui-text-muted)"
              :title="$t('settings.teamManagement.customPermissionsActive')"
            />
          </div>
        </template>
        <template #isActive-cell="{ row }">
          <UBadge :color="row.original.isActive ? 'success' : 'neutral'" variant="subtle" size="sm">
            {{ row.original.isActive ? $t('common.active') : $t('common.inactive') }}
          </UBadge>
        </template>
        <template #allowedCompanies-cell="{ row }">
          <span v-if="row.original.role === 'owner' || row.original.role === 'admin'" class="text-sm text-muted">
            {{ $t('settings.teamManagement.allCompanies') }}
          </span>
          <span v-else-if="row.original.allowedCompanies.length === 0" class="text-sm text-muted">
            {{ $t('settings.teamManagement.allCompanies') }}
          </span>
          <span v-else class="text-sm">
            {{ $t('settings.teamManagement.restrictedCompanies', row.original.allowedCompanies.length, { count: row.original.allowedCompanies.length }) }}
          </span>
        </template>
        <template #actions-cell="{ row }">
          <UDropdownMenu
            v-if="meta.canManage && !row.original.isCurrentUser && !row.original.isSuperAdmin"
            :items="getMemberActions(row.original)"
          >
            <UButton icon="i-lucide-ellipsis-vertical" variant="ghost" size="xs" />
          </UDropdownMenu>
          <span v-else-if="row.original.isCurrentUser" class="text-xs text-muted italic">
            (tu)
          </span>
        </template>
      </UTable>

      <UEmpty
        v-if="!loading && members.length === 0"
        icon="i-lucide-users"
        :title="$t('settings.noTeamMembers')"
        class="py-12"
      />
    </UPageCard>

    <!-- Pending Invitations -->
    <div v-if="meta.canManage && invitations.length > 0" class="mt-8">
      <UPageCard
        :title="$t('settings.invitations.title')"
        variant="naked"
        class="mb-4"
      />

      <UPageCard
        variant="subtle"
        :ui="{ container: 'p-0 sm:p-0 gap-y-0', wrapper: 'items-stretch' }"
      >
        <UTable
          :data="invitations"
          :columns="invitationColumns"
          :ui="{
            base: 'table-fixed',
            thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
            tbody: '[&>tr]:last:[&>td]:border-b-0',
            th: 'px-4',
            td: 'px-4 border-b border-default',
          }"
        >
          <template #invRole-cell="{ row }">
            <UBadge :color="getRoleColor(row.original.role)" variant="subtle" size="sm">
              {{ $t(`settings.roles.${row.original.role}`) }}
            </UBadge>
          </template>
          <template #invCompanies-cell="{ row }">
            <span v-if="row.original.role === 'admin'" class="text-sm text-muted">
              {{ $t('settings.teamManagement.allCompanies') }}
            </span>
            <span v-else-if="!row.original.allowedCompanies?.length" class="text-sm text-muted">
              {{ $t('settings.teamManagement.allCompanies') }}
            </span>
            <span v-else class="text-sm">
              {{ row.original.allowedCompanies.map((c: any) => c.name).join(', ') }}
            </span>
          </template>
          <template #invExpires-cell="{ row }">
            <span class="text-sm">{{ formatDate(row.original.expiresAt) }}</span>
          </template>
          <template #invActions-cell="{ row }">
            <div class="flex gap-1">
              <UButton
                icon="i-lucide-send"
                variant="ghost"
                size="xs"
                :title="$t('settings.invitations.resend')"
                @click="onResendInvitation(row.original)"
              />
              <UButton
                icon="i-lucide-x"
                variant="ghost"
                size="xs"
                color="error"
                :title="$t('settings.invitations.cancel')"
                @click="openCancelInvitation(row.original)"
              />
            </div>
          </template>
        </UTable>
      </UPageCard>
    </div>

    <SharedConfirmModal
      v-model:open="removeModalOpen"
      :title="$t('settings.teamManagement.removeTitle')"
      :description="$t('settings.teamManagement.removeDescription')"
      icon="i-lucide-user-x"
      color="error"
      :confirm-label="$t('settings.teamManagement.removeMember')"
      :loading="removing"
      @confirm="onRemoveMember"
    />

    <SharedConfirmModal
      v-model:open="cancelInvitationModalOpen"
      :title="$t('settings.invitations.cancelTitle')"
      :description="$t('settings.invitations.cancelDescription')"
      icon="i-lucide-x"
      color="error"
      :confirm-label="$t('settings.invitations.cancel')"
      :loading="cancelling"
      @confirm="onCancelInvitation"
    />

    <!-- Invite Modal -->
    <TeamInviteMemberModal
      v-model:open="inviteModalOpen"
      @created="onInvitationCreated"
    />

    <!-- Edit Member Modal -->
    <TeamEditMemberModal
      v-model:open="editModalOpen"
      :member="editingMember"
      @updated="onMemberUpdated"
    />
  </div>
</template>
