<template>
  <UModal v-model:open="open" :ui="{ content: 'sm:max-w-lg' }">
    <template #header>
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-settings" class="size-5 shrink-0 text-(--ui-primary)" />
        <h3 class="font-semibold">{{ $t('accountingExport.settingsTitle') }}</h3>
      </div>
    </template>

    <template #body>
      <p class="mb-4 text-sm text-(--ui-text-muted)">
        {{ $t('accountingExport.settingsDescription') }}
      </p>

      <div v-if="loading" class="flex items-center justify-center py-8">
        <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-(--ui-text-muted)" />
      </div>

      <template v-else>
        <!-- Saga settings -->
        <div v-if="target === 'saga'" class="space-y-4">
          <UFormField :label="$t('accountingExport.sagaAccountCash')">
            <UInput
              v-model="sagaForm.accountCash"
              placeholder="5311"
              type="number"
              class="w-full"
            />
          </UFormField>
          <UFormField :label="$t('accountingExport.sagaAccountBank')">
            <UInput
              v-model="sagaForm.accountBank"
              placeholder="5121"
              type="number"
              class="w-full"
            />
          </UFormField>
          <UFormField :label="$t('accountingExport.sagaAccountCard')">
            <UInput
              v-model="sagaForm.accountCard"
              placeholder="5125"
              type="number"
              class="w-full"
            />
          </UFormField>
          <UFormField :label="$t('accountingExport.sagaAccountClients')">
            <UInput
              v-model="sagaForm.accountClients"
              placeholder="4111"
              type="number"
              class="w-full"
            />
          </UFormField>
          <UFormField :label="$t('accountingExport.sagaAccountSuppliers')">
            <UInput
              v-model="sagaForm.accountSuppliers"
              placeholder="4011"
              type="number"
              class="w-full"
            />
          </UFormField>
        </div>

        <!-- WinMentor settings -->
        <div v-else-if="target === 'winmentor'" class="space-y-4">
          <UFormField :label="$t('accountingExport.winmentorBankName')">
            <UInput
              v-model="winmentorForm.bankName"
              class="w-full"
            />
          </UFormField>
          <UFormField :label="$t('accountingExport.winmentorBankNumber')">
            <UInput
              v-model="winmentorForm.bankNumber"
              class="w-full"
            />
          </UFormField>
          <UFormField :label="$t('accountingExport.winmentorBankLocality')">
            <UInput
              v-model="winmentorForm.bankLocality"
              class="w-full"
            />
          </UFormField>
        </div>

        <!-- Ciel settings -->
        <div v-else-if="target === 'ciel'" class="py-4">
          <p class="text-sm text-(--ui-text-muted)">
            {{ $t('accountingExport.cielNoSettings') }}
          </p>
        </div>
      </template>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton
          type="button"
          variant="ghost"
          :disabled="saving"
          @click="open = false"
        >
          {{ $t('accountingExport.cancel') }}
        </UButton>
        <UButton
          v-if="target !== 'ciel'"
          type="button"
          :loading="saving"
          :disabled="loading"
          @click="onSave"
        >
          {{ $t('accountingExport.save') }}
        </UButton>
        <UButton
          v-else
          type="button"
          variant="ghost"
          @click="open = false"
        >
          {{ $t('common.close') }}
        </UButton>
      </div>
    </template>
  </UModal>
</template>

<script setup lang="ts">
type Target = 'saga' | 'winmentor' | 'ciel'

interface SagaSettings {
  accountCash: string
  accountBank: string
  accountCard: string
  accountClients: string
  accountSuppliers: string
}

interface WinmentorSettings {
  bankName: string
  bankNumber: string
  bankLocality: string
}

interface ExportSettings {
  saga?: Partial<SagaSettings>
  winmentor?: Partial<WinmentorSettings>
  ciel?: Record<string, never>
}

const props = defineProps<{
  target: Target
}>()

const emit = defineEmits<{
  saved: []
}>()

const open = defineModel<boolean>('open', { default: false })

const { t: $t } = useI18n()
const { get, put } = useApi()
const toast = useToast()

const loading = ref(false)
const saving = ref(false)

const sagaForm = ref<SagaSettings>({
  accountCash: '',
  accountBank: '',
  accountCard: '',
  accountClients: '',
  accountSuppliers: '',
})

const winmentorForm = ref<WinmentorSettings>({
  bankName: '',
  bankNumber: '',
  bankLocality: '',
})

async function fetchSettings() {
  loading.value = true
  try {
    const data = await get<ExportSettings>('/v1/accounting-export/settings')

    if (data.saga) {
      sagaForm.value = {
        accountCash: data.saga.accountCash ?? '',
        accountBank: data.saga.accountBank ?? '',
        accountCard: data.saga.accountCard ?? '',
        accountClients: data.saga.accountClients ?? '',
        accountSuppliers: data.saga.accountSuppliers ?? '',
      }
    }

    if (data.winmentor) {
      winmentorForm.value = {
        bankName: data.winmentor.bankName ?? '',
        bankNumber: data.winmentor.bankNumber ?? '',
        bankLocality: data.winmentor.bankLocality ?? '',
      }
    }
  }
  catch {
    // Settings may not exist yet — silently proceed with empty defaults
  }
  finally {
    loading.value = false
  }
}

async function onSave() {
  saving.value = true
  try {
    const payload: ExportSettings = {}

    if (props.target === 'saga') {
      payload.saga = { ...sagaForm.value }
    }
    else if (props.target === 'winmentor') {
      payload.winmentor = { ...winmentorForm.value }
    }

    await put('/v1/accounting-export/settings', payload)

    toast.add({
      title: $t('accountingExport.settingsSaved'),
      color: 'success',
      icon: 'i-lucide-check-circle',
    })

    emit('saved')
    open.value = false
  }
  catch {
    toast.add({
      title: $t('accountingExport.settingsError'),
      color: 'error',
      icon: 'i-lucide-alert-circle',
    })
  }
  finally {
    saving.value = false
  }
}

watch(open, (isOpen) => {
  if (isOpen) {
    fetchSettings()
  }
})
</script>
