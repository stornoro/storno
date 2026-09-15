<script setup lang="ts">
import type { ExpiryItem, ExpiryRow } from '~/types'

/** Renew an expiry item: the next one is created with the new date / number, the old one is closed and kept as history. */
const props = defineProps<{ item: ExpiryItem | ExpiryRow | null }>()
const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ renewed: [item: ExpiryItem] }>()

const { t: $t } = useI18n()
const store = useFleetStore()
const toast = useToast()
const saving = ref(false)
const form = reactive({ expiresAt: '', validFrom: '', number: '', provider: '' })

const DEFAULT_MONTHS: Record<string, number> = { rca: 12, itp: 24, rovinieta: 12, casco: 12, tahograf: 24, extinctor: 12, trusa_medicala: 36, licenta_transport: 120, copie_conforma: 120, certificat_digital: 12, autorizatie: 12 }

function addMonths(iso: string, months: number): string {
  const d = new Date(iso + 'T00:00:00')
  d.setMonth(d.getMonth() + months)
  return d.toISOString().slice(0, 10)
}

watch(open, (v) => {
  if (!v || !props.item) return
  const today = new Date().toISOString().slice(0, 10)
  const base = props.item.expiresAt > today ? props.item.expiresAt : today
  form.validFrom = base
  form.expiresAt = addMonths(base, DEFAULT_MONTHS[props.item.kind] ?? 12)
  form.number = ''
  form.provider = props.item.provider ?? ''
})

async function submit() {
  if (!props.item) return
  saving.value = true
  try {
    const res = await store.renewExpiry(props.item.id, { expiresAt: form.expiresAt, validFrom: form.validFrom || undefined, number: form.number || undefined, provider: form.provider || undefined })
    toast.add({ title: $t('fleet.renewed'), color: 'success' })
    open.value = false
    emit('renewed', res.item)
  } catch (e: any) {
    toast.add({ title: e?.data?.error ?? e?.message ?? $t('common.error'), color: 'error' })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UModal v-model:open="open" :title="$t('fleet.renewTitle', { label: item?.label ?? '' })">
    <template #body>
      <form class="space-y-3" @submit.prevent="submit">
        <p class="text-xs text-muted">{{ $t('fleet.renewHint') }}</p>
        <div class="grid grid-cols-2 gap-3">
          <UFormField :label="$t('fleet.form.validFrom')"><UInput v-model="form.validFrom" type="date" class="w-full" /></UFormField>
          <UFormField :label="$t('fleet.form.expiresAt')" required><UInput v-model="form.expiresAt" type="date" class="w-full" /></UFormField>
          <UFormField :label="$t('fleet.form.number')"><UInput v-model="form.number" class="w-full" /></UFormField>
          <UFormField :label="$t('fleet.form.provider')"><UInput v-model="form.provider" class="w-full" /></UFormField>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <UButton color="neutral" variant="ghost" @click="open = false">{{ $t('common.cancel') }}</UButton>
          <UButton type="submit" icon="i-lucide-refresh-cw" :loading="saving" :disabled="!form.expiresAt">{{ $t('fleet.renew') }}</UButton>
        </div>
      </form>
    </template>
  </UModal>
</template>
