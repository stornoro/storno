<script setup lang="ts">
import type { OAuth2Client, OAuth2ClientWithSecret, ApiKeyScope } from '~/types'

definePageMeta({ middleware: ['auth', 'permissions'] })

const { t: $t } = useI18n()
useHead({ title: $t('oauth2.title') })
const { can } = usePermissions()
const store = useOAuth2ClientStore()
const toast = useToast()
const { copy } = useClipboard()

const loading = computed(() => store.loading)
const clients = computed(() => store.items)

const modalOpen = ref(false)
const saving = ref(false)
const editingClient = ref<OAuth2Client | null>(null)
const createdSecret = ref<string | null>(null)
const createdClientId = ref<string | null>(null)

const form = ref({
  name: '',
  description: '',
  clientType: 'confidential' as 'confidential' | 'public',
  redirectUris: [''] as string[],
  scopes: [] as string[],
  websiteUrl: '',
  logoUrl: '',
})

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

function getStatusColor(client: OAuth2Client): string {
  if (client.revokedAt) return 'error'
  if (!client.isActive) return 'warning'
  return 'success'
}

function getStatusLabel(client: OAuth2Client): string {
  if (client.revokedAt) return $t('oauth2.revoked')
  if (!client.isActive) return $t('oauth2.inactive')
  return $t('oauth2.active')
}

const columns = [
  { accessorKey: 'name', header: $t('oauth2.name') },
  { accessorKey: 'clientId', header: $t('oauth2.clientId') },
  { accessorKey: 'clientType', header: $t('oauth2.clientType') },
  { id: 'scopes', header: $t('oauth2.scopes') },
  { id: 'status', header: $t('oauth2.status') },
  { id: 'actions', header: $t('common.actions') },
]

const clientTypeOptions = [
  { label: $t('oauth2.confidential'), value: 'confidential' },
  { label: $t('oauth2.public'), value: 'public' },
]

const canSave = computed(() => {
  if (!form.value.name.trim()) return false
  if (form.value.scopes.length === 0) return false
  if (!form.value.redirectUris.some(u => u.trim())) return false
  return true
})

function openCreate() {
  editingClient.value = null
  createdSecret.value = null
  createdClientId.value = null
  form.value = {
    name: '',
    description: '',
    clientType: 'confidential',
    redirectUris: [''],
    scopes: [],
    websiteUrl: '',
    logoUrl: '',
  }
  modalOpen.value = true
}

function openEdit(client: OAuth2Client) {
  editingClient.value = client
  createdSecret.value = null
  createdClientId.value = null
  form.value = {
    name: client.name,
    description: client.description ?? '',
    clientType: client.clientType,
    redirectUris: client.redirectUris.length > 0 ? [...client.redirectUris] : [''],
    scopes: [...client.scopes],
    websiteUrl: client.websiteUrl ?? '',
    logoUrl: client.logoUrl ?? '',
  }
  modalOpen.value = true
}

function addRedirectUri() {
  form.value.redirectUris.push('')
}

function removeRedirectUri(index: number) {
  if (form.value.redirectUris.length > 1) {
    form.value.redirectUris.splice(index, 1)
  }
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
  saving.value = true
  const redirectUris = form.value.redirectUris.filter(u => u.trim())

  if (editingClient.value) {
    const ok = await store.updateClient(editingClient.value.id, {
      name: form.value.name,
      description: form.value.description || null,
      redirectUris,
      scopes: form.value.scopes,
      websiteUrl: form.value.websiteUrl || null,
      logoUrl: form.value.logoUrl || null,
    })
    if (ok) {
      toast.add({ title: $t('oauth2.updateSuccess'), color: 'success' })
      modalOpen.value = false
    }
    else if (store.error) {
      toast.add({ title: store.error, color: 'error' })
    }
  }
  else {
    const result = await store.createClient({
      name: form.value.name,
      description: form.value.description || null,
      clientType: form.value.clientType,
      redirectUris,
      scopes: form.value.scopes,
      websiteUrl: form.value.websiteUrl || null,
      logoUrl: form.value.logoUrl || null,
    })
    if (result) {
      createdSecret.value = (result as OAuth2ClientWithSecret).clientSecret ?? null
      createdClientId.value = result.clientId
      if (!createdSecret.value) {
        toast.add({ title: $t('oauth2.createSuccess'), color: 'success' })
        modalOpen.value = false
      }
    }
    else if (store.error) {
      toast.add({ title: store.error, color: 'error' })
    }
  }
  saving.value = false
}

const revokeModalOpen = ref(false)
const revokingClient = ref<OAuth2Client | null>(null)
const revoking = ref(false)

function openRevoke(client: OAuth2Client) {
  revokingClient.value = client
  revokeModalOpen.value = true
}

async function onRevoke() {
  if (!revokingClient.value) return
  revoking.value = true
  const ok = await store.revokeClient(revokingClient.value.id)
  if (ok) {
    toast.add({ title: $t('oauth2.revokeSuccess'), color: 'success' })
    revokeModalOpen.value = false
  }
  else if (store.error) {
    toast.add({ title: store.error, color: 'error' })
  }
  revoking.value = false
}

const rotateModalOpen = ref(false)
const rotateTargetClient = ref<OAuth2Client | null>(null)
const rotating = ref(false)

function openRotate(client: OAuth2Client) {
  rotateTargetClient.value = client
  rotateModalOpen.value = true
}

async function onRotate() {
  if (!rotateTargetClient.value) return
  rotating.value = true
  const result = await store.rotateSecret(rotateTargetClient.value.id)
  rotating.value = false
  if (result) {
    createdSecret.value = (result as OAuth2ClientWithSecret).clientSecret ?? null
    createdClientId.value = null
    rotateModalOpen.value = false
    if (createdSecret.value) {
      editingClient.value = null
      modalOpen.value = true
    }
    toast.add({ title: $t('oauth2.rotateSuccess'), color: 'success' })
  }
  else if (store.error) {
    toast.add({ title: store.error, color: 'error' })
  }
}

function copySecret() {
  if (createdSecret.value) {
    copy(createdSecret.value)
    toast.add({ title: $t('oauth2.secretCopied'), color: 'success' })
  }
}

function copyClientId() {
  if (createdClientId.value) {
    copy(createdClientId.value)
    toast.add({ title: $t('oauth2.clientIdCopied'), color: 'success' })
  }
}

onMounted(() => {
  store.fetchClients()
  store.fetchAvailableScopes()
})
</script>

<template>
  <div>
    <UPageCard
      :title="$t('oauth2.title')"
      :description="$t('oauth2.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        v-if="can(P.OAUTH2_APP_MANAGE)"
        :label="$t('oauth2.createApp')"
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
        :data="clients"
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
        <template #clientId-cell="{ row }">
          <span class="font-mono text-xs">{{ row.original.clientId.substring(0, 20) }}...</span>
        </template>
        <template #clientType-cell="{ row }">
          <UBadge variant="subtle" size="sm" :color="row.original.clientType === 'confidential' ? 'primary' : 'neutral'">
            {{ row.original.clientType === 'confidential' ? $t('oauth2.confidential') : $t('oauth2.public') }}
          </UBadge>
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
        <template #status-cell="{ row }">
          <UBadge :color="getStatusColor(row.original)" variant="subtle" size="sm">
            {{ getStatusLabel(row.original) }}
          </UBadge>
        </template>
        <template #actions-cell="{ row }">
          <div v-if="can(P.OAUTH2_APP_MANAGE)" class="flex gap-1">
            <UButton
              v-if="!row.original.revokedAt"
              icon="i-lucide-pencil"
              variant="ghost"
              size="xs"
              @click="openEdit(row.original)"
            />
            <UButton
              v-if="!row.original.revokedAt && row.original.clientType === 'confidential'"
              icon="i-lucide-refresh-cw"
              variant="ghost"
              size="xs"
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

      <UEmpty v-if="!loading && clients.length === 0" icon="i-lucide-app-window" :title="$t('oauth2.noApps')" class="py-12" />
    </UPageCard>

    <SharedConfirmModal
      v-model:open="revokeModalOpen"
      :title="$t('oauth2.revokeTitle')"
      :description="$t('oauth2.revokeDescription')"
      icon="i-lucide-trash-2"
      color="error"
      :confirm-label="$t('common.revoke')"
      :loading="revoking"
      @confirm="onRevoke"
    />

    <SharedConfirmModal
      v-model:open="rotateModalOpen"
      :title="$t('oauth2.rotateTitle')"
      :description="$t('oauth2.rotateDescription')"
      icon="i-lucide-refresh-cw"
      color="warning"
      :confirm-label="$t('oauth2.rotateSecret')"
      :loading="rotating"
      @confirm="onRotate"
    />

    <!-- Create/Edit Slideover -->
    <USlideover v-model:open="modalOpen" :ui="{ content: 'sm:max-w-lg' }">
      <template #header>
        <h3 class="text-lg font-semibold">
          {{ createdSecret ? $t('oauth2.createSuccess') : editingClient ? $t('oauth2.editApp') : $t('oauth2.createApp') }}
        </h3>
      </template>
      <template #body>
        <!-- Secret display after creation -->
        <div v-if="createdSecret" class="space-y-4">
          <div v-if="createdClientId" class="rounded-lg border border-default bg-elevated/50 p-4">
            <div class="mb-2 text-sm font-medium text-(--ui-text-highlighted)">{{ $t('oauth2.clientId') }}</div>
            <div class="flex items-center gap-2">
              <code class="flex-1 rounded bg-elevated px-3 py-2 font-mono text-xs break-all select-all">{{ createdClientId }}</code>
              <UButton icon="i-lucide-copy" variant="ghost" size="sm" @click="copyClientId" />
            </div>
          </div>

          <div class="rounded-lg border border-warning/50 bg-warning/5 p-4">
            <div class="flex items-center gap-2 mb-2">
              <UIcon name="i-lucide-alert-triangle" class="text-warning" />
              <span class="text-sm font-medium text-(--ui-text-highlighted)">{{ $t('oauth2.secretWarning') }}</span>
            </div>
            <div class="flex items-center gap-2">
              <code class="flex-1 rounded bg-elevated px-3 py-2 font-mono text-xs break-all select-all">{{ createdSecret }}</code>
              <UButton icon="i-lucide-copy" variant="ghost" size="sm" @click="copySecret" />
            </div>
          </div>
          <UButton class="w-full" @click="modalOpen = false">{{ $t('common.close') }}</UButton>
        </div>

        <!-- Form -->
        <div v-else class="space-y-4">
          <UFormField :label="$t('oauth2.name')">
            <UInput
              v-model="form.name"
              size="xl"
              class="w-full"
              :placeholder="$t('oauth2.name')"
            />
          </UFormField>

          <UFormField :label="$t('oauth2.appDescription')">
            <UTextarea
              v-model="form.description"
              size="xl"
              class="w-full"
              :rows="2"
            />
          </UFormField>

          <!-- Client type (only for create) -->
          <UFormField v-if="!editingClient" :label="$t('oauth2.clientType')">
            <USelectMenu
              v-model="form.clientType"
              :items="clientTypeOptions"
              value-key="value"
              size="xl"
              class="w-full"
            />
          </UFormField>

          <UFormField :label="$t('oauth2.websiteUrl')">
            <UInput
              v-model="form.websiteUrl"
              size="xl"
              class="w-full"
              placeholder="https://example.com"
            />
          </UFormField>

          <!-- Redirect URIs -->
          <UFormField :label="$t('oauth2.redirectUris')">
            <div class="space-y-2">
              <div v-for="(uri, index) in form.redirectUris" :key="index" class="flex items-center gap-2">
                <UInput
                  v-model="form.redirectUris[index]"
                  size="xl"
                  class="flex-1"
                  placeholder="https://app.example.com/callback"
                />
                <UButton
                  v-if="form.redirectUris.length > 1"
                  icon="i-lucide-x"
                  variant="ghost"
                  size="xs"
                  color="error"
                  @click="removeRedirectUri(index)"
                />
              </div>
              <UButton
                icon="i-lucide-plus"
                variant="ghost"
                size="xs"
                :label="$t('oauth2.addRedirectUri')"
                @click="addRedirectUri"
              />
            </div>
          </UFormField>

          <!-- Scopes -->
          <UFormField :label="$t('oauth2.scopes')">
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
      <template v-if="!createdSecret" #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="ghost" @click="modalOpen = false">{{ $t('common.cancel') }}</UButton>
          <UButton :loading="saving" :disabled="!canSave" @click="onSave">{{ $t('common.save') }}</UButton>
        </div>
      </template>
    </USlideover>
  </div>
</template>
