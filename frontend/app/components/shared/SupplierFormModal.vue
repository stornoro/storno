<template>
  <UModal v-model:open="open">
    <template #header>
      <h3 class="text-base font-semibold">{{ isEdit ? $t('suppliers.editSupplier') : $t('suppliers.addSupplier') }}</h3>
    </template>
    <template #body>
      <div class="space-y-4">
        <!-- CIF + Name -->
        <div class="relative">
          <!-- Click-outside overlay to close registry dropdown -->
          <div
            v-if="registryDropdownOpen && !isEdit"
            class="fixed inset-0 z-[199]"
            @click="registryDropdownOpen = false"
          />
          <div class="grid grid-cols-2 gap-4">
            <UFormField :label="$t('suppliers.cif')" :error="cifError">
              <UInput
                v-model="form.cif"
                :class="{ 'anaf-highlight': anafPopulatedFields.has('cif') }"
                :placeholder="isEdit ? 'ex: 12345678' : $t('clients.cuiSearchPlaceholder')"
                icon="i-lucide-search"
                :loading="registryLoading"
                @input="!isEdit && onCifInput()"
                @blur="validateCif"
              />
            </UFormField>
            <UFormField :label="$t('common.name')" required>
              <UInput v-model="form.name" :class="{ 'anaf-highlight': anafPopulatedFields.has('name') }" :placeholder="$t('invoices.receiverNamePlaceholder')" icon="i-lucide-building-2" />
            </UFormField>
          </div>
          <!-- Registry search dropdown -->
          <div
            v-if="registryDropdownOpen && !isEdit && registryResults.length > 0"
            class="absolute left-0 right-0 top-full z-[200] mt-1 max-h-48 overflow-y-auto rounded-md border border-(--ui-border) bg-(--ui-bg) shadow-lg"
          >
            <button
              v-for="r in registryResults"
              :key="r.cod_unic"
              type="button"
              class="flex w-full items-start gap-3 px-3 py-2 text-left hover:bg-(--ui-bg-elevated) transition-colors"
              @mousedown.prevent="selectRegistryCompany(r)"
            >
              <span
                class="mt-0.5 size-2 shrink-0 rounded-full"
                :class="r.radiat ? 'bg-red-500' : 'bg-green-500'"
              />
              <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-medium text-(--ui-text)">{{ r.denumire }}</div>
                <div class="truncate text-xs text-(--ui-text-muted)">
                  {{ r.cod_unic }}
                  <span v-if="r.localitate"> &middot; {{ r.localitate }}</span>
                </div>
              </div>
            </button>
          </div>
        </div>

        <!-- ANAF lookup button -->
        <div v-if="form.cif && form.cif.length >= 2 && !cifError" class="flex items-center gap-2">
          <UButton
            v-if="!anafSuccess"
            type="button"
            variant="soft"
            size="xs"
            icon="i-lucide-search-check"
            :loading="anafLookup"
            @click="lookupAnaf"
          >
            {{ $t('clients.lookupAnaf') }}
          </UButton>
          <span v-if="anafSuccess" class="text-xs text-(--ui-text-highlighted)">{{ $t('clients.anafLookupSuccess') }}</span>
          <span v-if="anafError" class="text-xs text-(--ui-error)">{{ $t('clients.anafLookupError') }}</span>
        </div>

        <!-- Registration Number -->
        <UFormField :label="$t('suppliers.registrationNumber')" required>
          <UInput v-model="form.registrationNumber" :class="{ 'anaf-highlight': anafPopulatedFields.has('registrationNumber') }" placeholder="ex: J40/14772/2007" icon="i-lucide-file-text" />
        </UFormField>

        <!-- Country -->
        <UFormField :label="$t('clients.country')" required>
          <USelectMenu
            v-model="form.country"
            :items="countryOptions"
            value-key="value"
            :placeholder="$t('clients.selectCountry')"
            :search-input="true"
            :ui="{ content: 'z-[200]' }"
          />
        </UFormField>

        <!-- County (only for Romania) -->
        <UFormField v-if="form.country === 'RO'" :label="$t('clients.county')" required>
          <USelectMenu
            v-model="form.county"
            :class="{ 'anaf-highlight': anafPopulatedFields.has('county') }"
            :items="countyOptions"
            value-key="value"
            :placeholder="$t('clients.selectCounty')"
            :search-input="true"
            :ui="{ content: 'z-[200]' }"
          />
        </UFormField>

        <!-- City + Address -->
        <div class="grid grid-cols-2 gap-4">
          <UFormField :label="$t('clients.city')" required>
            <USelectMenu
              v-if="form.country === 'RO' && form.county"
              v-model="form.city"
              :class="{ 'anaf-highlight': anafPopulatedFields.has('city') }"
              :items="cityOptions"
              value-key="value"
              :placeholder="$t('clients.selectCity')"
              :search-input="true"
              :ignore-filter="true"
              :ui="{ content: 'z-[200]' }"
              @update:search-term="onCitySearch"
            />
            <UInput v-else v-model="form.city" :class="{ 'anaf-highlight': anafPopulatedFields.has('city') }" />
          </UFormField>
          <UFormField :label="$t('clients.address')" required>
            <UInput v-model="form.address" :class="{ 'anaf-highlight': anafPopulatedFields.has('address') }" />
          </UFormField>
        </div>

        <!-- Contact -->
        <div class="grid grid-cols-2 gap-4">
          <UFormField :label="$t('common.email')">
            <UInput v-model="form.email" type="email" />
          </UFormField>
          <UFormField :label="$t('common.phone')">
            <UInput v-model="form.phone" />
          </UFormField>
        </div>

        <!-- VAT Payer -->
        <div class="flex items-center justify-between" :class="{ 'anaf-highlight rounded-lg px-2 py-1': anafPopulatedFields.has('isVatPayer') }">
          <span class="text-sm font-medium text-(--ui-text)">{{ $t('suppliers.isVatPayer') }}</span>
          <USwitch v-model="form.isVatPayer" />
        </div>

        <!-- Bank -->
        <div class="grid grid-cols-2 gap-4">
          <UFormField :label="$t('suppliers.bankName')">
            <UInput v-model="form.bankName" />
          </UFormField>
          <UFormField :label="$t('suppliers.bankAccount')">
            <UInput v-model="form.bankAccount" />
          </UFormField>
        </div>

        <!-- Notes -->
        <UFormField :label="$t('suppliers.notes')">
          <UTextarea v-model="form.notes" :rows="2" />
        </UFormField>
      </div>
    </template>
    <template #footer>
      <div class="flex gap-2 justify-end">
        <UButton variant="ghost" type="button" @click="open = false">{{ $t('common.cancel') }}</UButton>
        <UButton :loading="saving" :disabled="!canSave" type="button" @click="onSave">{{ $t('common.save') }}</UButton>
      </div>
    </template>
  </UModal>
</template>

<script setup lang="ts">
import type { Supplier } from '~/types'
import type { RegistryCompany } from '~/composables/useRegistrySearch'

const props = defineProps<{
  supplier?: Supplier | null
}>()

const open = defineModel<boolean>('open', { default: false })

const emit = defineEmits<{
  saved: [supplier: Supplier]
}>()

const isEdit = computed(() => !!props.supplier)

const { t: $t } = useI18n()
const store = useSupplierStore()
const { get } = useApi()
const { fetchDefaults, countryOptions, countyOptions } = useInvoiceDefaults()
const { results: registryResults, loading: registryLoading, onRegistrySearch, clear: clearRegistry } = useRegistrySearch()

const cityOptions = ref<{ label: string, value: string }[]>([])
const citySearchTimeout = ref<ReturnType<typeof setTimeout>>()

const skipCityReset = ref(false)

const saving = ref(false)
const anafLookup = ref(false)
const anafSuccess = ref(false)
const anafError = ref(false)
const cifError = ref('')
const registryDropdownOpen = ref(false)
const selectingCui = ref<string | null>(null)
const anafPopulatedFields = ref<Set<string>>(new Set())

const form = reactive({
  name: '',
  cif: '',
  country: 'RO',
  county: undefined as string | undefined,
  city: '',
  address: '',
  email: '',
  phone: '',
  vatCode: null as string | null,
  isVatPayer: false,
  registrationNumber: '',
  bankName: '',
  bankAccount: '',
  notes: '',
})

// Fetch defaults to populate country/county options
fetchDefaults()

// Populate form when supplier prop changes (edit mode)
watch(() => props.supplier, (s) => {
  if (s) {
    populateForm(s)
  }
}, { immediate: true })

// Reset form when modal closes in create mode
watch(open, (isOpen) => {
  if (isOpen && props.supplier) {
    populateForm(props.supplier)
  }
  if (!isOpen && !props.supplier) {
    resetForm()
  }
})

function populateForm(s: Supplier) {
  skipCityReset.value = true
  form.name = s.name || ''
  form.cif = s.cif || ''
  form.country = s.country || 'RO'
  form.county = s.county || undefined
  form.city = s.city || ''
  form.address = s.address || ''
  form.email = s.email || ''
  form.phone = s.phone || ''
  form.vatCode = s.vatCode || null
  form.isVatPayer = s.isVatPayer ?? false
  form.registrationNumber = s.registrationNumber || ''
  form.bankName = s.bankName || ''
  form.bankAccount = s.bankAccount || ''
  form.notes = s.notes || ''
  cifError.value = ''
  anafSuccess.value = false
  anafError.value = false
  if (s.county && s.country === 'RO') {
    fetchCities(s.county)
  }
}

// Fetch cities when county changes
watch(() => form.county, (newCounty) => {
  if (skipCityReset.value) {
    skipCityReset.value = false
  } else {
    form.city = ''
  }
  cityOptions.value = []
  if (newCounty && form.country === 'RO') {
    fetchCities(newCounty)
  }
})

async function fetchCities(county: string, search = '') {
  try {
    const res = await get<{ data: { label: string, value: string }[] }>('/v1/company-registry/cities', { county, q: search })
    cityOptions.value = res?.data ?? []
  } catch {
    cityOptions.value = []
  }
}

function onCitySearch(term: string) {
  clearTimeout(citySearchTimeout.value)
  if (!form.county) return
  citySearchTimeout.value = setTimeout(() => {
    fetchCities(form.county!, term)
  }, 300)
}

const canSave = computed(() => {
  if (!form.name) return false
  if (!form.country) return false
  if (!form.county) return false
  if (!form.city) return false
  if (!form.address) return false
  if (!form.registrationNumber) return false
  if (form.cif && cifError.value) return false
  return true
})

function validateCif() {
  if (!form.cif) {
    cifError.value = ''
    return
  }
  const cleaned = form.cif.replace(/^RO/i, '').trim()
  if (!/^\d{1,10}$/.test(cleaned)) {
    cifError.value = $t('clients.invalidCif')
  } else {
    cifError.value = ''
  }
}

function onCifInput() {
  const q = form.cif.trim()
  if (q.length >= 2) {
    registryDropdownOpen.value = true
    onRegistrySearch(q)
  } else {
    registryDropdownOpen.value = false
    clearRegistry()
  }
}

async function selectRegistryCompany(r: RegistryCompany) {
  selectingCui.value = r.cod_unic
  registryDropdownOpen.value = false
  clearRegistry()
  form.cif = r.cod_unic
  form.name = r.denumire
  if (r.cod_inmatriculare) {
    form.registrationNumber = r.cod_inmatriculare
  }
  await lookupAnaf()
  selectingCui.value = null
}

async function lookupAnaf() {
  if (!form.cif) return
  validateCif()
  if (cifError.value) return

  anafLookup.value = true
  anafSuccess.value = false
  anafError.value = false

  try {
    const res = await get<{ data: Record<string, any> }>('/v1/clients/anaf-lookup', { cui: form.cif })
    if (res?.data) {
      const d = res.data
      const populated = new Set<string>()
      if (d.name) { form.name = d.name; populated.add('name') }
      if (d.address) { form.address = d.address; populated.add('address') }
      if (d.city) { form.city = d.city; populated.add('city') }
      if (d.county) { skipCityReset.value = true; form.county = d.county; populated.add('county') }
      if (d.vatCode !== undefined) { form.vatCode = d.vatCode || null }
      if (d.isVatPayer !== undefined) { form.isVatPayer = d.isVatPayer; populated.add('isVatPayer') }
      if (d.registrationNumber) { form.registrationNumber = d.registrationNumber; populated.add('registrationNumber') }
      if (d.cui) { form.cif = d.cui; populated.add('cif') }
      if (d.county && form.country === 'RO') {
        await fetchCities(d.county)
      }
      anafPopulatedFields.value = populated
      anafSuccess.value = true
    }
  } catch {
    anafError.value = true
  } finally {
    anafLookup.value = false
  }
}

async function onSave() {
  if (!canSave.value) return
  saving.value = true

  const payload: Record<string, any> = {
    name: form.name,
    cif: form.cif || null,
    country: form.country,
    county: form.county || null,
    city: form.city || null,
    address: form.address || null,
    email: form.email || null,
    phone: form.phone || null,
    vatCode: form.vatCode,
    isVatPayer: form.isVatPayer,
    registrationNumber: form.registrationNumber || null,
    bankName: form.bankName || null,
    bankAccount: form.bankAccount || null,
    notes: form.notes || null,
  }

  if (isEdit.value && props.supplier) {
    const supplier = await store.updateSupplier(props.supplier.id, payload)
    saving.value = false
    if (supplier) {
      open.value = false
      emit('saved', supplier)
    }
    else {
      useToast().add({ title: store.error || 'Eroare la salvare.', color: 'error' })
    }
    return
  }

  const supplier = await store.createSupplier(payload)
  saving.value = false
  if (supplier) {
    open.value = false
    emit('saved', supplier)
    resetForm()
  }
}

function resetForm() {
  form.name = ''
  form.cif = ''
  form.country = 'RO'
  form.county = undefined
  form.city = ''
  form.address = ''
  form.email = ''
  form.phone = ''
  form.vatCode = null
  form.isVatPayer = false
  form.registrationNumber = ''
  form.bankName = ''
  form.bankAccount = ''
  form.notes = ''
  cifError.value = ''
  anafSuccess.value = false
  anafError.value = false
  anafPopulatedFields.value = new Set()
  cityOptions.value = []
  registryDropdownOpen.value = false
  selectingCui.value = null
  clearRegistry()
}
</script>

<style scoped>
.anaf-highlight {
  animation: anaf-flash 1.5s ease-out;
}

@keyframes anaf-flash {
  0% { box-shadow: 0 0 0 2px var(--ui-primary); }
  100% { box-shadow: none; }
}
</style>
