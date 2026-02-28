<template>
  <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-3">
    <UCard
      v-for="month in 12"
      :key="month"
      :ui="{ root: getCardClass(month), body: 'flex flex-col items-center gap-2 py-4 px-2 text-center' }"
      @click="handleCardClick(month)"
    >
      <p class="text-xs font-medium text-(--ui-text-highlighted) leading-tight">
        {{ $t(`reports.months.${month}`) }}
      </p>

      <!-- Empty: no balance uploaded -->
      <template v-if="getStatus(month) === 'empty'">
        <div class="w-8 h-8 rounded-full border-2 border-dashed border-gray-300 dark:border-gray-600 flex items-center justify-center">
          <UIcon name="i-lucide-plus" class="size-4 text-(--ui-text-muted)" />
        </div>
        <p class="text-xs text-(--ui-text-muted)">{{ $t('reports.balanceAnalysis.upload') }}</p>
      </template>

      <!-- Pending -->
      <template v-else-if="getStatus(month) === 'pending'">
        <UIcon name="i-lucide-clock" class="size-8 text-amber-500" />
        <p class="text-xs text-amber-600 dark:text-amber-400">{{ $t('reports.balanceAnalysis.pending') }}</p>
      </template>

      <!-- Processing -->
      <template v-else-if="getStatus(month) === 'processing'">
        <UIcon name="i-lucide-loader-2" class="size-8 text-blue-500 animate-spin" />
        <p class="text-xs text-blue-600 dark:text-blue-400">{{ $t('reports.balanceAnalysis.processing') }}</p>
      </template>

      <!-- Completed -->
      <template v-else-if="getStatus(month) === 'completed'">
        <div class="relative w-full flex flex-col items-center gap-2">
          <UButton
            icon="i-lucide-trash-2"
            size="xs"
            color="neutral"
            variant="ghost"
            class="absolute -top-3 -right-2 opacity-0 group-hover/card:opacity-100 transition-opacity"
            :title="$t('reports.balanceAnalysis.deleteBalance')"
            @click.stop="handleDelete(month)"
          />
          <UIcon name="i-lucide-check-circle" class="size-8 text-green-500" />
          <p class="text-xs text-green-600 dark:text-green-400">{{ $t('reports.balanceAnalysis.completed') }}</p>
          <p class="text-xs text-(--ui-text-muted)">
            {{ getBalance(month)?.totalAccounts }} {{ $t('reports.balanceAnalysis.accounts') }}
          </p>
        </div>
      </template>

      <!-- Failed -->
      <template v-else-if="getStatus(month) === 'failed'">
        <UIcon name="i-lucide-x-circle" class="size-8 text-red-500" />
        <p class="text-xs text-red-600 dark:text-red-400">{{ $t('reports.balanceAnalysis.failed') }}</p>
      </template>
    </UCard>
  </div>
</template>

<script setup lang="ts">
import type { TrialBalance } from '~/types'

const props = defineProps<{
  balances: TrialBalance[]
  year: number
}>()

const emit = defineEmits<{
  upload: [month: number]
  delete: [id: string]
}>()

const { t: $t } = useI18n()

function getBalance(month: number): TrialBalance | undefined {
  return props.balances.find(b => b.month === month)
}

function getStatus(month: number): 'empty' | 'pending' | 'processing' | 'completed' | 'failed' {
  const balance = getBalance(month)
  if (!balance) return 'empty'
  return balance.status
}

function getCardClass(month: number): string {
  const status = getStatus(month)
  const base = 'group/card transition-colors'
  if (status === 'empty') return `${base} cursor-pointer hover:border-primary hover:shadow-sm`
  if (status === 'completed') return `${base} border-green-200 dark:border-green-800/60`
  if (status === 'failed') return `${base} border-red-200 dark:border-red-800/60`
  if (status === 'pending') return `${base} border-amber-200 dark:border-amber-800/60`
  if (status === 'processing') return `${base} border-blue-200 dark:border-blue-800/60`
  return base
}

function handleCardClick(month: number) {
  if (getStatus(month) === 'empty') {
    emit('upload', month)
  }
}

function handleDelete(month: number) {
  const balance = getBalance(month)
  if (balance) {
    emit('delete', balance.id)
  }
}
</script>
