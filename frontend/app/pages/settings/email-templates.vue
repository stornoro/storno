<script setup lang="ts">
import type { EmailTemplate } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('emailTemplates.title') })
const { can } = usePermissions()
const store = useEmailTemplateStore()
const companyStore = useCompanyStore()
const toast = useToast()

const loading = computed(() => store.loading)
const templates = computed(() => store.items)

const modalOpen = ref(false)
const saving = ref(false)
const editingTemplate = ref<EmailTemplate | null>(null)
const form = ref({ name: '', subject: '', body: '', isDefault: false })

// Refs for cursor-position insertion
const subjectInputRef = ref<InstanceType<typeof HTMLInputElement> | null>(null)
const bodyEditorRef = ref<InstanceType<typeof HTMLElement> | null>(null)
const activeField = ref<'subject' | 'body'>('body')

const columns = [
  { accessorKey: 'name', header: $t('emailTemplates.name') },
  { accessorKey: 'subject', header: $t('emailTemplates.subject') },
  { accessorKey: 'isDefault', header: $t('emailTemplates.isDefault') },
  { id: 'actions', header: $t('common.actions') },
]

function openCreate() {
  editingTemplate.value = null
  form.value = { name: '', subject: '', body: '', isDefault: false }
  activeField.value = 'body'
  modalOpen.value = true
}

function openEdit(template: EmailTemplate) {
  editingTemplate.value = template
  form.value = {
    name: template.name,
    subject: template.subject,
    body: template.body,
    isDefault: template.isDefault,
  }
  activeField.value = 'body'
  modalOpen.value = true
}

async function onSave() {
  saving.value = true
  if (editingTemplate.value) {
    const ok = await store.updateTemplate(editingTemplate.value.id, form.value)
    if (ok) {
      toast.add({ title: $t('emailTemplates.updateSuccess'), color: 'success' })
      modalOpen.value = false
    }
    else if (store.error) {
      toast.add({ title: store.error, color: 'error' })
    }
  }
  else {
    const result = await store.createTemplate(form.value)
    if (result) {
      toast.add({ title: $t('emailTemplates.createSuccess'), color: 'success' })
      modalOpen.value = false
    }
    else if (store.error) {
      toast.add({ title: store.error, color: 'error' })
    }
  }
  saving.value = false
}

const deleteModalOpen = ref(false)
const deletingTemplate = ref<EmailTemplate | null>(null)
const deleting = ref(false)

function openDelete(template: EmailTemplate) {
  deletingTemplate.value = template
  deleteModalOpen.value = true
}

async function onDelete() {
  if (!deletingTemplate.value) return
  deleting.value = true
  const ok = await store.deleteTemplate(deletingTemplate.value.id)
  if (ok) {
    toast.add({ title: $t('emailTemplates.deleteSuccess'), color: 'success' })
    deleteModalOpen.value = false
  }
  else if (store.error) {
    toast.add({ title: store.error, color: 'error' })
  }
  deleting.value = false
}

function insertVariable(variable: string) {
  if (activeField.value === 'subject') {
    // Insert into subject at cursor position
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
    // Insert into body at cursor position via MarkdownEditor
    const editor = bodyEditorRef.value as any
    if (editor?.insertAtCursor) {
      editor.insertAtCursor(variable)
    }
    else {
      form.value.body += variable
    }
  }
}

watch(() => companyStore.currentCompanyId, () => store.fetchTemplates())

onMounted(() => {
  store.fetchTemplates()
})
</script>

<template>
  <div>
    <UPageCard
      :title="$t('emailTemplates.title')"
      :description="$t('settings.emailTemplatesDescription')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        v-if="can(P.EMAIL_TEMPLATE_MANAGE)"
        :label="$t('emailTemplates.addTemplate')"
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
        :data="templates"
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
        <template #isDefault-cell="{ row }">
          <UBadge v-if="row.original.isDefault" color="success" variant="subtle" size="sm">
            {{ $t('emailTemplates.isDefault') }}
          </UBadge>
        </template>
        <template #actions-cell="{ row }">
          <div class="flex gap-1">
            <UButton v-if="can(P.EMAIL_TEMPLATE_MANAGE)" icon="i-lucide-pencil" variant="ghost" size="xs" @click="openEdit(row.original)" />
            <UButton v-if="can(P.EMAIL_TEMPLATE_MANAGE)" icon="i-lucide-trash-2" variant="ghost" size="xs" color="error" @click="openDelete(row.original)" />
          </div>
        </template>
      </UTable>

      <UEmpty v-if="!loading && templates.length === 0" icon="i-lucide-mail" :title="$t('emailTemplates.noTemplates')" class="py-12" />
    </UPageCard>

    <SharedConfirmModal
      v-model:open="deleteModalOpen"
      :title="$t('emailTemplates.deleteTemplate')"
      :description="$t('emailTemplates.deleteTemplateDescription')"
      icon="i-lucide-trash-2"
      color="error"
      :confirm-label="$t('common.delete')"
      :loading="deleting"
      @confirm="onDelete"
    />

    <!-- Create/Edit Slideover -->
    <USlideover v-model:open="modalOpen" :ui="{ content: 'sm:max-w-2xl' }">
      <template #header>
        <div class="flex items-center justify-between w-full">
          <h3 class="text-lg font-semibold">{{ editingTemplate ? $t('emailTemplates.editTemplate') : $t('emailTemplates.addTemplate') }}</h3>
          <div class="flex items-center gap-2">
            <USwitch v-model="form.isDefault" size="sm" />
            <span class="text-sm text-(--ui-text-muted)">{{ $t('emailTemplates.isDefault') }}</span>
          </div>
        </div>
      </template>

      <template #body>
        <div class="flex flex-col gap-5">
          <!-- Template name -->
          <UFormField :label="$t('emailTemplates.name')">
            <UInput v-model="form.name" :placeholder="$t('emailTemplates.namePlaceholder')" />
          </UFormField>

          <!-- Subject — full width -->
          <UFormField :label="$t('emailTemplates.subject')">
            <UInput
              ref="subjectInputRef"
              v-model="form.subject"
              size="xl"
              class="w-full"
              :placeholder="$t('emailTemplates.subjectPlaceholder')"
              @focus="activeField = 'subject'"
            />
          </UFormField>

          <!-- Body editor -->
          <UFormField :label="$t('emailTemplates.body')" class="flex-1">
            <SharedMarkdownEditor
              ref="bodyEditorRef"
              v-model="form.body"
              :rows="14"
              @focus="activeField = 'body'"
            />
          </UFormField>

          <!-- Variables chips — always visible -->
          <div v-if="store.availableVariables.length > 0" class="space-y-2">
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-braces" class="size-4 text-(--ui-text-muted)" />
              <span class="text-xs font-medium text-(--ui-text-muted) uppercase tracking-wide">{{ $t('emailTemplates.variables') }}</span>
              <span class="text-xs text-(--ui-text-dimmed)">&mdash; {{ $t('emailTemplates.variablesClickHint') }}</span>
            </div>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="v in store.availableVariables"
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
          <UButton variant="ghost" @click="modalOpen = false">{{ $t('common.cancel') }}</UButton>
          <UButton :loading="saving" @click="onSave">{{ $t('common.save') }}</UButton>
        </div>
      </template>
    </USlideover>
  </div>
</template>
