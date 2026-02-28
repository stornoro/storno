<script setup lang="ts">
import type { WebhookEndpoint, WebhookDelivery } from '~/types'

definePageMeta({ middleware: ['auth', 'permissions'] })

const { t: $t } = useI18n()
useHead({ title: $t('webhooks.title') })
const { can } = usePermissions()
const store = useWebhookStore()
const companyStore = useCompanyStore()
const toast = useToast()
const { copy } = useClipboard()

const loading = computed(() => store.loading)
const webhooks = computed(() => store.items)

const modalOpen = ref(false)
const saving = ref(false)
const editingWebhook = ref<WebhookEndpoint | null>(null)
const form = ref({ url: '', description: '', events: [] as string[], isActive: true })

// Secret display
const revealedSecret = ref<string | null>(null)

// Test
const testing = ref<string | null>(null)

// Delivery log
const deliveryModalOpen = ref(false)
const deliveryWebhookId = ref<string | null>(null)
const deliveries = ref<WebhookDelivery[]>([])
const deliveryMeta = ref({ page: 1, limit: 20, total: 0 })
const deliveryLoading = ref(false)

// Detail delivery
const detailModalOpen = ref(false)
const detailDelivery = ref<WebhookDelivery | null>(null)

const columns = [
  { accessorKey: 'url', header: 'URL' },
  { accessorKey: 'events', header: $t('webhooks.events') },
  { accessorKey: 'isActive', header: $t('webhooks.status') },
  { id: 'actions', header: $t('common.actions') },
]

const deliveryColumns = [
  { accessorKey: 'eventType', header: $t('webhooks.eventType') },
  { accessorKey: 'status', header: $t('webhooks.status') },
  { accessorKey: 'responseStatusCode', header: 'HTTP' },
  { accessorKey: 'durationMs', header: $t('webhooks.duration') },
  { accessorKey: 'attempt', header: $t('webhooks.attempt') },
  { accessorKey: 'triggeredAt', header: $t('webhooks.triggeredAt') },
  { id: 'actions', header: '' },
]

const eventOptions = computed(() =>
  store.availableEvents.map(e => ({
    label: e.description,
    value: e.event,
    category: e.category,
  })),
)

function openCreate() {
  editingWebhook.value = null
  revealedSecret.value = null
  form.value = { url: '', description: '', events: [], isActive: true }
  modalOpen.value = true
}

function openEdit(webhook: WebhookEndpoint) {
  editingWebhook.value = webhook
  revealedSecret.value = null
  form.value = {
    url: webhook.url,
    description: webhook.description || '',
    events: [...webhook.events],
    isActive: webhook.isActive,
  }
  modalOpen.value = true
}

async function onSave() {
  saving.value = true
  if (editingWebhook.value) {
    const ok = await store.updateWebhook(editingWebhook.value.id, form.value)
    if (ok) {
      toast.add({ title: $t('webhooks.updateSuccess'), color: 'success' })
      modalOpen.value = false
    }
    else {
      toast.add({ title: store.error || $t('webhooks.updateError'), color: 'error' })
    }
  }
  else {
    const result = await store.createWebhook(form.value)
    if (result) {
      revealedSecret.value = result.secret
      toast.add({ title: $t('webhooks.createSuccess'), color: 'success' })
    }
    else {
      toast.add({ title: store.error || $t('webhooks.createError'), color: 'error' })
    }
  }
  saving.value = false
}

const deleteModalOpen = ref(false)
const deletingWebhook = ref<WebhookEndpoint | null>(null)
const deleting = ref(false)

function openDelete(webhook: WebhookEndpoint) {
  deletingWebhook.value = webhook
  deleteModalOpen.value = true
}

async function onDelete() {
  if (!deletingWebhook.value) return
  deleting.value = true
  const ok = await store.deleteWebhook(deletingWebhook.value.id)
  if (ok) {
    toast.add({ title: $t('webhooks.deleteSuccess'), color: 'success' })
    deleteModalOpen.value = false
  }
  else if (store.error) {
    toast.add({ title: store.error, color: 'error' })
  }
  deleting.value = false
}

async function onTest(webhook: WebhookEndpoint) {
  testing.value = webhook.id
  const result = await store.testWebhook(webhook.id)
  testing.value = null
  if (result) {
    if (result.success) {
      toast.add({ title: $t('webhooks.testSuccess', { status: result.statusCode, duration: result.durationMs }), color: 'success' })
    }
    else {
      toast.add({ title: $t('webhooks.testFailed', { error: result.error || `HTTP ${result.statusCode}` }), color: 'error' })
    }
  }
  else {
    toast.add({ title: $t('webhooks.testError'), color: 'error' })
  }
}

const regenerateModalOpen = ref(false)
const regeneratingWebhook = ref<WebhookEndpoint | null>(null)
const regenerating = ref(false)

function openRegenerateSecret(webhook: WebhookEndpoint) {
  regeneratingWebhook.value = webhook
  regenerateModalOpen.value = true
}

async function onRegenerateSecret() {
  if (!regeneratingWebhook.value) return
  regenerating.value = true
  const secret = await store.regenerateSecret(regeneratingWebhook.value.id)
  if (secret) {
    revealedSecret.value = secret
    editingWebhook.value = regeneratingWebhook.value
    toast.add({ title: $t('webhooks.regenerateSuccess'), color: 'success' })
    regenerateModalOpen.value = false
  }
  else if (store.error) {
    toast.add({ title: store.error, color: 'error' })
  }
  regenerating.value = false
}

async function openDeliveries(webhook: WebhookEndpoint) {
  deliveryWebhookId.value = webhook.id
  deliveryMeta.value.page = 1
  deliveryModalOpen.value = true
  await loadDeliveries()
}

async function loadDeliveries() {
  if (!deliveryWebhookId.value) return
  deliveryLoading.value = true
  const result = await store.fetchDeliveries(deliveryWebhookId.value, deliveryMeta.value.page, deliveryMeta.value.limit)
  if (result) {
    deliveries.value = result.data
    deliveryMeta.value = result.meta
  }
  deliveryLoading.value = false
}

async function openDeliveryDetail(delivery: WebhookDelivery) {
  if (!deliveryWebhookId.value) return
  const detail = await store.fetchDeliveryDetail(deliveryWebhookId.value, delivery.id)
  if (detail) {
    detailDelivery.value = detail
    detailModalOpen.value = true
  }
}

function statusColor(status: string): string {
  return { success: 'success', failed: 'error', retrying: 'warning', pending: 'info' }[status] || 'neutral'
}

function formatDate(date: string | null): string {
  if (!date) return '-'
  return new Date(date).toLocaleString('ro-RO')
}

const canSave = computed(() => {
  if (!form.value.url.trim()) return false
  if (form.value.events.length === 0) return false
  return true
})

watch(() => companyStore.currentCompanyId, () => {
  store.fetchWebhooks()
  store.fetchAvailableEvents()
})

onMounted(() => {
  store.fetchWebhooks()
  store.fetchAvailableEvents()
})
</script>

<template>
  <div>
    <UPageCard
      :title="$t('webhooks.title')"
      :description="$t('webhooks.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        v-if="can(P.WEBHOOK_MANAGE)"
        :label="$t('webhooks.addWebhook')"
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
        :data="webhooks"
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
        <template #url-cell="{ row }">
          <div class="truncate max-w-xs">
            <span class="font-mono text-sm">{{ row.original.url }}</span>
            <p v-if="row.original.description" class="text-xs text-(--ui-text-muted) truncate">{{ row.original.description }}</p>
          </div>
        </template>
        <template #events-cell="{ row }">
          <UBadge color="neutral" variant="subtle" size="sm">
            {{ row.original.events.length }} {{ $t('webhooks.events').toLowerCase() }}
          </UBadge>
        </template>
        <template #isActive-cell="{ row }">
          <UBadge :color="row.original.isActive ? 'success' : 'neutral'" variant="subtle" size="sm">
            {{ row.original.isActive ? $t('webhooks.active') : $t('webhooks.inactive') }}
          </UBadge>
        </template>
        <template #actions-cell="{ row }">
          <div v-if="can(P.WEBHOOK_MANAGE)" class="flex gap-1">
            <UButton
              icon="i-lucide-play"
              variant="ghost"
              size="xs"
              :loading="testing === row.original.id"
              :title="$t('webhooks.test')"
              @click="onTest(row.original)"
            />
            <UButton
              icon="i-lucide-list"
              variant="ghost"
              size="xs"
              :title="$t('webhooks.deliveryLog')"
              @click="openDeliveries(row.original)"
            />
            <UButton icon="i-lucide-pencil" variant="ghost" size="xs" @click="openEdit(row.original)" />
            <UButton icon="i-lucide-trash-2" variant="ghost" size="xs" color="error" @click="openDelete(row.original)" />
          </div>
        </template>
      </UTable>

      <UEmpty v-if="!loading && webhooks.length === 0" icon="i-lucide-webhook" :title="$t('webhooks.noWebhooks')" class="py-12" />
    </UPageCard>

    <SharedConfirmModal
      v-model:open="deleteModalOpen"
      :title="$t('webhooks.deleteTitle')"
      :description="$t('webhooks.deleteDescription')"
      icon="i-lucide-trash-2"
      color="error"
      :confirm-label="$t('common.delete')"
      :loading="deleting"
      @confirm="onDelete"
    />

    <SharedConfirmModal
      v-model:open="regenerateModalOpen"
      :title="$t('webhooks.regenerateTitle')"
      :description="$t('webhooks.regenerateDescription')"
      icon="i-lucide-refresh-cw"
      color="warning"
      :confirm-label="$t('webhooks.regenerateSecret')"
      :loading="regenerating"
      @confirm="onRegenerateSecret"
    />

    <!-- Create/Edit Slideover -->
    <USlideover v-model:open="modalOpen" :ui="{ content: 'sm:max-w-2xl' }">
      <template #header>
        <div class="flex items-center justify-between w-full">
          <h3 class="text-lg font-semibold">{{ editingWebhook ? $t('webhooks.editWebhook') : $t('webhooks.addWebhook') }}</h3>
          <div class="flex items-center gap-2">
            <USwitch v-model="form.isActive" size="sm" />
            <span class="text-sm text-(--ui-text-muted)">{{ $t('webhooks.active') }}</span>
          </div>
        </div>
      </template>
      <template #body>
        <div class="space-y-4">
          <!-- Secret display (after create or regenerate) -->
          <div v-if="revealedSecret" class="p-3 bg-(--ui-bg-elevated) rounded-lg border border-(--ui-border-accented)">
            <p class="text-sm font-medium mb-1">{{ $t('webhooks.secretLabel') }}</p>
            <div class="flex items-center gap-2">
              <code class="text-xs break-all flex-1">{{ revealedSecret }}</code>
              <UButton icon="i-lucide-copy" variant="ghost" size="xs" @click="copy(revealedSecret!)" />
            </div>
            <p class="text-xs text-(--ui-text-muted) mt-1">{{ $t('webhooks.secretWarning') }}</p>
          </div>

          <UFormField label="URL">
            <UInput
              v-model="form.url"
              size="xl"
              class="w-full"
              placeholder="https://example.com/webhook"
            />
          </UFormField>

          <UFormField :label="$t('webhooks.descriptionLabel')">
            <UInput
              v-model="form.description"
              size="xl"
              class="w-full"
              :placeholder="$t('webhooks.descriptionPlaceholder')"
            />
          </UFormField>

          <UFormField :label="$t('webhooks.events')">
            <USelectMenu
              v-model="form.events"
              :items="eventOptions"
              value-key="value"
              multiple
              size="xl"
              class="w-full"
              :placeholder="$t('webhooks.selectEvents')"
            />
          </UFormField>

          <!-- Regenerate secret (only for editing) -->
          <div v-if="editingWebhook" class="pt-2">
            <UButton
              variant="outline"
              color="warning"
              size="sm"
              icon="i-lucide-refresh-cw"
              :label="$t('webhooks.regenerateSecret')"
              type="button"
              @click="openRegenerateSecret(editingWebhook!)"
            />
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="ghost" @click="modalOpen = false">{{ $t('common.cancel') }}</UButton>
          <UButton :loading="saving" :disabled="!canSave" @click="onSave">{{ $t('common.save') }}</UButton>
        </div>
      </template>
    </USlideover>

    <!-- Delivery Log Slideover -->
    <USlideover v-model:open="deliveryModalOpen" :ui="{ content: 'sm:max-w-4xl' }">
      <template #header>
        <h3 class="text-lg font-semibold">{{ $t('webhooks.deliveryLog') }}</h3>
      </template>
      <template #body>
        <UTable
          :data="deliveries"
          :columns="deliveryColumns"
          :loading="deliveryLoading"
          :ui="{
            base: 'table-fixed',
            thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
            tbody: '[&>tr]:last:[&>td]:border-b-0',
            th: 'px-3 text-xs',
            td: 'px-3 border-b border-default text-sm',
          }"
        >
          <template #status-cell="{ row }">
            <UBadge :color="statusColor(row.original.status)" variant="subtle" size="sm">
              {{ $t(`webhooks.deliveryStatus.${row.original.status}`) }}
            </UBadge>
          </template>
          <template #durationMs-cell="{ row }">
            {{ row.original.durationMs ? `${row.original.durationMs}ms` : '-' }}
          </template>
          <template #triggeredAt-cell="{ row }">
            {{ formatDate(row.original.triggeredAt) }}
          </template>
          <template #actions-cell="{ row }">
            <UButton icon="i-lucide-eye" variant="ghost" size="xs" @click="openDeliveryDetail(row.original)" />
          </template>
        </UTable>

        <UEmpty v-if="!deliveryLoading && deliveries.length === 0" icon="i-lucide-inbox" :title="$t('webhooks.noDeliveries')" class="py-8" />

        <div v-if="deliveryMeta.total > deliveryMeta.limit" class="flex justify-center mt-4">
          <UPagination
            v-model:page="deliveryMeta.page"
            :total="deliveryMeta.total"
            :items-per-page="deliveryMeta.limit"
            @update:page="loadDeliveries"
          />
        </div>
      </template>
    </USlideover>

    <!-- Delivery Detail Modal -->
    <UModal v-model:open="detailModalOpen">
      <template #header>
        <h3 class="text-lg font-semibold">{{ $t('webhooks.deliveryDetail') }}</h3>
      </template>
      <template #body>
        <div v-if="detailDelivery" class="space-y-3 text-sm">
          <div class="grid grid-cols-2 gap-2">
            <div><span class="font-medium">{{ $t('webhooks.eventType') }}:</span> {{ detailDelivery.eventType }}</div>
            <div><span class="font-medium">{{ $t('webhooks.status') }}:</span>
              <UBadge :color="statusColor(detailDelivery.status)" variant="subtle" size="sm" class="ml-1">
                {{ $t(`webhooks.deliveryStatus.${detailDelivery.status}`) }}
              </UBadge>
            </div>
            <div><span class="font-medium">HTTP:</span> {{ detailDelivery.responseStatusCode ?? '-' }}</div>
            <div><span class="font-medium">{{ $t('webhooks.duration') }}:</span> {{ detailDelivery.durationMs ? `${detailDelivery.durationMs}ms` : '-' }}</div>
            <div><span class="font-medium">{{ $t('webhooks.attempt') }}:</span> {{ detailDelivery.attempt }}</div>
            <div><span class="font-medium">{{ $t('webhooks.triggeredAt') }}:</span> {{ formatDate(detailDelivery.triggeredAt) }}</div>
          </div>

          <div v-if="detailDelivery.errorMessage">
            <span class="font-medium text-(--ui-text-error)">{{ $t('webhooks.errorLabel') }}:</span>
            <p class="mt-1 text-xs font-mono bg-(--ui-bg-elevated) p-2 rounded">{{ detailDelivery.errorMessage }}</p>
          </div>

          <div>
            <span class="font-medium">Payload:</span>
            <pre class="mt-1 text-xs bg-(--ui-bg-elevated) p-2 rounded overflow-auto max-h-48">{{ JSON.stringify(detailDelivery.payload, null, 2) }}</pre>
          </div>

          <div v-if="detailDelivery.responseBody">
            <span class="font-medium">{{ $t('webhooks.responseBody') }}:</span>
            <pre class="mt-1 text-xs bg-(--ui-bg-elevated) p-2 rounded overflow-auto max-h-48">{{ detailDelivery.responseBody }}</pre>
          </div>
        </div>
      </template>
    </UModal>
  </div>
</template>
