<template>
  <span v-if="hasAny" class="inline-flex items-center gap-1 flex-wrap">
    <UBadge v-if="partner.status === 'blocked'" color="error" variant="subtle" :size="size">{{ $t('partners.badges.blocked') }}</UBadge>
    <UBadge v-else-if="partner.status === 'warning'" color="warning" variant="subtle" :size="size">{{ $t('partners.badges.warning') }}</UBadge>
    <UBadge v-if="partner.inactive === true" color="error" variant="subtle" :size="size">{{ $t('partners.badges.inactive') }}</UBadge>
    <UBadge v-if="isCompany && partner.vatRegistered === false" color="neutral" variant="subtle" :size="size">{{ $t('partners.badges.notVatRegistered') }}</UBadge>
    <UBadge v-if="partner.vatOnCollection === true" color="info" variant="subtle" :size="size">{{ $t('partners.badges.vatOnCollection') }}</UBadge>
    <UBadge v-if="vies && partner.viesValid === false" color="error" variant="subtle" :size="size">{{ $t('partners.badges.viesInvalid') }}</UBadge>
    <UBadge v-if="affiliated && partner.affiliated" color="neutral" variant="outline" :size="size">{{ $t('partners.badges.affiliated') }}</UBadge>
  </span>
</template>

<script setup lang="ts">
/**
 * Partner rule + registry badges for a client or supplier row: blocked / warning
 * (rules), inactive, not registered for VAT, VAT on collection (ANAF snapshot),
 * optionally VIES invalid and affiliated.
 */
const props = withDefaults(defineProps<{
  partner: Record<string, any>
  size?: 'xs' | 'sm' | 'md'
  vies?: boolean
  affiliated?: boolean
}>(), { size: 'xs', vies: false, affiliated: false })

const isCompany = computed(() => (props.partner?.type ?? 'company') === 'company')
const hasAny = computed(() => {
  const p = props.partner || {}
  return p.status === 'blocked' || p.status === 'warning' || p.inactive === true
    || (isCompany.value && p.vatRegistered === false) || p.vatOnCollection === true
    || (props.vies && p.viesValid === false) || (props.affiliated && p.affiliated)
})
</script>
