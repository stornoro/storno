<script setup lang="ts">
import type { ExpiryItem, ExpiryKind, Vehicle } from '~/types'

/** Create or edit an expiry item; with `vehicleId` fixed the vehicle picker is hidden. */
const props = defineProps<{
  item?: ExpiryItem | null
  vehicleId?: string | null
  vehicles?: Vehicle[]
}>()
const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ saved: [item: ExpiryItem] }>()

const { t: $t } = useI18n()
const store = useFleetStore()
const toast = useToast()
const saving = ref(false)

const KINDS: ExpiryKind[] = ['rca', 'itp', 'rovinieta', 'casco', 'tahograf', 'extinctor', 'trusa_medicala', 'licenta_transport', 'copie_conforma', 'leasing', 'certificat_digital', 'contract', 'autorizatie', 'other']
const form = reactive({ kind: 'rca' as ExpiryKind, label: '', number: '', provider: '', validFrom: '', expiresAt: '', remindDaysBefore: 30, vehicleId: '' as string, notes: '' })

watch(open, (v) => {
  if (!v) return
  const i = props.item
  form.kind = i?.kind ?? (props.vehicleId ? 'rca' : 'certificat_digital')
  form.label = i?.label ?? ''
  form.number = i?.number ?? ''
  form.provider = i?.provider ?? ''
  form.validFrom = i?.validFrom ?? ''
  form.expiresAt = i?.expiresAt ?? ''
  form.remindDaysBefore = i?.remindDaysBefore ?? 30
  form.vehicleId = i?.vehicleId ?? props.vehicleId ?? ''
  form.notes = i?.notes ?? ''
})

const kindItems = computed(() => KINDS.map(k => ({ label: $t(`fleet.kinds.${k}`), value: k })))
const vehicleItems = computed(() => [{ label: $t('fleet.form.companyLevel'), value: '' }, ...(props.vehicles ?? []).map(v => ({ label: v.displayName, value: v.id }))])
const labelPlaceholder = computed(() => $t(`fleet.kinds.${form.kind}`))

async function submit() {
  saving.value = true
  try {
    const payload: Record<string, any> = {
      kind: form.kind,
      label: form.label || undefined,
      number: form.number || null,
      provider: form.provider || null,
      validFrom: form.validFrom || null,
      expiresAt: form.expiresAt,
      remindDaysBefore: form.remindDaysBefore,
      notes: form.notes || null,
    }
    if (!props.vehicleId) payload.vehicleId = form.vehicleId || null
    const saved = props.item
      ? (await store.updateExpiry(props.item.id, payload)).item
      : await store.createExpiry({ ...payload, vehicleId: props.vehicleId ?? payload.vehicleId ?? null })
    toast.add({ title: $t(props.item ? 'fleet.saved' : 'fleet.created'), color: 'success' })
    open.value = false
    emit('saved', saved)
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? e?.message ?? $t('common.error'), color: 'error' })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UModal v-model:open="open" :title="$t(item ? 'fleet.editExpiry' : 'fleet.newExpiry')">
    <template #body>
      <form class="space-y-3" @submit.prevent="submit">
        <div class="grid grid-cols-2 gap-3">
          <UFormField :label="$t('fleet.form.kind')" required>
            <USelectMenu v-model="form.kind" :items="kindItems" value-key="value" class="w-full" />
          </UFormField>
          <UFormField v-if="!vehicleId" :label="$t('fleet.form.vehicle')">
            <USelectMenu v-model="form.vehicleId" :items="vehicleItems" value-key="value" class="w-full" />
          </UFormField>
        </div>
        <UFormField :label="$t('fleet.form.label')" :hint="$t('fleet.form.labelHint')">
          <UInput v-model="form.label" :placeholder="labelPlaceholder" class="w-full" />
        </UFormField>
        <div class="grid grid-cols-2 gap-3">
          <UFormField :label="$t('fleet.form.validFrom')"><UInput v-model="form.validFrom" type="date" class="w-full" /></UFormField>
          <UFormField :label="$t('fleet.form.expiresAt')" required><UInput v-model="form.expiresAt" type="date" class="w-full" /></UFormField>
          <UFormField :label="$t('fleet.form.number')"><UInput v-model="form.number" class="w-full" /></UFormField>
          <UFormField :label="$t('fleet.form.provider')"><UInput v-model="form.provider" class="w-full" /></UFormField>
          <UFormField :label="$t('fleet.form.remindDaysBefore')" :hint="$t('fleet.form.remindHint')">
            <UInput v-model.number="form.remindDaysBefore" type="number" min="0" max="365" class="w-full" />
          </UFormField>
        </div>
        <UFormField :label="$t('fleet.form.notes')"><UTextarea v-model="form.notes" :rows="2" class="w-full" /></UFormField>
        <div class="flex justify-end gap-2 pt-2">
          <UButton color="neutral" variant="ghost" @click="open = false">{{ $t('common.cancel') }}</UButton>
          <UButton type="submit" :loading="saving" :disabled="!form.expiresAt">{{ $t('common.save') }}</UButton>
        </div>
      </form>
    </template>
  </UModal>
</template>
