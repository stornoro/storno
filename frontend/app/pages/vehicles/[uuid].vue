<script setup lang="ts">
import type { ExpiryItem, VehicleDetail } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useFleetStore()
const toast = useToast()
const intlLocale = useIntlLocale()

const id = computed(() => String(route.params.uuid))
const detail = ref<VehicleDetail | null>(null)
const loading = ref(true)
const history = ref<ExpiryItem[]>([])
const showHistory = ref(false)

useHead({ title: computed(() => detail.value?.vehicle.displayName ?? $t('fleet.title')) })

async function load() {
  try {
    detail.value = await store.fetchVehicle(id.value)
    if (showHistory.value) await loadHistory()
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('common.error'), color: 'error' })
    router.push('/vehicles')
  } finally {
    loading.value = false
  }
}
async function loadHistory() {
  const { get } = useApi()
  const res = await get<{ data: ExpiryItem[] }>(`/v1/vehicles/${id.value}/expiries`, { includeClosed: 1 })
  history.value = res.data.filter(e => e.closedAt)
}
watch(showHistory, v => { if (v) loadHistory() })
onMounted(load)

// ── Expiry actions ─────────────────────────────────────────────────
const expiryOpen = ref(false)
const editingExpiry = ref<ExpiryItem | null>(null)
const renewOpen = ref(false)
const renewing = ref<ExpiryItem | null>(null)

function openAdd() { editingExpiry.value = null; expiryOpen.value = true }
function openEdit(item: ExpiryItem) { editingExpiry.value = item; expiryOpen.value = true }
function openRenew(item: ExpiryItem) { renewing.value = item; renewOpen.value = true }
async function removeExpiry(item: ExpiryItem) {
  if (!confirm($t('fleet.confirmDeleteExpiry', { label: item.label }))) return
  try {
    await store.deleteExpiry(item.id)
    toast.add({ title: $t('fleet.deleted'), color: 'success' })
    await load()
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('common.error'), color: 'error' })
  }
}
async function removeVehicle() {
  if (!detail.value || !confirm($t('fleet.confirmDeleteVehicle', { name: detail.value.vehicle.displayName }))) return
  try {
    await store.deleteVehicle(detail.value.vehicle.id)
    toast.add({ title: $t('fleet.deleted'), color: 'success' })
    router.push('/vehicles')
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('common.error'), color: 'error' })
  }
}
async function toggleActive() {
  if (!detail.value) return
  try {
    detail.value = await store.updateVehicle(detail.value.vehicle.id, { active: !detail.value.vehicle.active })
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('common.error'), color: 'error' })
  }
}

const statusColor: Record<string, 'error' | 'warning' | 'success' | 'neutral'> = { expired: 'error', due: 'warning', ok: 'success' }
function formatDate(iso: string): string {
  return new Date(iso + 'T00:00:00').toLocaleDateString(intlLocale, { dateStyle: 'medium' })
}
function daysText(days: number): string {
  return days < 0 ? $t('fleet.daysOverdue', { count: -days }, -days) : $t('fleet.daysLeft', { count: days }, days)
}
const facts = computed(() => {
  const v = detail.value?.vehicle
  if (!v) return []
  return [
    ['vin', v.vin], ['year', v.year], ['fuel', v.fuel ? $t(`fleet.fuel.${v.fuel}`) : null], ['ownership', $t(`fleet.ownership.${v.ownership}`)], ['driverName', v.driverName],
  ].filter(([, val]) => val !== null && val !== undefined && val !== '') as [string, string | number][]
})
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="detail?.vehicle.displayName ?? $t('fleet.title')">
        <template #leading>
          <UButton icon="i-lucide-arrow-left" color="neutral" variant="ghost" to="/vehicles" />
        </template>
        <template #right>
          <UButton v-if="detail" icon="i-lucide-plus" @click="openAdd">{{ $t('fleet.newExpiry') }}</UButton>
          <UButton v-if="detail" :icon="detail.vehicle.active ? 'i-lucide-archive' : 'i-lucide-archive-restore'" color="neutral" variant="outline" @click="toggleActive">{{ $t(detail.vehicle.active ? 'fleet.deactivate' : 'fleet.activate') }}</UButton>
          <UButton icon="i-lucide-trash-2" color="error" variant="ghost" @click="removeVehicle" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="loading" class="p-4 space-y-3"><USkeleton class="h-20 w-full" /><USkeleton class="h-40 w-full" /></div>
      <div v-else-if="detail" class="p-4 space-y-4">
        <UCard>
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
              <div class="flex flex-wrap items-center gap-2">
                <UIcon name="i-lucide-car" class="text-xl text-muted" />
                <span class="text-lg font-semibold">{{ detail.vehicle.displayName || detail.vehicle.plate }}</span>
                <span v-if="detail.vehicle.make || detail.vehicle.model" class="text-muted">{{ [detail.vehicle.make, detail.vehicle.model].filter(Boolean).join(' ') }}</span>
                <UBadge v-if="!detail.vehicle.active" color="neutral" variant="subtle" size="xs">{{ $t('fleet.inactive') }}</UBadge>
              </div>
              <dl class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                <div v-for="[k, val] in facts" :key="k"><dt class="inline">{{ $t(`fleet.form.${k}`) }}:</dt> <dd class="inline text-default">{{ val }}</dd></div>
              </dl>
              <p v-if="detail.vehicle.notes" class="text-sm whitespace-pre-line">{{ detail.vehicle.notes }}</p>
            </div>
            <div class="text-right text-sm">
              <template v-if="detail.nextExpiry">
                <div class="text-xs text-muted">{{ $t('fleet.nextExpiry') }}</div>
                <UBadge :color="statusColor[detail.nextExpiry.status] ?? 'neutral'" variant="subtle">{{ detail.nextExpiry.label }} · {{ formatDate(detail.nextExpiry.expiresAt) }} · {{ daysText(detail.nextExpiry.daysLeft) }}</UBadge>
              </template>
              <div class="flex gap-3 justify-end text-xs text-muted mt-2">
                <span :class="detail.counts.expired ? 'text-error' : ''">{{ $t('fleet.counts.expired') }}: {{ detail.counts.expired }}</span>
                <span :class="detail.counts.due ? 'text-warning' : ''">{{ $t('fleet.counts.due') }}: {{ detail.counts.due }}</span>
                <span>{{ $t('fleet.counts.ok') }}: {{ detail.counts.ok }}</span>
              </div>
            </div>
          </div>
        </UCard>

        <UCard :ui="{ body: 'p-0 sm:p-0' }">
          <template #header>
            <div class="flex items-center justify-between">
              <span class="font-semibold text-sm">{{ $t('fleet.expiriesTitle') }}</span>
              <UCheckbox v-model="showHistory" :label="$t('fleet.showHistory')" />
            </div>
          </template>
          <FleetExpiryTable :items="detail.expiries" @renew="openRenew" @edit="openEdit" @remove="removeExpiry" />
          <template v-if="showHistory && history.length">
            <div class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-muted border-t border-default">{{ $t('fleet.history') }}</div>
            <FleetExpiryTable :items="history" @renew="openRenew" @edit="openEdit" @remove="removeExpiry" />
          </template>
        </UCard>
      </div>

      <FleetExpiryModal v-model:open="expiryOpen" :item="editingExpiry" :vehicle-id="id" @saved="load" />
      <FleetRenewModal v-model:open="renewOpen" :item="renewing" @renewed="load" />
    </template>
  </UDashboardPanel>
</template>
