<template>
  <UModal v-model:open="isOpen" :ui="{ content: 'sm:max-w-3xl' }">
    <template #header>
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-mail" class="size-5 shrink-0 text-(--ui-primary)" />
        <h3 class="font-semibold">{{ $t('receipts.sendEmail') }}</h3>
      </div>
    </template>
    <template #body>
      <div class="space-y-5">
        <!-- Receipt context -->
        <div class="rounded-lg bg-(--ui-bg-elevated) px-4 py-3 flex items-center justify-between">
          <div class="flex items-center gap-3 min-w-0">
            <UIcon name="i-lucide-receipt" class="size-4 shrink-0 text-(--ui-text-muted)" />
            <div class="min-w-0">
              <span class="font-medium">{{ receipt.number }}</span>
              <span class="text-(--ui-text-muted) mx-1.5">&middot;</span>
              <span class="text-sm text-(--ui-text-muted)">{{ receipt.clientName || receipt.customerName }}</span>
            </div>
          </div>
          <span class="font-semibold shrink-0">{{ formatMoney(receipt.total, receipt.currency) }}</span>
        </div>

        <!-- Recipients -->
        <div class="space-y-3">
          <UFormField :label="$t('invoices.emailTo')" required>
            <UInput v-model="form.to" type="email" placeholder="email@example.com" icon="i-lucide-user" />
          </UFormField>

          <!-- CC/BCC toggle & fields -->
          <div v-if="!showCcBcc" class="flex">
            <button
              type="button"
              class="text-xs text-(--ui-primary) hover:underline"
              @click="showCcBcc = true"
            >
              + CC / BCC
            </button>
          </div>
          <div v-else class="grid grid-cols-2 gap-3">
            <UFormField :label="$t('invoices.emailCc')">
              <UInput v-model="form.cc" :placeholder="$t('invoices.emailCcHint')" icon="i-lucide-users" />
            </UFormField>
            <UFormField :label="$t('invoices.emailBcc')">
              <UInput v-model="form.bcc" :placeholder="$t('invoices.emailCcHint')" icon="i-lucide-eye-off" />
            </UFormField>
          </div>
        </div>

        <USeparator />

        <!-- Subject -->
        <UFormField :label="$t('invoices.emailSubject')">
          <UInput
            ref="subjectInputRef"
            v-model="form.subject"
            icon="i-lucide-heading"
            @focus="activeField = 'subject'"
          />
        </UFormField>

        <!-- Body -->
        <UFormField :label="$t('invoices.emailBody')">
          <SharedMarkdownEditor
            ref="bodyEditorRef"
            v-model="form.body"
            :rows="8"
            @focus="activeField = 'body'"
          />
        </UFormField>

        <!-- Variables chips -->
        <div class="space-y-2">
          <div class="flex items-center gap-2">
            <UIcon name="i-lucide-braces" class="size-4 text-(--ui-text-muted)" />
            <span class="text-xs font-medium text-(--ui-text-muted) uppercase tracking-wide">{{ $t('emailTemplates.variables') }}</span>
            <span class="text-xs text-(--ui-text-dimmed)">&mdash; {{ $t('emailTemplates.variablesClickHint') }}</span>
          </div>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="v in availableVariables"
              :key="v"
              type="button"
              class="inline-flex items-center gap-1 px-2 py-1 text-xs font-mono rounded-md bg-(--ui-bg-elevated) border border-(--ui-border) text-(--ui-text-muted) hover:text-(--ui-text) hover:border-(--ui-border-hover) hover:bg-(--ui-bg-elevated)/80 transition-colors cursor-pointer"
              @click="insertVariable(v)"
            >
              {{ v }}
            </button>
          </div>
        </div>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton variant="ghost" @click="isOpen = false">{{ $t('common.cancel') }}</UButton>
        <UButton icon="i-lucide-send" :loading="sending" :disabled="!form.to" @click="onSend">
          {{ $t('invoices.emailSend') }}
        </UButton>
      </div>
    </template>
  </UModal>
</template>

<script setup lang="ts">
import type { Receipt } from '~/types'

const props = defineProps<{
  receipt: Receipt
}>()

const isOpen = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ sent: [] }>()

const { t: $t } = useI18n()
const receiptStore = useReceiptStore()
const toast = useToast()

const sending = ref(false)
const showCcBcc = ref(false)
const form = ref({
  to: '',
  cc: '',
  bcc: '',
  subject: '',
  body: '',
})

const subjectInputRef = ref<InstanceType<typeof HTMLInputElement> | null>(null)
const bodyEditorRef = ref<InstanceType<typeof HTMLElement> | null>(null)
const activeField = ref<'subject' | 'body'>('body')

const availableVariables = [
  '[[client_name]]',
  '[[receipt_number]]',
  '[[total]]',
  '[[issue_date]]',
  '[[company_name]]',
  '[[currency]]',
]

function formatMoney(amount?: string | number, currency = 'RON') {
  return new Intl.NumberFormat('ro-RO', { style: 'currency', currency }).format(Number(amount || 0))
}

function insertVariable(variable: string) {
  if (activeField.value === 'subject') {
    const el = subjectInputRef.value
    const input = el instanceof HTMLInputElement ? el : (el as any)?.$el?.querySelector('input')
    if (input) {
      const start = input.selectionStart ?? form.value.subject.length
      const text = form.value.subject
      form.value.subject = text.slice(0, start) + variable + text.slice(start)
      nextTick(() => {
        input.focus()
        input.selectionStart = input.selectionEnd = start + variable.length
      })
    }
    else {
      form.value.subject += variable
    }
  }
  else {
    const editor = bodyEditorRef.value as any
    if (editor?.insertAtCursor) {
      editor.insertAtCursor(variable)
    }
    else {
      form.value.body += variable
    }
  }
}

// Load defaults when modal opens
watch(isOpen, async (open) => {
  if (open) {
    showCcBcc.value = false
    const defaults = await receiptStore.fetchEmailDefaults(props.receipt.id)
    form.value.to = defaults.to ?? ''
    form.value.subject = defaults.subject ?? ''
    form.value.body = defaults.body ?? ''
    if (form.value.cc || form.value.bcc) {
      showCcBcc.value = true
    }
  }
})

function parseCsv(value: string): string[] {
  return value
    .split(',')
    .map(s => s.trim())
    .filter(s => s.length > 0)
}

async function onSend() {
  if (!form.value.to) return
  sending.value = true
  const result = await receiptStore.sendEmail(props.receipt.id, {
    to: form.value.to,
    subject: form.value.subject || undefined,
    body: form.value.body || undefined,
    cc: form.value.cc ? parseCsv(form.value.cc) : undefined,
    bcc: form.value.bcc ? parseCsv(form.value.bcc) : undefined,
  })
  sending.value = false
  if (result) {
    isOpen.value = false
    form.value = { to: '', cc: '', bcc: '', subject: '', body: '' }
    toast.add({
      title: $t('receipts.emailSent'),
      icon: 'i-lucide-mail-check',
      color: 'success',
    })
    emit('sent')
  }
  else {
    toast.add({
      title: $t('receipts.emailError'),
      icon: 'i-lucide-mail-x',
      color: 'error',
    })
  }
}
</script>
