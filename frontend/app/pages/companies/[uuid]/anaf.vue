<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4 sm:gap-6 p-4 sm:p-6 w-full lg:max-w-2xl mx-auto">
    <div>
      <UButton
        icon="i-lucide-arrow-left"
        variant="ghost"
        to="/companies"
        class="mb-4"
      >
        {{ $t('common.back') }}
      </UButton>
      <h1 class="text-2xl font-bold">{{ $t('companies.anafConnection') }}</h1>
    </div>

    <!-- Loading skeleton -->
    <div v-if="loadingStatus" class="space-y-6">
      <UCard variant="outline">
        <template #header>
          <div class="flex items-center justify-between">
            <USkeleton class="h-5 w-40" />
            <USkeleton class="h-5 w-20 rounded-full" />
          </div>
        </template>
        <div class="space-y-4">
          <USkeleton class="h-4 w-full" />
          <USkeleton class="h-4 w-3/4" />
        </div>
      </UCard>
    </div>

    <div v-else class="space-y-6">
      <!-- Aggregate Status Card -->
      <UCard variant="outline">
        <template #header>
          <div class="flex items-center justify-between">
            <h3 class="font-semibold">{{ $t('companies.connectionStatus') }}</h3>
            <UBadge
              :color="statusData?.connected ? 'success' : 'error'"
              variant="subtle"
            >
              {{ statusData?.connected ? $t('companies.connected') : $t('companies.disconnected') }}
            </UBadge>
          </div>
        </template>

        <div class="space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-sm text-(--ui-text-muted)">{{ $t('anaf.tokenCount') }}</span>
            <span class="text-sm font-medium">{{ statusData?.tokenCount ?? 0 }}</span>
          </div>
          <div v-if="statusData?.nearestExpiry" class="flex items-center justify-between">
            <span class="text-sm text-(--ui-text-muted)">{{ $t('anaf.nearestExpiry') }}</span>
            <span class="text-sm font-medium">{{ formatDateShort(statusData.nearestExpiry) }}</span>
          </div>
          <div v-if="statusData?.hasExpiredTokens" class="flex items-center gap-2 pt-1">
            <UIcon name="i-lucide-alert-triangle" class="h-4 w-4 text-(--ui-warning)" />
            <span class="text-xs text-(--ui-warning)">{{ $t('anaf.hasExpiredTokens') }}</span>
          </div>
        </div>
      </UCard>

      <!-- Token List -->
      <UCard v-if="tokens.length > 0" variant="outline">
        <template #header>
          <h3 class="font-semibold">{{ $t('anaf.myTokens') }}</h3>
        </template>

        <UTable :data="tokens" :columns="tokenColumns">
          <template #label-cell="{ row }">
            <span class="font-medium">{{ row.original.label || $t('anaf.untitledToken') }}</span>
          </template>

          <template #expiresAt-cell="{ row }">
            <div class="flex items-center gap-2">
              <UBadge
                :color="row.original.isExpired ? 'error' : 'success'"
                variant="subtle"
                size="sm"
              >
                {{ row.original.isExpired ? $t('common.invalid') : $t('common.valid') }}
              </UBadge>
              <span v-if="row.original.expiresAt" class="text-sm text-(--ui-text-muted)">
                {{ formatDateShort(row.original.expiresAt) }}
              </span>
            </div>
          </template>

          <template #validatedCifs-cell="{ row }">
            <div class="flex flex-wrap gap-1">
              <UBadge
                v-for="cif in row.original.validatedCifs"
                :key="cif"
                variant="subtle"
                color="neutral"
                size="sm"
              >
                {{ cif }}
              </UBadge>
              <span v-if="!row.original.validatedCifs?.length" class="text-sm text-(--ui-text-muted)">-</span>
            </div>
          </template>

          <template #actions-cell="{ row }">
            <div class="flex items-center gap-1">
              <UButton
                icon="i-lucide-shield-check"
                variant="ghost"
                size="xs"
                :loading="validatingCifTokenId === row.original.id"
                @click="openValidateCif(row.original)"
              />
              <UButton
                icon="i-lucide-trash-2"
                variant="ghost"
                color="error"
                size="xs"
                @click="openDeleteToken(row.original)"
              />
            </div>
          </template>
        </UTable>
      </UCard>

      <!-- Add Token -->
      <UCard variant="outline">
        <template #header>
          <h3 class="font-semibold">{{ $t('anaf.addToken') }}</h3>
        </template>

        <UTabs :items="tabs" class="w-full">
          <template #accountant>
            <div class="space-y-4 pt-4">
              <p class="text-sm text-(--ui-text-muted)">
                {{ $t('anaf.accountantDescription') }}
              </p>

              <UFormField :label="$t('anaf.labelFieldName')">
                <UInput
                  v-model="tokenLabel"
                  :placeholder="$t('anaf.labelPlaceholder')"
                  class="w-full"
                />
              </UFormField>

              <UFormField :label="$t('anaf.tokenLabel')" :error="tokenError">
                <UTextarea
                  v-model="manualToken"
                  :placeholder="$t('anaf.tokenPlaceholder')"
                  :rows="4"
                  class="w-full font-mono text-xs"
                />
              </UFormField>

              <UButton
                icon="i-lucide-check-circle"
                :loading="savingToken"
                :disabled="!manualToken.trim() || !!tokenError"
                @click="saveManualToken"
              >
                {{ savingToken ? $t('anaf.validatingToken') : $t('anaf.saveToken') }}
              </UButton>
            </div>
          </template>

          <template #device>
            <div class="space-y-4 pt-4">
              <p class="text-sm text-(--ui-text-muted)">
                {{ $t('anaf.deviceDescription') }}
              </p>

              <div class="bg-(--ui-bg-elevated) rounded-lg p-4 space-y-2">
                <p class="text-sm font-medium">{{ $t('anaf.deviceSteps') }}</p>
                <ol class="text-sm text-(--ui-text-muted) list-decimal list-inside space-y-1">
                  <li>{{ $t('companies.anafStep1') }}</li>
                  <li>{{ $t('companies.anafStep2') }}</li>
                  <li>{{ $t('companies.anafStep3') }}</li>
                  <li>{{ $t('companies.anafStep4') }}</li>
                </ol>
              </div>

              <div class="flex items-center gap-3">
                <UButton
                  icon="i-lucide-external-link"
                  :loading="waitingForAuth"
                  @click="connectWithDevice"
                >
                  {{ $t('anaf.connectWithDevice') }}
                </UButton>

                <UBadge v-if="waitingForAuth" color="warning" variant="subtle">
                  <UIcon name="i-lucide-loader-2" class="animate-spin h-3 w-3 mr-1" />
                  {{ $t('anaf.waitingForAuth') }}
                </UBadge>
              </div>
            </div>
          </template>

          <template #link>
            <div class="space-y-4 pt-4">
              <p class="text-sm text-(--ui-text-muted)">
                {{ $t('anaf.linkDescription') }}
              </p>

              <UButton
                v-if="!linkUrl"
                icon="i-lucide-link"
                :loading="generatingLink"
                @click="generateLink"
              >
                {{ $t('anaf.generateLink') }}
              </UButton>

              <div v-if="linkUrl" class="space-y-3">
                <div class="flex items-center gap-2">
                  <UInput
                    :model-value="linkUrl"
                    readonly
                    class="flex-1 font-mono text-xs"
                  />
                  <UButton
                    icon="i-lucide-copy"
                    variant="outline"
                    @click="copyLinkUrl"
                  />
                </div>

                <p class="text-xs text-(--ui-text-muted)">{{ $t('anaf.linkExpires') }}</p>

                <UBadge color="warning" variant="subtle">
                  <UIcon name="i-lucide-loader-2" class="animate-spin h-3 w-3 mr-1" />
                  {{ $t('anaf.linkWaiting') }}
                </UBadge>
              </div>
            </div>
          </template>
        </UTabs>
      </UCard>
    </div>

    <!-- Delete Token Modal -->
    <SharedConfirmModal
      v-model:open="deleteTokenModalOpen"
      :title="$t('anaf.confirmDeleteToken')"
      :description="$t('anaf.deleteTokenConfirmDescription')"
      icon="i-lucide-trash-2"
      color="error"
      :confirm-label="$t('common.delete')"
      @confirm="confirmDeleteToken"
    />

    <!-- Validate CIF Modal -->
    <UModal v-model:open="cifPromptModalOpen">
      <template #header>
        <h3 class="font-semibold">{{ $t('anaf.cifValidateTitle') }}</h3>
      </template>
      <template #body>
        <div class="space-y-4">
          <p class="text-sm text-(--ui-text-muted)">{{ $t('anaf.cifValidateDescription') }}</p>
          <UFormField label="CIF">
            <USelectMenu
              v-model="selectedCifForValidation"
              :items="companyOptions"
              value-key="value"
              class="w-full"
              :placeholder="$t('anaf.selectCompany')"
            />
          </UFormField>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="ghost" @click="cifPromptModalOpen = false">
            {{ $t('common.cancel') }}
          </UButton>
          <UButton :loading="!!validatingCifTokenId" :disabled="!selectedCifForValidation" @click="confirmValidateCif">
            {{ $t('common.confirm') }}
          </UButton>
        </div>
      </template>
    </UModal>

    <!-- Upgrade modal for plan limit -->
    <SharedUpgradeModal
      v-model:open="showUpgrade"
      :feature="$t('plan.maxCompanies').toLowerCase()"
      :current-limit="maxCompanies"
    />
      </div>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
import type { AnafToken, AnafStatus } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const route = useRoute()
const companyStore = useCompanyStore()
const authStore = useAuthStore()
const config = useRuntimeConfig()
const toast = useToast()

const uuid = computed(() => route.params.uuid as string)

const loadingStatus = ref(true)
const savingToken = ref(false)
const waitingForAuth = ref(false)
const manualToken = ref('')
const tokenLabel = ref('')
const validatingCifTokenId = ref<string | null>(null)
const deleteTokenModalOpen = ref(false)
const tokenToDelete = ref<AnafToken | null>(null)
const cifPromptModalOpen = ref(false)
const cifTokenToValidate = ref<AnafToken | null>(null)
const selectedCifForValidation = ref<string | null>(null)
const showUpgrade = ref(false)
const linkToken = ref<string | null>(null)
const linkUrl = ref<string | null>(null)
const generatingLink = ref(false)

const maxCompanies = computed(() => {
  const max = authStore.plan?.features?.maxCompanies
  if (!max || max >= 999999) return undefined
  return max
})

const companyOptions = computed(() => {
  const alreadyValidated = cifTokenToValidate.value?.validatedCifs ?? []
  return companyStore.companies
    .filter(c => !alreadyValidated.includes(c.cif))
    .map(c => ({
      label: `${c.cif} - ${c.name}`,
      value: String(c.cif),
    }))
})

const statusData = ref<AnafStatus | null>(null)
const tokens = ref<AnafToken[]>([])

const tabs = [
  { label: $t('anaf.tabAccountant'), slot: 'accountant' },
  { label: $t('anaf.tabDevice'), slot: 'device' },
  { label: $t('anaf.tabLink'), slot: 'link' },
]

const tokenColumns = [
  { accessorKey: 'label', header: $t('common.name') },
  { accessorKey: 'expiresAt', header: $t('common.status') },
  { accessorKey: 'validatedCifs', header: 'CIF-uri' },
  { accessorKey: 'actions', header: $t('common.actions') },
]

// ── Validation ─────────────────────────────────────────────────
const tokenError = computed(() => {
  if (!manualToken.value) return ''
  if (manualToken.value.trim().length < 10) return $t('anaf.tokenMinLength')
  return ''
})

// ── Date helpers ───────────────────────────────────────────────
function formatDateShort(date: string) {
  return new Date(date).toLocaleDateString('ro-RO', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

// ── API calls ──────────────────────────────────────────────────
async function fetchData() {
  try {
    const { get } = useApi()
    const [status, tokenList, activeLinks] = await Promise.all([
      get<AnafStatus>('/v1/anaf/status'),
      get<{ data: AnafToken[] }>('/v1/anaf/tokens'),
      get<{ data: Array<{ linkToken: string; linkUrl: string; companyId: string | null; expiresAt: string }> }>('/v1/anaf/token-links'),
    ])
    statusData.value = status
    tokens.value = tokenList.data

    // Pre-populate link if one already exists for this company
    const existing = activeLinks.data.find(l => l.companyId === uuid.value) ?? activeLinks.data[0]
    if (existing && !linkUrl.value) {
      linkToken.value = existing.linkToken
      linkUrl.value = existing.linkUrl
    }
  }
  catch {
    // Not connected
  }
  finally {
    loadingStatus.value = false
  }
}

async function saveManualToken() {
  if (tokenError.value) return

  savingToken.value = true
  try {
    const { post } = useApi()
    await post('/v1/anaf/tokens', {
      token: manualToken.value.trim(),
      label: tokenLabel.value.trim() || null,
      companyId: uuid.value,
    })

    toast.add({
      title: $t('anaf.tokenSaved'),
      color: 'success',
    })

    manualToken.value = ''
    tokenLabel.value = ''
    await fetchData()
  }
  catch (err: any) {
    const errorMsg = err?.data?.error || $t('anaf.tokenSaveError')
    toast.add({
      title: errorMsg,
      color: 'error',
    })
  }
  finally {
    savingToken.value = false
  }
}

function openDeleteToken(token: AnafToken) {
  tokenToDelete.value = token
  deleteTokenModalOpen.value = true
}

async function confirmDeleteToken() {
  if (!tokenToDelete.value) return
  try {
    const { del } = useApi()
    await del(`/v1/anaf/tokens/${tokenToDelete.value.id}`)
    deleteTokenModalOpen.value = false
    tokenToDelete.value = null
    toast.add({ title: $t('anaf.tokenDeleted'), color: 'success' })
    await fetchData()
  }
  catch {
    toast.add({ title: $t('anaf.tokenDeleteError'), color: 'error' })
  }
}

function openValidateCif(token: AnafToken) {
  cifTokenToValidate.value = token
  selectedCifForValidation.value = null
  cifPromptModalOpen.value = true
}

async function confirmValidateCif() {
  if (!cifTokenToValidate.value || !selectedCifForValidation.value) return

  const cif = parseInt(selectedCifForValidation.value, 10)
  if (isNaN(cif)) return

  validatingCifTokenId.value = cifTokenToValidate.value.id
  try {
    const { post } = useApi()
    await post(`/v1/anaf/tokens/${cifTokenToValidate.value.id}/validate-cif`, { cif })
    cifPromptModalOpen.value = false
    cifTokenToValidate.value = null
    toast.add({ title: $t('anaf.cifValidated', { cif }), color: 'success' })
    await fetchData()
  }
  catch (err: any) {
    if (err?.data?.code === 'PLAN_LIMIT') {
      cifPromptModalOpen.value = false
      showUpgrade.value = true
    }
    else {
      toast.add({
        title: err?.data?.error || $t('anaf.cifValidationFailed'),
        color: 'error',
      })
    }
  }
  finally {
    validatingCifTokenId.value = null
  }
}

function connectWithDevice() {
  waitingForAuth.value = true

  const apiBase = config.public.apiBase as string
  const url = `${apiBase}/connect/anaf`
  const popup = window.open(url, '_blank', 'width=600,height=800')

  function onMessage(event: MessageEvent) {
    if (event.data?.type === 'anaf-token-saved') {
      waitingForAuth.value = false
      window.removeEventListener('message', onMessage)

      toast.add({
        title: $t('anaf.tokenSaved'),
        color: 'success',
      })

      fetchData()
    }
  }

  window.addEventListener('message', onMessage)

  const pollClosed = setInterval(() => {
    if (popup?.closed) {
      clearInterval(pollClosed)
      setTimeout(() => {
        if (waitingForAuth.value) {
          waitingForAuth.value = false
          window.removeEventListener('message', onMessage)
        }
      }, 500)
    }
  }, 1000)
}

// ── Link flow ──────────────────────────────────────────────────
async function generateLink() {
  generatingLink.value = true
  try {
    const { post } = useApi()
    const result = await post<{ linkToken: string; linkUrl: string; expiresAt: string }>('/v1/anaf/token-links', {
      companyId: uuid.value,
    })
    linkToken.value = result.linkToken
    linkUrl.value = result.linkUrl
  }
  catch (err: any) {
    toast.add({ title: err?.data?.error || $t('anaf.linkCreateError'), color: 'error' })
  }
  finally {
    generatingLink.value = false
  }
}

function copyLinkUrl() {
  if (!linkUrl.value) return
  navigator.clipboard.writeText(linkUrl.value)
  toast.add({ title: $t('anaf.linkCopied'), color: 'success' })
}

// WebSocket listener for link completion
const { subscribe: centrifugoSubscribe, unsubscribe: centrifugoUnsubscribe } = useCentrifugo()

watch(linkToken, (token) => {
  if (!token) return
  const userId = authStore.user?.id
  if (!userId) return

  const channel = `user:${userId}`
  centrifugoSubscribe(channel, (data: any) => {
    if (data.type === 'anaf.link_completed' && data.linkToken === token) {
      linkToken.value = null
      linkUrl.value = null
      toast.add({ title: $t('anaf.linkCompleted'), color: 'success' })
      fetchData()
    }
  })
})

onUnmounted(() => {
  const userId = authStore.user?.id
  if (userId) {
    centrifugoUnsubscribe(`user:${userId}`)
  }
})

// ── Init ───────────────────────────────────────────────────────
onMounted(async () => {
  if (!companyStore.companies.length) {
    await companyStore.fetchCompanies()
  }
  await fetchData()
})
</script>
