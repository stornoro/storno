<script setup lang="ts">
import type { StorageProvider } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('storageConfig.title') })
const store = useStorageConfigStore()
const toast = useToast()

const loading = computed(() => store.loading)
const config = computed(() => store.config)
const providers = computed(() => store.providers)

const editing = ref(false)
const saving = ref(false)
const testing = ref(false)
const testResult = ref<{ success: boolean, error?: string } | null>(null)
const deleteConfirmOpen = ref(false)

const form = ref({
  provider: null as string | null,
  accessKeyId: '',
  secretAccessKey: '',
  accountId: '',
  bucket: '',
  region: '',
  endpoint: '',
  prefix: 'documents',
  forcePathStyle: false,
  isActive: true,
})

const providerOptions = computed(() =>
  providers.value.map(p => ({ label: p.name, value: p.value })),
)

const selectedProvider = computed<StorageProvider | null>(() => {
  if (!form.value.provider) return null
  return providers.value.find(p => p.value === form.value.provider) ?? null
})

const providerFields = computed(() => {
  if (!selectedProvider.value) return []
  return selectedProvider.value.fields.filter(f => f.key !== 'bucket' && f.key !== 'region' && f.key !== 'endpoint')
})

const showRegion = computed(() => selectedProvider.value?.supportsRegion ?? false)
const showEndpoint = computed(() => selectedProvider.value?.supportsEndpoint ?? false)
const needsAccountId = computed(() =>
  selectedProvider.value?.fields.some(f => f.key === 'accountId') ?? false,
)

const canSave = computed(() => {
  if (!form.value.provider) return false
  if (!form.value.bucket.trim()) return false
  if (!config.value && (!form.value.accessKeyId.trim() || !form.value.secretAccessKey.trim())) return false
  if (needsAccountId.value && !form.value.accountId.trim()) return false
  if (showRegion.value && !form.value.region.trim() && form.value.provider !== 'minio') return false
  if (showEndpoint.value && !form.value.endpoint.trim()) return false
  return true
})

const providerName = computed(() => {
  if (!config.value) return ''
  const p = providers.value.find(p => p.value === config.value!.provider)
  return p?.name ?? config.value.provider
})

function startEdit() {
  if (config.value) {
    form.value = {
      provider: config.value.provider,
      accessKeyId: '',
      secretAccessKey: '',
      accountId: '',
      bucket: config.value.bucket,
      region: config.value.region ?? '',
      endpoint: config.value.endpoint ?? '',
      prefix: config.value.prefix ?? 'documents',
      forcePathStyle: config.value.forcePathStyle,
      isActive: config.value.isActive,
    }
  }
  else {
    form.value = {
      provider: null,
      accessKeyId: '',
      secretAccessKey: '',
      accountId: '',
      bucket: '',
      region: '',
      endpoint: '',
      prefix: 'documents',
      forcePathStyle: false,
      isActive: true,
    }
  }
  testResult.value = null
  editing.value = true
}

function cancelEdit() {
  editing.value = false
  testResult.value = null
}

async function onTest() {
  testing.value = true
  testResult.value = null

  const payload: Record<string, any> = {
    provider: form.value.provider,
    bucket: form.value.bucket,
    region: form.value.region || undefined,
    endpoint: form.value.endpoint || undefined,
    forcePathStyle: form.value.forcePathStyle,
  }
  if (form.value.accessKeyId) payload.accessKeyId = form.value.accessKeyId
  if (form.value.secretAccessKey) payload.secretAccessKey = form.value.secretAccessKey
  if (form.value.accountId) payload.accountId = form.value.accountId

  testResult.value = await store.testConnection(payload)
  testing.value = false
}

async function onSave() {
  saving.value = true

  const payload: Record<string, any> = {
    provider: form.value.provider,
    bucket: form.value.bucket,
    region: form.value.region || null,
    endpoint: form.value.endpoint || null,
    prefix: form.value.prefix || 'documents',
    forcePathStyle: form.value.forcePathStyle,
    isActive: form.value.isActive,
  }
  if (form.value.accessKeyId) payload.accessKeyId = form.value.accessKeyId
  if (form.value.secretAccessKey) payload.secretAccessKey = form.value.secretAccessKey
  if (form.value.accountId) payload.accountId = form.value.accountId

  const ok = await store.saveConfig(payload)
  if (ok) {
    toast.add({ title: $t('storageConfig.saveSuccess'), color: 'success' })
    editing.value = false
  }
  else {
    toast.add({ title: $t('storageConfig.saveError'), color: 'error' })
  }
  saving.value = false
}

async function onDelete() {
  const ok = await store.deleteConfig()
  if (ok) {
    toast.add({ title: $t('storageConfig.deleteSuccess'), color: 'success' })
    deleteConfirmOpen.value = false
  }
}

function formatDate(dateStr: string | null): string {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleString('ro-RO')
}

watch(() => form.value.provider, (newProvider) => {
  if (newProvider && !config.value) {
    const provider = providers.value.find(p => p.value === newProvider)
    if (provider) {
      form.value.forcePathStyle = provider.defaultForcePathStyle
    }
  }
})

onMounted(() => {
  store.fetchConfig()
  store.fetchProviders()
})
</script>

<template>
  <div>
    <UPageCard
      :title="$t('storageConfig.title')"
      :description="$t('storageConfig.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        v-if="!editing"
        :label="config ? $t('common.edit') : $t('storageConfig.configure')"
        color="neutral"
        :icon="config ? 'i-lucide-pencil' : 'i-lucide-plus'"
        class="w-fit lg:ms-auto"
        @click="startEdit"
      />
    </UPageCard>

    <!-- Status card (when config exists and not editing) -->
    <UPageCard v-if="config && !editing" variant="subtle">
      <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <span class="text-sm text-(--ui-text-muted)">{{ $t('storageConfig.provider') }}</span>
            <p class="font-medium">{{ providerName }}</p>
          </div>
          <div>
            <span class="text-sm text-(--ui-text-muted)">{{ $t('storageConfig.bucket') }}</span>
            <p class="font-mono font-medium">{{ config.bucket }}</p>
          </div>
          <div v-if="config.region">
            <span class="text-sm text-(--ui-text-muted)">{{ $t('storageConfig.region') }}</span>
            <p class="font-mono">{{ config.region }}</p>
          </div>
          <div v-if="config.endpoint">
            <span class="text-sm text-(--ui-text-muted)">{{ $t('storageConfig.endpoint') }}</span>
            <p class="font-mono text-sm break-all">{{ config.endpoint }}</p>
          </div>
          <div>
            <span class="text-sm text-(--ui-text-muted)">{{ $t('storageConfig.prefix') }}</span>
            <p class="font-mono">{{ config.prefix || '-' }}</p>
          </div>
          <div>
            <span class="text-sm text-(--ui-text-muted)">{{ $t('storageConfig.lastTested') }}</span>
            <p>{{ formatDate(config.lastTestedAt) }}</p>
          </div>
        </div>

        <div class="flex items-center justify-between pt-2 border-t border-default">
          <div class="flex items-center gap-2">
            <UBadge :color="config.isActive ? 'success' : 'neutral'" variant="subtle" size="sm">
              {{ config.isActive ? $t('storageConfig.active') : $t('storageConfig.inactive') }}
            </UBadge>
          </div>
          <div class="flex gap-2">
            <UButton
              :label="$t('common.edit')"
              variant="outline"
              size="sm"
              icon="i-lucide-pencil"
              @click="startEdit"
            />
            <UButton
              :label="$t('common.delete')"
              variant="outline"
              size="sm"
              color="error"
              icon="i-lucide-trash-2"
              @click="deleteConfirmOpen = true"
            />
          </div>
        </div>
      </div>
    </UPageCard>

    <!-- Empty state -->
    <UPageCard v-else-if="!config && !editing && !loading" variant="subtle">
      <UEmpty
        icon="i-lucide-hard-drive"
        :title="$t('storageConfig.noConfig')"
        :description="$t('storageConfig.noConfigDescription')"
        class="py-12"
      >
        <template #actions>
          <UButton
            :label="$t('storageConfig.configure')"
            icon="i-lucide-plus"
            @click="startEdit"
          />
        </template>
      </UEmpty>
    </UPageCard>

    <!-- Configuration form -->
    <UPageCard v-if="editing" variant="subtle">
      <div class="space-y-5">
        <!-- Provider -->
        <UFormField :label="$t('storageConfig.provider')">
          <USelectMenu
            v-model="form.provider"
            :items="providerOptions"
            value-key="value"
            :placeholder="$t('storageConfig.selectProvider')"
            class="w-full"
          />
        </UFormField>

        <template v-if="form.provider">
          <!-- Credentials -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <UFormField :label="$t('storageConfig.accessKeyId')">
              <UInput
                v-model="form.accessKeyId"
                :placeholder="config ? $t('storageConfig.credentialsUnchanged') : ''"
              />
            </UFormField>
            <UFormField :label="$t('storageConfig.secretAccessKey')">
              <UInput
                v-model="form.secretAccessKey"
                type="password"
                :placeholder="config ? $t('storageConfig.credentialsUnchanged') : ''"
              />
            </UFormField>
          </div>

          <!-- Account ID (Cloudflare R2) -->
          <UFormField v-if="needsAccountId" :label="$t('storageConfig.accountId')">
            <UInput v-model="form.accountId" />
          </UFormField>

          <!-- Bucket + Region -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <UFormField :label="$t('storageConfig.bucket')">
              <UInput v-model="form.bucket" />
            </UFormField>
            <UFormField v-if="showRegion" :label="$t('storageConfig.region')">
              <UInput v-model="form.region" placeholder="us-east-1" />
            </UFormField>
          </div>

          <!-- Endpoint (MinIO) -->
          <UFormField v-if="showEndpoint" :label="$t('storageConfig.endpoint')">
            <UInput v-model="form.endpoint" placeholder="https://minio.example.com" />
          </UFormField>

          <!-- Prefix -->
          <UFormField :label="$t('storageConfig.prefix')">
            <UInput v-model="form.prefix" placeholder="documents" />
          </UFormField>

          <!-- Force path style -->
          <div class="flex items-center gap-2">
            <USwitch v-model="form.forcePathStyle" size="sm" />
            <span class="text-sm">{{ $t('storageConfig.forcePathStyle') }}</span>
          </div>

          <!-- Active toggle -->
          <div class="flex items-center gap-2">
            <USwitch v-model="form.isActive" size="sm" />
            <span class="text-sm">{{ $t('storageConfig.activeToggle') }}</span>
          </div>

          <!-- Test result banner -->
          <div v-if="testResult" :class="[
            'rounded-lg p-3 text-sm',
            testResult.success ? 'bg-success-50 text-success-700 dark:bg-success-950 dark:text-success-300' : 'bg-error-50 text-error-700 dark:bg-error-950 dark:text-error-300',
          ]">
            <div class="flex items-center gap-2">
              <UIcon :name="testResult.success ? 'i-lucide-check-circle' : 'i-lucide-x-circle'" class="size-4 shrink-0" />
              <span>{{ testResult.success ? $t('storageConfig.testSuccess') : (testResult.error || $t('storageConfig.testError')) }}</span>
            </div>
          </div>

          <!-- Warning -->
          <div class="rounded-lg p-3 text-sm bg-warning-50 text-warning-700 dark:bg-warning-950 dark:text-warning-300">
            <div class="flex items-start gap-2">
              <UIcon name="i-lucide-info" class="size-4 shrink-0 mt-0.5" />
              <span>{{ $t('storageConfig.migrationWarning') }}</span>
            </div>
          </div>
        </template>

        <!-- Actions -->
        <div class="flex justify-end gap-2 pt-2 border-t border-default">
          <UButton variant="ghost" @click="cancelEdit" type="button">{{ $t('common.cancel') }}</UButton>
          <UButton
            :label="$t('storageConfig.testConnection')"
            variant="outline"
            :loading="testing"
            :disabled="!canSave"
            icon="i-lucide-plug"
            type="button"
            @click="onTest"
          />
          <UButton
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
        <h3 class="text-lg font-semibold">{{ $t('storageConfig.deleteConfirmTitle') }}</h3>
      </template>
      <template #body>
        <p>{{ $t('storageConfig.deleteConfirm') }}</p>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="ghost" @click="deleteConfirmOpen = false" type="button">{{ $t('common.cancel') }}</UButton>
          <UButton color="error" @click="onDelete" type="button">{{ $t('common.delete') }}</UButton>
        </div>
      </template>
    </UModal>
  </div>
</template>
