<script setup lang="ts">
import type { PdfLabelOverride, PdfTemplateConfig, PdfTemplateInfo } from '~/types'

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

// Local form state
const localSlug = ref('classic')
const localColor = ref<string | null>(null)
const localFont = ref<string | null>(null)
const localShowLogo = ref(true)
const localShowBankInfo = ref(true)
const localBankDisplaySection = ref<'supplier' | 'payment' | 'both'>('both')
const localBankDisplayMode = ref<'stacked' | 'inline'>('stacked')
const localDefaultNotes = ref<string | null>(null)
const localDefaultPaymentTerms = ref<string | null>(null)
const localDefaultPaymentMethod = ref<string | null>(null)
const localLabelOverrides = ref<Record<string, PdfLabelOverride>>({})

// Label definitions for Texte tab
interface LabelDef {
  key: string
  label: string
  hideable: boolean
}

const generalLabels: LabelDef[] = [
  { key: 'invoice_title', label: 'FACTURA', hideable: false },
  { key: 'proforma_title', label: 'FACTURA PROFORMA', hideable: false },
  { key: 'credit_note_title', label: 'FACTURA DE RAMBURSARE', hideable: false },
  { key: 'delivery_note_title', label: 'AVIZ DE INSOTIRE A MARFII', hideable: false },
  { key: 'receipt_title', label: 'BON FISCAL', hideable: false },
  { key: 'date_label', label: 'Data', hideable: false },
  { key: 'due_date', label: 'Scadenta', hideable: false },
  { key: 'subtotal', label: 'Subtotal', hideable: false },
  { key: 'vat_label', label: 'TVA', hideable: false },
  { key: 'discount_label', label: 'Discount', hideable: false },
  { key: 'total', label: 'TOTAL', hideable: false },
  { key: 'exchange_rate', label: 'Curs valutar', hideable: false },
  { key: 'payment_method', label: 'Modalitate de plata', hideable: true },
  { key: 'notes', label: 'Observatii', hideable: true },
  { key: 'payment_terms', label: 'Conditii de plata', hideable: true },
  { key: 'bank_account', label: 'Cont bancar', hideable: true },
  { key: 'footer_text', label: 'Factura circula fara semnatura si stampila...', hideable: true },
]

const supplierLabels: LabelDef[] = [
  { key: 'supplier', label: 'Furnizor', hideable: false },
  { key: 'supplier_cui', label: 'CUI', hideable: true },
  { key: 'supplier_reg_number', label: 'Nr. Reg. Com.', hideable: true },
  { key: 'supplier_address', label: 'Adresa', hideable: true },
  { key: 'supplier_county', label: 'Judet', hideable: true },
  { key: 'supplier_phone', label: 'Tel', hideable: true },
  { key: 'supplier_email', label: 'Email', hideable: true },
  { key: 'supplier_website', label: 'Web', hideable: true },
]

const clientLabels: LabelDef[] = [
  { key: 'client', label: 'Client', hideable: false },
  { key: 'client_cui', label: 'CUI', hideable: true },
  { key: 'client_reg_number', label: 'Nr. Reg. Com.', hideable: true },
  { key: 'client_cnp', label: 'CNP', hideable: true },
  { key: 'client_address', label: 'Adresa', hideable: true },
  { key: 'client_county', label: 'Judet', hideable: true },
  { key: 'client_phone', label: 'Tel', hideable: true },
  { key: 'client_email', label: 'Email', hideable: true },
  { key: 'client_contact', label: 'Persoana de contact', hideable: true },
]

const tableLabels: LabelDef[] = [
  { key: 'col_description', label: 'Articol', hideable: false },
  { key: 'col_code', label: 'Cod', hideable: true },
  { key: 'col_unit', label: 'U.M.', hideable: true },
  { key: 'col_quantity', label: 'Cant.', hideable: true },
  { key: 'col_unit_price', label: 'Pret unitar', hideable: true },
  { key: 'col_line_total', label: 'Valoare', hideable: true },
  { key: 'col_vat_percent', label: 'Procent TVA', hideable: true },
  { key: 'col_vat', label: 'TVA', hideable: true },
  { key: 'col_total', label: 'Total', hideable: true },
]

const labelSubTabs = computed(() => [
  { label: $t('pdfTemplates.labelsGeneral'), slot: 'labelsGeneral' },
  { label: $t('pdfTemplates.labelsSupplier'), slot: 'labelsSupplier' },
  { label: $t('pdfTemplates.labelsClient'), slot: 'labelsClient' },
  { label: $t('pdfTemplates.labelsTable'), slot: 'labelsTable' },
])

function getLabelOverride(key: string): PdfLabelOverride {
  return localLabelOverrides.value[key] || {}
}

function setLabelVisible(key: string, visible: boolean) {
  const current = localLabelOverrides.value[key] || {}
  localLabelOverrides.value = {
    ...localLabelOverrides.value,
    [key]: { ...current, visible },
  }
}

function setLabelText(key: string, text: string) {
  const current = localLabelOverrides.value[key] || {}
  localLabelOverrides.value = {
    ...localLabelOverrides.value,
    [key]: { ...current, text: text || null },
  }
}

// Logo
const logoFile = ref<File | null>(null)
const logoPreviewUrl = ref<string | null>(null)
const uploadingLogo = ref(false)

// Tabs
const tabItems = computed(() => [
  { label: $t('pdfTemplates.tabTemplate'), slot: 'template', icon: 'i-lucide-layout' },
  { label: $t('pdfTemplates.tabStyle'), slot: 'style', icon: 'i-lucide-paintbrush' },
  { label: $t('pdfTemplates.tabOptions'), slot: 'options', icon: 'i-lucide-settings' },
  { label: $t('pdfTemplates.tabLogo'), slot: 'logo', icon: 'i-lucide-image' },
  { label: $t('pdfTemplates.tabLabels'), slot: 'labels', icon: 'i-lucide-type' },
])

const fontOptions = [
  { label: 'DejaVu Sans (implicit)', value: null },
  { label: 'DejaVu Serif', value: 'DejaVu Serif' },
  { label: 'DejaVu Sans Mono', value: 'DejaVu Sans Mono' },
  { label: 'PT Sans', value: 'PT Sans' },
  { label: 'Arial', value: 'Arial' },
  { label: 'Verdana', value: 'Verdana' },
  { label: 'Tahoma', value: 'Tahoma' },
  { label: 'Trebuchet MS', value: 'Trebuchet MS' },
  { label: 'Poppins', value: 'Poppins' },
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
    localBankDisplaySection.value = cfg.bankDisplaySection ?? 'both'
    localBankDisplayMode.value = cfg.bankDisplayMode ?? 'stacked'
    localDefaultNotes.value = cfg.defaultNotes
    localDefaultPaymentTerms.value = cfg.defaultPaymentTerms
    localDefaultPaymentMethod.value = cfg.defaultPaymentMethod
    localLabelOverrides.value = cfg.labelOverrides ? { ...cfg.labelOverrides } : {}
  }
}, { immediate: true })

// Collect all current overrides for preview
function previewOverrides(): Partial<PdfTemplateConfig> {
  return {
    templateSlug: localSlug.value,
    primaryColor: localColor.value,
    fontFamily: localFont.value,
    showLogo: localShowLogo.value,
    showBankInfo: localShowBankInfo.value,
    bankDisplaySection: localBankDisplaySection.value,
    bankDisplayMode: localBankDisplayMode.value,
    defaultNotes: localDefaultNotes.value,
    defaultPaymentTerms: localDefaultPaymentTerms.value,
    defaultPaymentMethod: localDefaultPaymentMethod.value,
    labelOverrides: Object.keys(localLabelOverrides.value).length > 0 ? localLabelOverrides.value : null,
  }
}

// Debounced preview update — triggers on ANY option change
const previewDebounce = ref<ReturnType<typeof setTimeout> | null>(null)

function requestPreview() {
  if (previewDebounce.value) clearTimeout(previewDebounce.value)
  previewDebounce.value = setTimeout(() => {
    store.fetchPreviewHtml(previewOverrides())
  }, 400)
}

watch(
  [localSlug, localColor, localFont, localShowLogo, localShowBankInfo, localBankDisplaySection, localBankDisplayMode, localDefaultNotes, localDefaultPaymentTerms, localDefaultPaymentMethod, localLabelOverrides],
  () => requestPreview(),
  { deep: true },
)

async function saveConfig() {
  await store.updateConfig({
    templateSlug: localSlug.value,
    primaryColor: localColor.value,
    fontFamily: localFont.value,
    showLogo: localShowLogo.value,
    showBankInfo: localShowBankInfo.value,
    bankDisplaySection: localBankDisplaySection.value,
    bankDisplayMode: localBankDisplayMode.value,
    defaultNotes: localDefaultNotes.value,
    defaultPaymentTerms: localDefaultPaymentTerms.value,
    defaultPaymentMethod: localDefaultPaymentMethod.value,
    labelOverrides: Object.keys(localLabelOverrides.value).length > 0 ? localLabelOverrides.value : null,
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
      <!-- Left Column: Tabbed Settings -->
      <UCard :ui="{ body: 'p-0 sm:p-0' }">
        <UTabs :items="tabItems" class="w-full">
          <!-- Template tab -->
          <template #template>
            <div class="p-4 sm:p-5">
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
            </div>
          </template>

          <!-- Style tab (color + font) -->
          <template #style>
            <div class="p-4 sm:p-5 flex flex-col gap-5">
              <!-- Color presets -->
              <div>
                <label class="text-sm font-medium text-(--ui-text) mb-2 block">{{ $t('pdfTemplates.primaryColor') }}</label>
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
              </div>

              <!-- Font -->
              <UFormField :label="$t('pdfTemplates.fontFamily')">
                <USelectMenu
                  v-model="localFont"
                  :items="fontOptions"
                  value-key="value"
                  class="w-full"
                />
              </UFormField>
            </div>
          </template>

          <!-- Options tab -->
          <template #options>
            <div class="p-4 sm:p-5 flex flex-col gap-4">
              <div class="flex items-center justify-between">
                <span class="text-sm">{{ $t('pdfTemplates.showLogo') }}</span>
                <USwitch v-model="localShowLogo" />
              </div>

              <USeparator />

              <div class="flex items-center justify-between">
                <span class="text-sm">{{ $t('pdfTemplates.showBankInfo') }}</span>
                <USwitch v-model="localShowBankInfo" />
              </div>

              <template v-if="localShowBankInfo">
                <UFormField :label="$t('pdfTemplates.bankDisplaySection')">
                  <USelectMenu
                    v-model="localBankDisplaySection"
                    :items="[
                      { label: $t('pdfTemplates.bankDisplaySectionSupplier'), value: 'supplier' },
                      { label: $t('pdfTemplates.bankDisplaySectionBoth'), value: 'both' },
                      { label: $t('pdfTemplates.bankDisplaySectionPayment'), value: 'payment' },
                    ]"
                    value-key="value"
                    class="w-full"
                  />
                </UFormField>

                <UFormField :label="$t('pdfTemplates.bankDisplayMode')">
                  <div class="flex gap-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                      <input v-model="localBankDisplayMode" type="radio" value="stacked" class="accent-primary">
                      <span class="text-sm">{{ $t('pdfTemplates.bankDisplayModeStacked') }}</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                      <input v-model="localBankDisplayMode" type="radio" value="inline" class="accent-primary">
                      <span class="text-sm">{{ $t('pdfTemplates.bankDisplayModeInline') }}</span>
                    </label>
                  </div>
                </UFormField>
              </template>

              <USeparator />

              <UFormField :label="$t('pdfTemplates.defaultPaymentMethod')">
                <USelectMenu
                  v-model="localDefaultPaymentMethod"
                  :items="[
                    { label: $t('pdfTemplates.paymentMethodNone'), value: null },
                    { label: $t('pdfTemplates.paymentMethodBankTransfer'), value: 'bank_transfer' },
                    { label: $t('pdfTemplates.paymentMethodCash'), value: 'cash' },
                    { label: $t('pdfTemplates.paymentMethodCard'), value: 'card' },
                    { label: $t('pdfTemplates.paymentMethodCheque'), value: 'cheque' },
                    { label: $t('pdfTemplates.paymentMethodOther'), value: 'other' },
                  ]"
                  value-key="value"
                  class="w-full"
                />
              </UFormField>

              <div class="grid grid-cols-2 gap-4">
                <UFormField :label="$t('pdfTemplates.defaultNotes')">
                  <UTextarea
                    class="w-full"
                    v-model="localDefaultNotes"
                    :placeholder="$t('pdfTemplates.defaultNotesPlaceholder')"
                    :rows="2"
                  />
                </UFormField>

                <UFormField :label="$t('pdfTemplates.defaultPaymentTerms')">
                  <UTextarea
                    class="w-full"
                    v-model="localDefaultPaymentTerms"
                    :placeholder="$t('pdfTemplates.defaultPaymentTermsPlaceholder')"
                    :rows="2"
                  />
                </UFormField>
              </div>
            </div>
          </template>

          <!-- Logo tab -->
          <template #logo>
            <div class="p-4 sm:p-5 flex flex-col gap-4">
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
          </template>

          <!-- Labels tab -->
          <template #labels>
            <div class="p-4 sm:p-5">
              <UTabs :items="labelSubTabs" class="w-full" variant="link">
                <template #labelsGeneral>
                  <div class="flex flex-col gap-3 pt-3">
                    <div
                      v-for="def in generalLabels"
                      :key="def.key"
                      class="flex items-center gap-3"
                    >
                      <USwitch
                        v-if="def.hideable"
                        :model-value="getLabelOverride(def.key).visible !== false"
                        size="xs"
                        @update:model-value="setLabelVisible(def.key, $event)"
                      />
                      <div v-else class="w-9" />
                      <UInput
                        :model-value="getLabelOverride(def.key).text || ''"
                        :placeholder="def.label"
                        size="sm"
                        class="flex-1"
                        @update:model-value="setLabelText(def.key, $event)"
                      />
                    </div>
                  </div>
                </template>

                <template #labelsSupplier>
                  <div class="flex flex-col gap-3 pt-3">
                    <div
                      v-for="def in supplierLabels"
                      :key="def.key"
                      class="flex items-center gap-3"
                    >
                      <USwitch
                        v-if="def.hideable"
                        :model-value="getLabelOverride(def.key).visible !== false"
                        size="xs"
                        @update:model-value="setLabelVisible(def.key, $event)"
                      />
                      <div v-else class="w-9" />
                      <UInput
                        :model-value="getLabelOverride(def.key).text || ''"
                        :placeholder="def.label"
                        size="sm"
                        class="flex-1"
                        :disabled="def.key.endsWith('_address') || def.key.endsWith('_county')"
                        @update:model-value="setLabelText(def.key, $event)"
                      />
                    </div>
                  </div>
                </template>

                <template #labelsClient>
                  <div class="flex flex-col gap-3 pt-3">
                    <div
                      v-for="def in clientLabels"
                      :key="def.key"
                      class="flex items-center gap-3"
                    >
                      <USwitch
                        v-if="def.hideable"
                        :model-value="getLabelOverride(def.key).visible !== false"
                        size="xs"
                        @update:model-value="setLabelVisible(def.key, $event)"
                      />
                      <div v-else class="w-9" />
                      <UInput
                        :model-value="getLabelOverride(def.key).text || ''"
                        :placeholder="def.label"
                        size="sm"
                        class="flex-1"
                        :disabled="def.key.endsWith('_address') || def.key.endsWith('_county')"
                        @update:model-value="setLabelText(def.key, $event)"
                      />
                    </div>
                  </div>
                </template>

                <template #labelsTable>
                  <div class="flex flex-col gap-3 pt-3">
                    <div
                      v-for="def in tableLabels"
                      :key="def.key"
                      class="flex items-center gap-3"
                    >
                      <USwitch
                        v-if="def.hideable"
                        :model-value="getLabelOverride(def.key).visible !== false"
                        size="xs"
                        @update:model-value="setLabelVisible(def.key, $event)"
                      />
                      <div v-else class="w-9" />
                      <UInput
                        :model-value="getLabelOverride(def.key).text || ''"
                        :placeholder="def.label"
                        size="sm"
                        class="flex-1"
                        @update:model-value="setLabelText(def.key, $event)"
                      />
                    </div>
                  </div>
                </template>
              </UTabs>
            </div>
          </template>
        </UTabs>
      </UCard>

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
