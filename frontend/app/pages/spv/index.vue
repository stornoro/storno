<script setup lang="ts">
import type { SpvDocument, SpvRequest, SpvRequestType } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t, locale } = useI18n()
const store = useSpvDocumentStore()
const companyStore = useCompanyStore()
const toast = useToast()
const route = useRoute()
const { syncSpvViaAgent, requestSpvViaAgent, getPreferredCertId, checkAgent, agentAvailable, tryAutoStart } = useAnafAgent()

useHead({ title: $t('spv.title') })

onMounted(async () => {
  await Promise.all([store.fetchDocuments(), store.fetchStats(), store.fetchRequests()])
  const focus = route.query.document as string | undefined
  if (focus) {
    const doc = await store.fetchDocument(focus)
    if (doc) openDetail(doc)
  }
})

watch([() => store.category, () => store.severity, () => store.unreadOnly, () => store.page], () => {
  store.fetchDocuments()
})

let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(() => store.search, () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { store.page = 1; store.fetchDocuments() }, 300)
})

watch(() => companyStore.currentCompanyId, () => {
  store.$reset()
  store.fetchDocuments()
  store.fetchStats()
  store.fetchRequests()
})

// ── Sync through the local agent ───────────────────────────────────
const savedCertId = computed(() => {
  const companyId = companyStore.currentCompanyId
  return companyId ? getPreferredCertId(companyId) : null
})
const syncing = ref(false)
const syncProgress = ref<{ done: number; total: number } | null>(null)
const setupOpen = ref(false)
const setupState = ref<'no-cert' | 'no-agent'>('no-cert')

async function handleSync() {
  if (!savedCertId.value) {
    setupState.value = 'no-cert'
    setupOpen.value = true
    return
  }
  await checkAgent()
  if (!agentAvailable.value) {
    await tryAutoStart()
    await checkAgent()
    if (!agentAvailable.value) {
      setupState.value = 'no-agent'
      setupOpen.value = true
      return
    }
  }

  syncing.value = true
  syncProgress.value = null
  try {
    const stats = await syncSpvViaAgent(savedCertId.value, 60, (done, total) => {
      syncProgress.value = { done, total }
    })
    toast.add({
      title: $t('spv.syncSuccess'),
      description: $t('spv.syncSummary', { received: stats.received, created: stats.created, downloaded: stats.downloaded, failed: stats.failed }),
      color: stats.failed > 0 ? 'warning' : 'success',
    })
    await Promise.all([store.fetchDocuments(), store.fetchStats()])
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? e?.message ?? $t('spv.syncError'), color: 'error' })
  } finally {
    syncing.value = false
    syncProgress.value = null
  }
}

// ── Detail slideover ───────────────────────────────────────────────
const detailOpen = ref(false)
const detail = ref<SpvDocument | null>(null)

async function openDetail(doc: SpvDocument) {
  detail.value = doc
  detailOpen.value = true
  if (!doc.read) {
    try { await store.markRead(doc.id) } catch { /* ignore */ }
  }
}

async function handleDownload(doc: SpvDocument) {
  try {
    await store.download(doc)
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('spv.downloadError'), color: 'error' })
  }
}

async function handleMarkAllRead() {
  try {
    const n = await store.markAllRead()
    toast.add({ title: $t('spv.markedRead', { count: n }), color: 'success' })
  } catch (e: any) {
    toast.add({ title: e?.message ?? $t('common.error'), color: 'error' })
  }
}

// ── Requests to ANAF (solicitari) ──────────────────────────────────
const requestOpen = ref(false)
const requestSending = ref(false)
const requestType = ref<string | null>(null)
const requestParams = ref<Record<string, string>>({})

const groupLabels: Record<string, string> = { rapoarte: 'spv.requests.groups.rapoarte', documente: 'spv.requests.groups.documente', declaratii: 'spv.requests.groups.declaratii', decizii: 'spv.requests.groups.decizii' }
const requestTypeItems = computed(() => {
  const groups: Array<Array<{ label: string, value: string }>> = []
  for (const g of ['rapoarte', 'documente', 'declaratii', 'decizii']) {
    const items = store.requestTypes.filter(t => t.group === g).map(t => ({ label: t.label !== t.type ? `${t.label}` : t.type, value: t.type, group: g }))
    if (items.length) groups.push(items)
  }
  return groups
})
const selectedRequestType = computed<SpvRequestType | undefined>(() => store.requestTypes.find(t => t.type === requestType.value))
const requestFields = computed(() => {
  const t = selectedRequestType.value
  if (!t) return [] as Array<{ name: string, required: boolean }>
  return [...t.params.map(n => ({ name: n, required: true })), ...t.optional.map(n => ({ name: n, required: false }))]
})
const monthItems = Array.from({ length: 12 }, (_, i) => ({ label: String(i + 1), value: String(i + 1) }))
const reasonItems = computed(() => store.incomeCertificateReasons.map(r => ({ label: r, value: r })))
const requestReady = computed(() => !!selectedRequestType.value && selectedRequestType.value.params.every(n => (requestParams.value[n] ?? '') !== ''))

async function openRequest() {
  try { await store.fetchRequestTypes() } catch (e: any) { toast.add({ title: e?.message ?? $t('common.error'), color: 'error' }); return }
  requestType.value = null
  requestParams.value = { an: String(new Date().getFullYear()) }
  requestOpen.value = true
}

async function sendRequest() {
  if (!requestType.value) return
  if (!savedCertId.value) { setupState.value = 'no-cert'; setupOpen.value = true; return }
  await checkAgent()
  if (!agentAvailable.value) {
    await tryAutoStart()
    await checkAgent()
    if (!agentAvailable.value) { setupState.value = 'no-agent'; setupOpen.value = true; return }
  }
  requestSending.value = true
  try {
    const params: Record<string, string> = {}
    for (const f of requestFields.value) {
      const v = (requestParams.value[f.name] ?? '').toString().trim()
      if (v !== '') params[f.name] = v
    }
    const result: SpvRequest = await requestSpvViaAgent(savedCertId.value, requestType.value, params)
    if (result.status === 'requested') {
      toast.add({ title: $t('spv.requests.created'), description: $t('spv.requests.hintAnswer'), color: 'success' })
      requestOpen.value = false
    } else {
      toast.add({ title: $t('spv.requests.failed'), description: result.errorMessage ?? undefined, color: 'error' })
    }
  } catch (e: any) {
    toast.add({ title: $t('spv.requests.failed'), description: e?.data?.error ?? e?.message, color: 'error' })
  } finally {
    requestSending.value = false
    await store.fetchRequests()
  }
}

async function openAnswer(req: SpvRequest) {
  if (!req.answerDocumentId) return
  const doc = await store.fetchDocument(req.answerDocumentId)
  if (doc) openDetail(doc)
}

async function removeRequest(req: SpvRequest) {
  try { await store.deleteRequest(req.id) } catch (e: any) { toast.add({ title: e?.message ?? $t('common.error'), color: 'error' }) }
}

const requestStatusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral' | 'primary'> = { pending: 'neutral', requested: 'primary', answered: 'success', error: 'error' }
function describeParams(params: Record<string, string>): string {
  return Object.entries(params).map(([k, v]) => `${$t(`spv.requests.params.${k}`)}: ${v}`).join(' · ')
}

// ── Presentation helpers ───────────────────────────────────────────
const severityColor: Record<string, 'error' | 'warning' | 'primary' | 'neutral'> = {
  critical: 'error',
  high: 'warning',
  normal: 'primary',
  low: 'neutral',
}

const categoryItems = computed(() => [
  { label: $t('spv.allCategories'), value: null },
  ...(store.stats?.categories ?? []).map(c => ({
    label: `${c.label}${store.stats?.byCategory[c.value] ? ` (${store.stats.byCategory[c.value]})` : ''}`,
    value: c.value,
  })),
])

const severityItems = computed(() => [
  { label: $t('spv.allSeverities'), value: null },
  ...(['critical', 'high', 'normal', 'low'] as const).map(s => ({ label: $t(`spv.severity.${s}`), value: s })),
])

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString(locale.value === 'ro' ? 'ro-RO' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' })
}

function formatSize(bytes: number | null): string {
  if (!bytes) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

const criticalUnread = computed(() => store.items.filter(d => d.severity === 'critical' && !d.read).length)
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('spv.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            v-if="store.stats && store.stats.unread > 0"
            icon="i-lucide-check-check"
            color="neutral"
            variant="ghost"
            @click="handleMarkAllRead"
          >
            {{ $t('spv.markAllRead') }}
          </UButton>
          <UButton icon="i-lucide-send" color="neutral" variant="outline" @click="openRequest">
            {{ $t('spv.requests.new') }}
          </UButton>
          <UButton icon="i-lucide-refresh-cw" :loading="syncing" @click="handleSync">
            {{ syncing && syncProgress ? $t('spv.syncingPdfs', { done: syncProgress.done, total: syncProgress.total }) : $t('spv.syncNow') }}
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="p-4 space-y-4">
        <UAlert
          v-if="criticalUnread > 0"
          icon="i-lucide-siren"
          color="error"
          variant="soft"
          :title="$t('spv.criticalUnreadTitle', { count: criticalUnread })"
          :description="$t('spv.criticalUnreadHint')"
        />

        <p class="text-xs text-muted flex items-center gap-1.5">
          <UIcon name="i-lucide-clock" class="size-3.5" />
          <span v-if="store.stats?.lastSyncedAt">{{ $t('spv.lastSynced', { date: formatDate(store.stats.lastSyncedAt) }) }}</span>
          <span v-else>{{ $t('spv.neverSynced') }}</span>
        </p>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <UCard>
            <div class="text-xs text-muted">{{ $t('spv.stats.total') }}</div>
            <div class="text-2xl font-bold">{{ store.stats?.total ?? '—' }}</div>
          </UCard>
          <UCard>
            <div class="text-xs text-muted">{{ $t('spv.stats.unread') }}</div>
            <div class="text-2xl font-bold" :class="(store.stats?.unread ?? 0) > 0 ? 'text-primary' : ''">{{ store.stats?.unread ?? '—' }}</div>
          </UCard>
          <UCard>
            <div class="text-xs text-muted">{{ $t('spv.stats.critical') }}</div>
            <div class="text-2xl font-bold" :class="(store.stats?.bySeverity?.critical ?? 0) > 0 ? 'text-error' : ''">{{ store.stats?.bySeverity?.critical ?? 0 }}</div>
          </UCard>
          <UCard>
            <div class="text-xs text-muted">{{ $t('spv.stats.pendingPdf') }}</div>
            <div class="text-2xl font-bold">{{ store.stats?.pendingPdf ?? '—' }}</div>
          </UCard>
        </div>

        <div class="flex flex-col sm:flex-row gap-2">
          <UInput v-model="store.search" icon="i-lucide-search" :placeholder="$t('spv.searchPlaceholder')" class="flex-1" />
          <USelectMenu v-model="store.category" :items="categoryItems" value-key="value" class="w-full sm:w-60" />
          <USelectMenu v-model="store.severity" :items="severityItems" value-key="value" class="w-full sm:w-44" />
          <UCheckbox v-model="store.unreadOnly" :label="$t('spv.unreadOnly')" class="self-center" />
        </div>

        <UAlert v-if="store.error" color="error" variant="soft" icon="i-lucide-alert-circle" :title="store.error" />

        <div v-if="store.loading && store.items.length === 0" class="space-y-2">
          <USkeleton v-for="i in 6" :key="i" class="h-14 w-full" />
        </div>

        <UEmpty
          v-else-if="store.isEmpty"
          icon="i-lucide-inbox"
          :title="$t('spv.emptyTitle')"
          :description="$t('spv.emptyDescription')"
        >
          <template #actions>
            <UButton icon="i-lucide-refresh-cw" :loading="syncing" @click="handleSync">{{ $t('spv.syncNow') }}</UButton>
          </template>
        </UEmpty>

        <div v-else class="rounded-lg border border-default divide-y divide-default overflow-hidden">
          <div
            v-for="doc in store.items"
            :key="doc.id"
            class="flex items-start gap-3 px-4 py-3 hover:bg-elevated/50 cursor-pointer"
            :class="!doc.read ? 'bg-primary/5' : ''"
            @click="openDetail(doc)"
          >
            <UIcon
              :name="doc.severity === 'critical' ? 'i-lucide-siren' : doc.severity === 'high' ? 'i-lucide-bell-ring' : doc.hasPdf ? 'i-lucide-file-text' : 'i-lucide-file'"
              class="size-5 shrink-0 mt-0.5"
              :class="doc.severity === 'critical' ? 'text-error' : doc.severity === 'high' ? 'text-warning' : 'text-muted'"
            />
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-medium" :class="!doc.read ? 'text-highlighted' : ''">{{ doc.messageType }}</span>
                <UBadge :color="severityColor[doc.severity] ?? 'neutral'" variant="subtle" size="xs">{{ $t(`spv.severity.${doc.severity}`) }}</UBadge>
                <UBadge color="neutral" variant="outline" size="xs">{{ doc.categoryLabel }}</UBadge>
                <UBadge v-if="!doc.read" color="primary" variant="soft" size="xs">{{ $t('spv.unread') }}</UBadge>
              </div>
              <p v-if="doc.summary" class="text-sm mt-0.5 line-clamp-2">{{ doc.summary }}</p>
              <p class="text-xs text-muted mt-0.5 line-clamp-1">{{ doc.details || '—' }}</p>
              <div class="text-xs text-dimmed mt-1 flex gap-3 flex-wrap">
                <span>{{ formatDate(doc.anafCreatedAt) }}</span>
                <span v-if="doc.hasPdf">{{ doc.fileName }} {{ formatSize(doc.fileSize) }}</span>
                <span v-else-if="doc.purgedAt" class="italic">{{ $t('spv.filePurged') }}</span>
                <span v-else class="italic">{{ $t('spv.filePending') }}</span>
              </div>
            </div>
            <UButton
              v-if="doc.hasPdf"
              icon="i-lucide-download"
              color="neutral"
              variant="ghost"
              size="sm"
              :aria-label="$t('spv.download')"
              @click.stop="handleDownload(doc)"
            />
          </div>
        </div>

        <div v-if="store.totalPages > 1" class="flex justify-center">
          <UPagination v-model:page="store.page" :total="store.total" :items-per-page="store.limit" />
        </div>

        <!-- Requests to ANAF (solicitari) -->
        <UCard>
          <template #header>
            <div class="flex items-center justify-between gap-3">
              <div>
                <h3 class="font-semibold">{{ $t('spv.requests.title') }}</h3>
                <p class="text-xs text-muted">{{ $t('spv.requests.description') }}</p>
              </div>
              <UButton icon="i-lucide-send" size="sm" variant="outline" color="neutral" @click="openRequest">{{ $t('spv.requests.new') }}</UButton>
            </div>
          </template>
          <div v-if="store.requests.length === 0" class="text-sm text-muted py-2">{{ $t('spv.requests.empty') }}</div>
          <div v-else class="divide-y divide-default -mx-4">
            <div v-for="req in store.requests" :key="req.id" class="flex items-start gap-3 px-4 py-2.5">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="font-medium text-sm">{{ req.requestType }}</span>
                  <UBadge :color="requestStatusColor[req.status] ?? 'neutral'" variant="subtle" size="xs">{{ $t(`spv.requests.status.${req.status}`) }}</UBadge>
                </div>
                <div class="text-xs text-muted mt-0.5">
                  <span v-if="Object.keys(req.params).length">{{ describeParams(req.params) }} · </span>
                  <span>{{ formatDate(req.createdAt) }}</span>
                  <span v-if="req.anafRequestId"> · ANAF #{{ req.anafRequestId }}</span>
                  <span v-if="req.requestedByName"> · {{ req.requestedByName }}</span>
                </div>
                <p v-if="req.errorMessage" class="text-xs text-error mt-0.5">{{ req.errorMessage }}</p>
                <p v-else-if="req.status === 'requested'" class="text-xs text-dimmed mt-0.5">{{ $t('spv.requests.hintAnswer') }}</p>
              </div>
              <UButton v-if="req.answerDocumentId" icon="i-lucide-file-text" size="xs" variant="soft" @click="openAnswer(req)">{{ $t('spv.requests.viewAnswer') }}</UButton>
              <UButton v-else-if="req.status === 'error' || req.status === 'pending'" icon="i-lucide-trash-2" size="xs" color="neutral" variant="ghost" :aria-label="$t('common.delete')" @click="removeRequest(req)" />
            </div>
          </div>
        </UCard>
      </div>

      <!-- New request modal -->
      <UModal v-model:open="requestOpen" :title="$t('spv.requests.new')">
        <template #body>
          <div class="space-y-4">
            <UFormField :label="$t('spv.requests.type')" required>
              <USelectMenu v-model="requestType" :items="requestTypeItems" value-key="value" :placeholder="$t('spv.requests.typePlaceholder')" class="w-full" />
            </UFormField>
            <p v-if="selectedRequestType?.note" class="text-xs text-muted">{{ selectedRequestType.note }}</p>
            <p v-if="selectedRequestType?.since" class="text-xs text-dimmed">{{ $t('spv.requests.since', { year: selectedRequestType.since }) }}</p>
            <div v-if="requestFields.length" class="grid grid-cols-2 gap-3">
              <UFormField v-for="f in requestFields" :key="f.name" :label="$t(`spv.requests.params.${f.name}`)" :required="f.required" :class="f.name === 'motiv' || f.name === 'numar_inregistrare' ? 'col-span-2' : ''">
                <USelectMenu v-if="f.name === 'luna' || f.name === 'lunai' || f.name === 'lunas'" v-model="requestParams[f.name]" :items="monthItems" value-key="value" class="w-full" />
                <USelectMenu v-else-if="f.name === 'motiv'" v-model="requestParams[f.name]" :items="reasonItems" value-key="value" class="w-full" searchable />
                <UInput v-else v-model="requestParams[f.name]" :placeholder="f.name === 'numar_inregistrare' ? 'INTERNT-100000123-2026' : ''" class="w-full" />
              </UFormField>
            </div>
            <p class="text-xs text-muted">{{ $t('spv.requests.agentHint') }}</p>
          </div>
        </template>
        <template #footer>
          <div class="flex gap-2 justify-end w-full">
            <UButton color="neutral" variant="ghost" @click="requestOpen = false">{{ $t('common.cancel') }}</UButton>
            <UButton icon="i-lucide-send" :loading="requestSending" :disabled="!requestReady" @click="sendRequest">{{ $t('spv.requests.submit') }}</UButton>
          </div>
        </template>
      </UModal>

      <!-- Detail -->
      <USlideover v-model:open="detailOpen" :title="detail?.messageType ?? ''">
        <template #body>
          <div v-if="detail" class="space-y-4">
            <div class="flex items-center gap-2 flex-wrap">
              <UBadge :color="severityColor[detail.severity] ?? 'neutral'" variant="subtle">{{ $t(`spv.severity.${detail.severity}`) }}</UBadge>
              <UBadge color="neutral" variant="outline">{{ detail.categoryLabel }}</UBadge>
            </div>
            <UAlert v-if="detail.summary" color="primary" variant="soft" icon="i-lucide-info" :title="$t('spv.summary')" :description="detail.summary ?? undefined" />
            <div>
              <div class="text-xs text-muted mb-1">{{ $t('spv.anafText') }}</div>
              <p class="text-sm whitespace-pre-wrap">{{ detail.details || '—' }}</p>
            </div>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
              <dt class="text-muted">{{ $t('spv.detail.anafDate') }}</dt><dd>{{ formatDate(detail.anafCreatedAt) }}</dd>
              <dt class="text-muted">{{ $t('spv.detail.anafId') }}</dt><dd class="font-mono">{{ detail.anafMessageId }}</dd>
              <dt v-if="detail.idSolicitare" class="text-muted">{{ $t('spv.detail.requestId') }}</dt><dd v-if="detail.idSolicitare" class="font-mono">{{ detail.idSolicitare }}</dd>
              <dt class="text-muted">{{ $t('spv.detail.cif') }}</dt><dd>{{ detail.cif }}</dd>
              <dt class="text-muted">{{ $t('spv.detail.archived') }}</dt>
              <dd>
                <template v-if="detail.hasPdf">{{ formatDate(detail.downloadedAt) }} · {{ formatSize(detail.fileSize) }}</template>
                <template v-else-if="detail.purgedAt">{{ $t('spv.filePurged') }}</template>
                <template v-else>{{ $t('spv.filePending') }}</template>
              </dd>
            </dl>
            <UAlert v-if="detail.downloadError" color="warning" variant="soft" icon="i-lucide-triangle-alert" :description="detail.downloadError" />
          </div>
        </template>
        <template #footer>
          <div class="flex gap-2 justify-end w-full">
            <UButton color="neutral" variant="ghost" @click="detailOpen = false">{{ $t('common.close') }}</UButton>
            <UButton v-if="detail?.hasPdf" icon="i-lucide-download" @click="handleDownload(detail!)">{{ $t('spv.download') }}</UButton>
          </div>
        </template>
      </USlideover>

      <!-- Agent / certificate setup -->
      <UModal v-model:open="setupOpen">
        <template #header>
          <h3 class="text-lg font-semibold">{{ $t('spv.setupTitle') }}</h3>
        </template>
        <template #body>
          <div class="flex flex-col items-center gap-3 py-4 px-4">
            <UIcon :name="setupState === 'no-cert' ? 'i-lucide-key-round' : 'i-lucide-wifi-off'" class="text-3xl text-warning" />
            <p class="text-sm font-medium">{{ setupState === 'no-cert' ? $t('declarations.agentNoCertConfigured') : $t('declarations.bulkAgentNotRunning') }}</p>
            <p class="text-sm text-muted text-center">{{ setupState === 'no-cert' ? $t('declarations.agentConfigureFirstHint') : $t('declarations.bulkAgentStartHint') }}</p>
            <UButton
              v-if="setupState === 'no-cert'"
              :label="$t('declarations.agentConfigureFirst')"
              icon="i-lucide-settings"
              variant="outline"
              :to="`/companies/${companyStore.currentCompanyId}/anaf`"
              @click="setupOpen = false"
            />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
