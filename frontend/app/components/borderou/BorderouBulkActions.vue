<script setup lang="ts">
const { t: $t } = useI18n()
const store = useBordereauStore()
const toast = useToast()

const props = defineProps<{
  selectedIds: string[]
}>()

const emit = defineEmits<{
  saved: []
  cleared: []
}>()

const confirmOpen = ref(false)

async function handleSave() {
  confirmOpen.value = false
  const result = await store.saveTransactions(props.selectedIds)

  if (result) {
    toast.add({
      title: $t('borderou.bulkSaveSuccess'),
      description: $t('borderou.bulkSaveCount', { count: result.saved }),
      color: 'success',
    })
    if (result.errors.length > 0) {
      toast.add({
        title: `${result.errors.length} erori`,
        description: result.errors.map(e => e.error).join(', '),
        color: 'warning',
      })
    }
    emit('saved')
  }
}

async function handleRematch() {
  await store.rematchTransactions(props.selectedIds)
  emit('saved')
}
</script>

<template>
  <div
    v-if="selectedIds.length > 0"
    class="flex items-center justify-between p-3 rounded-lg bg-primary/5 border border-primary/20"
  >
    <span class="text-sm font-medium">
      {{ selectedIds.length }} {{ selectedIds.length === 1 ? 'tranzactie selectata' : 'tranzactii selectate' }}
    </span>

    <div class="flex items-center gap-2">
      <UButton
        :label="$t('borderou.bulkRematch')"
        icon="i-lucide-refresh-cw"
        variant="soft"
        size="sm"
        :loading="store.loading"
        @click="handleRematch"
      />

      <UButton
        :label="$t('borderou.bulkSaveSelected')"
        icon="i-lucide-save"
        size="sm"
        :loading="store.saving"
        @click="confirmOpen = true"
      />
    </div>

    <!-- Confirm modal -->
    <UModal v-model:open="confirmOpen">
      <template #header>
        <h3 class="text-lg font-semibold">{{ $t('borderou.bulkSaveSelected') }}</h3>
      </template>
      <template #body>
        <p class="text-sm">{{ $t('borderou.bulkSaveConfirm') }}</p>
        <p class="text-sm text-(--ui-text-muted) mt-2">
          {{ selectedIds.length }} {{ selectedIds.length === 1 ? 'tranzactie' : 'tranzactii' }}
        </p>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton :label="$t('common.cancel')" variant="ghost" @click="confirmOpen = false" />
          <UButton
            :label="$t('borderou.bulkSaveSelected')"
            icon="i-lucide-save"
            :loading="store.saving"
            @click="handleSave"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>
