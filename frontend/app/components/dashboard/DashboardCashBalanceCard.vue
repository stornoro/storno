<script setup lang="ts">
const { t: $t } = useI18n()
</script>

<template>
  <div class="rounded-lg border border-(--ui-border) bg-(--ui-bg) overflow-hidden">
    <div class="px-5 pt-4 pb-2">
      <h3 class="text-sm font-bold tracking-wide text-(--ui-text) uppercase">
        {{ $t('dashboard.cards.cashBalance') }}
      </h3>
    </div>

    <div class="px-5 pb-5">
      <p class="text-sm text-(--ui-text-muted) text-center mb-4">
        {{ $t('dashboard.cards.cashBalanceDesc') }}
      </p>

      <div class="flex justify-center">
        <UButton to="/settings/bank-accounts" color="primary" size="sm">
          {{ $t('dashboard.cards.setInitialBalance') }}
        </UButton>
      </div>
    </div>
  </div>
</template>
