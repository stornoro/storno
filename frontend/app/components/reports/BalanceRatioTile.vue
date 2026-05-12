<template>
  <div class="flex flex-col gap-1 p-3 rounded-lg bg-(--ui-bg-elevated)/50 min-w-0">
    <div class="flex items-center justify-between gap-1 min-w-0">
      <span class="text-xs text-(--ui-text-muted) truncate leading-tight">{{ label }}</span>
      <UTooltip v-if="hint" :text="hint" :ui="{ content: 'max-w-64 text-xs' }">
        <UIcon name="i-lucide-info" class="size-3 text-(--ui-text-dimmed) flex-shrink-0 cursor-help" />
      </UTooltip>
    </div>
    <div class="flex items-baseline gap-1.5">
      <span
        class="text-xl font-semibold tabular-nums leading-tight"
        :class="isNa ? 'text-(--ui-text-muted)' : 'text-(--ui-text-highlighted)'"
      >
        {{ displayValue }}
      </span>
      <span v-if="!isNa && suffix" class="text-xs text-(--ui-text-muted) leading-tight">{{ suffix }}</span>
      <span
        v-if="!isNa"
        class="ml-auto size-2 rounded-full flex-shrink-0"
        :class="statusDotClass"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import type { RatioStatus } from '~/types'

interface Props {
  label: string
  value: number | string | null
  status: RatioStatus
  hint?: string
  suffix?: string
  format?: 'number' | 'percent' | 'days' | 'money' | 'ratio'
}

const props = withDefaults(defineProps<Props>(), {
  hint: undefined,
  suffix: undefined,
  format: 'number',
})

const intlLocale = useIntlLocale()

const isNa = computed(() => props.status === 'na' || props.value === null)

const displayValue = computed(() => {
  if (isNa.value) return '—'
  const v = props.value
  if (v === null || v === undefined) return '—'
  const num = typeof v === 'string' ? parseFloat(v) : v
  if (Number.isNaN(num)) return '—'
  switch (props.format) {
    case 'percent':
      return `${num.toFixed(1)}%`
    case 'days':
      return Math.round(num).toLocaleString(intlLocale)
    case 'ratio':
      return num.toFixed(2)
    case 'money':
      return new Intl.NumberFormat(intlLocale, { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(num)
    default:
      return num.toFixed(2)
  }
})

const statusDotClass = computed(() => {
  switch (props.status) {
    case 'normal': return 'bg-success'
    case 'warning': return 'bg-warning'
    case 'critical': return 'bg-error'
    default: return 'bg-(--ui-text-dimmed)'
  }
})
</script>
