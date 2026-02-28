<script setup lang="ts">
import { z } from 'zod'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('settings.profile.title') })
const authStore = useAuthStore()
const passkeyComposable = usePasskey()
const mfa = useMfa()
const toast = useToast()

const loading = ref(false)
const deleteModalOpen = ref(false)
const deletePassword = ref('')
const deleting = ref(false)
const registeringPasskey = ref(false)
const passkeys = ref<any[]>([])
const deletePasskeyModalOpen = ref(false)
const passkeyToDelete = ref<string | null>(null)
const passkeyNameModalOpen = ref(false)

const passkeySupported = computed(() => passkeyComposable.isSupported.value)

// MFA state
const mfaSetupModalOpen = ref(false)
const mfaBackupCodesModalOpen = ref(false)
const mfaBackupCodes = ref<string[]>([])
const mfaStatus = ref<{ totpEnabled: boolean; backupCodesRemaining: number; passkeysCount: number } | null>(null)
const mfaDisablePassword = ref('')
const mfaDisableModalOpen = ref(false)
const mfaDisabling = ref(false)
const mfaRegenerateModalOpen = ref(false)
const mfaRegeneratePassword = ref('')
const mfaRegenerating = ref(false)

const schema = z.object({
  firstName: z.string().min(2, $t('validation.nameMin')),
  lastName: z.string().min(2, $t('validation.nameMin')),
  email: z.string().email($t('validation.emailInvalid')),
  currentPassword: z.string().optional(),
  newPassword: z.string().min(8, $t('validation.passwordMin')).optional().or(z.literal('')),
})

const formState = reactive({
  firstName: authStore.user?.firstName || '',
  lastName: authStore.user?.lastName || '',
  email: authStore.user?.email || '',
  currentPassword: '',
  newPassword: '',
})

function formatDate(dateStr: string | null) {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleDateString('ro-RO', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

async function fetchPasskeys() {
  try {
    passkeys.value = await passkeyComposable.listPasskeys()
  }
  catch {
    passkeys.value = []
  }
}

function onRegisterPasskey() {
  passkeyNameModalOpen.value = true
}

async function confirmRegisterPasskey(name: string) {
  passkeyNameModalOpen.value = false
  registeringPasskey.value = true
  try {
    await passkeyComposable.register(name || undefined)
    toast.add({ title: $t('settings.passkeys.registerSuccess'), color: 'success' })
    await fetchPasskeys()
  }
  catch (e: any) {
    toast.add({ title: $t('settings.passkeys.registerError'), description: translateApiError(e.message), color: 'error' })
  }
  finally {
    registeringPasskey.value = false
  }
}

function onDeletePasskey(id: string) {
  passkeyToDelete.value = id
  deletePasskeyModalOpen.value = true
}

async function confirmDeletePasskey() {
  if (!passkeyToDelete.value) return
  try {
    await passkeyComposable.deletePasskey(passkeyToDelete.value)
    deletePasskeyModalOpen.value = false
    passkeyToDelete.value = null
    toast.add({ title: $t('settings.passkeys.deleteSuccess'), color: 'success' })
    await fetchPasskeys()
  }
  catch {
    toast.add({ title: $t('settings.passkeys.deleteError'), color: 'error' })
  }
}

async function onSubmit() {
  const { patch } = useApi()
  loading.value = true
  try {
    const payload: any = {
      firstName: formState.firstName,
      lastName: formState.lastName,
    }

    if (formState.currentPassword && formState.newPassword) {
      payload.currentPassword = formState.currentPassword
      payload.newPassword = formState.newPassword
    }

    await patch('/v1/me', payload)

    if (authStore.user) {
      authStore.user.firstName = formState.firstName
      authStore.user.lastName = formState.lastName
    }

    formState.currentPassword = ''
    formState.newPassword = ''

    toast.add({ title: $t('settings.profile.updateSuccess'), color: 'success' })
  }
  catch (error: any) {
    toast.add({ title: error?.data?.error ? translateApiError(error.data.error) : $t('settings.profile.updateError'), color: 'error' })
  }
  finally {
    loading.value = false
  }
}

async function onDeleteAccount() {
  if (!deletePassword.value) return
  deleting.value = true
  try {
    await authStore.deleteAccount(deletePassword.value)
    toast.add({ title: $t('settings.deleteAccount.success'), color: 'success' })
  }
  catch (error: any) {
    toast.add({ title: error?.data?.error ? translateApiError(error.data.error) : $t('settings.deleteAccount.error'), color: 'error' })
  }
  finally {
    deleting.value = false
    deletePassword.value = ''
    deleteModalOpen.value = false
  }
}

async function fetchMfaStatus() {
  try {
    mfaStatus.value = await mfa.getStatus()
  }
  catch {
    mfaStatus.value = null
  }
}

function onMfaEnabled(codes: string[]) {
  mfaBackupCodes.value = codes
  fetchMfaStatus()
  if (authStore.user) authStore.user.mfaEnabled = true
}

async function onMfaDisable() {
  if (!mfaDisablePassword.value) return
  mfaDisabling.value = true
  try {
    await mfa.disableTotp(mfaDisablePassword.value)
    mfaDisableModalOpen.value = false
    mfaDisablePassword.value = ''
    toast.add({ title: $t('settings.mfa.disableSuccess'), color: 'success' })
    fetchMfaStatus()
    if (authStore.user) authStore.user.mfaEnabled = false
  }
  catch (error: any) {
    toast.add({ title: error?.data?.error ? translateApiError(error.data.error) : $t('settings.mfa.disableError'), color: 'error' })
  }
  finally {
    mfaDisabling.value = false
  }
}

async function onRegenerateBackupCodes() {
  if (!mfaRegeneratePassword.value) return
  mfaRegenerating.value = true
  try {
    const result = await mfa.regenerateBackupCodes(mfaRegeneratePassword.value)
    mfaBackupCodes.value = result.backupCodes
    mfaRegenerateModalOpen.value = false
    mfaRegeneratePassword.value = ''
    mfaBackupCodesModalOpen.value = true
    toast.add({ title: $t('settings.mfa.regenerateSuccess'), color: 'success' })
    fetchMfaStatus()
  }
  catch (error: any) {
    toast.add({ title: error?.data?.error ? translateApiError(error.data.error) : $t('settings.mfa.regenerateError'), color: 'error' })
  }
  finally {
    mfaRegenerating.value = false
  }
}

onMounted(() => {
  if (authStore.user) {
    formState.firstName = authStore.user.firstName || ''
    formState.lastName = authStore.user.lastName || ''
    formState.email = authStore.user.email
  }
  if (passkeySupported.value) {
    fetchPasskeys()
  }
  fetchMfaStatus()
})
</script>

<template>
  <UForm
    id="profile-form"
    :schema="schema"
    :state="formState"
    @submit="onSubmit"
  >
    <UPageCard
      :title="$t('settings.profile.title')"
      :description="$t('settings.profileDescription')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        form="profile-form"
        :label="$t('common.save')"
        color="neutral"
        type="submit"
        :loading="loading"
        class="w-fit lg:ms-auto"
      />
    </UPageCard>

    <UPageCard variant="subtle">
      <UFormField
        name="firstName"
        :label="$t('settings.profile.firstName')"
        required
        class="flex max-sm:flex-col justify-between items-start gap-4"
      >
        <UInput v-model="formState.firstName" autocomplete="given-name" />
      </UFormField>
      <USeparator />
      <UFormField
        name="lastName"
        :label="$t('settings.profile.lastName')"
        required
        class="flex max-sm:flex-col justify-between items-start gap-4"
      >
        <UInput v-model="formState.lastName" autocomplete="family-name" />
      </UFormField>
      <USeparator />
      <UFormField
        name="email"
        :label="$t('settings.profile.email')"
        class="flex max-sm:flex-col justify-between items-start gap-4"
      >
        <UInput v-model="formState.email" type="email" disabled autocomplete="email" />
      </UFormField>
    </UPageCard>
  </UForm>

  <!-- Password Section -->
  <div>
    <UPageCard
      :title="$t('settings.profile.changePassword')"
      :description="$t('settings.profile.changePasswordDesc')"
      variant="naked"
      class="mb-4"
    />

    <UPageCard variant="subtle">
      <UFormField
        :label="$t('settings.profile.currentPassword')"
        class="flex max-sm:flex-col justify-between items-start gap-4"
      >
        <UInput
          v-model="formState.currentPassword"
          type="password"
          autocomplete="current-password"
        />
      </UFormField>
      <USeparator />
      <UFormField
        :label="$t('settings.profile.newPassword')"
        class="flex max-sm:flex-col justify-between items-start gap-4"
      >
        <UInput
          v-model="formState.newPassword"
          type="password"
          autocomplete="new-password"
        />
      </UFormField>
    </UPageCard>
  </div>

  <!-- Passkeys Section -->
  <div v-if="passkeySupported">
    <UPageCard
      :title="$t('settings.passkeys.title')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        :label="$t('settings.passkeys.add')"
        color="neutral"
        icon="i-lucide-plus"
        :loading="registeringPasskey"
        class="w-fit lg:ms-auto"
        @click="onRegisterPasskey"
      />
    </UPageCard>

    <UPageCard variant="subtle">
      <div v-if="passkeys.length" class="divide-y divide-default">
        <div
          v-for="pk in passkeys"
          :key="pk.id"
          class="flex items-center justify-between py-3 first:pt-0 last:pb-0"
        >
          <div>
            <div class="font-medium text-sm">{{ pk.name || $t('settings.passkeys.unnamed') }}</div>
            <div class="text-xs text-muted">
              {{ $t('settings.passkeys.registered') }}: {{ formatDate(pk.createdAt) }}
              <span v-if="pk.lastUsedAt"> &middot; {{ $t('settings.passkeys.lastUsed') }}: {{ formatDate(pk.lastUsedAt) }}</span>
            </div>
          </div>
          <UButton
            icon="i-lucide-trash-2"
            variant="ghost"
            color="error"
            size="xs"
            @click="onDeletePasskey(pk.id)"
          />
        </div>
      </div>
      <div v-else class="text-center py-6 text-muted text-sm">
        {{ $t('settings.passkeys.empty') }}
      </div>
    </UPageCard>
  </div>

  <!-- MFA Section -->
  <div>
    <UPageCard
      :title="$t('settings.mfa.title')"
      :description="$t('settings.mfa.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <div class="flex items-center gap-2 lg:ms-auto">
        <UBadge
          v-if="mfaStatus"
          :color="mfaStatus.totpEnabled ? 'success' : 'neutral'"
          variant="subtle"
        >
          {{ mfaStatus.totpEnabled ? $t('settings.mfa.enabled') : $t('settings.mfa.disabled') }}
        </UBadge>
        <UButton
          v-if="!mfaStatus?.totpEnabled"
          :label="$t('settings.mfa.enable')"
          color="neutral"
          icon="i-lucide-shield-check"
          @click="mfaSetupModalOpen = true"
        />
      </div>
    </UPageCard>

    <UPageCard v-if="mfaStatus?.totpEnabled" variant="subtle">
      <div class="space-y-4">
        <div class="flex items-center justify-between">
          <div>
            <div class="font-medium text-sm">{{ $t('settings.mfa.backupCodes') }}</div>
            <div class="text-xs text-muted">
              {{ $t('settings.mfa.backupCodesRemaining', { count: mfaStatus.backupCodesRemaining }) }}
            </div>
          </div>
          <UButton
            :label="$t('settings.mfa.regenerate')"
            variant="outline"
            color="neutral"
            size="sm"
            icon="i-lucide-refresh-cw"
            @click="mfaRegenerateModalOpen = true"
          />
        </div>
        <USeparator />
        <div class="flex items-center justify-between">
          <div>
            <div class="font-medium text-sm text-error">{{ $t('settings.mfa.disable') }}</div>
          </div>
          <UButton
            :label="$t('settings.mfa.disable')"
            variant="soft"
            color="error"
            size="sm"
            icon="i-lucide-shield-off"
            @click="mfaDisableModalOpen = true"
          />
        </div>
      </div>
    </UPageCard>
  </div>

  <!-- Danger Zone -->
  <div>
    <UPageCard
      :title="$t('settings.deleteAccount.title')"
      variant="naked"
      class="mb-4"
    />

    <UPageCard variant="subtle" class="border border-error/20">
      <div class="flex max-sm:flex-col items-start justify-between gap-4">
        <div>
          <div class="flex items-center gap-2 text-error mb-1">
            <UIcon name="i-lucide-alert-triangle" class="size-4" />
            <span class="font-medium text-sm">{{ $t('settings.deleteAccount.title') }}</span>
          </div>
          <p class="text-sm text-muted">{{ $t('settings.deleteAccount.description') }}</p>
        </div>
        <UButton
          color="error"
          variant="soft"
          icon="i-lucide-trash-2"
          class="shrink-0"
          @click="deleteModalOpen = true"
        >
          {{ $t('settings.deleteAccount.title') }}
        </UButton>
      </div>
    </UPageCard>
  </div>

  <!-- Delete account confirmation modal -->
  <UModal v-model:open="deleteModalOpen">
    <template #content>
      <div class="p-6 space-y-4">
        <div class="flex items-center gap-2 text-error">
          <UIcon name="i-lucide-alert-triangle" class="size-5" />
          <h3 class="text-lg font-semibold">{{ $t('settings.deleteAccount.title') }}</h3>
        </div>
        <p class="text-sm text-muted">{{ $t('settings.deleteAccount.description') }}</p>
        <UFormField :label="$t('settings.deleteAccount.passwordLabel')">
          <UInput
            v-model="deletePassword"
            type="password"
            :placeholder="$t('settings.deleteAccount.passwordPlaceholder')"
            autocomplete="current-password"
          />
        </UFormField>
        <div class="flex justify-end gap-2 pt-2">
          <UButton variant="ghost" @click="deleteModalOpen = false">
            {{ $t('common.cancel') }}
          </UButton>
          <UButton
            color="error"
            :loading="deleting"
            :disabled="!deletePassword"
            @click="onDeleteAccount"
          >
            {{ $t('settings.deleteAccount.confirm') }}
          </UButton>
        </div>
      </div>
    </template>
  </UModal>

  <!-- Delete Passkey Modal -->
  <SharedConfirmModal
    v-model:open="deletePasskeyModalOpen"
    :title="$t('settings.passkeys.deleteConfirm')"
    :description="$t('settings.passkeys.deleteConfirmDescription')"
    icon="i-lucide-trash-2"
    color="error"
    :confirm-label="$t('common.delete')"
    @confirm="confirmDeletePasskey"
  />

  <!-- Passkey Name Modal -->
  <SharedPromptModal
    v-model:open="passkeyNameModalOpen"
    :title="$t('settings.passkeys.namePromptTitle')"
    :description="$t('settings.passkeys.namePromptDescription')"
    :label="$t('common.name')"
    placeholder="ex: MacBook Pro, iPhone"
    @confirm="confirmRegisterPasskey"
  />

  <!-- MFA Setup Modal -->
  <SettingsMfaSetupModal
    v-model:open="mfaSetupModalOpen"
    @enabled="onMfaEnabled"
  />

  <!-- MFA Backup Codes Modal -->
  <SettingsBackupCodesModal
    v-model:open="mfaBackupCodesModalOpen"
    :codes="mfaBackupCodes"
  />

  <!-- MFA Disable Modal -->
  <UModal v-model:open="mfaDisableModalOpen">
    <template #content>
      <div class="p-6 space-y-4">
        <h3 class="text-lg font-semibold">{{ $t('settings.mfa.disable') }}</h3>
        <p class="text-sm text-muted">{{ $t('settings.mfa.disableConfirm') }}</p>
        <UFormField :label="$t('auth.password')">
          <UInput
            v-model="mfaDisablePassword"
            type="password"
            autocomplete="current-password"
          />
        </UFormField>
        <div class="flex justify-end gap-2 pt-2">
          <UButton variant="ghost" @click="mfaDisableModalOpen = false">
            {{ $t('common.cancel') }}
          </UButton>
          <UButton
            color="error"
            :loading="mfaDisabling"
            :disabled="!mfaDisablePassword"
            @click="onMfaDisable"
          >
            {{ $t('settings.mfa.disable') }}
          </UButton>
        </div>
      </div>
    </template>
  </UModal>

  <!-- MFA Regenerate Backup Codes Modal -->
  <UModal v-model:open="mfaRegenerateModalOpen">
    <template #content>
      <div class="p-6 space-y-4">
        <h3 class="text-lg font-semibold">{{ $t('settings.mfa.regenerate') }}</h3>
        <p class="text-sm text-muted">{{ $t('settings.mfa.regenerateConfirm') }}</p>
        <UFormField :label="$t('auth.password')">
          <UInput
            v-model="mfaRegeneratePassword"
            type="password"
            autocomplete="current-password"
          />
        </UFormField>
        <div class="flex justify-end gap-2 pt-2">
          <UButton variant="ghost" @click="mfaRegenerateModalOpen = false">
            {{ $t('common.cancel') }}
          </UButton>
          <UButton
            :loading="mfaRegenerating"
            :disabled="!mfaRegeneratePassword"
            @click="onRegenerateBackupCodes"
          >
            {{ $t('settings.mfa.regenerate') }}
          </UButton>
        </div>
      </div>
    </template>
  </UModal>
</template>
