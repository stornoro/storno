<script setup lang="ts">
import type { LicenseKey } from '~/types'

definePageMeta({ middleware: ['auth', 'permissions'] })

const { t: $t } = useI18n()
useHead({ title: $t('licenseKeys.title') })
const { can } = usePermissions()
const store = useLicenseKeyStore()
const toast = useToast()
const { copy } = useClipboard()

const loading = computed(() => store.loading)
const keys = computed(() => store.items)

const slideoverOpen = ref(false)
const saving = ref(false)
const createdKey = ref<string | null>(null)
const instanceName = ref('')
const expandedViolations = ref<string | null>(null)

function getStatusColor(key: LicenseKey): 'success' | 'error' | 'warning' {
  if (!key.active) return 'error'
  if (key.lastValidatedAt) {
    const hoursSince = (Date.now() - new Date(key.lastValidatedAt).getTime()) / (1000 * 60 * 60)
    if (hoursSince > 48) return 'warning'
  }
  return 'success'
}

function getStatusLabel(key: LicenseKey): string {
  if (!key.active) return $t('licenseKeys.revoked')
  if (key.lastValidatedAt) {
    const hoursSince = (Date.now() - new Date(key.lastValidatedAt).getTime()) / (1000 * 60 * 60)
    if (hoursSince > 48) return $t('licenseKeys.stale')
  }
  return $t('licenseKeys.active')
}

function formatRelativeDate(date: string | null): string {
  if (!date) return $t('licenseKeys.never')
  const diff = Date.now() - new Date(date).getTime()
  const minutes = Math.floor(diff / 60000)
  if (minutes < 1) return 'acum'
  if (minutes < 60) return `${minutes}m`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h`
  const days = Math.floor(hours / 24)
  return `${days}z`
}

function formatMetrics(key: LicenseKey): string {
  if (!key.instanceMetrics) return '-'
  const m = key.instanceMetrics
  return `${m.orgCount} ${$t('licenseKeys.orgs')}, ${m.userCount} ${$t('licenseKeys.users')}, ${m.companyCount} ${$t('licenseKeys.companies')}, ${m.invoicesThisMonth} ${$t('licenseKeys.invoices')}`
}

function toggleViolations(id: string) {
  expandedViolations.value = expandedViolations.value === id ? null : id
}

const columns = [
  { accessorKey: 'instanceName', header: $t('licenseKeys.instanceName') },
  { accessorKey: 'licenseKey', header: $t('licenseKeys.key') },
  { id: 'status', header: $t('licenseKeys.status') },
  { id: 'lastValidated', header: $t('licenseKeys.lastValidated') },
  { id: 'metrics', header: $t('licenseKeys.metrics') },
  { id: 'violations', header: $t('licenseKeys.violations') },
  { id: 'actions', header: $t('common.actions') },
]

function openCreate() {
  createdKey.value = null
  instanceName.value = ''
  slideoverOpen.value = true
}

async function onCreate() {
  saving.value = true
  const result = await store.createLicenseKey(instanceName.value.trim() || undefined)
  if (result) {
    createdKey.value = result.licenseKey ?? null
    if (!createdKey.value) {
      toast.add({ title: $t('licenseKeys.createSuccess'), color: 'success' })
      slideoverOpen.value = false
    }
  }
  else if (store.error) {
    toast.add({ title: store.error, color: 'error' })
  }
  saving.value = false
}

const revokeModalOpen = ref(false)
const revokingKey = ref<LicenseKey | null>(null)
const revoking = ref(false)

function openRevoke(key: LicenseKey) {
  revokingKey.value = key
  revokeModalOpen.value = true
}

async function onRevoke() {
  if (!revokingKey.value) return
  revoking.value = true
  const ok = await store.revokeLicenseKey(revokingKey.value.id)
  if (ok) {
    toast.add({ title: $t('licenseKeys.revokeSuccess'), color: 'success' })
    revokeModalOpen.value = false
  }
  else if (store.error) {
    toast.add({ title: store.error, color: 'error' })
  }
  revoking.value = false
}

function copyKey() {
  if (createdKey.value) {
    copy(createdKey.value)
    toast.add({ title: $t('licenseKeys.tokenCopied'), color: 'success' })
  }
}

onMounted(() => {
  store.fetchLicenseKeys()
})
</script>

<template>
  <div>
    <UPageCard
      :title="$t('licenseKeys.title')"
      :description="$t('licenseKeys.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        v-if="can(P.ORG_MANAGE_BILLING)"
        :label="$t('licenseKeys.generateKey')"
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
        <template #instanceName-cell="{ row }">
          <div>
            <span class="font-medium">{{ row.original.instanceName || '-' }}</span>
            <a
              v-if="row.original.instanceUrl"
              :href="row.original.instanceUrl"
              target="_blank"
              class="block text-xs text-(--ui-text-muted) hover:text-(--ui-text) truncate"
            >
              {{ row.original.instanceUrl }}
            </a>
          </div>
        </template>
        <template #licenseKey-cell="{ row }">
          <span class="font-mono text-sm">{{ row.original.licenseKey }}</span>
        </template>
        <template #status-cell="{ row }">
          <UBadge :color="getStatusColor(row.original)" variant="subtle" size="sm">
            {{ getStatusLabel(row.original) }}
          </UBadge>
        </template>
        <template #lastValidated-cell="{ row }">
          <span :title="row.original.lastValidatedAt ?? undefined">
            {{ formatRelativeDate(row.original.lastValidatedAt) }}
          </span>
        </template>
        <template #metrics-cell="{ row }">
          <span class="text-sm text-(--ui-text-muted)">{{ formatMetrics(row.original) }}</span>
        </template>
        <template #violations-cell="{ row }">
          <div>
            <UBadge
              :color="(row.original.lastViolations?.length ?? 0) > 0 ? 'error' : 'neutral'"
              variant="subtle"
              size="sm"
              class="cursor-pointer"
              @click="(row.original.lastViolations?.length ?? 0) > 0 && toggleViolations(row.original.id)"
            >
              {{ (row.original.lastViolations?.length ?? 0) > 0 ? row.original.lastViolations!.length : $t('licenseKeys.noViolations') }}
            </UBadge>
            <ul
              v-if="expandedViolations === row.original.id && row.original.lastViolations?.length"
              class="mt-1 text-xs text-error space-y-0.5"
            >
              <li v-for="(v, i) in row.original.lastViolations" :key="i">
                {{ v }}
              </li>
            </ul>
          </div>
        </template>
        <template #actions-cell="{ row }">
          <div class="flex gap-1">
            <UButton
              v-if="row.original.active && can(P.ORG_MANAGE_BILLING)"
              icon="i-lucide-trash-2"
              variant="ghost"
              size="xs"
              color="error"
              @click="openRevoke(row.original)"
            />
          </div>
        </template>
      </UTable>

      <UEmpty v-if="!loading && keys.length === 0" icon="i-lucide-key-round" :title="$t('licenseKeys.noKeys')" class="py-12" />
    </UPageCard>

    <SharedConfirmModal
      v-model:open="revokeModalOpen"
      :title="$t('licenseKeys.revokeTitle')"
      :description="$t('licenseKeys.revokeDescription')"
      icon="i-lucide-trash-2"
      color="error"
      :confirm-label="$t('common.delete')"
      :loading="revoking"
      @confirm="onRevoke"
    />

    <!-- Create Slideover -->
    <USlideover v-model:open="slideoverOpen" :ui="{ content: 'sm:max-w-lg' }">
      <template #header>
        <h3 class="text-lg font-semibold">
          {{ createdKey ? $t('licenseKeys.createSuccess') : $t('licenseKeys.generateKey') }}
        </h3>
      </template>
      <template #body>
        <!-- Key display after creation -->
        <div v-if="createdKey" class="space-y-4">
          <div class="rounded-lg border border-warning/50 bg-warning/5 p-4">
            <div class="flex items-center gap-2 mb-2">
              <UIcon name="i-lucide-alert-triangle" class="text-warning" />
              <span class="text-sm font-medium text-(--ui-text-highlighted)">{{ $t('licenseKeys.tokenWarning') }}</span>
            </div>
            <div class="flex items-center gap-2">
              <code class="flex-1 rounded bg-elevated px-3 py-2 font-mono text-xs break-all select-all">{{ createdKey }}</code>
              <UButton icon="i-lucide-copy" variant="ghost" size="sm" @click="copyKey" />
            </div>
          </div>
          <UButton class="w-full" @click="slideoverOpen = false">{{ $t('common.close') }}</UButton>
        </div>

        <!-- Form -->
        <div v-else class="space-y-4">
          <UFormField :label="$t('licenseKeys.instanceName')">
            <UInput
              v-model="instanceName"
              size="xl"
              class="w-full"
              :placeholder="$t('licenseKeys.instanceNamePlaceholder')"
            />
          </UFormField>
        </div>
      </template>
      <template v-if="!createdKey" #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="ghost" @click="slideoverOpen = false">{{ $t('common.cancel') }}</UButton>
          <UButton :loading="saving" @click="onCreate">{{ $t('licenseKeys.generateKey') }}</UButton>
        </div>
      </template>
    </USlideover>
  </div>
</template>
