<script setup lang="ts">
import type { BankAccount } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('bankAccounts.title') })
const store = useBankAccountStore()
const companyStore = useCompanyStore()
const toast = useToast()
const { copy } = useClipboard()
const { fetchDefaults, currencyOptions } = useInvoiceDefaults()
const { isModuleEnabled, MODULE_KEYS } = useModules()
const cashRegisterEnabled = computed(() => isModuleEnabled(MODULE_KEYS.CASH_REGISTER))

const loading = computed(() => store.loading)
const accounts = computed(() => store.items)

const modalOpen = ref(false)
const saving = ref(false)
const editingAccount = ref<BankAccount | null>(null)
const form = ref({
  iban: '',
  bankName: '',
  currency: 'RON',
  isDefault: false,
  type: 'bank' as 'bank' | 'cash',
  openingBalance: '',
  openingBalanceDate: '',
})

const typeOptions = computed(() => [
  { label: $t('bankAccounts.typeBank'), value: 'bank' },
  { label: $t('bankAccounts.typeCash'), value: 'cash' },
])

const cashAccountExists = computed(() => accounts.value.some(a => a.type === 'cash'))
const isCashForm = computed(() => form.value.type === 'cash')

// Romanian bank code → bank name mapping (SWIFT/BIC first 4 chars)
const BANK_CODES: Record<string, string> = {
  RNCB: 'BCR',
  BTRL: 'Banca Transilvania',
  BRDE: 'BRD - Groupe Société Générale',
  INGB: 'ING Bank',
  RZBR: 'Raiffeisen Bank',
  BACX: 'UniCredit Bank',
  CECE: 'CEC Bank',
  PIRB: 'First Bank',
  TREZ: 'Trezoreria Statului',
  OTPV: 'OTP Bank',
  BREL: 'Libra Internet Bank',
  UGBI: 'Garanti BBVA',
  DAFB: 'EximBank',
  CARP: 'Patria Bank',
  MILB: 'TBI Bank',
  BSEA: 'Vista Bank',
  WBAN: 'Wise',
  NBOR: 'BNR',
  BUCU: 'Alpha Bank',
  HVBL: 'Intesa Sanpaolo Bank',
  MIND: 'Idea Bank',
  CRCO: 'Credit Agricole',
  PORL: 'Patria Bank',
  BPOS: 'Banca Romaneasca',
}

function parseBankFromIban(iban: string): string | null {
  const clean = iban.replace(/\s/g, '').toUpperCase()
  if (clean.length >= 8 && clean.startsWith('RO')) {
    const code = clean.substring(4, 8)
    return BANK_CODES[code] ?? null
  }
  return null
}

// IBAN validation (ISO 13616 mod-97 checksum)
function validateIban(iban: string): boolean {
  const clean = iban.replace(/\s/g, '').toUpperCase()
  if (clean.length < 15 || clean.length > 34) return false
  if (!/^[A-Z]{2}\d{2}[A-Z0-9]+$/.test(clean)) return false
  // Move first 4 chars to end, convert letters to digits (A=10..Z=35)
  const rearranged = clean.slice(4) + clean.slice(0, 4)
  const digits = rearranged.replace(/[A-Z]/g, ch => String(ch.charCodeAt(0) - 55))
  // Mod 97 on large number (process in chunks to avoid overflow)
  let remainder = 0
  for (const ch of digits) {
    remainder = (remainder * 10 + Number(ch)) % 97
  }
  return remainder === 1
}

const ibanError = computed(() => {
  const clean = form.value.iban.replace(/\s/g, '')
  if (!clean) return null
  if (clean.length < 15) return $t('bankAccounts.ibanTooShort')
  if (!validateIban(clean)) return $t('bankAccounts.ibanInvalid')
  return null
})

const canSave = computed(() => {
  if (isCashForm.value) {
    // Opening balance + date must be provided together (or both empty for "configure later").
    // openingBalance comes from a number input so it can be a number or empty string; coerce.
    const amount = form.value.openingBalance
    const hasAmount = amount !== '' && amount !== null && amount !== undefined
    const hasDate = String(form.value.openingBalanceDate ?? '').trim() !== ''
    if (hasAmount !== hasDate) return false
    if (hasAmount && Number(amount) < 0) return false
    return true
  }
  if (!editingAccount.value && !form.value.iban.trim()) return false
  if (!editingAccount.value && ibanError.value) return false
  return true
})

// Auto-fill bank name when IBAN changes (only for new accounts)
watch(() => form.value.iban, (iban) => {
  if (editingAccount.value) return
  const bank = parseBankFromIban(iban)
  if (bank) {
    form.value.bankName = bank
  }
})

const columns = [
  { accessorKey: 'iban', header: $t('bankAccounts.iban') },
  { accessorKey: 'bankName', header: $t('bankAccounts.bankName') },
  { accessorKey: 'currency', header: $t('bankAccounts.currency') },
  { accessorKey: 'isDefault', header: $t('bankAccounts.isDefault') },
  { accessorKey: 'showOnInvoice', header: $t('bankAccounts.showOnInvoice') },
  { accessorKey: 'source', header: $t('bankAccounts.source') },
  { id: 'actions', header: $t('common.actions') },
]

function openCreate(type: 'bank' | 'cash' = 'bank') {
  editingAccount.value = null
  form.value = {
    iban: '',
    bankName: '',
    currency: 'RON',
    isDefault: false,
    type,
    openingBalance: '',
    openingBalanceDate: '',
  }
  modalOpen.value = true
}

function openEdit(account: BankAccount) {
  editingAccount.value = account
  form.value = {
    iban: account.iban || '',
    bankName: account.bankName || '',
    currency: account.currency,
    isDefault: account.isDefault,
    type: account.type ?? 'bank',
    openingBalance: account.openingBalance ?? '',
    openingBalanceDate: account.openingBalanceDate ?? '',
  }
  modalOpen.value = true
}

function buildPayload() {
  if (isCashForm.value) {
    const payload: Record<string, unknown> = {
      type: 'cash',
      bankName: form.value.bankName || null,
      currency: form.value.currency,
    }
    const amount = form.value.openingBalance
    const date = String(form.value.openingBalanceDate ?? '').trim()
    const hasOpening = amount !== '' && amount !== null && amount !== undefined && date !== ''
    if (hasOpening) {
      payload.openingBalance = Number(amount).toFixed(2)
      payload.openingBalanceDate = date
    }
    return payload
  }
  return {
    type: 'bank',
    iban: form.value.iban,
    bankName: form.value.bankName,
    currency: form.value.currency,
    isDefault: form.value.isDefault,
  }
}

async function onSave() {
  saving.value = true
  let payload = buildPayload() as Record<string, unknown>
  if (editingAccount.value) {
    const ok = await runUpdate(editingAccount.value.id, payload)
    if (ok === 'locked') {
      // Backend says the opening balance is locked because cash transactions exist.
      // Confirm with the user, then retry with the override flag.
      if (window.confirm($t('bankAccounts.openingBalanceResetConfirm'))) {
        payload = { ...payload, confirmReset: true }
        const retry = await runUpdate(editingAccount.value.id, payload)
        if (retry === true) {
          toast.add({ title: $t('bankAccounts.updateSuccess'), color: 'success' })
          modalOpen.value = false
        }
      }
    }
    else if (ok === true) {
      toast.add({ title: $t('bankAccounts.updateSuccess'), color: 'success' })
      modalOpen.value = false
    }
  }
  else {
    const result = await store.createBankAccount(payload as Parameters<typeof store.createBankAccount>[0])
    if (result) {
      toast.add({ title: $t('bankAccounts.createSuccess'), color: 'success' })
      modalOpen.value = false
    }
    else if (store.error) {
      toast.add({ title: store.error, color: 'error' })
    }
  }
  saving.value = false
}

// Wraps the update call so we can distinguish the lock-conflict error from other failures.
async function runUpdate(id: string, payload: Record<string, unknown>): Promise<true | 'locked' | false> {
  const { patch } = useApi()
  try {
    await patch(`/v1/bank-accounts/${id}`, payload)
    await store.fetchBankAccounts()
    return true
  }
  catch (err: any) {
    const code = err?.data?.error
    if (err?.statusCode === 409 && code === 'opening_balance_locked') {
      return 'locked'
    }
    toast.add({ title: code ? translateApiError(code) : $t('error.generic'), color: 'error' })
    return false
  }
}

const deleteModalOpen = ref(false)
const deletingAccount = ref<BankAccount | null>(null)
const deleting = ref(false)

function openDelete(account: BankAccount) {
  deletingAccount.value = account
  deleteModalOpen.value = true
}

async function onDelete() {
  if (!deletingAccount.value) return
  deleting.value = true
  const ok = await store.deleteBankAccount(deletingAccount.value.id)
  if (ok) {
    toast.add({ title: $t('bankAccounts.deleteSuccess'), color: 'success' })
    deleteModalOpen.value = false
  }
  else if (store.error) {
    toast.add({ title: store.error, color: 'error' })
  }
  deleting.value = false
}

watch(() => companyStore.currentCompanyId, () => store.fetchBankAccounts())

onMounted(() => {
  store.fetchBankAccounts()
  fetchDefaults()
})
</script>

<template>
  <div>
    <UPageCard
      :title="$t('bankAccounts.title')"
      :description="$t('settings.bankAccountsDescription')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <div class="flex flex-wrap gap-2 lg:ms-auto">
        <UButton
          v-if="cashRegisterEnabled && !cashAccountExists"
          :label="$t('bankAccounts.addCashAccount')"
          color="neutral"
          variant="outline"
          icon="i-lucide-coins"
          @click="openCreate('cash')"
        />
        <UButton
          :label="$t('bankAccounts.addAccount')"
          color="neutral"
          icon="i-lucide-plus"
          @click="openCreate('bank')"
        />
      </div>
    </UPageCard>

    <UPageCard
      variant="subtle"
      :ui="{ container: 'p-0 sm:p-0 gap-y-0', wrapper: 'items-stretch' }"
    >
      <UTable
        :data="accounts"
        :columns="columns"
        :loading="loading"
        :ui="{
          base: 'table-fixed',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'px-4',
          td: 'px-4 border-b border-default',
        }"
      >
        <template #iban-cell="{ row }">
          <span v-if="row.original.type === 'cash'" class="inline-flex items-center gap-1.5 text-sm">
            <UIcon name="i-lucide-coins" class="size-4" />
            {{ $t('bankAccounts.typeCash') }}
          </span>
          <span v-else-if="row.original.iban" class="flex items-center gap-1 font-mono text-sm">
            {{ row.original.iban }}
            <UButton icon="i-lucide-copy" variant="ghost" size="xs" @click="copy(row.original.iban)" />
          </span>
          <span v-else class="text-sm text-(--ui-text-muted)">-</span>
        </template>
        <template #isDefault-cell="{ row }">
          <UBadge v-if="row.original.isDefault" color="success" variant="subtle" size="sm">
            {{ $t('bankAccounts.isDefault') }}
          </UBadge>
        </template>
        <template #showOnInvoice-cell="{ row }">
          <USwitch
            v-if="row.original.type !== 'cash'"
            :model-value="row.original.showOnInvoice"
            size="sm"
            @update:model-value="(val: boolean) => store.updateBankAccount(row.original.id, { showOnInvoice: val })"
          />
          <span v-else class="text-(--ui-text-muted) text-xs">—</span>
        </template>
        <template #actions-cell="{ row }">
          <div class="flex gap-1">
            <UButton icon="i-lucide-pencil" variant="ghost" size="xs" @click="openEdit(row.original)" />
            <UButton icon="i-lucide-trash-2" variant="ghost" size="xs" color="error" @click="openDelete(row.original)" />
          </div>
        </template>
      </UTable>

      <UEmpty v-if="!loading && accounts.length === 0" icon="i-lucide-landmark" :title="$t('bankAccounts.noAccounts')" class="py-12" />
    </UPageCard>

    <SharedConfirmModal
      v-model:open="deleteModalOpen"
      :title="$t('bankAccounts.deleteAccount')"
      :description="$t('bankAccounts.deleteDescription')"
      icon="i-lucide-trash-2"
      color="error"
      :confirm-label="$t('common.delete')"
      :loading="deleting"
      @confirm="onDelete"
    />

    <!-- Create/Edit Slideover -->
    <USlideover v-model:open="modalOpen">
      <template #header>
        <div class="flex items-center justify-between w-full">
          <h3 class="text-lg font-semibold">{{ editingAccount ? $t('bankAccounts.editAccount') : (isCashForm ? $t('bankAccounts.addCashAccount') : $t('bankAccounts.addAccount')) }}</h3>
          <div v-if="!isCashForm" class="flex items-center gap-2">
            <USwitch v-model="form.isDefault" size="sm" />
            <span class="text-sm text-(--ui-text-muted)">{{ $t('bankAccounts.isDefault') }}</span>
          </div>
        </div>
      </template>
      <template #body>
        <div class="space-y-4">
          <UFormField v-if="!editingAccount" :label="$t('bankAccounts.type')">
            <USelectMenu v-model="form.type" :items="typeOptions" value-key="value" size="xl" class="w-full" />
          </UFormField>

          <template v-if="!isCashForm">
            <UFormField :label="$t('bankAccounts.iban')" :error="ibanError ?? undefined">
              <UInput
                v-model="form.iban"
                size="xl"
                class="w-full"
                placeholder="RO49..."
                :color="ibanError ? 'error' : undefined"
                :disabled="!!editingAccount"
              />
            </UFormField>
            <div class="grid grid-cols-2 gap-3">
              <UFormField :label="$t('bankAccounts.bankName')">
                <UInput v-model="form.bankName" size="xl" class="w-full" />
              </UFormField>
              <UFormField :label="$t('bankAccounts.currency')">
                <USelectMenu v-model="form.currency" :items="currencyOptions" value-key="value" size="xl" class="w-full" />
              </UFormField>
            </div>
          </template>

          <template v-else>
            <UFormField :label="$t('bankAccounts.cashAccountLabel')">
              <UInput v-model="form.bankName" size="xl" class="w-full" :placeholder="$t('bankAccounts.cashAccountPlaceholder')" />
            </UFormField>
            <UFormField :label="$t('bankAccounts.currency')">
              <USelectMenu v-model="form.currency" :items="currencyOptions" value-key="value" size="xl" class="w-full" />
            </UFormField>
            <div class="grid grid-cols-2 gap-3">
              <UFormField :label="$t('bankAccounts.openingBalance')" :hint="form.currency">
                <UInput v-model="form.openingBalance" type="number" step="0.01" min="0" size="xl" class="w-full" />
              </UFormField>
              <UFormField :label="$t('bankAccounts.openingBalanceDate')">
                <UInput v-model="form.openingBalanceDate" type="date" size="xl" class="w-full" />
              </UFormField>
            </div>
            <p class="text-xs text-(--ui-text-muted)">{{ $t('bankAccounts.openingBalanceHint') }}</p>
          </template>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="ghost" @click="modalOpen = false">{{ $t('common.cancel') }}</UButton>
          <UButton :loading="saving" :disabled="!canSave" @click="onSave">{{ $t('common.save') }}</UButton>
        </div>
      </template>
    </USlideover>
  </div>
</template>
