<script setup lang="ts">
import type { PdfTemplateConfig, PdfTemplateInfo } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('pdfTemplates.title') })

const store = usePdfTemplateConfigStore()
const companyStore = useCompanyStore()
const toast = useToast()

const config = computed(() => store.config)
const templates = computed(() => store.templates)
const saving = computed(() => store.saving)
const previewLoading = computed(() => store.previewLoading)

// Local form state for debounced preview
const localSlug = ref('classic')
const localColor = ref<string | null>(null)
const localFont = ref<string | null>(null)
const localShowLogo = ref(true)
const localShowBankInfo = ref(true)
const localFooterText = ref<string | null>(null)

// Logo
const logoFile = ref<File | null>(null)
const logoPreviewUrl = ref<string | null>(null)
const uploadingLogo = ref(false)

const fontOptions = [
  { label: 'DejaVu Sans (implicit)', value: null },
  { label: 'DejaVu Serif', value: 'DejaVu Serif' },
  { label: 'DejaVu Sans Mono', value: 'DejaVu Sans Mono' },
]

const colorPresets = [
  '#2563eb', '#6366f1', '#8b5cf6', '#ec4899',
  '#dc2626', '#ea580c', '#059669', '#374151',
]

// Initialize form from config
watch(config, (cfg) => {
  if (cfg) {
    localSlug.value = cfg.templateSlug
    localColor.value = cfg.primaryColor
    localFont.value = cfg.fontFamily
    localShowLogo.value = cfg.showLogo
    localShowBankInfo.value = cfg.showBankInfo
    localFooterText.value = cfg.footerText
  }
}, { immediate: true })

// Debounced preview update
const previewDebounce = ref<ReturnType<typeof setTimeout> | null>(null)

function requestPreview() {
  if (previewDebounce.value) clearTimeout(previewDebounce.value)
  previewDebounce.value = setTimeout(() => {
    store.fetchPreviewHtml(localSlug.value, localColor.value, localFont.value)
  }, 500)
}

watch([localSlug, localColor, localFont], () => {
  requestPreview()
})

async function saveConfig() {
  await store.updateConfig({
    templateSlug: localSlug.value,
    primaryColor: localColor.value,
    fontFamily: localFont.value,
    showLogo: localShowLogo.value,
    showBankInfo: localShowBankInfo.value,
    footerText: localFooterText.value,
  })
  if (!store.error) {
    toast.add({ title: $t('pdfTemplates.saveSuccess'), color: 'success' })
  } else {
    toast.add({ title: store.error, color: 'error' })
  }
}

function selectTemplate(slug: string) {
  localSlug.value = slug
}

function selectColor(color: string) {
  localColor.value = color
}

function onLogoFileChange(event: Event) {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  if (!file) return
  logoFile.value = file
  logoPreviewUrl.value = URL.createObjectURL(file)
}

async function uploadLogo() {
  const company = companyStore.currentCompany
  if (!company || !logoFile.value) return

  uploadingLogo.value = true
  try {
    await store.uploadLogo(company.id, logoFile.value)
    toast.add({ title: $t('pdfTemplates.logoUploadSuccess'), color: 'success' })
    logoFile.value = null
    requestPreview()
  } catch {
    toast.add({ title: $t('pdfTemplates.logoUploadError'), color: 'error' })
  } finally {
    uploadingLogo.value = false
  }
}

async function removeLogo() {
  const company = companyStore.currentCompany
  if (!company) return

  try {
    await store.deleteLogo(company.id)
    logoPreviewUrl.value = null
    toast.add({ title: $t('pdfTemplates.logoRemoved'), color: 'success' })
    requestPreview()
  } catch {
    toast.add({ title: $t('pdfTemplates.logoRemoveError'), color: 'error' })
  }
}

function getTemplateIcon(slug: string): string {
  const icons: Record<string, string> = {
    classic: 'i-lucide-file-text',
    modern: 'i-lucide-layout',
    minimal: 'i-lucide-minus-square',
    bold: 'i-lucide-bold',
  }
  return icons[slug] || 'i-lucide-file'
}

onMounted(async () => {
  await Promise.all([
    store.fetchConfig(),
    store.fetchTemplates(),
  ])
  requestPreview()

  // Load existing logo preview
  const company = companyStore.currentCompany
  if (company) {
    const { apiFetch } = useApi()
    try {
      const blob = await apiFetch<Blob>(`/v1/companies/${company.id}/logo`, {
        method: 'GET',
        responseType: 'blob',
      })
      if (blob && blob.size > 0) {
        logoPreviewUrl.value = URL.createObjectURL(blob)
      }
    } catch {
      // No logo exists
    }
  }
})
</script>

<template>
  <div class="flex flex-col gap-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">
          {{ $t('pdfTemplates.title') }}
        </h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
          {{ $t('pdfTemplates.description') }}
        </p>
      </div>
      <UButton
        :label="$t('common.save')"
        icon="i-lucide-save"
        color="primary"
        :loading="saving"
        @click="saveConfig"
      />
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
      <!-- Left Column: Settings -->
      <div class="flex flex-col gap-6">
        <!-- Template Selection -->
        <UCard>
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-palette" class="text-primary-500" />
              <span class="font-medium">{{ $t('pdfTemplates.selectTemplate') }}</span>
            </div>
          </template>

          <div class="grid grid-cols-2 gap-3">
            <button
              v-for="tpl in templates"
              :key="tpl.slug"
              class="relative flex flex-col items-center gap-2 p-4 rounded-lg border-2 transition-all cursor-pointer hover:border-primary-300 dark:hover:border-primary-700"
              :class="localSlug === tpl.slug
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-950/20'
                : 'border-gray-200 dark:border-gray-700'"
              @click="selectTemplate(tpl.slug)"
            >
              <UIcon :name="getTemplateIcon(tpl.slug)" class="w-8 h-8" :class="localSlug === tpl.slug ? 'text-primary-500' : 'text-gray-400'" />
              <span class="font-medium text-sm" :class="localSlug === tpl.slug ? 'text-primary-700 dark:text-primary-300' : 'text-gray-700 dark:text-gray-300'">{{ tpl.name }}</span>
              <span class="text-xs text-gray-500 dark:text-gray-400 text-center leading-tight">{{ tpl.description }}</span>
              <div v-if="localSlug === tpl.slug" class="absolute top-2 right-2">
                <UIcon name="i-lucide-check-circle" class="w-5 h-5 text-primary-500" />
              </div>
            </button>
          </div>
        </UCard>

        <!-- Colors -->
        <UCard>
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-paintbrush" class="text-primary-500" />
              <span class="font-medium">{{ $t('pdfTemplates.primaryColor') }}</span>
            </div>
          </template>

          <div class="flex flex-wrap gap-2 mb-3">
            <button
              v-for="color in colorPresets"
              :key="color"
              class="w-8 h-8 rounded-full border-2 transition-all"
              :class="localColor === color ? 'border-gray-900 dark:border-white scale-110' : 'border-transparent'"
              :style="{ backgroundColor: color }"
              @click="selectColor(color)"
            />
          </div>
          <UFormField :label="$t('pdfTemplates.customColor')">
            <div class="flex gap-2 items-center">
              <input
                type="color"
                :value="localColor || '#2563eb'"
                class="w-10 h-10 rounded cursor-pointer border border-gray-300 dark:border-gray-600"
                @input="localColor = ($event.target as HTMLInputElement).value"
              >
              <UInput
                v-model="localColor"
                placeholder="#2563eb"
                class="flex-1"
              />
              <UButton
                v-if="localColor"
                icon="i-lucide-x"
                variant="ghost"
                size="xs"
                @click="localColor = null"
              />
            </div>
          </UFormField>
        </UCard>

        <!-- Font & Options -->
        <UCard>
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-settings" class="text-primary-500" />
              <span class="font-medium">{{ $t('pdfTemplates.options') }}</span>
            </div>
          </template>

          <div class="flex flex-col gap-4">
            <UFormField :label="$t('pdfTemplates.fontFamily')">
              <USelectMenu
                v-model="localFont"
                :items="fontOptions"
                value-key="value"
                class="w-full"
              />
            </UFormField>

            <div class="flex items-center justify-between">
              <span class="text-sm">{{ $t('pdfTemplates.showLogo') }}</span>
              <USwitch v-model="localShowLogo" />
            </div>

            <div class="flex items-center justify-between">
              <span class="text-sm">{{ $t('pdfTemplates.showBankInfo') }}</span>
              <USwitch v-model="localShowBankInfo" />
            </div>

            <UFormField :label="$t('pdfTemplates.footerText')">
              <UTextarea
                v-model="localFooterText"
                :placeholder="$t('pdfTemplates.footerPlaceholder')"
                :rows="2"
              />
            </UFormField>
          </div>
        </UCard>

        <!-- Logo Upload -->
        <UCard>
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-image" class="text-primary-500" />
              <span class="font-medium">{{ $t('pdfTemplates.companyLogo') }}</span>
            </div>
          </template>

          <div class="flex flex-col gap-4">
            <div v-if="logoPreviewUrl" class="flex items-center gap-4">
              <img :src="logoPreviewUrl" alt="Logo" class="max-h-16 max-w-40 object-contain border rounded p-1">
              <UButton
                :label="$t('pdfTemplates.removeLogo')"
                icon="i-lucide-trash-2"
                variant="soft"
                color="error"
                size="sm"
                @click="removeLogo"
              />
            </div>

            <div class="flex items-center gap-3">
              <label class="flex items-center gap-2 px-3 py-2 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:border-primary-400 transition-colors">
                <UIcon name="i-lucide-upload" class="w-4 h-4 text-gray-500" />
                <span class="text-sm text-gray-600 dark:text-gray-400">{{ $t('pdfTemplates.chooseLogo') }}</span>
                <input
                  type="file"
                  accept="image/png,image/jpeg,image/svg+xml"
                  class="hidden"
                  @change="onLogoFileChange"
                >
              </label>
              <UButton
                v-if="logoFile"
                :label="$t('pdfTemplates.uploadLogo')"
                icon="i-lucide-upload"
                color="primary"
                size="sm"
                :loading="uploadingLogo"
                @click="uploadLogo"
              />
            </div>
            <p class="text-xs text-gray-400">{{ $t('pdfTemplates.logoHint') }}</p>
          </div>
        </UCard>
      </div>

      <!-- Right Column: Preview -->
      <div class="flex flex-col gap-4">
        <UCard class="sticky top-4">
          <template #header>
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-eye" class="text-primary-500" />
                <span class="font-medium">{{ $t('pdfTemplates.preview') }}</span>
              </div>
              <UButton
                icon="i-lucide-refresh-cw"
                variant="ghost"
                size="xs"
                :loading="previewLoading"
                @click="requestPreview"
              />
            </div>
          </template>

          <div class="relative bg-gray-100 dark:bg-gray-800 rounded-lg overflow-hidden" style="aspect-ratio: 210/297;">
            <div v-if="previewLoading" class="absolute inset-0 flex items-center justify-center">
              <UIcon name="i-lucide-loader-2" class="w-8 h-8 text-gray-400 animate-spin" />
            </div>
            <iframe
              v-else-if="store.previewHtml"
              :srcdoc="store.previewHtml"
              class="w-full h-full border-0"
              sandbox="allow-same-origin"
            />
            <div v-else class="flex items-center justify-center h-full text-gray-400 text-sm">
              {{ $t('pdfTemplates.noPreview') }}
            </div>
          </div>
        </UCard>
      </div>
    </div>
  </div>
</template>
