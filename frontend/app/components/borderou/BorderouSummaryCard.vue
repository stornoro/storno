<script setup lang="ts">
const { t: $t } = useI18n()

const props = defineProps<{
  summary: {
    total: number
    certain: number
    attention: number
    noMatch: number
    totalAmount: string
  }
  savedCount?: number
  unsavedCount?: number
}>()

function formatAmount(amount: string): string {
  return new Intl.NumberFormat('ro-RO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(amount))
}

const stats = computed(() => [
  {
    label: $t('borderou.summaryTotal'),
    value: props.summary.total,
    icon: 'i-lucide-receipt',
    color: 'text-blue-600 dark:text-blue-400',
    bg: 'bg-blue-100 dark:bg-blue-900/30',
  },
  {
    label: $t('borderou.summaryTotalAmount'),
    value: formatAmount(props.summary.totalAmount) + ' RON',
    icon: 'i-lucide-banknote',
    color: 'text-emerald-600 dark:text-emerald-400',
    bg: 'bg-emerald-100 dark:bg-emerald-900/30',
  },
  {
    label: $t('borderou.summaryCertain'),
    value: props.summary.certain,
    icon: 'i-lucide-check-circle',
    color: 'text-blue-600 dark:text-blue-400',
    bg: 'bg-blue-100 dark:bg-blue-900/30',
    dot: 'bg-blue-500',
  },
  {
    label: $t('borderou.summaryAttention'),
    value: props.summary.attention,
    icon: 'i-lucide-alert-circle',
    color: 'text-gray-600 dark:text-gray-400',
    bg: 'bg-gray-100 dark:bg-gray-800',
    dot: 'bg-gray-400',
  },
  {
    label: $t('borderou.summaryNoMatch'),
    value: props.summary.noMatch,
    icon: 'i-lucide-circle-x',
    color: 'text-orange-600 dark:text-orange-400',
    bg: 'bg-orange-100 dark:bg-orange-900/30',
    dot: 'bg-orange-500',
  },
])
</script>

<template>
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
    <div
      v-for="stat in stats"
      :key="stat.label"
      class="flex items-center gap-3 p-4 rounded-lg border border-gray-200 dark:border-gray-700"
    >
      <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" :class="stat.bg">
        <span v-if="stat.dot" class="w-3 h-3 rounded-full" :class="stat.dot" />
        <UIcon v-else :name="stat.icon" class="w-5 h-5" :class="stat.color" />
      </div>
      <div class="min-w-0">
        <p class="text-xs text-(--ui-text-muted) truncate">{{ stat.label }}</p>
        <p class="text-lg font-semibold tabular-nums">{{ stat.value }}</p>
      </div>
    </div>
  </div>
</template>
