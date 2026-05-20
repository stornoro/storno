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
              class="w-full"
            />
          </UFormField>
          <UFormField :label="$t('accountingExport.sagaAccountBank')">
            <UInput
              v-model="sagaForm.accountBank"
              placeholder="5121"
              class="w-full"
            />
          </UFormField>
          <UFormField :label="$t('accountingExport.sagaAccountCard')">
            <UInput
              v-model="sagaForm.accountCard"
              placeholder="5125.2"
              class="w-full"
            />
          </UFormField>
          <UFormField :label="$t('accountingExport.sagaAccountClients')">
            <UInput
              v-model="sagaForm.accountClients"
              placeholder="4111"
              class="w-full"
            />
          </UFormField>
          <UFormField :label="$t('accountingExport.sagaAccountSuppliers')">
            <UInput
              v-model="sagaForm.accountSuppliers"
              placeholder="4011"
              class="w-full"
            />
          </UFormField>

          <div class="pt-3 border-t border-(--ui-border)">
            <div class="flex items-center justify-between mb-1">
              <p class="text-sm font-medium">{{ $t('accountingExport.sagaCurrencyAccountsTitle') }}</p>
              <UButton
                :label="$t('accountingExport.sagaCurrencyAccountsAdd')"
                icon="i-lucide-plus"
                size="xs"
                variant="ghost"
                type="button"
                @click="addCurrencyRow"
              />
            </div>
            <p class="text-xs text-(--ui-text-muted) mb-3">{{ $t('accountingExport.sagaCurrencyAccountsHelp') }}</p>
            <div v-for="(row, i) in sagaCurrencyRows" :key="i" class="grid grid-cols-12 gap-2 mb-2 items-end">
              <UFormField :label="$t('accountingExport.sagaCurrencyCode')" class="col-span-2">
                <UInput v-model="row.currency" placeholder="USD" class="w-full" :maxlength="3" />
              </UFormField>
              <UFormField :label="$t('accountingExport.sagaAccountCash')" class="col-span-3">
                <UInput v-model="row.cash" placeholder="5314" class="w-full" />
              </UFormField>
              <UFormField :label="$t('accountingExport.sagaAccountBank')" class="col-span-3">
                <UInput v-model="row.bank" placeholder="5124" class="w-full" />
              </UFormField>
              <UFormField :label="$t('accountingExport.sagaAccountCard')" class="col-span-3">
                <UInput v-model="row.card" placeholder="5125.1" class="w-full" />
              </UFormField>
              <UButton
                icon="i-lucide-trash-2"
                size="xs"
                variant="ghost"
                color="error"
                type="button"
                class="col-span-1 mb-1"
                @click="removeCurrencyRow(i)"
              />
            </div>
          </div>
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
  currencyAccounts: Record<string, { cash?: string, bank?: string, card?: string } | undefined>
}

interface CurrencyAccountRow {
  currency: string
  cash: string
  bank: string
  card: string
}

interface WinmentorSettings {
  bankName: string
  bankNumber: string
  bankLocality: string
}

interface ExportSettings {
  saga?: Partial<Omit<SagaSettings, 'currencyAccounts'>> & {
    currencyAccounts?: Record<string, { cash?: string, bank?: string, card?: string } | undefined>
  }
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
  currencyAccounts: {},
})

const sagaCurrencyRows = ref<CurrencyAccountRow[]>([])

function addCurrencyRow() {
  sagaCurrencyRows.value.push({ currency: '', cash: '', bank: '', card: '' })
}

function removeCurrencyRow(i: number) {
  sagaCurrencyRows.value.splice(i, 1)
}

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
      const ca = data.saga.currencyAccounts && typeof data.saga.currencyAccounts === 'object' && !Array.isArray(data.saga.currencyAccounts)
        ? data.saga.currencyAccounts
        : {}
      sagaForm.value = {
        accountCash: data.saga.accountCash ?? '',
        accountBank: data.saga.accountBank ?? '',
        accountCard: data.saga.accountCard ?? '',
        accountClients: data.saga.accountClients ?? '',
        accountSuppliers: data.saga.accountSuppliers ?? '',
        currencyAccounts: ca,
      }
      sagaCurrencyRows.value = Object.entries(ca).map(([currency, accounts]) => ({
        currency,
        cash: accounts?.cash ?? '',
        bank: accounts?.bank ?? '',
        card: accounts?.card ?? '',
      }))
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
      const currencyAccounts: Record<string, { cash: string, bank: string, card: string }> = {}
      for (const row of sagaCurrencyRows.value) {
        const code = row.currency.trim().toUpperCase()
        if (!code || code === 'RON') continue
        currencyAccounts[code] = {
          cash: row.cash.trim(),
          bank: row.bank.trim(),
          card: row.card.trim(),
        }
      }
      payload.saga = { ...sagaForm.value, currencyAccounts }
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
