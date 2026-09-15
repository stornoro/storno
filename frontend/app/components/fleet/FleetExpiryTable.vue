<script setup lang="ts">
import type { ExpiryItem } from '~/types'

/** Expiry items as a table: status badge, days left, renew / edit / delete. */
defineProps<{ items: ExpiryItem[], showVehicle?: boolean, loading?: boolean }>()
const emit = defineEmits<{ renew: [item: ExpiryItem], edit: [item: ExpiryItem], remove: [item: ExpiryItem] }>()

const { t: $t } = useI18n()
const intlLocale = useIntlLocale()
const statusColor: Record<string, 'error' | 'warning' | 'success' | 'neutral'> = { expired: 'error', due: 'warning', ok: 'success', renewed: 'neutral' }

function formatDate(iso: string | null): string {
  return iso ? new Date(iso + 'T00:00:00').toLocaleDateString(intlLocale, { dateStyle: 'medium' }) : '—'
}
function daysText(item: ExpiryItem): string {
  if (item.status === 'renewed') return $t('fleet.status.renewed')
  if (item.daysLeft < 0) return $t('fleet.daysOverdue', { count: -item.daysLeft }, -item.daysLeft)
  return $t('fleet.daysLeft', { count: item.daysLeft }, item.daysLeft)
}
</script>

<template>
  <div v-if="loading" class="space-y-2 p-4"><USkeleton class="h-6 w-full" /><USkeleton class="h-6 w-2/3" /></div>
  <p v-else-if="!items.length" class="text-sm text-muted p-4">{{ $t('fleet.noExpiries') }}</p>
  <div v-else class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="text-xs text-muted uppercase tracking-wide">
        <tr class="border-b border-default">
          <th class="text-left font-medium px-4 py-2">{{ $t('fleet.form.label') }}</th>
          <th v-if="showVehicle" class="text-left font-medium px-2 py-2">{{ $t('fleet.form.vehicle') }}</th>
          <th class="text-left font-medium px-2 py-2">{{ $t('fleet.form.number') }}</th>
          <th class="text-left font-medium px-2 py-2">{{ $t('fleet.form.expiresAt') }}</th>
          <th class="text-left font-medium px-2 py-2">{{ $t('fleet.statusLabel') }}</th>
          <th class="px-2 py-2" />
        </tr>
      </thead>
      <tbody class="divide-y divide-default">
        <tr v-for="item in items" :key="item.id" :class="item.status === 'renewed' ? 'opacity-60' : ''">
          <td class="px-4 py-2">
            <div class="font-medium">{{ item.label }}</div>
            <div class="text-xs text-muted">{{ $t(`fleet.kinds.${item.kind}`) }}<span v-if="item.provider"> · {{ item.provider }}</span></div>
          </td>
          <td v-if="showVehicle" class="px-2 py-2 text-xs">
            <NuxtLink v-if="item.vehicle" :to="`/vehicles/${item.vehicle.id}`" class="hover:underline">{{ item.vehicle.displayName }}</NuxtLink>
            <span v-else class="text-muted">{{ $t('fleet.form.companyLevel') }}</span>
          </td>
          <td class="px-2 py-2 text-xs">{{ item.number ?? '—' }}</td>
          <td class="px-2 py-2 whitespace-nowrap">
            <div>{{ formatDate(item.expiresAt) }}</div>
            <div v-if="item.validFrom" class="text-xs text-muted">{{ $t('fleet.from') }} {{ formatDate(item.validFrom) }}</div>
          </td>
          <td class="px-2 py-2 whitespace-nowrap">
            <UBadge :color="statusColor[item.status] ?? 'neutral'" variant="subtle" size="xs">{{ daysText(item) }}</UBadge>
          </td>
          <td class="px-2 py-2 text-right whitespace-nowrap">
            <UButton v-if="item.status !== 'renewed'" size="xs" variant="soft" icon="i-lucide-refresh-cw" @click="emit('renew', item)">{{ $t('fleet.renew') }}</UButton>
            <UButton size="xs" variant="ghost" color="neutral" icon="i-lucide-pencil" @click="emit('edit', item)" />
            <UButton size="xs" variant="ghost" color="error" icon="i-lucide-trash-2" @click="emit('remove', item)" />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
