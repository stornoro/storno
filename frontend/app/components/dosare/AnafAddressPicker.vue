<script setup lang="ts">
/**
 * Address the way ANAF declarations want it: county, locality and street as
 * nomenclator codes (looked up from Storno's local mirror), plus number, details
 * and postal code. Emits the object the C168 builder expects.
 */
interface Coded {
  tara?: string
  judet?: string
  localitate?: string
  localitateNume?: string
  strada?: string
  stradaNume?: string
  numar?: string
  detalii?: string
  codPostal?: string
}
interface Item { code: string, name: string }

const props = defineProps<{ modelValue: Coded | null | undefined, requirePostal?: boolean }>()
const emit = defineEmits<{ (e: 'update:modelValue', v: Coded): void }>()
const { t: $t } = useI18n()
const { get } = useApi()

const value = reactive<Coded>({ tara: 'RO', ...(props.modelValue ?? {}) })
watch(value, () => emit('update:modelValue', { ...value }), { deep: true })

const judete = ref<Item[]>([])
const localitati = ref<Item[]>([])
const strazi = ref<Item[]>([])
const streetQuery = ref('')
const loadingStreets = ref(false)

onMounted(async () => {
  try {
    judete.value = (await get<{ data: Item[] }>('/v1/public/anaf/nomenclator/judete')).data
  } catch { judete.value = [] }
  if (value.judet) await loadLocalitati()
  if (value.judet && value.localitate) await loadStrazi()
})

async function loadLocalitati() {
  localitati.value = []
  if (!value.judet) return
  try {
    localitati.value = (await get<{ data: Item[] }>(`/v1/public/anaf/nomenclator/localitati/${value.judet}`)).data
  } catch { localitati.value = [] }
}
async function loadStrazi() {
  strazi.value = []
  if (!value.judet || !value.localitate) return
  loadingStreets.value = true
  try {
    strazi.value = (await get<{ data: Item[] }>(`/v1/public/anaf/nomenclator/strazi/${value.judet}/${value.localitate}`, streetQuery.value ? { q: streetQuery.value } : {})).data
  } catch { strazi.value = [] }
  finally { loadingStreets.value = false }
}
watch(() => value.judet, async () => { value.localitate = undefined; value.localitateNume = undefined; value.strada = undefined; value.stradaNume = undefined; await loadLocalitati() })
watch(() => value.localitate, async (code) => {
  value.localitateNume = localitati.value.find(l => l.code === code)?.name ?? value.localitateNume
  value.strada = undefined
  value.stradaNume = undefined
  await loadStrazi()
})
watch(() => value.strada, (code) => { value.stradaNume = strazi.value.find(s => s.code === code)?.name ?? value.stradaNume })
let streetTimer: ReturnType<typeof setTimeout> | null = null
watch(streetQuery, () => { if (streetTimer) clearTimeout(streetTimer); streetTimer = setTimeout(loadStrazi, 250) })

const judetItems = computed(() => judete.value.map(j => ({ label: `${j.name} (${j.code})`, value: j.code })))
const localitateItems = computed(() => localitati.value.map(l => ({ label: l.name, value: l.code })))
const stradaItems = computed(() => strazi.value.map(s => ({ label: s.name, value: s.code })))
</script>

<template>
  <div class="grid grid-cols-2 gap-3">
    <UFormField :label="$t('dosare.address.county')" required>
      <USelectMenu v-model="value.judet" :items="judetItems" value-key="value" :search-input="{ placeholder: $t('dosare.address.search') }" class="w-full" />
    </UFormField>
    <UFormField :label="$t('dosare.address.locality')" required>
      <USelectMenu v-model="value.localitate" :items="localitateItems" value-key="value" :search-input="{ placeholder: $t('dosare.address.search') }" :disabled="!value.judet" class="w-full" />
    </UFormField>
    <UFormField :label="$t('dosare.address.street')" required class="col-span-2">
      <USelectMenu v-model="value.strada" v-model:search-term="streetQuery" :items="stradaItems" value-key="value" :loading="loadingStreets" :disabled="!value.localitate" :search-input="{ placeholder: $t('dosare.address.searchStreet') }" class="w-full" />
    </UFormField>
    <UFormField :label="$t('dosare.address.number')" required>
      <UInput v-model="value.numar" class="w-full" />
    </UFormField>
    <UFormField :label="$t('dosare.address.postal')" :required="requirePostal">
      <UInput v-model="value.codPostal" maxlength="6" class="w-full" />
    </UFormField>
    <UFormField :label="$t('dosare.address.details')" class="col-span-2">
      <UInput v-model="value.detalii" :placeholder="$t('dosare.address.detailsPlaceholder')" class="w-full" />
    </UFormField>
  </div>
</template>
