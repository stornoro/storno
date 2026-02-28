<script setup lang="ts">
import { marked } from 'marked'

const modelValue = defineModel<string>({ required: true })

const props = withDefaults(defineProps<{
  rows?: number
  placeholder?: string
  variables?: string[]
}>(), {
  rows: 8,
  placeholder: '',
  variables: () => [],
})

const emit = defineEmits<{ focus: [] }>()

const { t: $t } = useI18n()
const showPreview = ref(false)
const textareaRef = ref<HTMLTextAreaElement | null>()

const renderedHtml = computed(() => {
  if (!modelValue.value) return ''
  return marked.parse(modelValue.value, { breaks: true }) as string
})

function getTextarea(): HTMLTextAreaElement | null {
  // UTextarea wraps a native textarea
  const el = textareaRef.value
  if (!el) return null
  if (el instanceof HTMLTextAreaElement) return el
  return (el as any)?.$el?.querySelector('textarea') ?? null
}

function wrapSelection(before: string, after: string) {
  const ta = getTextarea()
  if (!ta) return
  const start = ta.selectionStart
  const end = ta.selectionEnd
  const text = modelValue.value
  const selected = text.slice(start, end)
  const replacement = before + (selected || 'text') + after
  modelValue.value = text.slice(0, start) + replacement + text.slice(end)
  nextTick(() => {
    ta.focus()
    ta.selectionStart = start + before.length
    ta.selectionEnd = start + before.length + (selected || 'text').length
  })
}

function insertAtCursor(text: string) {
  const ta = getTextarea()
  if (!ta) return
  const start = ta.selectionStart
  const val = modelValue.value
  modelValue.value = val.slice(0, start) + text + val.slice(start)
  nextTick(() => {
    ta.focus()
    ta.selectionStart = ta.selectionEnd = start + text.length
  })
}

function insertPrefix(prefix: string) {
  const ta = getTextarea()
  if (!ta) return
  const start = ta.selectionStart
  const text = modelValue.value
  // Find the start of the current line
  const lineStart = text.lastIndexOf('\n', start - 1) + 1
  modelValue.value = text.slice(0, lineStart) + prefix + text.slice(lineStart)
  nextTick(() => {
    ta.focus()
    ta.selectionStart = ta.selectionEnd = start + prefix.length
  })
}

// Expose insertAtCursor for parent components
defineExpose({ insertAtCursor })

const toolbarActions = [
  { icon: 'i-lucide-bold', tooltip: 'Bold', action: () => wrapSelection('**', '**') },
  { icon: 'i-lucide-italic', tooltip: 'Italic', action: () => wrapSelection('*', '*') },
  { icon: 'i-lucide-heading-3', tooltip: 'Heading', action: () => insertPrefix('### ') },
  { icon: 'i-lucide-list', tooltip: 'List', action: () => insertPrefix('- ') },
  { icon: 'i-lucide-list-ordered', tooltip: 'Numbered list', action: () => insertPrefix('1. ') },
  { icon: 'i-lucide-minus', tooltip: 'Divider', action: () => insertAtCursor('\n\n---\n\n') },
  { icon: 'i-lucide-link', tooltip: 'Link', action: () => wrapSelection('[', '](url)') },
]
</script>

<template>
  <div class="rounded-md border border-(--ui-border) overflow-hidden flex flex-col">
    <!-- Toolbar -->
    <div class="flex items-center gap-0.5 px-2 py-1.5 bg-(--ui-bg-elevated)/50 border-b border-(--ui-border)">
      <UTooltip v-for="btn in toolbarActions" :key="btn.icon" :text="btn.tooltip">
        <UButton
          :icon="btn.icon"
          variant="ghost"
          size="xs"
          color="neutral"
          @click="btn.action"
        />
      </UTooltip>

      <div class="w-px h-4 bg-(--ui-border) mx-1" />

      <!-- Variable insertion dropdown (when variables are passed directly) -->
      <UDropdownMenu
        v-if="variables.length > 0"
        :items="variables.map(v => ({ label: v, onSelect: () => insertAtCursor(v) }))"
      >
        <UButton
          icon="i-lucide-braces"
          variant="ghost"
          size="xs"
          color="neutral"
          :label="$t('emailTemplates.variables')"
        />
      </UDropdownMenu>

      <div class="flex-1" />

      <!-- Preview toggle -->
      <UButton
        :icon="showPreview ? 'i-lucide-pencil' : 'i-lucide-eye'"
        variant="ghost"
        size="xs"
        color="neutral"
        :label="showPreview ? $t('emailTemplates.edit') : $t('emailTemplates.preview')"
        @click="showPreview = !showPreview"
      />
    </div>

    <!-- Editor / Preview -->
    <div v-if="!showPreview" class="flex-1 [&>div]:w-full">
      <UTextarea
        ref="textareaRef"
        v-model="modelValue"
        :rows="rows"
        :placeholder="placeholder"
        class="w-full"
        :ui="{ root: 'w-full', base: 'w-full border-0 rounded-none focus:ring-0 shadow-none h-full' }"
        autoresize
        @focus="emit('focus')"
      />
    </div>
    <div
      v-else
      class="prose prose-sm dark:prose-invert max-w-none p-3 min-h-[200px] text-sm flex-1 overflow-y-auto"
      v-html="renderedHtml"
    />
  </div>
</template>
