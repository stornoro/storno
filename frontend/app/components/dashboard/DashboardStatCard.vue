<template>
  <UPageCard
    :icon="icon"
    :title="label"
    variant="subtle"
    :ui="{
      container: 'gap-y-1.5',
      wrapper: 'items-start',
      leading: `p-2.5 rounded-full ${iconRingClass} flex-col`,
      title: 'font-normal text-muted text-xs uppercase',
    }"
    :class="cardClass"
  >
    <template v-if="loading">
      <USkeleton class="w-24 h-7" />
    </template>
    <template v-else>
      <div class="flex items-center gap-2">
        <span class="text-2xl font-semibold text-highlighted tabular-nums">
          {{ formattedValue }}
        </span>
        <UBadge
          v-if="variation !== undefined"
          :color="variation >= 0 ? 'success' : 'error'"
          variant="subtle"
          class="text-xs"
        >
          {{ variation > 0 ? '+' : '' }}{{ variation }}%
        </UBadge>
      </div>
      <p v-if="subtitle" class="text-xs text-muted mt-0.5">{{ subtitle }}</p>
    </template>
    <slot />
  </UPageCard>
</template>

<script setup lang="ts">
interface Props {
  icon: string
  color?: 'primary' | 'success' | 'warning' | 'error' | 'info' | 'neutral'
  value: string | number
  label: string
  subtitle?: string
  loading?: boolean
  format?: 'number' | 'percent' | 'currency'
  variation?: number
  connected?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  color: 'primary',
  subtitle: undefined,
  loading: false,
  format: 'number',
  variation: undefined,
  connected: false,
})

const formattedValue = computed(() => {
  if (typeof props.value === 'string') return props.value
  switch (props.format) {
    case 'percent':
      return `${props.value}%`
    case 'currency':
      return new Intl.NumberFormat('ro-RO', { style: 'currency', currency: 'RON' }).format(props.value)
    default:
      return props.value.toLocaleString('ro-RO')
  }
})

const iconRingMap: Record<string, string> = {
  primary: 'bg-primary/10 ring ring-inset ring-primary/25',
  success: 'bg-success/10 ring ring-inset ring-success/25',
  warning: 'bg-warning/10 ring ring-inset ring-warning/25',
  error: 'bg-error/10 ring ring-inset ring-error/25',
  info: 'bg-info/10 ring ring-inset ring-info/25',
  neutral: 'bg-(--ui-bg-elevated) ring ring-inset ring-(--ui-border)',
}

const iconRingClass = computed(() => iconRingMap[props.color] ?? iconRingMap.primary)

const cardClass = computed(() =>
  props.connected ? 'lg:rounded-none first:rounded-l-lg last:rounded-r-lg hover:z-1' : '',
)
</script>
