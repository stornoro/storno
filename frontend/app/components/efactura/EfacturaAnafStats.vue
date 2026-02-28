<script setup lang="ts">
const { t: $t } = useI18n()

interface AnafStats {
  downtimeHours: number
  uptimeHours: number
  successRate: number | null
  totalSubmissions: number
  successfulSubmissions: number
  lastSuccessAt: string | null
  lastFailureAt: string | null
  lastFailureMessage: string | null
  nextRetryAt: string | null
}

const loading = ref(true)
const stats = ref<AnafStats | null>(null)

const status = computed(() => {
  if (!stats.value || stats.value.totalSubmissions === 0) return 'none'
  if (stats.value.downtimeHours === 0) return 'operational'
  if (stats.value.downtimeHours < 4) return 'partial'
  return 'major'
})

const statusConfig = computed(() => {
  const configs = {
    operational: {
      accent: 'border-l-success bg-success/5',
      icon: 'i-lucide-check-circle',
      iconColor: 'text-success',
      label: $t('efactura.anafStats.operational'),
    },
    partial: {
      accent: 'border-l-warning bg-warning/5',
      icon: 'i-lucide-alert-triangle',
      iconColor: 'text-warning',
      label: $t('efactura.anafStats.partialOutage'),
    },
    major: {
      accent: 'border-l-error bg-error/5',
      icon: 'i-lucide-x-circle',
      iconColor: 'text-error',
      label: $t('efactura.anafStats.majorOutage'),
    },
    none: {
      accent: 'border-l-(--ui-border) bg-(--ui-bg-elevated)',
      icon: 'i-lucide-minus-circle',
      iconColor: 'text-(--ui-text-muted)',
      label: $t('efactura.anafStats.noData'),
    },
  }
  return configs[status.value]
})

const successRateColorClass = computed(() => {
  const rate = stats.value?.successRate ?? 0
  if (rate >= 95) return 'text-success'
  if (rate >= 80) return 'text-warning'
  return 'text-error'
})

const uptimePercent = computed(() => {
  if (!stats.value) return 100
  const total = stats.value.uptimeHours + stats.value.downtimeHours
  if (total === 0) return 100
  return Math.round((stats.value.uptimeHours / total) * 100)
})

function timeAgo(dateStr: string | null): string {
  if (!dateStr) return '—'
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  if (diffMs < 0) return '—'

  const minutes = Math.floor(diffMs / 60000)
  if (minutes < 1) return $t('efactura.anafStats.timeAgo', { time: '<1min' })
  if (minutes < 60) return $t('efactura.anafStats.timeAgo', { time: `${minutes}min` })

  const hours = Math.floor(minutes / 60)
  if (hours < 24) return $t('efactura.anafStats.timeAgo', { time: `${hours}h` })

  const days = Math.floor(hours / 24)
  return $t('efactura.anafStats.timeAgo', { time: `${days}z` })
}

function formatAbsoluteDate(dateStr: string | null): string {
  if (!dateStr) return ''
  return new Intl.DateTimeFormat('ro-RO', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(dateStr))
}

function formatRetryCountdown(dateStr: string | null): string {
  if (!dateStr) return ''
  const target = new Date(dateStr)
  const now = new Date()
  const diff = target.getTime() - now.getTime()
  if (diff <= 0) return ''
  const minutes = Math.ceil(diff / 60000)
  if (minutes < 60) return $t('efactura.anafStats.retryIn', { time: `${minutes}min` })
  const hours = Math.floor(minutes / 60)
  const rem = minutes % 60
  return $t('efactura.anafStats.retryIn', { time: rem > 0 ? `${hours}h ${rem}min` : `${hours}h` })
}

async function fetchStats() {
  const { get } = useApi()
  loading.value = true
  try {
    stats.value = await get<AnafStats>('/v1/anaf/stats')
  }
  catch {
    // silently fail
  }
  finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchStats()
})
</script>

<template>
  <!-- Loading skeleton -->
  <div v-if="loading" class="rounded-lg border border-(--ui-border) bg-(--ui-bg) p-5 space-y-4">
    <div class="flex items-center justify-between">
      <USkeleton class="w-40 h-5" />
      <USkeleton class="w-16 h-5 rounded-full" />
    </div>
    <USkeleton class="w-full h-2 rounded-full" />
    <div class="grid grid-cols-3 gap-4">
      <USkeleton v-for="i in 3" :key="i" class="h-20 rounded-lg" />
    </div>
  </div>

  <!-- Content -->
  <div v-else class="rounded-lg border border-(--ui-border) bg-(--ui-bg) overflow-hidden">
    <!-- Zone 1 — Status banner -->
    <div
      class="flex items-center justify-between px-4 py-3 border-l-4"
      :class="statusConfig.accent"
    >
      <div class="flex items-center gap-2">
        <UIcon :name="statusConfig.icon" class="size-4" :class="statusConfig.iconColor" />
        <span class="text-sm font-semibold text-(--ui-text)">{{ statusConfig.label }}</span>
      </div>
      <UBadge variant="subtle" color="neutral" size="xs">
        {{ $t('efactura.anafStats.period') }}
      </UBadge>
    </div>

    <!-- Has data -->
    <div v-if="stats && stats.totalSubmissions > 0" class="px-5 pb-5 pt-4 space-y-5">
      <!-- Zone 2 — Stacked uptime bar -->
      <div>
        <div class="h-2 bg-(--ui-bg-elevated) rounded-full overflow-hidden flex">
          <div
            class="h-full bg-success transition-all"
            :class="stats.downtimeHours === 0 ? 'rounded-full' : 'rounded-l-full'"
            :style="{ width: `${uptimePercent}%` }"
          />
          <div
            v-if="stats.downtimeHours > 0"
            class="h-full rounded-r-full transition-all"
            :class="status === 'major' ? 'bg-error' : 'bg-warning'"
            :style="{ width: `${100 - uptimePercent}%` }"
          />
        </div>
        <div class="flex justify-between text-xs text-(--ui-text-muted) mt-1.5">
          <span class="tabular-nums">{{ stats.uptimeHours }}h {{ $t('efactura.anafStats.uptimeLabel') }}</span>
          <span v-if="stats.downtimeHours > 0" class="tabular-nums">{{ stats.downtimeHours }}h {{ $t('efactura.anafStats.downtimeLabel') }}</span>
        </div>
      </div>

      <!-- Zone 3 — KPI grid -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <!-- Success rate -->
        <div class="rounded-lg bg-(--ui-bg-elevated) p-4">
          <div class="text-2xl font-semibold tabular-nums" :class="successRateColorClass">
            {{ stats.successRate !== null ? `${stats.successRate}%` : '—' }}
          </div>
          <div class="text-xs text-(--ui-text-muted) mt-1 uppercase tracking-wide font-medium">
            {{ $t('efactura.anafStats.successRate') }}
          </div>
        </div>

        <!-- Last success -->
        <div class="rounded-lg bg-(--ui-bg-elevated) p-4">
          <div class="text-2xl font-semibold tabular-nums text-(--ui-text)">
            {{ timeAgo(stats.lastSuccessAt) }}
          </div>
          <div class="text-xs text-(--ui-text-muted) mt-1 uppercase tracking-wide font-medium">
            {{ $t('efactura.anafStats.lastSuccess') }}
          </div>
          <div v-if="stats.lastSuccessAt" class="text-xs text-(--ui-text-muted) mt-0.5">
            {{ formatAbsoluteDate(stats.lastSuccessAt) }}
          </div>
        </div>

        <!-- Last failure -->
        <div class="rounded-lg bg-(--ui-bg-elevated) p-4">
          <div class="text-2xl font-semibold tabular-nums" :class="stats.lastFailureAt ? 'text-error' : 'text-(--ui-text-muted)'">
            {{ stats.lastFailureAt ? timeAgo(stats.lastFailureAt) : '—' }}
          </div>
          <div class="text-xs text-(--ui-text-muted) mt-1 uppercase tracking-wide font-medium">
            {{ $t('efactura.anafStats.lastFailure') }}
          </div>
          <div v-if="!stats.lastFailureAt" class="text-xs text-success mt-0.5">
            {{ $t('efactura.anafStats.noFailures') }}
          </div>
          <div v-else-if="formatRetryCountdown(stats.nextRetryAt)" class="text-xs text-warning mt-0.5">
            {{ formatRetryCountdown(stats.nextRetryAt) }}
          </div>
        </div>
      </div>
    </div>

    <!-- No data state -->
    <div v-else-if="stats" class="px-5 py-8 text-center">
      <p class="text-sm text-(--ui-text-muted)">{{ $t('efactura.anafStats.noData') }}</p>
    </div>
  </div>
</template>
