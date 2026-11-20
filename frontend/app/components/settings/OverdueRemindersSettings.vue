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
    </div>
  </div>
</template>
