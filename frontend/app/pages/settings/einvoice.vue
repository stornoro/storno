<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('einvoiceConfig.title') })
const store = useEInvoiceConfigStore()
const companyStore = useCompanyStore()
const toast = useToast()

const companyId = computed(() => companyStore.currentCompanyId)
const companyCountry = computed(() => companyStore.currentCompany?.country || '')

const loading = computed(() => store.loading)
const configs = computed(() => store.configs)
const providers = computed(() => store.providers)

const editing = ref(false)
const saving = ref(false)
const testing = ref(false)
const testResult = ref<{ success: boolean, error?: string } | null>(null)
const deleteConfirmOpen = ref(false)
const deleteProvider = ref<string | null>(null)

// Form state
const form = ref({
  provider: null as string | null,
  enabled: true,
  // XRechnung / Factur-X
  clientId: '',
  clientSecret: '',
  // SDI
  sdiMode: 'intermediary' as 'direct' | 'intermediary',
  certPassword: '',
  apiEndpoint: '',
  apiKey: '',
  // KSeF
  authToken: '',
  nip: '',
  // Factur-X
  siret: '',
})

const editingExisting = ref(false)

const providerFields: Record<string, { key: string, label: string, type: string }[]> = {
  xrechnung: [
    { key: 'clientId', label: 'einvoiceConfig.fields.clientId', type: 'text' },
    { key: 'clientSecret', label: 'einvoiceConfig.fields.clientSecret', type: 'password' },
  ],
  ksef: [
    { key: 'authToken', label: 'einvoiceConfig.fields.authToken', type: 'password' },
    { key: 'nip', label: 'einvoiceConfig.fields.nip', type: 'text' },
  ],
  facturx: [
    { key: 'clientId', label: 'einvoiceConfig.fields.clientId', type: 'text' },
    { key: 'clientSecret', label: 'einvoiceConfig.fields.clientSecret', type: 'password' },
    { key: 'siret', label: 'einvoiceConfig.fields.siret', type: 'text' },
  ],
}

const providerOptions = computed(() =>
  providers.value.map(p => ({
    label: p.label,
    value: p.value,
    suffix: p.country === companyCountry.value ? $t('einvoiceConfig.recommended') : undefined,
  })),
)

const isAnaf = computed(() => form.value.provider === 'anaf')

const currentFields = computed(() => {
  if (!form.value.provider || isAnaf.value) return []
  if (form.value.provider === 'sdi') return [] // Handled separately
  return providerFields[form.value.provider] || []
})

const canSave = computed(() => {
  if (!form.value.provider || isAnaf.value) return false
  if (form.value.provider === 'sdi') {
    if (form.value.sdiMode === 'direct') {
      return editingExisting.value || !!form.value.certPassword.trim()
    }
    return (editingExisting.value || !!form.value.apiKey.trim()) && !!form.value.apiEndpoint.trim()
  }
  const fields = providerFields[form.value.provider] || []
  return fields.every(f => {
    if (f.type === 'password' && editingExisting.value) return true // OK to leave empty
    return !!(form.value as any)[f.key]?.trim()
  })
})

function getProviderLabel(providerValue: string): string {
  return providers.value.find(p => p.value === providerValue)?.label || providerValue
}

function startAdd() {
  form.value = {
    provider: null, enabled: true,
    clientId: '', clientSecret: '',
    sdiMode: 'intermediary', certPassword: '', apiEndpoint: '', apiKey: '',
    authToken: '', nip: '', siret: '',
  }
  editingExisting.value = false
  testResult.value = null
  editing.value = true
}

function startEdit(cfg: any) {
  form.value = {
    provider: cfg.provider,
    enabled: cfg.enabled,
    clientId: '', clientSecret: '',
    sdiMode: cfg.maskedConfig?.apiEndpoint ? 'intermediary' : 'direct',
    certPassword: '', apiEndpoint: '', apiKey: '',
    authToken: '', nip: '', siret: '',
  }
  editingExisting.value = true
  testResult.value = null
  editing.value = true
}

function cancelEdit() {
  editing.value = false
  testResult.value = null
}

function buildConfigPayload(): Record<string, any> {
  const provider = form.value.provider!
  const config: Record<string, any> = {}

  if (provider === 'xrechnung') {
    if (form.value.clientId) config.clientId = form.value.clientId
    if (form.value.clientSecret) config.clientSecret = form.value.clientSecret
  }
  else if (provider === 'sdi') {
    if (form.value.sdiMode === 'direct') {
      if (form.value.certPassword) config.certPassword = form.value.certPassword
    }
    else {
      if (form.value.apiEndpoint) config.apiEndpoint = form.value.apiEndpoint
      if (form.value.apiKey) config.apiKey = form.value.apiKey
    }
  }
  else if (provider === 'ksef') {
    if (form.value.authToken) config.authToken = form.value.authToken
    if (form.value.nip) config.nip = form.value.nip
  }
  else if (provider === 'facturx') {
    if (form.value.clientId) config.clientId = form.value.clientId
    if (form.value.clientSecret) config.clientSecret = form.value.clientSecret
    if (form.value.siret) config.siret = form.value.siret
  }

  return config
}

async function onTest() {
  if (!companyId.value) return
  testing.value = true
  testResult.value = null

  const config = buildConfigPayload()
  testResult.value = await store.testConnection(companyId.value, {
    provider: form.value.provider,
    config,
  })
  testing.value = false
}

async function onSave() {
  if (!companyId.value) return
  saving.value = true

  const config = buildConfigPayload()
  const ok = await store.saveConfig(companyId.value, {
    provider: form.value.provider,
    enabled: form.value.enabled,
    config,
  })

  if (ok) {
    toast.add({ title: $t('einvoiceConfig.saveSuccess'), color: 'success' })
    editing.value = false
  }
  else {
    toast.add({ title: $t('einvoiceConfig.saveError'), color: 'error' })
  }
  saving.value = false
}

function confirmDelete(provider: string) {
  deleteProvider.value = provider
  deleteConfirmOpen.value = true
}

async function onDelete() {
  if (!companyId.value || !deleteProvider.value) return
  const ok = await store.deleteConfig(companyId.value, deleteProvider.value)
  if (ok) {
    toast.add({ title: $t('einvoiceConfig.deleteSuccess'), color: 'success' })
    deleteConfirmOpen.value = false
    deleteProvider.value = null
  }
}

function formatDate(dateStr: string | null): string {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleString('ro-RO')
}

onMounted(() => {
  if (companyId.value) {
    store.fetchConfigs(companyId.value)
  }
  store.fetchProviders()
})

watch(companyId, (id) => {
  if (id) store.fetchConfigs(id)
})
</script>

<template>
  <div>
    <UPageCard
      :title="$t('einvoiceConfig.title')"
      :description="$t('einvoiceConfig.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        v-if="!editing"
        :label="$t('einvoiceConfig.addProvider')"
        color="neutral"
        icon="i-lucide-plus"
        class="w-fit lg:ms-auto"
        @click="startAdd"
      />
    </UPageCard>

    <!-- Configured providers list (when not editing) -->
    <div v-if="!editing && configs.length > 0" class="space-y-3">
      <UPageCard v-for="cfg in configs" :key="cfg.provider" variant="subtle">
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <span class="font-medium">{{ getProviderLabel(cfg.provider) }}</span>
              <UBadge :color="cfg.enabled ? 'success' : 'neutral'" variant="subtle" size="sm">
                {{ cfg.enabled ? $t('einvoiceConfig.enabled') : $t('einvoiceConfig.disabled') }}
              </UBadge>
            </div>
            <div class="flex gap-2">
              <UButton
                :label="$t('common.edit')"
                variant="outline"
                size="sm"
                icon="i-lucide-pencil"
                @click="startEdit(cfg)"
              />
              <UButton
                :label="$t('common.delete')"
                variant="outline"
                size="sm"
                color="error"
                icon="i-lucide-trash-2"
                @click="confirmDelete(cfg.provider)"
              />
            </div>
          </div>

          <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
            <div v-for="(value, key) in cfg.maskedConfig" :key="key">
              <span class="text-(--ui-text-muted)">{{ key }}</span>
              <p class="font-mono">{{ value }}</p>
            </div>
          </div>

          <div class="text-xs text-(--ui-text-muted)">
            {{ $t('einvoiceConfig.lastUpdated') }}: {{ formatDate(cfg.updatedAt) }}
          </div>
        </div>
      </UPageCard>
    </div>

    <!-- Empty state -->
    <UPageCard v-else-if="!editing && configs.length === 0 && !loading" variant="subtle">
      <UEmpty
        icon="i-lucide-file-check"
        :title="$t('einvoiceConfig.noConfigs')"
        :description="$t('einvoiceConfig.noConfigsDescription')"
        class="py-12"
      >
        <template #actions>
          <UButton
            :label="$t('einvoiceConfig.addProvider')"
            icon="i-lucide-plus"
            @click="startAdd"
          />
        </template>
      </UEmpty>
    </UPageCard>

    <!-- Configuration form -->
    <UPageCard v-if="editing" variant="subtle">
      <div class="space-y-5">
        <!-- Provider select -->
        <UFormField :label="$t('einvoiceConfig.provider')">
          <USelectMenu
            v-model="form.provider"
            :items="providerOptions"
            value-key="value"
            :placeholder="$t('einvoiceConfig.selectProvider')"
            :disabled="editingExisting"
            class="w-full"
          />
        </UFormField>

        <!-- ANAF info card -->
        <div v-if="isAnaf" class="rounded-lg p-4 bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300">
          <div class="flex items-start gap-2">
            <UIcon name="i-lucide-info" class="size-5 shrink-0 mt-0.5" />
            <div>
              <p class="font-medium">{{ $t('einvoiceConfig.anafInfo') }}</p>
              <p class="text-sm mt-1">{{ $t('einvoiceConfig.anafDescription') }}</p>
            </div>
          </div>
        </div>

        <template v-if="form.provider && !isAnaf">
          <!-- SDI mode selector -->
          <UFormField v-if="form.provider === 'sdi'" :label="$t('einvoiceConfig.fields.sdiMode')">
            <USelectMenu
              v-model="form.sdiMode"
              :items="[
                { label: $t('einvoiceConfig.fields.sdiDirect'), value: 'direct' },
                { label: $t('einvoiceConfig.fields.sdiIntermediary'), value: 'intermediary' },
              ]"
              value-key="value"
              class="w-full"
            />
          </UFormField>

          <!-- SDI direct fields -->
          <template v-if="form.provider === 'sdi' && form.sdiMode === 'direct'">
            <UFormField :label="$t('einvoiceConfig.fields.certPassword')">
              <UInput
                v-model="form.certPassword"
                type="password"
                :placeholder="editingExisting ? $t('einvoiceConfig.credentialsUnchanged') : ''"
              />
            </UFormField>
          </template>

          <!-- SDI intermediary fields -->
          <template v-if="form.provider === 'sdi' && form.sdiMode === 'intermediary'">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <UFormField :label="$t('einvoiceConfig.fields.apiEndpoint')">
                <UInput
                  v-model="form.apiEndpoint"
                  type="url"
                  placeholder="https://api.provider.com"
                />
              </UFormField>
              <UFormField :label="$t('einvoiceConfig.fields.apiKey')">
                <UInput
                  v-model="form.apiKey"
                  type="password"
                  :placeholder="editingExisting ? $t('einvoiceConfig.credentialsUnchanged') : ''"
                />
              </UFormField>
            </div>
          </template>

          <!-- Generic provider fields (xrechnung, ksef, facturx) -->
          <div v-if="form.provider !== 'sdi'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <UFormField v-for="field in currentFields" :key="field.key" :label="$t(field.label)">
              <UInput
                v-model="(form as any)[field.key]"
                :type="field.type"
                :placeholder="field.type === 'password' && editingExisting ? $t('einvoiceConfig.credentialsUnchanged') : ''"
              />
            </UFormField>
          </div>

          <!-- Enabled toggle -->
          <div class="flex items-center gap-2">
            <USwitch v-model="form.enabled" size="sm" />
            <span class="text-sm">{{ $t('einvoiceConfig.enabledToggle') }}</span>
          </div>

          <!-- Test result banner -->
          <div v-if="testResult" :class="[
            'rounded-lg p-3 text-sm',
            testResult.success ? 'bg-success-50 text-success-700 dark:bg-success-950 dark:text-success-300' : 'bg-error-50 text-error-700 dark:bg-error-950 dark:text-error-300',
          ]">
            <div class="flex items-center gap-2">
              <UIcon :name="testResult.success ? 'i-lucide-check-circle' : 'i-lucide-x-circle'" class="size-4 shrink-0" />
              <span>{{ testResult.success ? $t('einvoiceConfig.testSuccess') : (testResult.error || $t('einvoiceConfig.testError')) }}</span>
            </div>
          </div>
        </template>

        <!-- Actions -->
        <div class="flex justify-end gap-2 pt-2 border-t border-default">
          <UButton variant="ghost" type="button" @click="cancelEdit">{{ $t('common.cancel') }}</UButton>
          <UButton
            v-if="!isAnaf"
            :label="$t('einvoiceConfig.testConnection')"
            variant="outline"
            :loading="testing"
            :disabled="!canSave"
            icon="i-lucide-plug"
            type="button"
            @click="onTest"
          />
          <UButton
            v-if="!isAnaf"
            :loading="saving"
            :disabled="!canSave"
            type="button"
            @click="onSave"
          >
            {{ $t('common.save') }}
          </UButton>
        </div>
      </div>
    </UPageCard>

    <!-- Delete confirmation modal -->
    <UModal v-model:open="deleteConfirmOpen">
      <template #header>
        <h3 class="text-lg font-semibold">{{ $t('einvoiceConfig.deleteConfirmTitle') }}</h3>
      </template>
      <template #body>
        <p>{{ $t('einvoiceConfig.deleteConfirm') }}</p>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="ghost" type="button" @click="deleteConfirmOpen = false">{{ $t('common.cancel') }}</UButton>
          <UButton color="error" type="button" @click="onDelete">{{ $t('common.delete') }}</UButton>
        </div>
      </template>
    </UModal>
  </div>
</template>
