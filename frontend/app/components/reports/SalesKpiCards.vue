<template>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <!-- Annual Total -->
    <UCard :ui="{ root: 'overflow-hidden', body: 'relative' }">
      <div class="absolute inset-y-0 left-0 w-1 bg-(--ui-primary) rounded-l-lg" />
      <div class="pl-3">
        <div class="flex items-start justify-between mb-3">
          <div class="size-9 rounded-lg bg-(--ui-primary)/10 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-calendar" class="size-4.5 text-(--ui-primary)" />
          </div>
          <div class="flex flex-col items-end gap-1">
            <UBadge
              :color="yoyChange >= 0 ? 'success' : 'error'"
              variant="subtle"
              size="xs"
              class="font-semibold"
            >
              <UIcon
                :name="yoyChange >= 0 ? 'i-lucide-trending-up' : 'i-lucide-trending-down'"
                class="size-3 mr-0.5"
              />
              {{ yoyChange >= 0 ? '+' : '' }}{{ yoyChange.toFixed(1) }}%
            </UBadge>
            <span class="text-[10px] text-(--ui-text-muted) leading-none">{{ $t('reports.salesAnalysis.yoyChange') }}</span>
          </div>
        </div>
        <div class="text-[11px] font-medium text-(--ui-text-muted) uppercase tracking-wide mb-1">
          {{ $t('reports.salesAnalysis.annualTotal') }} {{ data.annualTotal.year }}
        </div>
        <div class="text-2xl font-bold tabular-nums text-(--ui-text-highlighted) leading-tight">
          {{ formatMoney(data.annualTotal.amount) }}
        </div>
        <div class="text-xs text-(--ui-text-muted) mt-1">
          {{ $t('reports.salesAnalysis.annualTotal') }} {{ data.annualTotal.prevYear }}: {{ formatMoney(data.annualTotal.prevAmount) }}
        </div>
      </div>
    </UCard>

    <!-- Period Invoiced -->
    <UCard :ui="{ root: 'overflow-hidden', body: 'relative' }">
      <div class="absolute inset-y-0 left-0 w-1 bg-(--ui-info) rounded-l-lg" />
      <div class="pl-3">
        <div class="flex items-start justify-between mb-3">
          <div class="size-9 rounded-lg bg-(--ui-info)/10 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-file-text" class="size-4.5 text-(--ui-info)" />
          </div>
          <span class="text-xs font-medium text-(--ui-text-muted) tabular-nums">
            {{ data.periodInvoiced.count }} {{ $t('reports.salesAnalysis.invoices') }}
          </span>
        </div>
        <div class="text-[11px] font-medium text-(--ui-text-muted) uppercase tracking-wide mb-1">
          {{ $t('reports.salesAnalysis.periodInvoiced') }}
        </div>
        <div class="text-2xl font-bold tabular-nums text-(--ui-text-highlighted) leading-tight">
          {{ formatMoney(data.periodInvoiced.total) }}
        </div>
        <div class="text-xs text-(--ui-text-muted) mt-1">
          {{ $t('reports.salesAnalysis.vatLabel') }}: {{ formatMoney(data.periodInvoiced.vatTotal) }}
        </div>
      </div>
    </UCard>

    <!-- Period Collected -->
    <UCard :ui="{ root: 'overflow-hidden', body: 'relative' }">
      <div class="absolute inset-y-0 left-0 w-1 bg-(--ui-success) rounded-l-lg" />
      <div class="pl-3">
        <div class="flex items-start justify-between mb-3">
          <div class="size-9 rounded-lg bg-(--ui-success)/10 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-circle-check" class="size-4.5 text-(--ui-success)" />
          </div>
          <span class="text-xs font-medium text-(--ui-text-muted) tabular-nums">
            {{ data.periodCollected.count }} {{ $t('reports.salesAnalysis.invoices') }}
          </span>
        </div>
        <div class="text-[11px] font-medium text-(--ui-text-muted) uppercase tracking-wide mb-1">
          {{ $t('reports.salesAnalysis.periodCollected') }}
        </div>
        <div class="text-2xl font-bold tabular-nums text-(--ui-success) leading-tight">
          {{ formatMoney(data.periodCollected.total) }}
        </div>
        <!-- Collection rate progress bar -->
        <div class="mt-2 space-y-1">
          <div class="flex items-center justify-between">
            <span class="text-[10px] text-(--ui-text-muted)">{{ $t('reports.salesAnalysis.collectionRate') }}</span>
            <span class="text-[10px] font-semibold text-(--ui-success)">{{ collectionRate.toFixed(0) }}%</span>
          </div>
          <div class="h-1.5 rounded-full bg-(--ui-border) overflow-hidden">
            <div
              class="h-full rounded-full bg-(--ui-success) transition-all duration-700"
              :style="{ width: `${Math.min(collectionRate, 100)}%` }"
            />
          </div>
        </div>
      </div>
    </UCard>

    <!-- Outstanding -->
    <UCard :ui="{ root: 'overflow-hidden', body: 'relative' }">
      <div class="absolute inset-y-0 left-0 w-1 bg-(--ui-warning) rounded-l-lg" />
      <div class="pl-3">
        <div class="flex items-start justify-between mb-3">
          <div class="size-9 rounded-lg bg-(--ui-warning)/10 flex items-center justify-center shrink-0">
            <UIcon name="i-lucide-circle-alert" class="size-4.5 text-(--ui-warning)" />
          </div>
          <span class="text-xs font-medium text-(--ui-text-muted) tabular-nums">
            {{ data.periodOutstanding.count }} {{ $t('reports.salesAnalysis.invoices') }}
          </span>
        </div>
        <div class="text-[11px] font-medium text-(--ui-text-muted) uppercase tracking-wide mb-1">
          {{ $t('reports.salesAnalysis.periodOutstanding') }}
        </div>
        <div class="text-2xl font-bold tabular-nums text-(--ui-warning) leading-tight">
          {{ formatMoney(data.periodOutstanding.total) }}
        </div>
        <!-- Outstanding rate progress bar -->
        <div class="mt-2 space-y-1">
          <div class="flex items-center justify-between">
            <span class="text-[10px] text-(--ui-text-muted)">{{ $t('reports.salesAnalysis.outstandingRate') }}</span>
            <span class="text-[10px] font-semibold text-(--ui-warning)">{{ outstandingRate.toFixed(0) }}%</span>
          </div>
          <div class="h-1.5 rounded-full bg-(--ui-border) overflow-hidden">
            <div
              class="h-full rounded-full bg-(--ui-warning) transition-all duration-700"
              :style="{ width: `${Math.min(outstandingRate, 100)}%` }"
            />
          </div>
        </div>
      </div>
    </UCard>
  </div>
</template>

<script setup lang="ts">
import type { SalesKpiSummary } from '~/types'

const props = defineProps<{
  data: SalesKpiSummary
}>()

const { t: $t } = useI18n()
const { formatMoney } = useMoney()

const yoyChange = computed(() => {
  const prev = parseFloat(props.data.annualTotal.prevAmount) || 0
  const curr = parseFloat(props.data.annualTotal.amount) || 0
  if (prev === 0) return curr > 0 ? 100 : 0
  return ((curr - prev) / prev) * 100
})

const collectionRate = computed(() => {
  const invoiced = parseFloat(props.data.periodInvoiced.total) || 0
  const collected = parseFloat(props.data.periodCollected.total) || 0
  if (invoiced === 0) return 0
  return (collected / invoiced) * 100
})

const outstandingRate = computed(() => {
  const invoiced = parseFloat(props.data.periodInvoiced.total) || 0
  const outstanding = parseFloat(props.data.periodOutstanding.total) || 0
  if (invoiced === 0) return 0
  return (outstanding / invoiced) * 100
})
</script>
