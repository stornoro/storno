<script setup lang="ts">
import type { DeltaResult } from '~/stores/dashboard'

const props = defineProps<{
  delta: DeltaResult
  label?: string
  invertSemantic?: boolean
}>()

const { t: $t } = useI18n()

const resolvedLabel = computed(() =>
  props.label ?? $t('dashboard.delta.vsPreviousPeriod'),
)

const isPositive = computed(() => props.delta.direction === 'up')
const isNegative = computed(() => props.delta.direction === 'down')

// When semantics are inverted (expenses): up = bad (red), down = good (green).
const badgeColor = computed(() => {
  if (props.delta.direction === 'flat') return 'neutral'
  if (props.invertSemantic) {
    return isPositive.value ? 'error' : 'success'
  }
  return isPositive.value ? 'success' : 'error'
})

const icon = computed(() => {
  if (isPositive.value) return 'i-lucide-trending-up'
  if (isNegative.value) return 'i-lucide-trending-down'
  return 'i-lucide-minus'
})

const formattedValue = computed(() => {
  if (props.delta.value === null) return null
  return `${Math.abs(props.delta.value).toFixed(1)}%`
})
</script>

<template>
  <span
    v-if="delta.value !== null"
    class="inline-flex items-center gap-1.5 flex-wrap"
  >
    <UBadge
      :color="badgeColor"
      variant="subtle"
      size="xs"
      class="tabular-nums"
    >
      <UIcon :name="icon" class="size-3 shrink-0" />
      {{ formattedValue }}
    </UBadge>
    <span class="text-[10px] text-(--ui-text-muted) leading-none">{{ resolvedLabel }}</span>
  </span>
</template>
