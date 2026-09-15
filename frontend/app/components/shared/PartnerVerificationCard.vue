<template>
  <UCard>
    <template #header>
      <div class="flex items-center justify-between gap-3">
        <div>
          <h3 class="font-semibold">{{ $t('partners.verification.title') }}</h3>
          <p class="text-xs text-muted">
            <template v-if="partner.vatStatusCheckedAt">{{ $t('partners.verification.checkedAt') }} {{ formatDateTime(partner.vatStatusCheckedAt) }}</template>
            <template v-else>{{ $t('partners.verification.neverChecked') }}</template>
          </p>
        </div>
        <UButton icon="i-lucide-shield-check" size="sm" variant="soft" :loading="verifying" @click="verify">
          {{ $t('partners.verification.verifyNow') }}
        </UButton>
      </div>
    </template>

    <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
      <template v-if="isRomanian">
        <div>
          <dt class="text-muted">{{ $t('partners.verification.vatRegistered') }}</dt>
          <dd><UBadge :color="flag(partner.vatRegistered).color" variant="subtle" size="sm">{{ flag(partner.vatRegistered).label }}</UBadge></dd>
        </div>
        <div>
          <dt class="text-muted">{{ $t('partners.verification.vatOnCollection') }}</dt>
          <dd class="flex items-center gap-2">
            <UBadge :color="flag(partner.vatOnCollection, true).color" variant="subtle" size="sm">{{ flag(partner.vatOnCollection, true).label }}</UBadge>
            <span v-if="partner.vatOnCollection && partner.vatOnCollectionFrom" class="text-xs text-muted">
              {{ $t('partners.verification.from') }} {{ formatDate(partner.vatOnCollectionFrom) }}<template v-if="partner.vatOnCollectionTo"> — {{ formatDate(partner.vatOnCollectionTo) }}</template>
            </span>
          </dd>
        </div>
        <div>
          <dt class="text-muted">{{ $t('partners.verification.inactive') }}</dt>
          <dd><UBadge :color="partner.inactive === true ? 'error' : partner.inactive === false ? 'success' : 'neutral'" variant="subtle" size="sm">{{ partner.inactive === true ? $t('common.yes') : partner.inactive === false ? $t('common.no') : $t('partners.verification.unknown') }}</UBadge></dd>
        </div>
        <div>
          <dt class="text-muted">{{ $t('partners.verification.efacturaRegistered') }}</dt>
          <dd><UBadge :color="flag(partner.efacturaRegistered, true).color" variant="subtle" size="sm">{{ flag(partner.efacturaRegistered, true).label }}</UBadge></dd>
        </div>
      </template>
      <template v-else>
        <div>
          <dt class="text-muted">{{ $t('partners.verification.viesValid') }}</dt>
          <dd><UBadge :color="flag(partner.viesValid).color" variant="subtle" size="sm">{{ flag(partner.viesValid).label }}</UBadge></dd>
        </div>
        <div v-if="partner.viesName">
          <dt class="text-muted">{{ $t('clients.viesName') }}</dt>
          <dd>{{ partner.viesName }}</dd>
        </div>
      </template>
      <div v-if="partner.verificationNotes" class="md:col-span-2">
        <dt class="text-muted">{{ $t('partners.verification.notes') }}</dt>
        <dd>{{ partner.verificationNotes }}</dd>
      </div>
      <div v-if="!isRomanian && !isEu" class="md:col-span-2 text-xs text-muted">
        {{ $t('partners.verification.notApplicable') }}
      </div>
    </dl>
  </UCard>
</template>

<script setup lang="ts">
/**
 * "Verificare ANAF / VIES" card: the stored registry snapshot of a client or
 * supplier and a button that re-checks it now (POST /v1/{clients|suppliers}/{id}/verify).
 */
const props = defineProps<{
  partner: Record<string, any>
  kind: 'client' | 'supplier'
}>()

const emit = defineEmits<{
  verified: [partner: Record<string, any>]
}>()

const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'GR', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'SE', 'SI', 'SK', 'XI']

const { t: $t } = useI18n()
const intlLocale = useIntlLocale()
const toast = useToast()
const verifying = ref(false)

const isRomanian = computed(() => !props.partner?.country || props.partner.country === 'RO')
const isEu = computed(() => !!props.partner?.country && EU.includes(props.partner.country))

function flag(value: boolean | null | undefined, neutralWhenFalse = false) {
  if (value === true) return { label: $t('common.yes'), color: 'success' as const }
  if (value === false) return { label: $t('common.no'), color: neutralWhenFalse ? 'neutral' as const : 'error' as const }
  return { label: $t('partners.verification.unknown'), color: 'neutral' as const }
}

function formatDate(date: string) {
  return new Date(date).toLocaleDateString(intlLocale, { year: 'numeric', month: 'short', day: 'numeric' })
}

function formatDateTime(date: string) {
  return new Date(date).toLocaleString(intlLocale, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

async function verify() {
  verifying.value = true
  try {
    const { post } = useApi()
    const res = await post<any>(`/v1/${props.kind}s/${props.partner.id}/verify`)
    const updated = res[props.kind] || {}
    const result = res.result || {}
    emit('verified', updated)
    if (result.checked) {
      const changes = (result.changes || []).map((c: string) => $t(`partners.verification.changes.${c}`)).join(', ')
      toast.add({
        title: $t('partners.verification.done'),
        description: changes ? $t('partners.verification.changed', { changes }) : (result.error === 'not_found' ? $t('partners.verification.notFound') : undefined),
        color: changes ? 'warning' : 'success',
        icon: 'i-lucide-shield-check',
      })
    }
    else if (result.error === 'not_applicable') {
      toast.add({ title: $t('partners.verification.notApplicable'), color: 'info' })
    }
    else {
      toast.add({ title: $t('partners.verification.registryUnavailable'), color: 'warning' })
    }
  }
  catch (err: any) {
    toast.add({ title: err?.data?.error || $t('partners.verification.error'), color: 'error' })
  }
  finally {
    verifying.value = false
  }
}
</script>
