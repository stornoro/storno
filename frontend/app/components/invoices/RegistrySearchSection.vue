<template>
  <div class="relative">
    <!-- Search input -->
    <UInput
      ref="inputRef"
      v-model="searchQuery"
      :placeholder="$t('invoices.clientSearchHint')"
      icon="i-lucide-search"
      :loading="clientSearching || registryLoading"
      size="xl"
      autocomplete="off"
      @focus="dropdownOpen = true"
      @input="onSearch"
    />

    <!-- Dropdown results -->
    <div
      v-if="showDropdown"
      class="absolute z-[100] mt-1 w-full max-h-72 overflow-y-auto rounded-lg border border-(--ui-border) bg-(--ui-bg) shadow-lg"
    >
      <!-- Existing clients -->
      <template v-if="visibleClients.length > 0">
        <div class="px-3 pt-2 pb-1">
          <span class="text-xs font-semibold text-(--ui-text-muted) uppercase">{{ $t('invoices.client') }}</span>
        </div>
        <button
          v-for="c in visibleClients"
          :key="c.id"
          type="button"
          class="w-full flex items-center gap-3 px-3 py-2 text-left transition-colors hover:bg-(--ui-bg-elevated) cursor-pointer"
          @mousedown.prevent="selectClient(c)"
        >
          <div class="flex items-center justify-center size-8 rounded-full bg-primary/10 text-primary shrink-0">
            <UIcon name="i-lucide-building-2" class="size-4" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-medium truncate">{{ c.name }}</div>
            <div class="text-xs text-(--ui-text-muted)">{{ c.cui || c.cnp || '-' }}<span v-if="c.city"> &middot; {{ c.city }}</span></div>
          </div>
        </button>
      </template>

      <!-- Registry results (only when searching) -->
      <template v-if="searchQuery.length >= 2 && registryResults.length > 0">
        <div v-if="visibleClients.length > 0" class="border-t border-(--ui-border)" />
        <div class="px-3 pt-2 pb-1">
          <span class="text-xs font-semibold text-(--ui-text-muted) uppercase">{{ $t('invoices.registryResults') }}</span>
        </div>
        <button
          v-for="r in registryResults"
          :key="r.cod_unic"
          type="button"
          class="w-full flex items-center gap-3 px-3 py-2 text-left transition-colors hover:bg-(--ui-bg-elevated) cursor-pointer"
          :disabled="addingCui === r.cod_unic"
          @mousedown.prevent="selectRegistry(r)"
        >
          <div class="flex items-center justify-center size-8 rounded-full shrink-0" :class="r.radiat ? 'bg-red-100 dark:bg-red-900/30 text-red-500' : 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400'">
            <UIcon :name="addingCui === r.cod_unic ? 'i-lucide-loader-2' : 'i-lucide-building-2'" :class="addingCui === r.cod_unic ? 'animate-spin' : ''" class="size-4" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-medium truncate">{{ r.denumire }}</div>
            <div class="text-xs text-(--ui-text-muted)">
              CUI: {{ r.cod_unic }}<span v-if="r.nume_judet"> &middot; {{ r.localitate || r.nume_judet }}</span>
            </div>
          </div>
          <UBadge v-if="r.radiat" color="error" variant="subtle" size="xs">{{ $t('invoices.registryDeregistered') }}</UBadge>
          <UBadge v-else color="primary" variant="subtle" size="xs">
            <UIcon name="i-lucide-plus" class="size-3 mr-0.5" />{{ $t('common.add') }}
          </UBadge>
        </button>
      </template>
    </div>

    <!-- Click-outside overlay (only when dropdown has content) -->
    <div v-if="showDropdown" class="fixed inset-0 z-[99]" @click="dropdownOpen = false" />

    <!-- "Add manually" link below -->
    <div class="flex items-center justify-between mt-2">
      <p v-if="searchQuery.length < 2 && clientResults.length === 0" class="text-xs text-(--ui-text-muted)">
        {{ $t('invoices.clientSearchHint') }}
      </p>
      <span v-else />
      <UButton variant="link" size="xs" icon="i-lucide-plus" @click="$emit('create')">
        {{ $t('clients.addClient') }}
      </UButton>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { Client } from '~/types'
import type { RegistryCompany } from '~/composables/useRegistrySearch'

const emit = defineEmits<{
  'select-client': [client: Client]
  'select-registry': [client: Client]
  'prefill-create': [data: Record<string, any>]
  create: []
}>()

const { t: $t } = useI18n()
const clientStore = useClientStore()
const { results: registryResults, loading: registryLoading, onRegistrySearch } = useRegistrySearch()

const inputRef = ref()
const searchQuery = ref('')
const dropdownOpen = ref(false)
const clientResults = ref<Client[]>([])
const clientSearching = ref(false)
const addingCui = ref<string | null>(null)

// Clients visible in dropdown: search results when typing, all clients when empty
const visibleClients = computed(() => {
  if (searchQuery.value.length >= 2) return clientResults.value
  return clientStore.items
})

// Only show dropdown when it actually has content
const showDropdown = computed(() => {
  if (!dropdownOpen.value) return false
  if (visibleClients.value.length > 0) return true
  if (searchQuery.value.length >= 2 && registryResults.value.length > 0) return true
  return false
})

const onSearch = useDebounceFn(async () => {
  const q = searchQuery.value
  if (!q || q.length < 2) {
    clientResults.value = []
    return
  }
  clientSearching.value = true
  dropdownOpen.value = true
  try {
    await clientStore.setSearch(q)
    await clientStore.fetchClients()
    clientResults.value = clientStore.items
  }
  finally {
    clientSearching.value = false
  }
  // Also trigger registry search in parallel
  onRegistrySearch(q)
}, 300)

function selectClient(client: Client) {
  dropdownOpen.value = false
  searchQuery.value = ''
  emit('select-client', client)
}

async function selectRegistry(company: RegistryCompany) {
  addingCui.value = company.cod_unic
  try {
    // Check if client already exists in DB
    const existing = clientStore.items.find(c => c.cui === company.cod_unic)
    if (existing) {
      dropdownOpen.value = false
      searchQuery.value = ''
      emit('select-client', existing)
      return
    }

    // ANAF lookup to get full details, then open modal for user to review & save
    const { get } = useApi()
    let prefill: Record<string, any> = {
      type: 'company',
      cui: company.cod_unic,
      name: company.denumire,
    }

    try {
      const res = await get<{ data: Record<string, any> }>('/v1/clients/anaf-lookup', { cui: company.cod_unic })
      if (res?.data) {
        const d = res.data
        prefill = {
          type: 'company',
          cui: d.cui || company.cod_unic,
          name: d.name || company.denumire,
          address: d.address || null,
          city: d.city || null,
          county: d.county || null,
          vatCode: d.vatCode || null,
          isVatPayer: d.isVatPayer ?? false,
          registrationNumber: d.registrationNumber || null,
        }
      }
    }
    catch {
      // ANAF lookup failed — use basic registry data
    }

    dropdownOpen.value = false
    searchQuery.value = ''
    emit('prefill-create', prefill)
  }
  finally {
    addingCui.value = null
  }
}
</script>
