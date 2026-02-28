<template>
  <UModal v-model:open="open">
    <template #header>
      <h3 class="text-base font-semibold">{{ isEdit ? $t('clients.editClient') : $t('clients.addClient') }}</h3>
    </template>
    <template #body>
      <div class="space-y-4">
        <!-- Type chips -->
        <div class="flex gap-2">
          <button
            v-for="t in typeOptions"
            :key="t.value"
            type="button"
            class="px-4 py-2 rounded-full text-sm font-semibold border transition-colors cursor-pointer"
            :class="form.type === t.value
              ? 'bg-primary/10 border-primary text-primary'
              : 'bg-(--ui-bg-elevated) border-(--ui-border) text-(--ui-text-muted) hover:border-(--ui-text-muted)'"
            @click="form.type = t.value"
          >
            {{ t.label }}
          </button>
        </div>

        <!-- CUI / CNP + Name -->
        <div v-if="form.type === 'company'" class="relative">
          <!-- Click-outside overlay to close registry dropdown -->
          <div
            v-if="registryDropdownOpen && !isEdit"
            class="fixed inset-0 z-[199]"
            @click="registryDropdownOpen = false"
          />
          <div class="grid grid-cols-2 gap-4">
            <UFormField :label="$t('clients.cui')" :error="cifError">
              <UInput
                v-model="form.cui"
                :class="{ 'anaf-highlight': anafPopulatedFields.has('cui') }"
                :placeholder="isEdit ? 'ex: 12345678' : $t('clients.cuiSearchPlaceholder')"
                icon="i-lucide-search"
                :loading="registryLoading"
                @input="!isEdit && onCuiInput()"
                @blur="validateCif"
              />
            </UFormField>
            <UFormField :label="$t('invoices.receiverName')" required>
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

        <!-- CNP + Name (individual type) -->
        <div v-else class="grid grid-cols-2 gap-4">
          <UFormField label="CNP" :error="cnpError">
            <UInput v-model="form.cnp" placeholder="ex: 1234567890123" icon="i-lucide-hash" @blur="validateCnp" />
          </UFormField>
          <UFormField :label="$t('invoices.receiverName')" required>
            <UInput v-model="form.name" :placeholder="$t('invoices.receiverNamePlaceholder')" icon="i-lucide-building-2" />
          </UFormField>
        </div>

        <!-- ANAF lookup button (company type with CUI, hidden after successful registry auto-lookup) -->
        <div v-if="form.type === 'company' && form.cui && form.cui.length >= 2 && !cifError" class="flex items-center gap-2">
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

        <!-- Registrul Comertului (company only) -->
        <UFormField v-if="form.type === 'company'" :label="$t('clients.registrationNumber')" required>
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

        <!-- City (searchable dropdown when RO + county selected, plain input otherwise) -->
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

        <!-- Contact (optional) -->
        <div class="grid grid-cols-2 gap-4">
          <UFormField :label="$t('common.email')">
            <UInput v-model="form.email" type="email" />
          </UFormField>
          <UFormField :label="$t('common.phone')">
            <UInput v-model="form.phone" />
          </UFormField>
        </div>

        <!-- VAT Payer (company only) -->
        <div v-if="form.type === 'company'" class="flex items-center justify-between" :class="{ 'anaf-highlight rounded-lg px-2 py-1': anafPopulatedFields.has('isVatPayer') }">
          <span class="text-sm font-medium text-(--ui-text)">{{ $t('clients.isVatPayer') }}</span>
          <USwitch v-model="form.isVatPayer" />
        </div>
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
import type { Client } from '~/types'
import type { RegistryCompany } from '~/composables/useRegistrySearch'

const props = defineProps<{
  client?: Client | null
  prefill?: Record<string, any> | null
}>()

const open = defineModel<boolean>('open', { default: false })

const emit = defineEmits<{
  saved: [client: Client]
}>()

const isEdit = computed(() => !!props.client)

const { t: $t } = useI18n()
const clientStore = useClientStore()
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
const cnpError = ref('')
const registryDropdownOpen = ref(false)
const selectingCui = ref<string | null>(null)
const anafPopulatedFields = ref<Set<string>>(new Set())

const typeOptions = computed(() => [
  { label: $t('clients.typeCompany'), value: 'company' },
  { label: $t('clients.typeIndividual'), value: 'individual' },
])

const form = reactive({
  type: 'company',
  name: '',
  cui: '',
  cnp: '',
  country: 'RO',
  county: undefined as string | undefined,
  city: '',
  address: '',
  email: '',
  phone: '',
  vatCode: null as string | null,
  isVatPayer: false,
  registrationNumber: '',
})

// Fetch defaults to populate country/county options
fetchDefaults()

// Populate form when client prop changes (edit mode)
watch(() => props.client, (c) => {
  if (c) {
    populateForm(c)
  }
}, { immediate: true })

// Populate form when prefill prop changes (registry → create mode)
watch(() => props.prefill, (p) => {
  if (p) {
    populateFromPrefill(p)
  }
}, { immediate: true })

// Reset form when modal closes in create mode
watch(open, (isOpen) => {
  if (isOpen && props.client) {
    populateForm(props.client)
  }
  if (!isOpen && !props.client) {
    resetForm()
  }
})

function populateForm(c: Client) {
  skipCityReset.value = true
  form.type = c.type || 'company'
  form.name = c.name || ''
  form.cui = c.cui || ''
  form.cnp = c.cnp || ''
  form.country = c.country || 'RO'
  form.county = c.county || undefined
  form.city = c.city || ''
  form.address = c.address || ''
  form.email = c.email || ''
  form.phone = c.phone || ''
  form.vatCode = c.vatCode || null
  form.isVatPayer = c.isVatPayer ?? false
  form.registrationNumber = c.registrationNumber || ''
  cifError.value = ''
  cnpError.value = ''
  anafSuccess.value = false
  anafError.value = false
  // Load cities for the county if editing
  if (c.county && c.country === 'RO') {
    fetchCities(c.county)
  }
}

function populateFromPrefill(p: Record<string, any>) {
  skipCityReset.value = true
  form.type = p.type || 'company'
  form.name = p.name || ''
  form.cui = p.cui || ''
  form.cnp = p.cnp || ''
  form.country = p.country || 'RO'
  form.county = p.county || undefined
  form.city = p.city || ''
  form.address = p.address || ''
  form.email = p.email || ''
  form.phone = p.phone || ''
  form.vatCode = p.vatCode || null
  form.isVatPayer = p.isVatPayer ?? false
  form.registrationNumber = p.registrationNumber || ''
  cifError.value = ''
  cnpError.value = ''
  anafSuccess.value = false
  anafError.value = false
  if (p.county && (p.country || 'RO') === 'RO') {
    fetchCities(p.county)
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
  if (form.type === 'company' && !form.registrationNumber) return false
  if (form.type === 'company' && form.cui && cifError.value) return false
  if (form.type === 'individual' && form.cnp && cnpError.value) return false
  return true
})

function validateCif() {
  if (!form.cui) {
    cifError.value = ''
    return
  }
  const cleaned = form.cui.replace(/^RO/i, '').trim()
  if (!/^\d{1,10}$/.test(cleaned)) {
    cifError.value = $t('clients.invalidCif')
  } else {
    cifError.value = ''
  }
}

function validateCnp() {
  if (!form.cnp) {
    cnpError.value = ''
    return
  }
  if (!/^[1-9]\d{12}$/.test(form.cnp.trim())) {
    cnpError.value = $t('clients.invalidCnp')
  } else {
    cnpError.value = ''
  }
}

function onCuiInput() {
  const q = form.cui.trim()
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
  form.cui = r.cod_unic
  form.name = r.denumire
  if (r.cod_inmatriculare) {
    form.registrationNumber = r.cod_inmatriculare
  }
  await lookupAnaf()
  selectingCui.value = null
}

async function lookupAnaf() {
  if (!form.cui) return
  validateCif()
  if (cifError.value) return

  anafLookup.value = true
  anafSuccess.value = false
  anafError.value = false

  try {
    const res = await get<{ data: Record<string, any> }>('/v1/clients/anaf-lookup', { cui: form.cui })
    if (res?.data) {
      const d = res.data
      const populated = new Set<string>()
      if (d.name) { form.name = d.name; populated.add('name') }
      if (d.address) { form.address = d.address; populated.add('address') }
      if (d.city) { form.city = d.city; populated.add('city') }
      // Prevent the county watcher from clearing the city we just set
      if (d.county) { skipCityReset.value = true; form.county = d.county; populated.add('county') }
      if (d.vatCode !== undefined) { form.vatCode = d.vatCode || null }
      if (d.isVatPayer !== undefined) { form.isVatPayer = d.isVatPayer; populated.add('isVatPayer') }
      if (d.registrationNumber) { form.registrationNumber = d.registrationNumber; populated.add('registrationNumber') }
      // Update CUI to the clean version from ANAF
      if (d.cui) { form.cui = d.cui; populated.add('cui') }
      // Load cities for the county to match the ANAF city value
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

  if (isEdit.value && props.client) {
    // Edit mode — PATCH
    const payload: Record<string, any> = {
      type: form.type,
      name: form.name,
      cui: form.type === 'company' ? (form.cui || null) : null,
      cnp: form.type === 'individual' ? (form.cnp || null) : null,
      country: form.country,
      county: form.county || null,
      city: form.city || null,
      address: form.address || null,
      email: form.email || null,
      phone: form.phone || null,
      vatCode: form.vatCode,
      isVatPayer: form.isVatPayer,
      registrationNumber: form.registrationNumber || null,
    }
    const client = await clientStore.updateClient(props.client.id, payload)
    saving.value = false
    if (client) {
      open.value = false
      emit('saved', client)
    }
    else {
      useToast().add({ title: clientStore.error || 'Eroare la salvare.', color: 'error' })
    }
    return
  }

  // Create mode — existing logic
  // If company with CUI, try ANAF-validated creation
  if (form.type === 'company' && form.cui) {
    const client = await clientStore.createClientFromRegistry(form.cui, form.name)
    if (client) {
      saving.value = false
      open.value = false
      emit('saved', client)
      resetForm()
      return
    }
  }

  // Manual creation
  const client = await clientStore.createClient({
    type: form.type,
    name: form.name,
    cui: form.type === 'company' && form.cui ? form.cui : undefined,
    cnp: form.type === 'individual' && form.cnp ? form.cnp : undefined,
    country: form.country,
    county: form.county || undefined,
    city: form.city || undefined,
    address: form.address || undefined,
    email: form.email || undefined,
    phone: form.phone || undefined,
    vatCode: form.vatCode,
    isVatPayer: form.isVatPayer,
    registrationNumber: form.registrationNumber || undefined,
  })

  saving.value = false
  if (client) {
    open.value = false
    emit('saved', client)
    resetForm()
  }
}

function clearAnafHighlights() {
  anafPopulatedFields.value = new Set()
}

function resetForm() {
  form.type = 'company'
  form.name = ''
  form.cui = ''
  form.cnp = ''
  form.country = 'RO'
  form.county = undefined
  form.city = ''
  form.address = ''
  form.email = ''
  form.phone = ''
  form.vatCode = null
  form.isVatPayer = false
  form.registrationNumber = ''
  cifError.value = ''
  cnpError.value = ''
  anafSuccess.value = false
  anafError.value = false
  cityOptions.value = []
  registryDropdownOpen.value = false
  selectingCui.value = null
  clearAnafHighlights()
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
