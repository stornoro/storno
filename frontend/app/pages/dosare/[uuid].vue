<script setup lang="ts">
import type { DosarDetail, SpvDocument, SpvRequest, TaxDeclaration } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useDosareStore()
const toast = useToast()
const { get } = useApi()

const id = computed(() => String(route.params.uuid))
const detail = ref<DosarDetail | null>(null)
const loading = ref(true)
const saving = ref(false)

useHead({ title: computed(() => detail.value?.dosar.title ?? $t('dosare.title')) })

async function load() {
  loading.value = true
  try {
    detail.value = await store.fetchDosar(id.value)
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('common.error'), color: 'error' })
    router.push('/dosare')
  } finally {
    loading.value = false
  }
}
onMounted(load)

// ── Edit ───────────────────────────────────────────────────────────
const editOpen = ref(false)
const edit = reactive({ title: '', nextStep: '', deadlineAt: '', deadlineLabel: '', notes: '' })
function openEdit() {
  const d = detail.value!.dosar
  edit.title = d.title
  edit.nextStep = d.nextStep ?? ''
  edit.deadlineAt = d.deadlineAt ? d.deadlineAt.slice(0, 10) : ''
  edit.deadlineLabel = d.deadlineLabel ?? ''
  edit.notes = d.notes ?? ''
  editOpen.value = true
}
async function saveEdit() {
  saving.value = true
  try {
    detail.value = await store.updateDosar(id.value, { title: edit.title, nextStep: edit.nextStep || null, deadlineAt: edit.deadlineAt || null, deadlineLabel: edit.deadlineLabel || null, notes: edit.notes || null } as any)
    editOpen.value = false
    toast.add({ title: $t('dosare.saved'), color: 'success' })
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('common.error'), color: 'error' })
  } finally {
    saving.value = false
  }
}
async function setStatus(status: 'active' | 'closed') {
  try {
    detail.value = await store.updateDosar(id.value, { status } as any)
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('common.error'), color: 'error' })
  }
}
async function remove() {
  if (!confirm($t('dosare.deleteConfirm'))) return
  try {
    await store.deleteDosar(id.value)
    toast.add({ title: $t('dosare.deleted'), color: 'success' })
    router.push('/dosare')
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('common.error'), color: 'error' })
  }
}

// ── Attach ─────────────────────────────────────────────────────────
const attachOpen = ref(false)
const attachKind = ref<'declaration' | 'request' | 'document'>('declaration')
const attachItems = ref<Array<{ label: string, value: string }>>([])
const attachValue = ref<string | undefined>(undefined)
const attachLoading = ref(false)

async function openAttach(kind: 'declaration' | 'request' | 'document') {
  attachKind.value = kind
  attachValue.value = undefined
  attachOpen.value = true
  attachLoading.value = true
  try {
    if (kind === 'declaration') {
      const res = await get<{ data: TaxDeclaration[] }>('/v1/declarations', { limit: 100 })
      attachItems.value = res.data.filter(d => !d.dosarId).map(d => ({ label: `${d.type.toUpperCase()} ${d.periodType === 'annual' ? d.year : `${String(d.month).padStart(2, '0')}.${d.year}`} · ${$t(`dosare.declStatus.${d.status}`)}`, value: d.id }))
    } else if (kind === 'request') {
      const res = await get<{ data: SpvRequest[] }>('/v1/spv/requests', { limit: 100 })
      attachItems.value = res.data.filter(r => !r.dosarId).map(r => ({ label: `${r.title ?? r.requestType} · ${formatDate(r.createdAt)}`, value: r.id }))
    } else {
      const res = await get<{ data: SpvDocument[] }>('/v1/spv/documents', { limit: 100 })
      attachItems.value = res.data.filter(d => !d.dosarId).map(d => ({ label: `${d.messageType} · ${formatDate(d.anafCreatedAt)} · ${(d.summary ?? d.details ?? '').slice(0, 60)}`, value: d.id }))
    }
  } finally {
    attachLoading.value = false
  }
}
async function submitAttach() {
  if (!attachValue.value) return
  const ref = attachKind.value === 'declaration' ? { declarationId: attachValue.value } : attachKind.value === 'request' ? { requestId: attachValue.value } : { documentId: attachValue.value }
  try {
    detail.value = await store.attach(id.value, ref)
    attachOpen.value = false
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('common.error'), color: 'error' })
  }
}
async function detachItem(ref: { declarationId?: string, requestId?: string, documentId?: string }) {
  try {
    detail.value = await store.detach(id.value, ref)
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('common.error'), color: 'error' })
  }
}

// ── D212 from the rental contracts ─────────────────────────────────
const building = ref(false)
const prefillNotes = ref<string[]>([])
async function buildD212() {
  building.value = true
  try {
    const prefill = await store.d212Prefill(id.value)
    prefillNotes.value = prefill.notes
    const decl = await store.createD212(id.value, prefill.input)
    toast.add({ title: $t('dosare.d212.created'), color: 'success' })
    await load()
    router.push(`/declarations/${decl.id}`)
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? e?.message ?? $t('common.error'), color: 'error' })
  } finally {
    building.value = false
  }
}

// ── Documents from the dosar ───────────────────────────────────────
const docOpen = ref(false)
const docType = ref<'conventie_incetare_inchiriere' | 'declaratie_incetare_contract'>('conventie_incetare_inchiriere')
const docFields = ref<Record<string, any>>({})
const docLoading = ref(false)
const docRendering = ref(false)
async function openDocument(type: 'conventie_incetare_inchiriere' | 'declaratie_incetare_contract') {
  docType.value = type
  docOpen.value = true
  docLoading.value = true
  try {
    const pre = await store.documentPrefill(id.value, type)
    docFields.value = pre.fields
    docFields.value.locatar ??= {}
    docFields.value.contract ??= {}
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('common.error'), color: 'error' })
    docOpen.value = false
  } finally {
    docLoading.value = false
  }
}
async function renderDocument() {
  docRendering.value = true
  try {
    const res = await store.documentRender(id.value, docType.value, docFields.value)
    const bytes = Uint8Array.from(atob(res.pdfBase64), c => c.charCodeAt(0))
    const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }))
    const a = document.createElement('a')
    a.href = url
    a.download = res.fileName
    a.click()
    URL.revokeObjectURL(url)
    docOpen.value = false
    toast.add({ title: $t('dosare.documents.generated'), color: 'success' })
    await load()
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('common.error'), color: 'error' })
  } finally {
    docRendering.value = false
  }
}

// ── Presentation ───────────────────────────────────────────────────
const statusColor: Record<string, 'success' | 'warning' | 'neutral'> = { active: 'success', attention: 'warning', closed: 'neutral' }
const declStatusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral' | 'primary'> = { draft: 'neutral', validated: 'primary', submitted: 'primary', processing: 'primary', accepted: 'success', rejected: 'error', error: 'error' }
const reqStatusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral' | 'primary'> = { pending: 'neutral', requested: 'primary', answered: 'success', error: 'error' }
const kindIcon: Record<string, string> = { declaration: 'i-lucide-file-badge', request: 'i-lucide-send', document: 'i-lucide-inbox' }

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString(locale.value === 'ro' ? 'ro-RO' : 'en-GB', { dateStyle: 'medium' })
}
function deadlineText(): string | null {
  const d = detail.value?.dosar
  if (!d || d.daysToDeadline === null) return null
  if (d.daysToDeadline === 0) return $t('dosare.deadlineToday')
  if (d.daysToDeadline < 0) return $t('dosare.deadlineOverdue', { days: -d.daysToDeadline })
  return $t('dosare.deadlineIn', { days: d.daysToDeadline })
}
const subjectEntries = computed(() => {
  const s = detail.value?.dosar.subject ?? {}
  const labels: Record<string, string> = { numar: $t('dosare.form.contractNumber'), data: $t('dosare.form.contractDate'), adresa: $t('dosare.form.address'), chirias: $t('dosare.form.tenant'), chiriasCif: $t('dosare.form.tenantCif'), chirie: $t('dosare.form.rent'), moneda: $t('dosare.form.currency'), deLa: $t('dosare.form.from'), panaLa: $t('dosare.form.until'), dataIncetare: $t('dosare.form.terminationDate'), an: $t('dosare.form.year') }
  return Object.entries(s).filter(([, v]) => v !== null && v !== '' && v !== undefined).map(([k, v]) => ({ key: k, label: labels[k] ?? k, value: String(v) }))
})
function summaryFor(doc: SpvDocument): string {
  return (locale.value === 'ro' ? (doc.summary ?? doc.summaryEn) : (doc.summaryEn ?? doc.summary)) ?? doc.details ?? ''
}
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="detail?.dosar.title ?? $t('dosare.title')">
        <template #leading>
          <UButton icon="i-lucide-arrow-left" color="neutral" variant="ghost" to="/dosare" />
        </template>
        <template #right>
          <UButton v-if="detail" icon="i-lucide-pencil" color="neutral" variant="ghost" @click="openEdit">{{ $t('common.edit') }}</UButton>
          <UButton v-if="detail?.dosar.status !== 'closed'" icon="i-lucide-check" color="neutral" variant="outline" @click="setStatus('closed')">{{ $t('dosare.close') }}</UButton>
          <UButton v-else icon="i-lucide-rotate-ccw" color="neutral" variant="outline" @click="setStatus('active')">{{ $t('dosare.reopen') }}</UButton>
          <UButton icon="i-lucide-trash-2" color="error" variant="ghost" @click="remove" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="loading" class="p-4 space-y-3"><USkeleton class="h-20 w-full" /><USkeleton class="h-40 w-full" /></div>
      <div v-else-if="detail" class="p-4 space-y-4">
        <!-- Header card -->
        <UCard>
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1 min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <UBadge color="neutral" variant="outline" size="xs">{{ $t(`dosare.typeSingular.${detail.dosar.type}`) }}</UBadge>
                <UBadge :color="statusColor[detail.dosar.status] ?? 'neutral'" variant="subtle" size="xs">{{ $t(`dosare.statuses.${detail.dosar.status}`) }}</UBadge>
                <UBadge v-if="deadlineText()" :color="(detail.dosar.daysToDeadline ?? 99) <= 1 ? 'error' : (detail.dosar.daysToDeadline ?? 99) <= 14 ? 'warning' : 'neutral'" variant="outline" size="xs">
                  {{ detail.dosar.deadlineLabel ?? $t('dosare.deadline') }} · {{ formatDate(detail.dosar.deadlineAt) }} · {{ deadlineText() }}
                </UBadge>
              </div>
              <dl v-if="subjectEntries.length" class="grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-1 text-sm pt-1">
                <template v-for="e in subjectEntries" :key="e.key">
                  <div class="col-span-1"><dt class="text-xs text-muted">{{ e.label }}</dt><dd class="font-medium truncate">{{ e.value }}</dd></div>
                </template>
              </dl>
              <p v-if="detail.dosar.nextStep" class="text-sm pt-1"><span class="text-muted">{{ $t('dosare.nextStep') }}:</span> {{ detail.dosar.nextStep }}</p>
            </div>
            <div class="text-xs text-muted text-right">{{ $t('dosare.counts', detail.counts) }}</div>
          </div>
          <div v-if="detail.dosar.type === 'annual_return'" class="mt-3 flex flex-wrap gap-2 items-center">
            <UButton icon="i-lucide-wand-sparkles" :loading="building" @click="buildD212">{{ building ? $t('dosare.d212.building') : $t('dosare.d212.build') }}</UButton>
            <span class="text-xs text-muted">{{ $t('dosare.d212.deadlineHint') }}</span>
          </div>
          <div v-if="detail.dosar.type === 'rental_contract'" class="mt-3 flex flex-wrap gap-2 items-center">
            <span class="text-xs text-muted">{{ $t('dosare.documents.title') }}:</span>
            <UButton size="xs" variant="outline" color="neutral" icon="i-lucide-file-signature" @click="openDocument('conventie_incetare_inchiriere')">{{ $t('dosare.documents.conventie') }}</UButton>
            <UButton size="xs" variant="outline" color="neutral" icon="i-lucide-file-check" @click="openDocument('declaratie_incetare_contract')">{{ $t('dosare.documents.declaratie') }}</UButton>
          </div>
          <UAlert v-if="prefillNotes.length" class="mt-3" color="warning" variant="soft" icon="i-lucide-alert-triangle" :title="$t('dosare.d212.prefillNotes')">
            <template #description><ul class="list-disc pl-4"><li v-for="n in prefillNotes" :key="n">{{ n }}</li></ul></template>
          </UAlert>
        </UCard>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <!-- Timeline -->
          <UCard class="lg:col-span-2">
            <template #header><span class="font-semibold text-sm">{{ $t('dosare.timeline') }}</span></template>
            <p v-if="!detail.timeline.length" class="text-sm text-muted">{{ $t('dosare.noEvents') }}</p>
            <ol v-else class="relative border-s border-default ms-2 space-y-4">
              <li v-for="ev in detail.timeline" :key="ev.kind + ev.id + ev.date" class="ms-4">
                <span class="absolute -start-2 mt-1 size-4 rounded-full bg-elevated border border-default flex items-center justify-center"><UIcon :name="kindIcon[ev.kind]" class="size-2.5" /></span>
                <div class="text-xs text-muted">{{ formatDate(ev.date) }}</div>
                <div class="text-sm">{{ ev.title }}</div>
              </li>
            </ol>
          </UCard>

          <!-- Notes -->
          <UCard>
            <template #header><span class="font-semibold text-sm">{{ $t('dosare.notes') }}</span></template>
            <p class="text-sm whitespace-pre-line" :class="!detail.dosar.notes ? 'text-muted' : ''">{{ detail.dosar.notes || '—' }}</p>
          </UCard>
        </div>

        <!-- Declarations -->
        <UCard>
          <template #header>
            <div class="flex items-center justify-between">
              <span class="font-semibold text-sm">{{ $t('dosare.declarations') }} ({{ detail.declarations.length }})</span>
              <UButton size="xs" variant="soft" icon="i-lucide-link" @click="openAttach('declaration')">{{ $t('dosare.attachDeclaration') }}</UButton>
            </div>
          </template>
          <ul v-if="detail.declarations.length" class="divide-y divide-default -my-2">
            <li v-for="d in detail.declarations" :key="d.id" class="py-2 flex items-center gap-3">
              <NuxtLink :to="`/declarations/${d.id}`" class="flex-1 min-w-0 hover:underline">
                <span class="font-medium">{{ d.type.toUpperCase() }} {{ d.periodType === 'annual' ? d.year : `${String(d.month).padStart(2, '0')}.${d.year}` }}</span>
                <span v-if="d.anafUploadId" class="text-xs text-muted"> · index {{ d.anafUploadId }}</span>
              </NuxtLink>
              <UBadge :color="declStatusColor[d.status] ?? 'neutral'" variant="subtle" size="xs">{{ $t(`dosare.declStatus.${d.status}`) }}</UBadge>
              <UButton icon="i-lucide-unlink" size="xs" color="neutral" variant="ghost" :aria-label="$t('dosare.detach')" @click="detachItem({ declarationId: d.id })" />
            </li>
          </ul>
          <p v-else class="text-sm text-muted">—</p>
        </UCard>

        <!-- Requests -->
        <UCard>
          <template #header>
            <div class="flex items-center justify-between">
              <span class="font-semibold text-sm">{{ $t('dosare.requests') }} ({{ detail.requests.length }})</span>
              <UButton size="xs" variant="soft" icon="i-lucide-link" @click="openAttach('request')">{{ $t('dosare.attachRequest') }}</UButton>
            </div>
          </template>
          <ul v-if="detail.requests.length" class="divide-y divide-default -my-2">
            <li v-for="r in detail.requests" :key="r.id" class="py-2 flex items-center gap-3">
              <div class="flex-1 min-w-0">
                <span class="font-medium">{{ r.title ?? r.requestType }}</span>
                <span class="text-xs text-muted"> · {{ formatDate(r.createdAt) }}<span v-if="r.anafRequestId"> · id {{ r.anafRequestId }}</span></span>
              </div>
              <UBadge :color="reqStatusColor[r.status] ?? 'neutral'" variant="subtle" size="xs">{{ $t(`spv.requests.status.${r.status}`) }}</UBadge>
              <UButton icon="i-lucide-unlink" size="xs" color="neutral" variant="ghost" :aria-label="$t('dosare.detach')" @click="detachItem({ requestId: r.id })" />
            </li>
          </ul>
          <p v-else class="text-sm text-muted">—</p>
        </UCard>

        <!-- ANAF messages -->
        <UCard>
          <template #header>
            <div class="flex items-center justify-between">
              <span class="font-semibold text-sm">{{ $t('dosare.documents') }} ({{ detail.documents.length }})</span>
              <UButton size="xs" variant="soft" icon="i-lucide-link" @click="openAttach('document')">{{ $t('dosare.attachDocument') }}</UButton>
            </div>
          </template>
          <ul v-if="detail.documents.length" class="divide-y divide-default -my-2">
            <li v-for="doc in detail.documents" :key="doc.id" class="py-2 flex items-start gap-3">
              <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-medium">{{ doc.messageType }}</span>
                  <UBadge color="neutral" variant="outline" size="xs">{{ doc.categoryLabel }}</UBadge>
                  <span class="text-xs text-muted">{{ formatDate(doc.anafCreatedAt) }}</span>
                </div>
                <p class="text-sm text-muted mt-0.5">{{ summaryFor(doc) }}</p>
              </div>
              <UButton icon="i-lucide-unlink" size="xs" color="neutral" variant="ghost" :aria-label="$t('dosare.detach')" @click="detachItem({ documentId: doc.id })" />
            </li>
          </ul>
          <p v-else class="text-sm text-muted">—</p>
        </UCard>
      </div>

      <!-- Edit modal -->
      <UModal v-model:open="editOpen" :title="$t('common.edit')">
        <template #body>
          <form class="space-y-3" @submit.prevent="saveEdit">
            <UFormField :label="$t('dosare.form.title')" required><UInput v-model="edit.title" class="w-full" /></UFormField>
            <UFormField :label="$t('dosare.form.nextStep')"><UInput v-model="edit.nextStep" class="w-full" /></UFormField>
            <div class="grid grid-cols-2 gap-3">
              <UFormField :label="$t('dosare.form.deadlineAt')"><UInput v-model="edit.deadlineAt" type="date" class="w-full" /></UFormField>
              <UFormField :label="$t('dosare.form.deadlineLabel')"><UInput v-model="edit.deadlineLabel" class="w-full" /></UFormField>
            </div>
            <UFormField :label="$t('dosare.form.notes')"><UTextarea v-model="edit.notes" :rows="4" class="w-full" /></UFormField>
            <div class="flex justify-end gap-2 pt-2">
              <UButton color="neutral" variant="ghost" @click="editOpen = false">{{ $t('common.cancel') }}</UButton>
              <UButton type="submit" :loading="saving">{{ $t('common.save') }}</UButton>
            </div>
          </form>
        </template>
      </UModal>

      <!-- Document modal -->
      <UModal v-model:open="docOpen" :title="$t(docType === 'conventie_incetare_inchiriere' ? 'dosare.documents.conventie' : 'dosare.documents.declaratie')">
        <template #body>
          <div v-if="docLoading" class="space-y-2"><USkeleton class="h-8 w-full" /><USkeleton class="h-8 w-full" /></div>
          <form v-else class="space-y-3" @submit.prevent="renderDocument">
            <p class="text-xs text-muted">{{ $t('dosare.documents.review') }}</p>
            <div class="grid grid-cols-2 gap-3">
              <UFormField :label="$t('dosare.form.tenant')" required><UInput v-model="docFields.locatar.nume" class="w-full" /></UFormField>
              <UFormField :label="$t('dosare.documents.tenantCnp')"><UInput v-model="docFields.locatar.cnp" class="w-full" /></UFormField>
            </div>
            <UFormField :label="$t('dosare.documents.tenantAddress')" :required="docType === 'conventie_incetare_inchiriere'"><UInput v-model="docFields.locatar.adresa" class="w-full" /></UFormField>
            <div class="grid grid-cols-2 gap-3">
              <UFormField :label="$t('dosare.form.contractNumber')" required><UInput v-model="docFields.contract.numar" class="w-full" /></UFormField>
              <UFormField :label="$t('dosare.form.contractDate')" required><UInput v-model="docFields.contract.data" placeholder="zz.ll.aaaa" class="w-full" /></UFormField>
              <UFormField :label="$t('dosare.form.from')" :required="docType === 'declaratie_incetare_contract'"><UInput v-model="docFields.contract.data_inceput" placeholder="zz.ll.aaaa" class="w-full" /></UFormField>
              <UFormField :label="$t('dosare.form.until')" :required="docType === 'declaratie_incetare_contract'"><UInput v-model="docFields.contract.data_sfarsit" placeholder="zz.ll.aaaa" class="w-full" /></UFormField>
            </div>
            <UFormField :label="$t('dosare.form.address')" required><UInput v-model="docFields.contract.adresa_imobil" class="w-full" /></UFormField>
            <UFormField :label="$t('dosare.documents.terminationDate')" required><UInput v-model="docFields.data_incetare" placeholder="zz.ll.aaaa" class="w-full" /></UFormField>
            <UFormField v-if="docType === 'declaratie_incetare_contract'" :label="$t('dosare.documents.reason')"><UInput v-model="docFields.motiv" class="w-full" /></UFormField>
            <div class="flex justify-end gap-2 pt-2">
              <UButton color="neutral" variant="ghost" @click="docOpen = false">{{ $t('common.cancel') }}</UButton>
              <UButton type="submit" :loading="docRendering" icon="i-lucide-download">{{ $t('dosare.documents.generate') }}</UButton>
            </div>
          </form>
        </template>
      </UModal>

      <!-- Attach modal -->
      <UModal v-model:open="attachOpen" :title="$t(attachKind === 'declaration' ? 'dosare.attachDeclaration' : attachKind === 'request' ? 'dosare.attachRequest' : 'dosare.attachDocument')">
        <template #body>
          <div class="space-y-3">
            <USelectMenu v-model="attachValue" :items="attachItems" value-key="value" :loading="attachLoading" class="w-full" />
            <div class="flex justify-end gap-2">
              <UButton color="neutral" variant="ghost" @click="attachOpen = false">{{ $t('common.cancel') }}</UButton>
              <UButton :disabled="!attachValue" @click="submitAttach">{{ $t('dosare.attach') }}</UButton>
            </div>
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
