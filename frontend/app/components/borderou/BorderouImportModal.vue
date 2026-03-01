<script setup lang="ts">
const { t: $t } = useI18n()
const store = useBordereauStore()
const bankAccountStore = useBankAccountStore()
const toast = useToast()
const { fetchDefaults, currencyOptions } = useInvoiceDefaults()

const props = defineProps<{
  sourceType: 'borderou' | 'bank_statement' | 'marketplace'
}>()

const open = defineModel<boolean>('open', { default: false })

const isBankStatement = computed(() => props.sourceType === 'bank_statement')
const isMarketplace = computed(() => props.sourceType === 'marketplace')

// ── Form state ──
const provider = ref<string | null>(null)
const currency = ref('RON')
const referenceNumber = ref('')
const bankAccountId = ref<string | null>(null)
const file = ref<File | null>(null)
const dragOver = ref(false)

// ── Courier logos ──
const courierLogos: Record<string, string> = {
  fan_courier: '/images/couriers/fan_courier.png',
  gls: '/images/couriers/gls.webp',
  sameday: '/images/couriers/sameday.svg',
  dpd: '/images/couriers/dpd.png',
  cargus: '/images/couriers/cargus.webp',
  generic: '/images/couriers/generic.svg',
}

// ── Options ──
const providerOptions = computed(() => {
  if (!store.providers) return []
  const sourceKey = isMarketplace.value ? 'marketplace' : 'borderou'
  const list = store.providers[sourceKey] || []
  return list.map(p => ({
    label: p.label,
    value: p.key,
    avatar: courierLogos[p.key] ? { src: courierLogos[p.key], alt: p.label } : undefined,
  }))
})

const bankAccountOptions = computed(() =>
  bankAccountStore.items.map(ba => ({
    label: `${ba.iban} (${ba.currency})${ba.bankName ? ' - ' + ba.bankName : ''}`,
    value: ba.id,
  })),
)

// Derive currency from bank account
const bankCurrency = computed(() => {
  if (!bankAccountId.value) return 'RON'
  const ba = bankAccountStore.items.find(b => b.id === bankAccountId.value)
  return ba?.currency || 'RON'
})

const effectiveCurrency = computed(() =>
  isBankStatement.value ? bankCurrency.value : currency.value,
)

// Auto-resolve bank provider from bankName
const resolvedBankProvider = computed(() => {
  if (!bankAccountId.value || !store.providers) return 'generic_bank'
  const ba = bankAccountStore.items.find(b => b.id === bankAccountId.value)
  if (!ba?.bankName) return 'generic_bank'

  const bankName = ba.bankName.toLowerCase()
  const providers = store.providers.bank_statement || []

  for (const p of providers) {
    if (p.key === 'generic_bank') continue
    if (bankName.includes(p.label.toLowerCase()) || p.label.toLowerCase().includes(bankName)) {
      return p.key
    }
  }

  const keywordMap: Record<string, string[]> = {
    alpha_bank: ['alpha'],
    bcr: ['bcr', 'comerciala romana'],
    bt: ['transilvania'],
    brd: ['brd', 'societe generale'],
    cec: ['cec'],
    first_bank: ['first bank'],
    garanti: ['garanti'],
    ing: ['ing'],
    libra: ['libra'],
    otp: ['otp'],
    raiffeisen: ['raiffeisen'],
    revolut: ['revolut'],
    unicredit: ['unicredit'],
  }

  for (const [key, keywords] of Object.entries(keywordMap)) {
    if (keywords.some(kw => bankName.includes(kw))) {
      return key
    }
  }

  return 'generic_bank'
})

const effectiveProvider = computed(() =>
  isBankStatement.value ? resolvedBankProvider.value : provider.value,
)

const canImport = computed(() => {
  if (!file.value) return false
  if (isBankStatement.value) return !!bankAccountId.value
  return !!provider.value
})

const modalTitle = computed(() =>
  isBankStatement.value
    ? $t('bankStatement.importTitle')
    : isMarketplace.value
      ? $t('marketplace.importTitle')
      : $t('borderou.importButton'),
)

// ── File handling ──
function onFileSelect(event: Event) {
  const target = event.target as HTMLInputElement
  if (target.files?.length) {
    file.value = target.files[0] ?? null
  }
}

function onDrop(event: DragEvent) {
  dragOver.value = false
  if (event.dataTransfer?.files?.length) {
    file.value = event.dataTransfer.files[0] ?? null
  }
}

function clearFile() {
  file.value = null
}

// ── Import ──
async function handleImport() {
  if (!canImport.value || !effectiveProvider.value) return

  const success = await store.uploadBorderou(
    file.value!,
    props.sourceType,
    effectiveProvider.value,
    effectiveCurrency.value,
    referenceNumber.value || undefined,
    isBankStatement.value ? (bankAccountId.value || undefined) : undefined,
  )

  if (success) {
    const description = isBankStatement.value
      ? `${store.summary.total} ${$t('bankStatement.transactionsImported')}`
      : `${store.summary.total} ${$t('borderou.phaseImport').toLowerCase()}`

    toast.add({
      title: $t('borderou.importAction'),
      description,
      color: 'success',
    })
    open.value = false
    resetForm()
  }
  else if (store.error) {
    toast.add({ title: store.error, color: 'error' })
  }
}

function resetForm() {
  provider.value = null
  currency.value = 'RON'
  referenceNumber.value = ''
  bankAccountId.value = null
  file.value = null
  dragOver.value = false
}

onMounted(() => {
  fetchDefaults()
  if (isBankStatement.value && bankAccountStore.items.length === 0) {
    bankAccountStore.fetchBankAccounts()
  }
})
</script>

<template>
  <UModal v-model:open="open">
    <template #header>
      <h3 class="text-lg font-semibold">{{ modalTitle }}</h3>
    </template>

    <template #body>
      <div class="space-y-4">
        <!-- Bank account (bank_statement only) -->
        <div v-if="isBankStatement">
          <label class="block text-sm font-medium mb-1.5">
            <span class="text-red-500">*</span> {{ $t('bankStatement.ibanLabel') }}
          </label>
          <USelectMenu
            v-model="bankAccountId"
            :items="bankAccountOptions"
            value-key="value"
            :placeholder="$t('bankStatement.ibanPlaceholder')"
          />
        </div>

        <!-- Provider (borderou + marketplace) -->
        <div v-if="!isBankStatement">
          <label class="block text-sm font-medium mb-1.5">
            <span class="text-red-500">*</span> {{ isMarketplace ? $t('marketplace.providerLabel') : $t('borderou.providerLabel') }}
          </label>
          <USelectMenu
            v-model="provider"
            :items="providerOptions"
            value-key="value"
            :placeholder="isMarketplace ? $t('marketplace.providerLabel') : $t('borderou.providerLabel')"
          />
        </div>

        <!-- Currency (borderou only) -->
        <div v-if="!isBankStatement && !isMarketplace">
          <label class="block text-sm font-medium mb-1.5">{{ $t('borderou.currencyLabel') }}</label>
          <USelectMenu
            v-model="currency"
            :items="currencyOptions"
            value-key="value"
          />
        </div>

        <!-- Reference number (not for marketplace) -->
        <div v-if="!isMarketplace">
          <label class="block text-sm font-medium mb-1.5">
            {{ isBankStatement ? $t('bankStatement.statementNumberLabel') : $t('borderou.bordereauNumberLabel') }}
          </label>
          <UInput
            v-model="referenceNumber"
            :placeholder="isBankStatement ? '' : $t('borderou.bordereauNumberHint')"
          />
        </div>

        <!-- File upload (drag & drop) -->
        <div>
          <label class="block text-sm font-medium mb-1.5">
            <span class="text-red-500">*</span> {{ $t('borderou.fileLabel') }}
          </label>
          <div
            class="border-2 border-dashed rounded-lg p-6 text-center transition-colors cursor-pointer"
            :class="dragOver ? 'border-primary bg-primary/5' : 'border-(--ui-border)'"
            @dragover.prevent="dragOver = true"
            @dragleave="dragOver = false"
            @drop.prevent="onDrop"
            @click="($refs.fileInput as HTMLInputElement)?.click()"
          >
            <input
              ref="fileInput"
              type="file"
              accept=".csv,.xlsx,.xls"
              class="hidden"
              @change="onFileSelect"
            >
            <template v-if="file">
              <UIcon name="i-lucide-file-check" class="w-8 h-8 mx-auto text-primary mb-2" />
              <p class="text-sm font-medium">{{ file.name }}</p>
              <button
                type="button"
                class="text-xs text-(--ui-text-muted) hover:text-primary mt-1 underline"
                @click.stop="clearFile"
              >
                {{ $t('common.change') }}
              </button>
            </template>
            <template v-else>
              <UIcon name="i-lucide-upload" class="w-8 h-8 mx-auto text-(--ui-text-muted) mb-2" />
              <p class="text-sm text-(--ui-text-muted)">
                {{ $t('borderou.fileDragHint') }}
              </p>
            </template>
          </div>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton
          :label="$t('common.cancel')"
          variant="ghost"
          @click="open = false"
        />
        <UButton
          :label="$t('borderou.importAction')"
          icon="i-lucide-upload"
          :loading="store.uploading"
          :disabled="!canImport"
          @click="handleImport"
        />
      </div>
    </template>
  </UModal>
</template>
