<script setup lang="ts">
import type { ApiKey, ApiKeyScope } from '~/types'

definePageMeta({ middleware: ['auth', 'permissions'] })

const { t: $t } = useI18n()
useHead({ title: $t('apiKeys.title') })
const { can } = usePermissions()
const store = useApiKeyStore()
const toast = useToast()
const { copy } = useClipboard()

const loading = computed(() => store.loading)
const keys = computed(() => store.items)

const modalOpen = ref(false)
const saving = ref(false)
const editingKey = ref<ApiKey | null>(null)
const createdToken = ref<string | null>(null)

const rotatingKey = ref<ApiKey | null>(null)
const showMfaModal = ref(false)
const pendingMfaAction = ref<'create' | 'rotate' | null>(null)

const form = ref({
  name: '',
  scopes: [] as string[],
  expiryOption: '90days' as string,
  customExpiryDate: '',
})

// Group scopes by category
const scopesByCategory = computed(() => {
  const grouped: Record<string, ApiKeyScope[]> = {}
  for (const scope of store.availableScopes) {
    if (!grouped[scope.category]) {
      grouped[scope.category] = []
    }
    grouped[scope.category].push(scope)
  }
  return grouped
})

function getCategoryLabel(category: string): string {
  const key = `apiKeys.scopeCategories.${category}` as any
  const translated = $t(key)
  return translated !== key ? translated : category
}

function getExpiresAtFromOption(option: string, customDate: string): string | null {
  const now = new Date()
  switch (option) {
    case '30days':
      return new Date(now.getTime() + 30 * 24 * 60 * 60 * 1000).toISOString()
    case '90days':
      return new Date(now.getTime() + 90 * 24 * 60 * 60 * 1000).toISOString()
    case '1year':
      return new Date(now.getTime() + 365 * 24 * 60 * 60 * 1000).toISOString()
    case 'custom':
      return customDate ? new Date(customDate).toISOString() : null
    default:
      return null
  }
}

function getStatusColor(key: ApiKey): string {
  if (key.revokedAt) return 'error'
  if (key.expireAt && new Date(key.expireAt) < new Date()) return 'warning'
  return 'success'
}

function getStatusLabel(key: ApiKey): string {
  if (key.revokedAt) return $t('apiKeys.revoked')
  if (key.expireAt && new Date(key.expireAt) < new Date()) return $t('apiKeys.expired')
  return $t('apiKeys.active')
}

function formatDate(date: string | null): string {
  if (!date) return $t('apiKeys.never')
  return new Date(date).toLocaleDateString('ro-RO', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

const columns = [
  { accessorKey: 'name', header: $t('apiKeys.name') },
  { accessorKey: 'tokenPrefix', header: $t('apiKeys.tokenPrefix') },
  { id: 'scopes', header: $t('apiKeys.scopes') },
  { id: 'lastUsedAt', header: $t('apiKeys.lastUsedAt') },
  { id: 'expireAt', header: $t('apiKeys.expiresAt') },
  { id: 'status', header: $t('apiKeys.status') },
  { id: 'actions', header: $t('common.actions') },
]

const expiryOptions = [
  { label: $t('apiKeys.expiryOptions.30days'), value: '30days' },
  { label: $t('apiKeys.expiryOptions.90days'), value: '90days' },
  { label: $t('apiKeys.expiryOptions.1year'), value: '1year' },
  { label: $t('apiKeys.expiryOptions.custom'), value: 'custom' },
  { label: $t('apiKeys.expiryOptions.never'), value: 'never' },
]

const canSave = computed(() => {
  if (!form.value.name.trim()) return false
  if (form.value.scopes.length === 0) return false
  if (form.value.expiryOption === 'custom' && !form.value.customExpiryDate) return false
  return true
})

function openCreate() {
  editingKey.value = null
  createdToken.value = null
  form.value = { name: '', scopes: [], expiryOption: '90days', customExpiryDate: '' }
  modalOpen.value = true
}

function openEdit(key: ApiKey) {
  editingKey.value = key
  createdToken.value = null
  form.value = {
    name: key.name,
    scopes: [...key.scopes],
    expiryOption: 'never',
    customExpiryDate: '',
  }
  modalOpen.value = true
}

function toggleScope(scopeValue: string) {
  const idx = form.value.scopes.indexOf(scopeValue)
  if (idx >= 0) {
    form.value.scopes.splice(idx, 1)
  }
  else {
    form.value.scopes.push(scopeValue)
  }
}

const allScopesSelected = computed(() =>
  store.availableScopes.length > 0 && store.availableScopes.every(s => form.value.scopes.includes(s.value)),
)

function toggleAllScopes() {
  if (allScopesSelected.value) {
    form.value.scopes = []
  }
  else {
    form.value.scopes = store.availableScopes.map(s => s.value)
  }
}

function toggleCategory(category: string) {
  const categoryScopes = scopesByCategory.value[category] ?? []
  const allSelected = categoryScopes.every(s => form.value.scopes.includes(s.value))
  if (allSelected) {
    form.value.scopes = form.value.scopes.filter(s => !categoryScopes.some(cs => cs.value === s))
  }
  else {
    for (const s of categoryScopes) {
      if (!form.value.scopes.includes(s.value)) {
        form.value.scopes.push(s.value)
      }
    }
  }
}

function isCategoryFullySelected(category: string): boolean {
  const categoryScopes = scopesByCategory.value[category] ?? []
  return categoryScopes.length > 0 && categoryScopes.every(s => form.value.scopes.includes(s.value))
}

function isCategoryPartiallySelected(category: string): boolean {
  const categoryScopes = scopesByCategory.value[category] ?? []
  const selectedCount = categoryScopes.filter(s => form.value.scopes.includes(s.value)).length
  return selectedCount > 0 && selectedCount < categoryScopes.length
}

async function onSave() {
  if (editingKey.value) {
    // Update doesn't require MFA
    saving.value = true
    const ok = await store.updateApiKey(editingKey.value.id, {
      name: form.value.name,
      scopes: form.value.scopes,
    })
    if (ok) {
      toast.add({ title: $t('apiKeys.updateSuccess'), color: 'success' })
      modalOpen.value = false
    }
    else if (store.error) {
      toast.add({ title: store.error, color: 'error' })
    }
    saving.value = false
  }
  else {
    // Create requires step-up MFA — check first
    try {
      const { post: apiPost } = useApi()
      const challengeResult = await apiPost<{ mfa_required: boolean }>('/v1/mfa/challenge', {})
      if (challengeResult.mfa_required) {
        pendingMfaAction.value = 'create'
        showMfaModal.value = true
        return
      }
    } catch {
      // No MFA configured, proceed
    }
    await doCreate()
  }
}

async function doCreate(verificationToken?: string) {
  saving.value = true
  const expiresAt = getExpiresAtFromOption(form.value.expiryOption, form.value.customExpiryDate)
  const result = await store.createApiKey({
    name: form.value.name,
    scopes: form.value.scopes,
    expiresAt,
  }, verificationToken)
  if (result) {
    createdToken.value = result.token ?? null
    if (!createdToken.value) {
      toast.add({ title: $t('apiKeys.createSuccess'), color: 'success' })
      modalOpen.value = false
    }
  }
  else if (store.error) {
    if (store.error.includes('mfa_required')) {
      pendingMfaAction.value = 'create'
      showMfaModal.value = true
    } else {
      toast.add({ title: store.error, color: 'error' })
    }
  }
  saving.value = false
}

const revokeModalOpen = ref(false)
const revokingKey = ref<ApiKey | null>(null)
const revoking = ref(false)

function openRevoke(key: ApiKey) {
  revokingKey.value = key
  revokeModalOpen.value = true
}

async function onRevoke() {
  if (!revokingKey.value) return
  revoking.value = true
  const ok = await store.revokeApiKey(revokingKey.value.id)
  if (ok) {
    toast.add({ title: $t('apiKeys.revokeSuccess'), color: 'success' })
    revokeModalOpen.value = false
  }
  else if (store.error) {
    toast.add({ title: store.error, color: 'error' })
  }
  revoking.value = false
}

const rotateModalOpen = ref(false)
const rotateTargetKey = ref<ApiKey | null>(null)
const rotating = ref(false)

function openRotate(key: ApiKey) {
  rotateTargetKey.value = key
  rotateModalOpen.value = true
}

async function onRotate() {
  if (!rotateTargetKey.value) return
  // Check if step-up MFA is needed
  try {
    const { post: apiPost } = useApi()
    const challengeResult = await apiPost<{ mfa_required: boolean }>('/v1/mfa/challenge', {})
    if (challengeResult.mfa_required) {
      pendingMfaAction.value = 'rotate'
      showMfaModal.value = true
      return
    }
  } catch {
    // No MFA configured, proceed
  }
  await doRotate()
}

async function doRotate(verificationToken?: string) {
  if (!rotateTargetKey.value) return
  rotating.value = true
  rotatingKey.value = rotateTargetKey.value
  const result = await store.rotateApiKey(rotateTargetKey.value.id, verificationToken)
  rotatingKey.value = null
  rotating.value = false
  if (result) {
    createdToken.value = result.token ?? null
    rotateModalOpen.value = false
    if (createdToken.value) {
      modalOpen.value = true
    }
    toast.add({ title: $t('apiKeys.rotateSuccess'), color: 'success' })
  }
  else if (store.error) {
    if (store.error.includes('mfa_required')) {
      pendingMfaAction.value = 'rotate'
      showMfaModal.value = true
    } else {
      toast.add({ title: store.error, color: 'error' })
    }
  }
}

async function onMfaVerified(verificationToken: string) {
  if (pendingMfaAction.value === 'create') {
    await doCreate(verificationToken)
  } else if (pendingMfaAction.value === 'rotate') {
    await doRotate(verificationToken)
  }
  pendingMfaAction.value = null
}

function copyToken() {
  if (createdToken.value) {
    copy(createdToken.value)
    toast.add({ title: $t('apiKeys.tokenCopied'), color: 'success' })
  }
}

onMounted(() => {
  store.fetchApiKeys()
  store.fetchAvailableScopes()
})
</script>

<template>
  <div>
    <UPageCard
      :title="$t('apiKeys.title')"
      :description="$t('settings.apiKeysDescription')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        v-if="can(P.API_KEY_MANAGE)"
        :label="$t('apiKeys.createKey')"
        color="neutral"
        icon="i-lucide-plus"
        class="w-fit lg:ms-auto"
        @click="openCreate"
      />
    </UPageCard>

    <UPageCard
      variant="subtle"
      :ui="{ container: 'p-0 sm:p-0 gap-y-0', wrapper: 'items-stretch' }"
    >
      <UTable
        :data="keys"
        :columns="columns"
        :loading="loading"
        :ui="{
          base: 'table-fixed',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'px-4',
          td: 'px-4 border-b border-default',
        }"
      >
        <template #tokenPrefix-cell="{ row }">
          <span class="font-mono text-sm">{{ row.original.tokenPrefix }}...</span>
        </template>
        <template #scopes-cell="{ row }">
          <div class="flex flex-wrap gap-1">
            <UBadge
              v-for="scope in row.original.scopes.slice(0, 3)"
              :key="scope"
              variant="subtle"
              size="sm"
            >
              {{ scope }}
            </UBadge>
            <UBadge
              v-if="row.original.scopes.length > 3"
              variant="subtle"
              color="neutral"
              size="sm"
            >
              +{{ row.original.scopes.length - 3 }}
            </UBadge>
          </div>
        </template>
        <template #lastUsedAt-cell="{ row }">
          {{ formatDate(row.original.lastUsedAt) }}
        </template>
        <template #expireAt-cell="{ row }">
          {{ row.original.expireAt ? formatDate(row.original.expireAt) : $t('apiKeys.noExpiry') }}
        </template>
        <template #status-cell="{ row }">
          <UBadge :color="getStatusColor(row.original)" variant="subtle" size="sm">
            {{ getStatusLabel(row.original) }}
          </UBadge>
        </template>
        <template #actions-cell="{ row }">
          <div v-if="can(P.API_KEY_MANAGE)" class="flex gap-1">
            <UButton
              v-if="!row.original.revokedAt"
              icon="i-lucide-pencil"
              variant="ghost"
              size="xs"
              @click="openEdit(row.original)"
            />
            <UButton
              v-if="!row.original.revokedAt"
              icon="i-lucide-refresh-cw"
              variant="ghost"
              size="xs"
              :loading="rotatingKey?.id === row.original.id"
              @click="openRotate(row.original)"
            />
            <UButton
              v-if="!row.original.revokedAt"
              icon="i-lucide-trash-2"
              variant="ghost"
              size="xs"
              color="error"
              @click="openRevoke(row.original)"
            />
          </div>
        </template>
      </UTable>

      <UEmpty v-if="!loading && keys.length === 0" icon="i-lucide-key-round" :title="$t('apiKeys.noKeys')" class="py-12" />
    </UPageCard>

    <SharedConfirmModal
      v-model:open="revokeModalOpen"
      :title="$t('apiKeys.revokeTitle')"
      :description="$t('apiKeys.revokeDescription')"
      icon="i-lucide-trash-2"
      color="error"
      :confirm-label="$t('apiKeys.revokeKey')"
      :loading="revoking"
      @confirm="onRevoke"
    />

    <SharedConfirmModal
      v-model:open="rotateModalOpen"
      :title="$t('apiKeys.rotateTitle')"
      :description="$t('apiKeys.rotateDescription')"
      icon="i-lucide-refresh-cw"
      color="warning"
      :confirm-label="$t('apiKeys.rotateKey')"
      :loading="rotating"
      @confirm="onRotate"
    />

    <SharedStepUpMfaModal v-model:open="showMfaModal" @verified="onMfaVerified" />

    <!-- Create/Edit Slideover -->
    <USlideover v-model:open="modalOpen" :ui="{ content: 'sm:max-w-lg' }">
      <template #header>
        <h3 class="text-lg font-semibold">
          {{ createdToken ? $t('apiKeys.createSuccess') : editingKey ? $t('apiKeys.editKey') : $t('apiKeys.createKey') }}
        </h3>
      </template>
      <template #body>
        <!-- Token display after creation -->
        <div v-if="createdToken" class="space-y-4">
          <div class="rounded-lg border border-warning/50 bg-warning/5 p-4">
            <div class="flex items-center gap-2 mb-2">
              <UIcon name="i-lucide-alert-triangle" class="text-warning" />
              <span class="text-sm font-medium text-(--ui-text-highlighted)">{{ $t('apiKeys.tokenWarning') }}</span>
            </div>
            <div class="flex items-center gap-2">
              <code class="flex-1 rounded bg-elevated px-3 py-2 font-mono text-xs break-all select-all">{{ createdToken }}</code>
              <UButton icon="i-lucide-copy" variant="ghost" size="sm" @click="copyToken" />
            </div>
          </div>
          <UButton class="w-full" @click="modalOpen = false">{{ $t('common.close') }}</UButton>
        </div>

        <!-- Form -->
        <div v-else class="space-y-4">
          <UFormField :label="$t('apiKeys.name')">
            <UInput
              v-model="form.name"
              size="xl"
              class="w-full"
              :placeholder="$t('apiKeys.name')"
            />
          </UFormField>

          <!-- Expiry (only for create) -->
          <UFormField v-if="!editingKey" :label="$t('apiKeys.expiresAt')">
            <USelectMenu
              v-model="form.expiryOption"
              :items="expiryOptions"
              value-key="value"
              size="xl"
              class="w-full"
            />
          </UFormField>
          <div v-if="!editingKey && form.expiryOption === 'never'" class="rounded-lg border border-warning/50 bg-warning/5 p-3 flex items-center gap-2">
            <UIcon name="i-lucide-alert-triangle" class="text-warning shrink-0" />
            <span class="text-sm text-(--ui-text-muted)">{{ $t('apiKeys.noExpiryWarning') }}</span>
          </div>
          <UFormField v-if="!editingKey && form.expiryOption === 'custom'">
            <UInput
              v-model="form.customExpiryDate"
              type="date"
              size="xl"
              class="w-full"
            />
          </UFormField>

          <!-- Scopes -->
          <UFormField :label="$t('apiKeys.selectScopes')">
            <button
              type="button"
              class="flex items-center gap-2 text-sm font-medium text-(--ui-text-highlighted) hover:text-(--ui-text) cursor-pointer mb-2"
              @click="toggleAllScopes"
            >
              <div
                class="size-4 rounded border flex items-center justify-center transition-colors"
                :class="allScopesSelected
                  ? 'bg-primary border-primary text-white'
                  : form.scopes.length > 0
                    ? 'bg-primary/20 border-primary'
                    : 'border-default'"
              >
                <UIcon v-if="allScopesSelected" name="i-lucide-check" class="size-3" />
                <UIcon v-else-if="form.scopes.length > 0" name="i-lucide-minus" class="size-3" />
              </div>
              {{ $t('common.selectAll') }}
            </button>
            <USeparator class="mb-2" />
            <div class="space-y-3 max-h-[60vh] overflow-y-auto">
              <div v-for="(scopes, category) in scopesByCategory" :key="category" class="space-y-1">
                <button
                  type="button"
                  class="flex items-center gap-2 text-sm font-medium text-(--ui-text-highlighted) hover:text-(--ui-text) cursor-pointer"
                  @click="toggleCategory(category as string)"
                >
                  <div
                    class="size-4 rounded border flex items-center justify-center transition-colors"
                    :class="isCategoryFullySelected(category as string)
                      ? 'bg-primary border-primary text-white'
                      : isCategoryPartiallySelected(category as string)
                        ? 'bg-primary/20 border-primary'
                        : 'border-default'"
                  >
                    <UIcon v-if="isCategoryFullySelected(category as string)" name="i-lucide-check" class="size-3" />
                    <UIcon v-else-if="isCategoryPartiallySelected(category as string)" name="i-lucide-minus" class="size-3" />
                  </div>
                  {{ getCategoryLabel(category as string) }}
                </button>
                <div class="ml-6 space-y-0.5">
                  <button
                    v-for="scope in scopes"
                    :key="scope.value"
                    type="button"
                    class="flex items-center gap-2 text-sm text-(--ui-text-muted) hover:text-(--ui-text) w-full cursor-pointer py-0.5"
                    @click="toggleScope(scope.value)"
                  >
                    <div
                      class="size-4 rounded border flex items-center justify-center transition-colors"
                      :class="form.scopes.includes(scope.value)
                        ? 'bg-primary border-primary text-white'
                        : 'border-default'"
                    >
                      <UIcon v-if="form.scopes.includes(scope.value)" name="i-lucide-check" class="size-3" />
                    </div>
                    {{ scope.value }}
                  </button>
                </div>
              </div>
            </div>
          </UFormField>
        </div>
      </template>
      <template v-if="!createdToken" #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="ghost" @click="modalOpen = false">{{ $t('common.cancel') }}</UButton>
          <UButton :loading="saving" :disabled="!canSave" @click="onSave">{{ $t('common.save') }}</UButton>
        </div>
      </template>
    </USlideover>
  </div>
</template>
