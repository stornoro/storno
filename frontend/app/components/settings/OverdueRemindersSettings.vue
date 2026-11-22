<script setup lang="ts">
import type { Company } from '~/types'

interface StepRow {
  key: number
  days: number | string | null
  subject: string
  body: string
  open: boolean
}

const props = defineProps<{
  company: Company
}>()

const { t: $t } = useI18n()
const companyStore = useCompanyStore()
const toast = useToast()

const MAX_STEPS = 5

// Built-in texts the backend uses when a step has no subject or body of its own.
const DEFAULT_SUBJECT = 'Reamintire de plata: factura [[invoice_number]]'
const DEFAULT_BODY = [
  'Buna ziua,',
  '',
  'Factura [[invoice_number]] din [[issue_date]], cu scadenta la [[due_date]], figureaza neachitata. Restul de plata este [[balance]] [[currency]], la [[days_overdue]] zile de la scadenta.',
  '',
  'Daca ati efectuat deja plata, va rugam sa ignorati acest mesaj.',
  '',
  'Cu stima,',
  '[[company_name]]',
].join('\n')

const variables = [
  'client_name', 'invoice_number', 'total', 'balance', 'currency',
  'due_date', 'issue_date', 'days_overdue', 'company_name', 'client_outstanding',
].map(v => `[[${v}]]`).join(' ')

let nextKey = 0
const enabled = ref(false)
const rows = ref<StepRow[]>([])
const saved = ref('')
const savingEnabled = ref(false)
const saving = ref(false)
const apiError = ref<string | null>(null)

function toRows(steps: Company['overdueReminderSteps']): StepRow[] {
  return (steps ?? []).map(s => ({
    key: nextKey++,
    days: s.days,
    subject: s.subject ?? '',
    body: s.body ?? '',
    open: false,
  }))
}

function serialize(list: StepRow[]): string {
  return JSON.stringify(list.map(r => [r.days, r.subject, r.body]))
}

function load(company: Company) {
  enabled.value = company.overdueRemindersEnabled ?? false
  rows.value = toRows(company.overdueReminderSteps?.length
    ? company.overdueReminderSteps
    : [{ days: 3, subject: null, body: null }, { days: 7, subject: null, body: null }, { days: 14, subject: null, body: null }])
  saved.value = serialize(rows.value)
  apiError.value = null
}

watch(() => props.company.id, () => load(props.company), { immediate: true })

const dirty = computed(() => serialize(rows.value) !== saved.value)

function daysOf(row: StepRow): number | null {
  if (row.days === null || row.days === '') return null
  const n = Number(row.days)
  return Number.isInteger(n) ? n : null
}

/** Same rules as the API, one message per row (or null). */
const rowErrors = computed<Array<string | null>>(() => {
  const seen = new Map<number, number>()
  return rows.value.map((row, i) => {
    const d = daysOf(row)
    if (d === null || d < 1 || d > 365) return $t('companies.overdueReminders.errDays')
    if (seen.has(d)) return $t('companies.overdueReminders.errDuplicate')
    seen.set(d, i)
    if (row.subject.length > 200 || /[\r\n]/.test(row.subject)) return $t('companies.overdueReminders.errSubject')
    if (row.body.length > 5000) return $t('companies.overdueReminders.errBody')
    return null
  })
})

const listError = computed(() => {
  if (rows.value.length < 1 || rows.value.length > MAX_STEPS) return $t('companies.overdueReminders.errCount')
  return null
})

const valid = computed(() => !listError.value && rowErrors.value.every(e => e === null))

function addStep() {
  if (rows.value.length >= MAX_STEPS) return
  const used = new Set(rows.value.map(r => daysOf(r)))
  const max = Math.max(0, ...rows.value.map(r => daysOf(r) ?? 0))
  let days = Math.min(max + 7, 365)
  while (used.has(days) && days > 1) days--
  rows.value.push({ key: nextKey++, days, subject: '', body: '', open: false })
}

function removeStep(index: number) {
  rows.value.splice(index, 1)
}

async function toggleEnabled(value: boolean) {
  savingEnabled.value = true
  const result = await companyStore.updateCompany(props.company.id, { overdueRemindersEnabled: value })
  savingEnabled.value = false
  if (result) {
    enabled.value = value
    toast.add({ title: $t('common.saved'), color: 'success' })
  }
  else {
    toast.add({ title: companyStore.error || $t('common.error'), color: 'error' })
  }
}

async function send(steps: unknown, resetting: boolean) {
  saving.value = true
  apiError.value = null
  const result = await companyStore.updateCompany(props.company.id, { overdueReminderSteps: steps } as any)
  saving.value = false
  if (result) {
    rows.value = toRows(result.overdueReminderSteps)
    saved.value = serialize(rows.value)
    toast.add({ title: resetting ? $t('companies.overdueReminders.resetDone') : $t('common.saved'), color: 'success' })
  }
  else {
    apiError.value = companyStore.error || $t('common.error')
  }
}

function save() {
  if (!valid.value) return
  send(rows.value.map(r => ({
    days: daysOf(r),
    subject: r.subject.trim() === '' ? null : r.subject,
    body: r.body.trim() === '' ? null : r.body,
  })), false)
}

function resetToDefault() {
  send(null, true)
}

// ── Backlog: overdue invoices that passed a step before the daily run could send it ──
interface BacklogEntry { clientId: string, clientName: string | null, invoices: number, balance: string, currency: string }
interface BacklogResult { clients: number, invoices: number, sent: number, failed: number, results: BacklogEntry[] }

const backlogOpen = ref(false)
const backlogChecking = ref(false)
const backlogSending = ref(false)
const backlogPreview = ref<BacklogResult | null>(null)

async function openBacklog() {
  backlogPreview.value = null
  backlogOpen.value = true
  backlogChecking.value = true
  try {
    const { post } = useApi()
    backlogPreview.value = await post<BacklogResult>(`/v1/companies/${props.company.id}/overdue-reminders/backlog`, { dryRun: true })
  }
  catch (err: any) {
    backlogOpen.value = false
    toast.add({ title: err?.data?.error || $t('common.error'), color: 'error' })
  }
  finally {
    backlogChecking.value = false
  }
}

async function sendBacklog() {
  backlogSending.value = true
  try {
    const { post } = useApi()
    const res = await post<BacklogResult>(`/v1/companies/${props.company.id}/overdue-reminders/backlog`, {})
    backlogOpen.value = false
    toast.add({
      title: $t('companies.overdueReminders.backlogDone', { sent: res.sent ?? 0, failed: res.failed ?? 0 }),
      color: (res.failed ?? 0) > 0 ? 'warning' : 'success',
    })
  }
  catch (err: any) {
    toast.add({ title: err?.data?.error || $t('common.error'), color: 'error' })
  }
  finally {
    backlogSending.value = false
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-3">
      <h4 class="text-sm font-semibold text-(--ui-text-muted) uppercase tracking-wide">{{ $t('companies.overdueReminders.title') }}</h4>
      <USwitch
        :model-value="enabled"
        :loading="savingEnabled"
        :disabled="savingEnabled"
        @update:model-value="toggleEnabled"
      />
    </div>
    <p class="text-xs text-(--ui-text-muted) italic mb-3">{{ $t('companies.overdueReminders.help') }}</p>

    <div v-if="enabled" class="space-y-2">
      <div
        v-for="(row, i) in rows"
        :key="row.key"
        class="rounded-lg border border-(--ui-border) px-3 py-2"
      >
        <div class="flex items-center gap-2">
          <UInput
            v-model="row.days"
            type="number"
            :min="1"
            :max="365"
            size="sm"
            class="w-20"
            :aria-label="$t('companies.overdueReminders.daysLabel')"
            :color="rowErrors[i] ? 'error' : undefined"
          />
          <span class="text-sm flex-1">{{ $t('companies.overdueReminders.daysAfterDue') }}</span>
          <UButton
            :icon="row.open ? 'i-lucide-chevron-up' : 'i-lucide-pencil'"
            size="xs"
            variant="ghost"
            color="neutral"
            :label="$t('companies.overdueReminders.customText')"
            :aria-expanded="row.open"
            @click="row.open = !row.open"
          />
          <UButton
            icon="i-lucide-trash-2"
            size="xs"
            variant="ghost"
            color="error"
            :disabled="rows.length <= 1"
            :aria-label="$t('companies.overdueReminders.removeStep')"
            @click="removeStep(i)"
          />
        </div>
        <p v-if="rowErrors[i]" class="text-xs text-error mt-1">{{ rowErrors[i] }}</p>
        <div v-if="row.open" class="space-y-2 mt-2">
          <UFormField :label="$t('companies.overdueReminders.subject')">
            <UInput v-model="row.subject" :placeholder="DEFAULT_SUBJECT" size="sm" class="w-full" />
          </UFormField>
          <UFormField :label="$t('companies.overdueReminders.body')">
            <UTextarea v-model="row.body" :placeholder="DEFAULT_BODY" :rows="6" size="sm" class="w-full" />
          </UFormField>
          <p class="text-xs text-(--ui-text-muted)">{{ $t('companies.overdueReminders.emptyIsDefault') }}</p>
        </div>
      </div>

      <p class="text-xs text-(--ui-text-muted)">
        {{ $t('companies.overdueReminders.variables') }} <code class="break-words">{{ variables }}</code>
      </p>
      <p v-if="listError" class="text-xs text-error">{{ listError }}</p>
      <p v-if="apiError" class="text-xs text-error" role="alert">{{ apiError }}</p>

      <div class="flex flex-wrap items-center gap-2 pt-1">
        <UButton
          icon="i-lucide-plus"
          size="xs"
          variant="outline"
          color="neutral"
          :disabled="rows.length >= MAX_STEPS"
          :label="$t('companies.overdueReminders.addStep')"
          @click="addStep"
        />
        <UButton
          size="xs"
          :label="$t('common.save')"
          :loading="saving"
          :disabled="!dirty || !valid || saving"
          @click="save"
        />
        <UButton
          size="xs"
          variant="ghost"
          color="neutral"
          :label="$t('companies.overdueReminders.reset')"
          :disabled="saving"
          @click="resetToDefault"
        />
      </div>

      <div class="rounded-lg border border-(--ui-border) px-3 py-2 mt-3">
        <p class="text-sm font-medium">{{ $t('companies.overdueReminders.backlogTitle') }}</p>
        <p class="text-xs text-(--ui-text-muted) mt-1 mb-2">{{ $t('companies.overdueReminders.backlogHelp') }}</p>
        <UButton
          icon="i-lucide-mail-warning"
          size="xs"
          variant="outline"
          color="neutral"
          :label="$t('companies.overdueReminders.backlogButton')"
          :loading="backlogChecking"
          @click="openBacklog"
        />
      </div>
    </div>

    <UModal v-model:open="backlogOpen" :title="$t('companies.overdueReminders.backlogTitle')">
      <template #body>
        <div v-if="backlogChecking" class="text-sm text-(--ui-text-muted)">{{ $t('companies.overdueReminders.backlogChecking') }}</div>
        <div v-else-if="backlogPreview" class="space-y-3">
          <p v-if="backlogPreview.clients === 0" class="text-sm">{{ $t('companies.overdueReminders.backlogEmpty') }}</p>
          <template v-else>
            <p class="text-sm font-medium">
              {{ $t('companies.overdueReminders.backlogSummary', { clients: backlogPreview.clients, invoices: backlogPreview.invoices }) }}
            </p>
            <ul class="text-sm divide-y divide-(--ui-border) max-h-64 overflow-y-auto">
              <li v-for="entry in backlogPreview.results" :key="entry.clientId" class="flex items-center justify-between gap-3 py-1.5">
                <span class="truncate">{{ entry.clientName }}</span>
                <span class="shrink-0 text-(--ui-text-muted) tabular-nums">
                  {{ $t('companies.overdueReminders.backlogInvoices', entry.invoices) }}, {{ entry.balance }} {{ entry.currency }}
                </span>
              </li>
            </ul>
            <p class="text-xs text-(--ui-text-muted)">{{ $t('companies.overdueReminders.backlogNote') }}</p>
          </template>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2 w-full">
          <UButton :label="$t('common.cancel')" variant="ghost" color="neutral" @click="backlogOpen = false" />
          <UButton
            :label="$t('companies.overdueReminders.backlogConfirm', { count: backlogPreview?.clients ?? 0 })"
            :loading="backlogSending"
            :disabled="backlogChecking || backlogSending || !backlogPreview || backlogPreview.clients === 0"
            @click="sendBacklog"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>
