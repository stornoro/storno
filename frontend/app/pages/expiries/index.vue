<script setup lang="ts">
import type { ExpiryItem, ExpiryKind } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const store = useFleetStore()
const toast = useToast()

useHead({ title: $t('fleet.expiriesTitle') })

const KINDS: ExpiryKind[] = ['rca', 'itp', 'rovinieta', 'casco', 'tahograf', 'extinctor', 'trusa_medicala', 'licenta_transport', 'copie_conforma', 'leasing', 'certificat_digital', 'contract', 'autorizatie', 'other']
const kind = ref<string>('')
const vehicleId = ref<string>('')
const includeClosed = ref(false)

async function load() {
  const params: Record<string, any> = {}
  if (kind.value) params.kind = kind.value
  if (vehicleId.value === 'company') params.companyLevel = 1
  else if (vehicleId.value) params.vehicleId = vehicleId.value
  if (includeClosed.value) params.includeClosed = 1
  await store.fetchExpiries(params)
}
onMounted(async () => {
  await Promise.all([store.fetchVehicles(), load()])
})
watch([kind, vehicleId, includeClosed], load)

const kindItems = computed(() => [{ label: $t('fleet.filters.allKinds'), value: '' }, ...KINDS.map(k => ({ label: $t(`fleet.kinds.${k}`), value: k }))])
const vehicleItems = computed(() => [
  { label: $t('fleet.filters.allVehicles'), value: '' },
  { label: $t('fleet.form.companyLevel'), value: 'company' },
  ...store.vehicles.map(v => ({ label: v.displayName, value: v.id })),
])

/** overdue first, then by date — the API already sorts by date; keep expired on top even with mixed labels */
const ordered = computed(() => {
  const rank: Record<string, number> = { expired: 0, due: 1, ok: 2, renewed: 3 }
  return [...store.expiries].sort((a, b) => (rank[a.status] ?? 9) - (rank[b.status] ?? 9) || a.expiresAt.localeCompare(b.expiresAt))
})

const expiryOpen = ref(false)
const editing = ref<ExpiryItem | null>(null)
const renewOpen = ref(false)
const renewing = ref<ExpiryItem | null>(null)
function openAdd() { editing.value = null; expiryOpen.value = true }
function openEdit(item: ExpiryItem) { editing.value = item; expiryOpen.value = true }
function openRenew(item: ExpiryItem) { renewing.value = item; renewOpen.value = true }
async function remove(item: ExpiryItem) {
  if (!confirm($t('fleet.confirmDeleteExpiry', { label: item.label }))) return
  try {
    await store.deleteExpiry(item.id)
    toast.add({ title: $t('fleet.deleted'), color: 'success' })
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('common.error'), color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('fleet.expiriesTitle')">
        <template #leading>
          <UButton icon="i-lucide-arrow-left" color="neutral" variant="ghost" to="/vehicles" />
        </template>
        <template #right>
          <UButton icon="i-lucide-plus" @click="openAdd">{{ $t('fleet.newExpiry') }}</UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="p-4 space-y-4">
        <p class="text-sm text-muted">{{ $t('fleet.expiriesSubtitle') }}</p>

        <div class="grid grid-cols-3 gap-3">
          <UCard :ui="{ body: 'p-3 sm:p-4' }">
            <div class="text-xs text-muted">{{ $t('fleet.counts.expired') }}</div>
            <div class="text-2xl font-bold" :class="store.expiryCounts.expired ? 'text-error' : ''">{{ store.expiryCounts.expired }}</div>
          </UCard>
          <UCard :ui="{ body: 'p-3 sm:p-4' }">
            <div class="text-xs text-muted">{{ $t('fleet.counts.due') }}</div>
            <div class="text-2xl font-bold" :class="store.expiryCounts.due ? 'text-warning' : ''">{{ store.expiryCounts.due }}</div>
          </UCard>
          <UCard :ui="{ body: 'p-3 sm:p-4' }">
            <div class="text-xs text-muted">{{ $t('fleet.counts.ok') }}</div>
            <div class="text-2xl font-bold">{{ store.expiryCounts.ok }}</div>
          </UCard>
        </div>

        <div class="flex flex-wrap items-center gap-3">
          <USelectMenu v-model="kind" :items="kindItems" value-key="value" class="w-56" />
          <USelectMenu v-model="vehicleId" :items="vehicleItems" value-key="value" class="w-64" />
          <UCheckbox v-model="includeClosed" :label="$t('fleet.filters.includeClosed')" />
        </div>

        <UAlert v-if="store.error" color="error" variant="soft" icon="i-lucide-alert-circle" :title="store.error" />

        <UCard :ui="{ body: 'p-0 sm:p-0' }">
          <FleetExpiryTable :items="ordered" :loading="store.loading" show-vehicle @renew="openRenew" @edit="openEdit" @remove="remove" />
        </UCard>
      </div>

      <FleetExpiryModal v-model:open="expiryOpen" :item="editing" :vehicles="store.vehicles" @saved="load" />
      <FleetRenewModal v-model:open="renewOpen" :item="renewing" @renewed="load" />
    </template>
  </UDashboardPanel>
</template>
