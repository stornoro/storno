<script setup lang="ts">
import type { Dosar, DosarActionItem, DosarType } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t, locale } = useI18n()
const store = useDosareStore()
const toast = useToast()
const router = useRouter()
const { describeAgentError } = useAgentError()

useHead({ title: $t('dosare.title') })

const TYPES: DosarType[] = ['rental_contract', 'annual_return', 'periodic', 'fiscal_status', 'generic']

onMounted(async () => {
  await Promise.all([store.fetchDosare(), store.fetchActions(), store.fetchStats()])
})

// ── Create ─────────────────────────────────────────────────────────
const createOpen = ref(false)
const creating = ref(false)
const form = reactive({
  type: 'rental_contract' as DosarType,
  title: '',
  numar: '', data: '', adresa: '', chirias: '', chiriasCif: '', chirie: '', moneda: 'RON', deLa: '', panaLa: '', dataIncetare: '',
  an: String(new Date().getFullYear()),
  deadlineAt: '', deadlineLabel: '', nextStep: '', notes: '',
})
const typeItems = computed(() => TYPES.map(t => ({ label: $t(`dosare.typeSingular.${t}`), value: t })))
const currencyItems = ['RON', 'EUR', 'USD', 'GBP']

function openCreate(type: DosarType = 'rental_contract') {
  form.type = type
  createOpen.value = true
}

async function submitCreate() {
  creating.value = true
  try {
    const subject: Record<string, any> = {}
    if (form.type === 'rental_contract') {
      Object.assign(subject, { numar: form.numar, data: form.data, adresa: form.adresa, chirias: form.chirias, chiriasCif: form.chiriasCif, chirie: form.chirie ? Number(form.chirie) : null, moneda: form.moneda, deLa: form.deLa, panaLa: form.panaLa, dataIncetare: form.dataIncetare || undefined })
    } else if (form.type === 'annual_return') {
      subject.an = Number(form.an)
    }
    const payload: Record<string, any> = { type: form.type, title: form.title || undefined, subject }
    if (form.deadlineAt) payload.deadlineAt = form.deadlineAt
    if (form.deadlineLabel) payload.deadlineLabel = form.deadlineLabel
    if (form.nextStep) payload.nextStep = form.nextStep
    if (form.notes) payload.notes = form.notes
    const detail = form.type === 'annual_return' && !form.title
      ? await store.ensureAnnualReturn(Number(form.an))
      : await store.createDosar(payload as any)
    createOpen.value = false
    toast.add({ title: $t('dosare.created'), color: 'success' })
    router.push(`/dosare/${detail.dosar.id}`)
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? e?.message ?? $t('common.error'), color: 'error' })
  } finally {
    creating.value = false
  }
}

// ── Presentation ───────────────────────────────────────────────────
const statusColor: Record<string, 'success' | 'warning' | 'neutral'> = { active: 'success', attention: 'warning', closed: 'neutral' }
const severityColor: Record<string, 'error' | 'warning' | 'primary' | 'neutral'> = { critical: 'error', high: 'warning', normal: 'primary', low: 'neutral' }

function deadlineText(d: Dosar): string | null {
  if (d.daysToDeadline === null) return null
  if (d.daysToDeadline === 0) return $t('dosare.deadlineToday')
  if (d.daysToDeadline < 0) return $t('dosare.deadlineOverdue', { days: -d.daysToDeadline })
  return $t('dosare.deadlineIn', { days: d.daysToDeadline })
}

function deadlineColor(d: Dosar): 'error' | 'warning' | 'neutral' {
  if (d.daysToDeadline === null) return 'neutral'
  return d.daysToDeadline <= 1 ? 'error' : d.daysToDeadline <= 14 ? 'warning' : 'neutral'
}

function subjectLine(d: Dosar): string {
  const s = d.subject ?? {}
  if (d.type === 'rental_contract') {
    return [s.chirias, s.chirie ? `${s.chirie} ${s.moneda ?? 'RON'}/luna` : null, s.deLa ? `${s.deLa}${s.panaLa ? ` → ${s.panaLa}` : ''}` : null].filter(Boolean).join(' · ')
  }
  if (d.type === 'annual_return') return $t('dosare.d212.deadlineHint')
  return d.nextStep ?? ''
}

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString(locale.value === 'ro' ? 'ro-RO' : 'en-GB', { dateStyle: 'medium' })
}

function openAction(item: DosarActionItem) {
  if (item.dosarId) return router.push(`/dosare/${item.dosarId}`)
  if (item.kind === 'declaration') return router.push(`/declarations/${item.id}`)
  if (item.kind === 'document' || item.kind === 'request') return router.push('/spv')
}

const groups = computed(() => TYPES.map(t => ({ type: t, items: store.byType[t] ?? [] })).filter(g => g.items.length > 0))
const nextYear = computed(() => {
  const now = new Date()
  return now <= new Date(now.getFullYear(), 4, 25) ? now.getFullYear() : now.getFullYear() + 1
})
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('dosare.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-calendar-check" color="neutral" variant="outline" @click="openCreate('annual_return')">
            {{ $t('dosare.annualReturn') }}
          </UButton>
          <UButton icon="i-lucide-folder-plus" @click="openCreate('rental_contract')">
            {{ $t('dosare.new') }}
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="p-4 space-y-6">
        <p class="text-sm text-muted">{{ $t('dosare.subtitle') }}</p>

        <!-- Action row -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
          <UCard v-for="col in (['todo', 'inProgress', 'answers'] as const)" :key="col">
            <template #header>
              <div class="flex items-center justify-between">
                <span class="font-semibold text-sm">{{ $t(`dosare.${col}`) }}</span>
                <UBadge :color="col === 'todo' && (store.actions?.todo.length ?? 0) > 0 ? 'warning' : 'neutral'" variant="subtle" size="xs">{{ store.actions?.[col].length ?? 0 }}</UBadge>
              </div>
            </template>
            <div v-if="store.actionsLoading" class="space-y-2">
              <USkeleton class="h-4 w-3/4" /><USkeleton class="h-4 w-1/2" />
            </div>
            <p v-else-if="!store.actions?.[col].length" class="text-xs text-muted">
              {{ $t(col === 'todo' ? 'dosare.nothingTodo' : col === 'inProgress' ? 'dosare.nothingInProgress' : 'dosare.noAnswers') }}
            </p>
            <ul v-else class="divide-y divide-default -my-2">
              <li v-for="item in store.actions[col].slice(0, 6)" :key="item.kind + item.id" class="py-2 flex items-start gap-2 cursor-pointer hover:bg-elevated/50 -mx-2 px-2 rounded" @click="openAction(item)">
                <UBadge :color="severityColor[item.severity] ?? 'neutral'" variant="subtle" size="xs" class="mt-0.5 shrink-0">{{ item.kind === 'deadline' ? $t('dosare.deadline') : item.kind === 'expiry' ? $t('dosare.expiry') : item.kind }}</UBadge>
                <div class="min-w-0">
                  <div class="text-sm font-medium truncate">{{ item.title }}</div>
                  <div class="text-xs text-muted truncate">{{ item.subtitle }}<span v-if="item.dosarTitle"> · {{ item.dosarTitle }}</span></div>
                </div>
              </li>
            </ul>
          </UCard>
        </div>

        <UAlert v-if="store.error" color="error" variant="soft" icon="i-lucide-alert-circle" :title="store.error" />

        <!-- Rental portfolio -->
        <UCard v-if="store.stats && store.stats.properties.length">
          <template #header><span class="font-semibold text-sm">{{ $t('dosare.stats.title') }}</span></template>
          <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div><div class="text-xs text-muted">{{ $t('dosare.stats.activeContracts') }}</div><div class="text-2xl font-bold">{{ store.stats.activeContracts }}</div></div>
            <div><div class="text-xs text-muted">{{ $t('dosare.stats.expiring') }}</div><div class="text-2xl font-bold" :class="store.stats.expiringWithin60Days > 0 ? 'text-warning' : ''">{{ store.stats.expiringWithin60Days }}</div></div>
            <div><div class="text-xs text-muted">{{ $t('dosare.stats.monthlyRent') }}</div><div class="text-2xl font-bold">{{ Object.entries(store.stats.monthlyRent).map(([c, v]) => `${v.toLocaleString('ro-RO')} ${c}`).join(' + ') || '—' }}</div></div>
            <div>
              <div class="text-xs text-muted">{{ $t('dosare.stats.expectedVsDeclared') }}</div>
              <ul class="text-sm mt-1 space-y-0.5">
                <li v-for="(byCur, year) in store.stats.expectedGrossByYear" :key="year" class="flex items-center gap-2">
                  <span class="text-muted w-10">{{ year }}</span>
                  <span>{{ Object.entries(byCur).map(([c, v]) => `${Number(v).toLocaleString('ro-RO')} ${c}`).join(' + ') }} {{ $t('dosare.stats.expected') }}</span>
                  <UBadge v-if="store.stats.declaredByIncomeYear[year]" :color="store.stats.declaredByIncomeYear[year].status === 'accepted' ? 'success' : 'primary'" variant="subtle" size="xs">{{ store.stats.declaredByIncomeYear[year].venitBrut.toLocaleString('ro-RO') }} RON {{ $t('dosare.stats.declared') }} · {{ $t(`dosare.declStatus.${store.stats.declaredByIncomeYear[year].status}`) }}</UBadge>
                  <UBadge v-else-if="Number(year) < new Date().getFullYear()" color="warning" variant="subtle" size="xs">{{ $t('dosare.stats.notDeclared') }}</UBadge>
                </li>
              </ul>
            </div>
          </div>
        </UCard>

        <!-- Groups -->
        <div v-if="store.loading" class="space-y-3">
          <USkeleton class="h-16 w-full" /><USkeleton class="h-16 w-full" />
        </div>
        <UCard v-else-if="!store.items.length">
          <div class="text-center py-8 space-y-3">
            <UIcon name="i-lucide-folder-open" class="text-4xl text-muted" />
            <p class="text-sm text-muted max-w-md mx-auto">{{ $t('dosare.empty') }}</p>
            <div class="flex justify-center gap-2">
              <UButton icon="i-lucide-folder-plus" @click="openCreate('rental_contract')">{{ $t('dosare.new') }}</UButton>
              <UButton icon="i-lucide-calendar-check" variant="outline" color="neutral" @click="openCreate('annual_return')">{{ $t('dosare.ensureAnnual', { year: nextYear }) }}</UButton>
            </div>
          </div>
        </UCard>
        <div v-for="g in groups" v-else :key="g.type" class="space-y-2">
          <h2 class="text-xs font-semibold uppercase tracking-wide text-muted">{{ $t(`dosare.types.${g.type}`) }} ({{ g.items.length }})</h2>
          <UCard :ui="{ body: 'p-0 sm:p-0' }">
            <ul class="divide-y divide-default">
              <li v-for="d in g.items" :key="d.id" class="p-3 sm:p-4 flex items-start gap-3 cursor-pointer hover:bg-elevated/50" @click="router.push(`/dosare/${d.id}`)">
                <UIcon :name="g.type === 'rental_contract' ? 'i-lucide-house' : g.type === 'annual_return' ? 'i-lucide-calendar-check' : g.type === 'fiscal_status' ? 'i-lucide-landmark' : 'i-lucide-folder'" class="text-xl text-muted mt-0.5 shrink-0" />
                <div class="flex-1 min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">{{ d.title }}</span>
                    <UBadge :color="statusColor[d.status] ?? 'neutral'" variant="subtle" size="xs">{{ $t(`dosare.statuses.${d.status}`) }}</UBadge>
                    <UBadge v-if="deadlineText(d)" :color="deadlineColor(d)" variant="outline" size="xs">{{ d.deadlineLabel ?? $t('dosare.deadline') }} · {{ deadlineText(d) }}</UBadge>
                  </div>
                  <p class="text-xs text-muted mt-0.5 truncate">{{ subjectLine(d) }}</p>
                  <p v-if="d.nextStep && d.type !== 'generic'" class="text-xs mt-1"><span class="text-muted">{{ $t('dosare.nextStep') }}:</span> {{ d.nextStep }}</p>
                </div>
                <div class="text-xs text-muted text-right shrink-0 hidden sm:block">
                  {{ $t('dosare.counts', { declarations: store.counts[d.id]?.declarations ?? 0, requests: store.counts[d.id]?.requests ?? 0, documents: store.counts[d.id]?.documents ?? 0 }) }}
                  <div>{{ formatDate(d.updatedAt) }}</div>
                </div>
              </li>
            </ul>
          </UCard>
        </div>
      </div>

      <!-- Create modal -->
      <UModal v-model:open="createOpen" :title="$t('dosare.new')">
        <template #body>
          <form class="space-y-3" @submit.prevent="submitCreate">
            <UFormField :label="$t('dosare.form.type')" required>
              <USelectMenu v-model="form.type" :items="typeItems" value-key="value" class="w-full" />
            </UFormField>
            <template v-if="form.type === 'rental_contract'">
              <div class="grid grid-cols-2 gap-3">
                <UFormField :label="$t('dosare.form.contractNumber')" required><UInput v-model="form.numar" class="w-full" /></UFormField>
                <UFormField :label="$t('dosare.form.contractDate')" required><UInput v-model="form.data" type="date" class="w-full" /></UFormField>
              </div>
              <UFormField :label="$t('dosare.form.address')" required><UInput v-model="form.adresa" class="w-full" /></UFormField>
              <div class="grid grid-cols-2 gap-3">
                <UFormField :label="$t('dosare.form.tenant')"><UInput v-model="form.chirias" class="w-full" /></UFormField>
                <UFormField :label="$t('dosare.form.tenantCif')"><UInput v-model="form.chiriasCif" class="w-full" /></UFormField>
                <UFormField :label="$t('dosare.form.rent')"><UInput v-model="form.chirie" type="number" min="0" class="w-full" /></UFormField>
                <UFormField :label="$t('dosare.form.currency')"><USelectMenu v-model="form.moneda" :items="currencyItems" class="w-full" /></UFormField>
                <UFormField :label="$t('dosare.form.from')"><UInput v-model="form.deLa" type="date" class="w-full" /></UFormField>
                <UFormField :label="$t('dosare.form.until')"><UInput v-model="form.panaLa" type="date" class="w-full" /></UFormField>
                <UFormField :label="$t('dosare.form.terminationDate')"><UInput v-model="form.dataIncetare" type="date" class="w-full" /></UFormField>
              </div>
            </template>
            <UFormField v-else-if="form.type === 'annual_return'" :label="$t('dosare.form.year')" required :hint="$t('dosare.d212.deadlineHint')">
              <UInput v-model="form.an" type="number" min="2025" max="2100" class="w-full" />
            </UFormField>
            <template v-else>
              <UFormField :label="$t('dosare.form.title')" required><UInput v-model="form.title" class="w-full" /></UFormField>
              <div class="grid grid-cols-2 gap-3">
                <UFormField :label="$t('dosare.form.deadlineAt')"><UInput v-model="form.deadlineAt" type="date" class="w-full" /></UFormField>
                <UFormField :label="$t('dosare.form.deadlineLabel')"><UInput v-model="form.deadlineLabel" class="w-full" /></UFormField>
              </div>
            </template>
            <UFormField v-if="form.type !== 'generic'" :label="$t('dosare.form.title')" :hint="$t('dosare.form.titleHint')"><UInput v-model="form.title" class="w-full" /></UFormField>
            <UFormField :label="$t('dosare.form.nextStep')"><UInput v-model="form.nextStep" class="w-full" /></UFormField>
            <div class="flex justify-end gap-2 pt-2">
              <UButton color="neutral" variant="ghost" @click="createOpen = false">{{ $t('common.cancel') }}</UButton>
              <UButton type="submit" :loading="creating" :disabled="form.type === 'rental_contract' && (!form.numar || !form.data || !form.adresa)">{{ $t('common.save') }}</UButton>
            </div>
          </form>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
