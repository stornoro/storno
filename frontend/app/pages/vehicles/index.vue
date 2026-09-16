<script setup lang="ts">
import type { Vehicle, VehicleOwnership } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const store = useFleetStore()
const toast = useToast()
const router = useRouter()
const intlLocale = useIntlLocale()

useHead({ title: $t('fleet.title') })

const showInactive = ref(false)
const search = ref('')
onMounted(() => Promise.all([store.fetchVehicles(), store.fetchUpcoming(60)]))
watch(showInactive, () => store.fetchVehicles(showInactive.value ? {} : { active: true }))

const shown = computed(() => {
  const q = search.value.trim().toLowerCase()
  return store.vehicles.filter(v => (showInactive.value || v.active) && (!q || v.displayName.toLowerCase().includes(q) || (v.driverName ?? '').toLowerCase().includes(q)))
})

// ── Create / edit ──────────────────────────────────────────────────
const OWNERSHIPS: VehicleOwnership[] = ['own', 'leasing', 'rented']
const FUELS = ['benzina', 'motorina', 'gpl', 'hibrid', 'electric', 'altul']
const modalOpen = ref(false)
const saving = ref(false)
const editing = ref<Vehicle | null>(null)
const form = reactive({ plate: '', vin: '', make: '', model: '', year: '' as string | number, fuel: '', ownership: 'own' as VehicleOwnership, driverName: '', notes: '', active: true })
const ownershipItems = computed(() => OWNERSHIPS.map(o => ({ label: $t(`fleet.ownership.${o}`), value: o })))
const fuelItems = computed(() => [{ label: '—', value: '' }, ...FUELS.map(f => ({ label: $t(`fleet.fuel.${f}`), value: f }))])

function openCreate() {
  editing.value = null
  Object.assign(form, { plate: '', vin: '', make: '', model: '', year: '', fuel: '', ownership: 'own', driverName: '', notes: '', active: true })
  modalOpen.value = true
}
function openEdit(v: Vehicle) {
  editing.value = v
  Object.assign(form, { plate: v.plate, vin: v.vin ?? '', make: v.make ?? '', model: v.model ?? '', year: v.year ?? '', fuel: v.fuel ?? '', ownership: v.ownership, driverName: v.driverName ?? '', notes: v.notes ?? '', active: v.active })
  modalOpen.value = true
}
async function submit() {
  saving.value = true
  try {
    const payload: Record<string, any> = { plate: form.plate, vin: form.vin || null, make: form.make || null, model: form.model || null, year: form.year ? Number(form.year) : null, fuel: form.fuel || null, ownership: form.ownership, driverName: form.driverName || null, notes: form.notes || null, active: form.active }
    if (editing.value) {
      await store.updateVehicle(editing.value.id, payload)
      toast.add({ title: $t('fleet.saved'), color: 'success' })
      modalOpen.value = false
      await store.fetchVehicles(showInactive.value ? {} : { active: true })
    } else {
      const detail = await store.createVehicle(payload)
      toast.add({ title: $t('fleet.created'), color: 'success' })
      modalOpen.value = false
      router.push(`/vehicles/${detail.vehicle.id}`)
    }
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? e?.message ?? $t('common.error'), color: 'error' })
  } finally {
    saving.value = false
  }
}
async function remove(v: Vehicle) {
  if (!confirm($t('fleet.confirmDeleteVehicle', { name: v.displayName }))) return
  try {
    await store.deleteVehicle(v.id)
    toast.add({ title: $t('fleet.deleted'), color: 'success' })
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('common.error'), color: 'error' })
  }
}

// ── Presentation ───────────────────────────────────────────────────
const statusColor: Record<string, 'error' | 'warning' | 'success' | 'neutral'> = { expired: 'error', due: 'warning', ok: 'success' }
function formatDate(iso: string): string {
  return new Date(iso + 'T00:00:00').toLocaleDateString(intlLocale, { day: 'numeric', month: 'short', year: 'numeric' })
}
function daysText(days: number): string {
  return days < 0 ? $t('fleet.daysOverdue', { count: -days }, -days) : $t('fleet.daysLeft', { count: days }, days)
}
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('fleet.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-calendar-clock" color="neutral" variant="ghost" to="/expiries">{{ $t('fleet.allExpiries') }}</UButton>
          <UButton icon="i-lucide-plus" @click="openCreate">{{ $t('fleet.newVehicle') }}</UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="p-4 space-y-4">
        <p class="text-sm text-muted">{{ $t('fleet.subtitle') }}</p>

        <!-- Summary of the next 60 days -->
        <div class="grid grid-cols-3 gap-3">
          <UCard :ui="{ body: 'p-3 sm:p-4' }">
            <div class="text-xs text-muted">{{ $t('fleet.counts.expired') }}</div>
            <div class="text-2xl font-bold" :class="store.upcomingCounts.expired ? 'text-error' : ''">{{ store.upcomingCounts.expired }}</div>
          </UCard>
          <UCard :ui="{ body: 'p-3 sm:p-4' }">
            <div class="text-xs text-muted">{{ $t('fleet.counts.due60') }}</div>
            <div class="text-2xl font-bold" :class="store.upcomingCounts.due ? 'text-warning' : ''">{{ store.upcomingCounts.due }}</div>
          </UCard>
          <UCard :ui="{ body: 'p-3 sm:p-4' }">
            <div class="text-xs text-muted">{{ $t('fleet.counts.vehicles') }}</div>
            <div class="text-2xl font-bold">{{ store.vehicles.filter(v => v.active).length }}</div>
          </UCard>
        </div>

        <div class="flex flex-wrap items-center gap-3">
          <UInput v-model="search" icon="i-lucide-search" :placeholder="$t('fleet.search')" class="w-64" />
          <UCheckbox v-model="showInactive" :label="$t('fleet.showInactive')" />
        </div>

        <UAlert v-if="store.error" color="error" variant="soft" icon="i-lucide-alert-circle" :title="store.error" />

        <div v-if="store.loading" class="space-y-3"><USkeleton class="h-16 w-full" /><USkeleton class="h-16 w-full" /></div>
        <UCard v-else-if="!shown.length">
          <div class="text-center py-8 space-y-3">
            <UIcon name="i-lucide-car" class="text-4xl text-muted" />
            <p class="text-sm text-muted max-w-md mx-auto">{{ $t('fleet.empty') }}</p>
            <UButton icon="i-lucide-plus" @click="openCreate">{{ $t('fleet.newVehicle') }}</UButton>
          </div>
        </UCard>
        <UCard v-else :ui="{ body: 'p-0 sm:p-0' }">
          <ul class="divide-y divide-default">
            <li v-for="v in shown" :key="v.id" class="p-3 sm:p-4 flex items-start gap-3 cursor-pointer hover:bg-elevated/50" @click="router.push(`/vehicles/${v.id}`)">
              <UIcon name="i-lucide-car" class="text-xl text-muted mt-0.5 shrink-0" />
              <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-medium">{{ v.displayName || v.plate }}</span>
                  <span v-if="v.make || v.model" class="text-muted">{{ [v.make, v.model].filter(Boolean).join(' ') }}</span>
                  <UBadge v-if="!v.active" color="neutral" variant="subtle" size="xs">{{ $t('fleet.inactive') }}</UBadge>
                  <UBadge v-if="v.ownership !== 'own'" color="neutral" variant="outline" size="xs">{{ $t(`fleet.ownership.${v.ownership}`) }}</UBadge>
                  <template v-if="store.vehicleExpiries[v.id]?.nextExpiry">
                    <UBadge :color="statusColor[store.vehicleExpiries[v.id]!.nextExpiry!.status] ?? 'neutral'" variant="subtle" size="xs">
                      {{ store.vehicleExpiries[v.id]!.nextExpiry!.label }} · {{ daysText(store.vehicleExpiries[v.id]!.nextExpiry!.daysLeft) }}
                    </UBadge>
                  </template>
                  <UBadge v-else color="neutral" variant="outline" size="xs">{{ $t('fleet.noExpiries') }}</UBadge>
                </div>
                <p class="text-xs text-muted mt-0.5 truncate">
                  {{ [v.year, v.fuel ? $t(`fleet.fuel.${v.fuel}`) : null, v.driverName].filter(Boolean).join(' · ') }}
                  <span v-if="store.vehicleExpiries[v.id]?.nextExpiry"> · {{ $t('fleet.nextExpiry') }}: {{ formatDate(store.vehicleExpiries[v.id]!.nextExpiry!.expiresAt) }}</span>
                </p>
              </div>
              <div class="text-xs text-muted text-right shrink-0 hidden sm:block" @click.stop>
                <div v-if="store.vehicleExpiries[v.id]" class="flex gap-2 justify-end">
                  <span v-if="store.vehicleExpiries[v.id]!.counts.expired" class="text-error">{{ store.vehicleExpiries[v.id]!.counts.expired }} {{ $t('fleet.status.expired').toLowerCase() }}</span>
                  <span v-if="store.vehicleExpiries[v.id]!.counts.due" class="text-warning">{{ store.vehicleExpiries[v.id]!.counts.due }} {{ $t('fleet.status.due').toLowerCase() }}</span>
                </div>
                <div class="mt-1 flex gap-1 justify-end">
                  <UButton size="xs" variant="ghost" color="neutral" icon="i-lucide-pencil" @click="openEdit(v)" />
                  <UButton size="xs" variant="ghost" color="error" icon="i-lucide-trash-2" @click="remove(v)" />
                </div>
              </div>
            </li>
          </ul>
        </UCard>
      </div>

      <!-- Create / edit modal -->
      <UModal v-model:open="modalOpen" :title="$t(editing ? 'fleet.editVehicle' : 'fleet.newVehicle')">
        <template #body>
          <form class="space-y-3" @submit.prevent="submit">
            <div class="grid grid-cols-2 gap-3">
              <UFormField :label="$t('fleet.form.plate')" :hint="$t('fleet.form.plateHint')"><UInput v-model="form.plate" placeholder="B 123 ABC" class="w-full" /></UFormField>
              <UFormField :label="$t('fleet.form.vin')"><UInput v-model="form.vin" class="w-full" /></UFormField>
              <UFormField :label="$t('fleet.form.make')"><UInput v-model="form.make" class="w-full" /></UFormField>
              <UFormField :label="$t('fleet.form.model')"><UInput v-model="form.model" class="w-full" /></UFormField>
              <UFormField :label="$t('fleet.form.year')"><UInput v-model="form.year" type="number" min="1950" :max="new Date().getFullYear() + 1" class="w-full" /></UFormField>
              <UFormField :label="$t('fleet.form.fuel')"><USelectMenu v-model="form.fuel" :items="fuelItems" value-key="value" class="w-full" /></UFormField>
              <UFormField :label="$t('fleet.form.ownership')"><USelectMenu v-model="form.ownership" :items="ownershipItems" value-key="value" class="w-full" /></UFormField>
              <UFormField :label="$t('fleet.form.driverName')"><UInput v-model="form.driverName" class="w-full" /></UFormField>
            </div>
            <UFormField :label="$t('fleet.form.notes')"><UTextarea v-model="form.notes" :rows="2" class="w-full" /></UFormField>
            <UCheckbox v-if="editing" v-model="form.active" :label="$t('fleet.form.active')" />
            <div class="flex justify-end gap-2 pt-2">
              <UButton color="neutral" variant="ghost" @click="modalOpen = false">{{ $t('common.cancel') }}</UButton>
              <UButton type="submit" :loading="saving" :disabled="!form.plate && !form.vin">{{ $t('common.save') }}</UButton>
            </div>
          </form>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
