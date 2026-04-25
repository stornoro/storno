<script setup lang="ts">
import type { CashRegisterBalance } from '~/types'

const { t: $t } = useI18n()
const intlLocale = useIntlLocale()

const data = ref<CashRegisterBalance | null>(null)
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    const { get } = useApi()
    data.value = await get<CashRegisterBalance>('/v1/cash-register/balance')
  }
  catch {
    data.value = null
  }
  finally {
    loading.value = false
  }
}

function formatMoney(amount?: string | null) {
  return new Intl.NumberFormat(intlLocale, { maximumFractionDigits: 2 }).format(Number(amount || 0))
}

onMounted(load)
</script>

<template>
  <div class="rounded-lg border border-(--ui-border) bg-(--ui-bg) overflow-hidden flex flex-col h-full">
    <div class="px-5 pt-4 pb-2">
      <h3 class="text-sm font-bold tracking-wide text-(--ui-text) uppercase">
        {{ $t('dashboard.cards.cashBalance') }}
      </h3>
    </div>

    <div class="px-5 pb-5 flex-1 flex flex-col">
      <template v-if="loading">
        <USkeleton class="w-32 h-8 mb-2" />
        <USkeleton class="w-48 h-4" />
      </template>

      <template v-else-if="data?.configured">
        <div class="flex items-baseline gap-1.5 mb-3">
          <span class="text-3xl font-semibold text-(--ui-text) tabular-nums">
            {{ formatMoney(data.currentBalance) }}
          </span>
          <span class="text-sm text-(--ui-text-muted)">{{ data.currency }}</span>
        </div>
        <dl class="grid grid-cols-2 gap-2 text-xs text-(--ui-text-muted) mb-3">
          <div>
            <dt>{{ $t('dashboard.cards.cashOpening') }}</dt>
            <dd class="font-medium text-(--ui-text) tabular-nums">{{ formatMoney(data.openingBalance) }}</dd>
          </div>
          <div>
            <dt>{{ $t('dashboard.cards.cashOpeningDate') }}</dt>
            <dd class="font-medium text-(--ui-text)">{{ data.openingBalanceDate }}</dd>
          </div>
          <div>
            <dt>{{ $t('common.incoming') }}</dt>
            <dd class="font-medium text-success tabular-nums">+{{ formatMoney(data.cashReceipts) }}</dd>
          </div>
          <div>
            <dt>{{ $t('common.outgoing') }}</dt>
            <dd class="font-medium text-error tabular-nums">−{{ formatMoney(data.cashPayments) }}</dd>
          </div>
        </dl>
        <p class="text-xs text-(--ui-text-muted) mt-auto">{{ $t('dashboard.cards.cashBalanceLive') }}</p>
      </template>

      <template v-else>
        <p class="text-sm text-(--ui-text-muted) text-center mb-4">
          {{ $t('dashboard.cards.cashBalanceDesc') }}
        </p>
        <div class="flex justify-center">
          <UButton to="/settings/bank-accounts" color="primary" size="sm">
            {{ $t('dashboard.cards.setInitialBalance') }}
          </UButton>
        </div>
      </template>
    </div>
  </div>
</template>
