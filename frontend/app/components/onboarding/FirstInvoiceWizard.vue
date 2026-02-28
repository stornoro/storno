<script setup lang="ts">
import type { Client } from '~/types'

const open = defineModel<boolean>('open', { default: false })

const emit = defineEmits<{
  invoiceCreated: [invoiceId: string]
}>()

const { t: $t } = useI18n()
const invoiceStore = useInvoiceStore()
const clientStore = useClientStore()
const toast = useToast()

const { generateSampleInvoice } = useSampleData()
const { computeSimpleTotals } = useLineCalc()
const { fetchDefaults, defaultVatRate, defaultCurrency } = useInvoiceDefaults()

// ── Step state ─────────────────────────────────────────────────────
const currentStep = ref(0)

const steps = computed(() => [
  { label: $t('onboarding.wizard.step1') },
  { label: $t('onboarding.wizard.step2') },
  { label: $t('onboarding.wizard.step3') },
])

// ── Step 1: Client ─────────────────────────────────────────────────
const { clients, clientOptions, onClientSearch, loadClients } = useClientSearch()
const selectedClientId = ref<string | null>(null)
const showNewClientForm = ref(false)
const creatingClient = ref(false)
const newClientError = ref('')

const newClientForm = reactive({
  name: '',
  cui: '',
  country: 'RO',
})

const selectedClient = computed<Client | null>(
  () => clients.value.find(c => c.id === selectedClientId.value) ?? null,
)

// ── Step 2: Line items ─────────────────────────────────────────────
interface WizardLine {
  description: string
  quantity: string
  unitOfMeasure: string
  unitPrice: string
  vatRate: string
  vatCategoryCode: string
  discount: string
  discountPercent: string
}

const lines = ref<WizardLine[]>([
  {
    description: '',
    quantity: '1',
    unitOfMeasure: 'buc',
    unitPrice: '',
    vatRate: '19.00',
    vatCategoryCode: 'S',
    discount: '0',
    discountPercent: '0',
  },
])

const totals = computed(() => computeSimpleTotals(lines.value))

function addLine() {
  lines.value.push({
    description: '',
    quantity: '1',
    unitOfMeasure: 'buc',
    unitPrice: '',
    vatRate: defaultVatRate.value,
    vatCategoryCode: 'S',
    discount: '0',
    discountPercent: '0',
  })
}

function removeLine(idx: number) {
  if (lines.value.length > 1) {
    lines.value.splice(idx, 1)
  }
}

// ── Step 3: Review + Send ──────────────────────────────────────────
const sendToAnaf = ref(false)
const sendByEmail = ref(false)
const clientEmail = ref('')
const submitting = ref(false)
const createdInvoiceId = ref<string | null>(null)
const submitError = ref('')

// ── Validation ─────────────────────────────────────────────────────
const step1Valid = computed(() => !!selectedClientId.value)
const step2Valid = computed(() =>
  lines.value.length > 0
  && lines.value.every(l => l.description.trim() !== '' && l.unitPrice !== ''),
)

// ── Sample data ────────────────────────────────────────────────────
async function applySampleData() {
  const sample = generateSampleInvoice()

  // Apply sample lines
  lines.value = sample.lines.map(l => ({ ...l }))

  // Try to find an existing client matching sample, otherwise use the name for display
  if (!selectedClientId.value) {
    // Pre-fill a lightweight version — just set lines
    const sampleLine = sample.lines[0]
    if (sampleLine) {
      lines.value = [{ ...sampleLine }]
    }
    // Show the new client form pre-filled
    showNewClientForm.value = true
    newClientForm.name = sample.client.name
    newClientForm.cui = sample.client.cui
  }
}

// ── Navigation ─────────────────────────────────────────────────────
function nextStep() {
  if (currentStep.value < steps.value.length - 1) {
    currentStep.value++
  }
}

function prevStep() {
  if (currentStep.value > 0) {
    currentStep.value--
  }
}

// ── Quick client creation ──────────────────────────────────────────
async function createClientQuick() {
  if (!newClientForm.name.trim()) return
  creatingClient.value = true
  newClientError.value = ''
  try {
    const client = await clientStore.createClient({
      type: 'company',
      name: newClientForm.name.trim(),
      cui: newClientForm.cui.trim() || undefined,
      country: 'RO',
    })
    if (client) {
      clients.value = [client, ...clients.value]
      selectedClientId.value = client.id
      showNewClientForm.value = false
      newClientForm.name = ''
      newClientForm.cui = ''
    }
  }
  catch {
    newClientError.value = $t('onboarding.wizard.errorCreateClient')
  }
  finally {
    creatingClient.value = false
  }
}

// ── Invoice submission ─────────────────────────────────────────────
async function submitInvoice() {
  if (!step1Valid.value || !step2Valid.value) return

  submitting.value = true
  submitError.value = ''

  try {
    const today = new Date()
    const due = new Date(today)
    due.setDate(due.getDate() + 30)
    const pad = (n: number) => String(n).padStart(2, '0')
    const fmt = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`

    const result = await invoiceStore.createInvoice({
      documentType: 'invoice',
      clientId: selectedClientId.value!,
      issueDate: fmt(today),
      dueDate: fmt(due),
      currency: defaultCurrency.value,
      lines: lines.value.map(l => ({
        description: l.description,
        quantity: l.quantity,
        unitOfMeasure: l.unitOfMeasure,
        unitPrice: l.unitPrice,
        vatRate: l.vatRate,
        vatCategoryCode: l.vatCategoryCode,
        discount: l.discount,
        discountPercent: l.discountPercent,
      })),
    })

    if (!result) {
      submitError.value = $t('onboarding.wizard.errorCreateInvoice')
      return
    }

    const invoiceId = result.invoice.id

    // Issue (finalize) the invoice
    await invoiceStore.issueInvoice(invoiceId)

    // Submit to ANAF if requested
    if (sendToAnaf.value) {
      await invoiceStore.submitInvoice(invoiceId)
    }

    // Send email if requested and email provided
    if (sendByEmail.value && clientEmail.value) {
      await invoiceStore.sendEmail(invoiceId, { to: clientEmail.value })
    }

    createdInvoiceId.value = invoiceId
    emit('invoiceCreated', invoiceId)
    toast.add({ title: $t('onboarding.wizard.successTitle'), color: 'success' })
  }
  catch (err: any) {
    submitError.value = err?.data?.error ?? $t('onboarding.wizard.errorIssueInvoice')
  }
  finally {
    submitting.value = false
  }
}

// ── Lifecycle ──────────────────────────────────────────────────────
watch(open, async (isOpen) => {
  if (isOpen) {
    currentStep.value = 0
    createdInvoiceId.value = null
    submitError.value = ''
    selectedClientId.value = null
    showNewClientForm.value = false
    sendToAnaf.value = false
    sendByEmail.value = false
    clientEmail.value = ''
    lines.value = [{
      description: '',
      quantity: '1',
      unitOfMeasure: 'buc',
      unitPrice: '',
      vatRate: '19.00',
      vatCategoryCode: 'S',
      discount: '0',
      discountPercent: '0',
    }]
    await Promise.all([loadClients(), fetchDefaults()])
  }
})

function goToInvoice() {
  if (createdInvoiceId.value) {
    open.value = false
    navigateTo(`/invoices/${createdInvoiceId.value}`)
  }
}

const formatMoney = (val: number) =>
  new Intl.NumberFormat('ro-RO', { style: 'currency', currency: defaultCurrency.value }).format(val)
</script>

<template>
  <UModal v-model:open="open" :title="$t('onboarding.wizard.title')" size="xl" :prevent-close="submitting">
    <template #body>
      <!-- Success state -->
      <div v-if="createdInvoiceId" class="flex flex-col items-center gap-4 py-8 text-center">
        <div class="flex items-center justify-center size-16 rounded-full bg-success-100 dark:bg-success-900/30">
          <UIcon name="i-lucide-circle-check" class="size-8 text-success-500" />
        </div>
        <div>
          <p class="text-lg font-semibold">{{ $t('onboarding.wizard.successTitle') }}</p>
          <p class="text-sm text-muted mt-1">{{ $t('onboarding.wizard.successDescription') }}</p>
        </div>
        <UButton icon="i-lucide-file-text" @click="goToInvoice">
          {{ $t('onboarding.wizard.goToInvoice') }}
        </UButton>
      </div>

      <!-- Wizard steps -->
      <template v-else>
        <!-- Step indicators -->
        <div class="flex items-center gap-0 mb-6">
          <template v-for="(step, idx) in steps" :key="idx">
            <div class="flex items-center gap-2">
              <div
                class="flex items-center justify-center size-7 rounded-full text-sm font-semibold transition-colors"
                :class="idx < currentStep
                  ? 'bg-success-500 text-white'
                  : idx === currentStep
                    ? 'bg-primary text-white'
                    : 'bg-(--ui-bg-elevated) text-(--ui-text-muted)'"
              >
                <UIcon v-if="idx < currentStep" name="i-lucide-check" class="size-4" />
                <span v-else>{{ idx + 1 }}</span>
              </div>
              <span
                class="text-sm hidden sm:inline"
                :class="idx === currentStep ? 'font-semibold text-(--ui-text)' : 'text-(--ui-text-muted)'"
              >
                {{ step.label }}
              </span>
            </div>
            <div v-if="idx < steps.length - 1" class="flex-1 h-px bg-(--ui-border) mx-3" />
          </template>
        </div>

        <!-- Step 1: Select client -->
        <div v-if="currentStep === 0" class="space-y-4">
          <p class="text-sm text-(--ui-text-muted)">{{ $t('onboarding.wizard.selectOrCreateClient') }}</p>

          <UFormField :label="$t('onboarding.wizard.clientLabel')">
            <USelectMenu
              v-model="selectedClientId"
              :items="clientOptions"
              value-key="value"
              :placeholder="$t('onboarding.wizard.searchClient')"
              :search-input="{ placeholder: $t('onboarding.wizard.searchClient') }"
              @update:search-term="onClientSearch"
            />
          </UFormField>

          <!-- Inline new client form -->
          <div>
            <UButton
              v-if="!showNewClientForm"
              variant="soft"
              color="neutral"
              size="sm"
              icon="i-lucide-plus"
              @click="showNewClientForm = true"
            >
              {{ $t('onboarding.wizard.createNewClient') }}
            </UButton>

            <div v-else class="p-4 rounded-lg border border-(--ui-border) bg-(--ui-bg-elevated) space-y-3">
              <p class="text-sm font-semibold">{{ $t('onboarding.wizard.createNewClient') }}</p>
              <div class="grid grid-cols-2 gap-3">
                <UFormField :label="$t('invoices.receiverName')" required>
                  <UInput v-model="newClientForm.name" :placeholder="$t('invoices.receiverNamePlaceholder')" icon="i-lucide-building-2" />
                </UFormField>
                <UFormField label="CUI (optional)">
                  <UInput v-model="newClientForm.cui" placeholder="ex: 12345678" icon="i-lucide-hash" />
                </UFormField>
              </div>
              <p v-if="newClientError" class="text-sm text-error">{{ newClientError }}</p>
              <div class="flex items-center gap-2">
                <UButton
                  size="sm"
                  :loading="creatingClient"
                  :disabled="!newClientForm.name.trim()"
                  @click="createClientQuick"
                >
                  {{ $t('common.save') }}
                </UButton>
                <UButton
                  size="sm"
                  variant="ghost"
                  color="neutral"
                  @click="showNewClientForm = false"
                >
                  {{ $t('common.cancel') }}
                </UButton>
              </div>
            </div>
          </div>

          <!-- Selected client preview -->
          <div
            v-if="selectedClient"
            class="flex items-center gap-3 p-3 rounded-lg bg-(--ui-bg-elevated) border border-(--ui-border)"
          >
            <div class="flex items-center justify-center size-10 rounded-full bg-primary/10 text-primary shrink-0">
              <UIcon name="i-lucide-building-2" class="size-5" />
            </div>
            <div class="flex-1 min-w-0">
              <div class="font-semibold text-sm truncate">{{ selectedClient.name }}</div>
              <div class="text-xs text-(--ui-text-muted)">{{ selectedClient.cui || selectedClient.cnp || '-' }}</div>
            </div>
            <UButton
              icon="i-lucide-x"
              variant="ghost"
              color="neutral"
              size="xs"
              @click="selectedClientId = null"
            />
          </div>
        </div>

        <!-- Step 2: Line items -->
        <div v-else-if="currentStep === 1" class="space-y-4">
          <div
            v-for="(line, idx) in lines"
            :key="idx"
            class="p-4 rounded-lg border border-(--ui-border) space-y-3"
          >
            <div class="flex items-center justify-between">
              <span class="text-sm font-semibold text-(--ui-text-muted)">#{{ idx + 1 }}</span>
              <UButton
                v-if="lines.length > 1"
                icon="i-lucide-trash-2"
                variant="ghost"
                color="error"
                size="xs"
                @click="removeLine(idx)"
              />
            </div>

            <UFormField :label="$t('onboarding.wizard.productName')" required>
              <UInput v-model="line.description" :placeholder="$t('onboarding.wizard.productName')" />
            </UFormField>

            <div class="grid grid-cols-2 gap-3">
              <UFormField :label="$t('onboarding.wizard.quantity')">
                <UInput v-model="line.quantity" type="number" min="0.01" step="0.01" />
              </UFormField>
              <UFormField :label="$t('onboarding.wizard.unitPrice')" required>
                <UInput v-model="line.unitPrice" type="number" min="0" step="0.01" placeholder="0.00" />
              </UFormField>
            </div>

            <UFormField :label="$t('onboarding.wizard.vatRate')">
              <USelectMenu
                v-model="line.vatRate"
                :items="[
                  { label: '19% - Standard', value: '19.00' },
                  { label: '9% - Redus', value: '9.00' },
                  { label: '5% - Redus', value: '5.00' },
                  { label: '0% - Scutit', value: '0.00' },
                ]"
                value-key="value"
              />
            </UFormField>
          </div>

          <UButton variant="soft" size="sm" icon="i-lucide-plus" @click="addLine">
            {{ $t('onboarding.wizard.addLine') }}
          </UButton>

          <!-- Totals summary -->
          <div class="p-3 rounded-lg bg-(--ui-bg-elevated) border border-(--ui-border) space-y-1 text-sm">
            <div class="flex justify-between">
              <span class="text-(--ui-text-muted)">{{ $t('onboarding.wizard.subtotal') }}</span>
              <span>{{ formatMoney(totals.subtotal) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-(--ui-text-muted)">{{ $t('onboarding.wizard.vatTotal') }}</span>
              <span>{{ formatMoney(totals.vat) }}</span>
            </div>
            <div class="flex justify-between font-semibold border-t border-(--ui-border) pt-1 mt-1">
              <span>{{ $t('onboarding.wizard.total') }}</span>
              <span>{{ formatMoney(totals.total) }}</span>
            </div>
          </div>
        </div>

        <!-- Step 3: Review + Send -->
        <div v-else-if="currentStep === 2" class="space-y-4">
          <div class="p-4 rounded-lg border border-(--ui-border) bg-(--ui-bg-elevated) space-y-3">
            <p class="text-sm font-semibold">{{ $t('onboarding.wizard.reviewClient') }}</p>
            <div v-if="selectedClient" class="flex items-center gap-3">
              <UIcon name="i-lucide-building-2" class="size-5 text-(--ui-text-muted) shrink-0" />
              <div>
                <div class="font-medium text-sm">{{ selectedClient.name }}</div>
                <div class="text-xs text-(--ui-text-muted)">{{ selectedClient.cui || '-' }}</div>
              </div>
            </div>
          </div>

          <div class="p-4 rounded-lg border border-(--ui-border) bg-(--ui-bg-elevated) space-y-2">
            <p class="text-sm font-semibold">{{ $t('onboarding.wizard.reviewLines') }}</p>
            <div v-for="(line, idx) in lines" :key="idx" class="flex justify-between text-sm">
              <span class="text-(--ui-text) truncate flex-1 pr-4">{{ line.description }}</span>
              <span class="text-(--ui-text-muted) whitespace-nowrap">
                {{ line.quantity }} x {{ formatMoney(parseFloat(line.unitPrice) || 0) }}
              </span>
            </div>
            <div class="flex justify-between font-semibold border-t border-(--ui-border) pt-2 mt-2 text-sm">
              <span>{{ $t('onboarding.wizard.total') }}</span>
              <span>{{ formatMoney(totals.total) }}</span>
            </div>
          </div>

          <!-- Send options -->
          <div class="space-y-3">
            <div class="flex items-center gap-3">
              <USwitch v-model="sendToAnaf" />
              <label class="text-sm cursor-pointer select-none" @click="sendToAnaf = !sendToAnaf">
                {{ $t('onboarding.wizard.sendToAnaf') }}
              </label>
            </div>

            <div class="space-y-2">
              <div class="flex items-center gap-3">
                <USwitch v-model="sendByEmail" />
                <label class="text-sm cursor-pointer select-none" @click="sendByEmail = !sendByEmail">
                  {{ $t('onboarding.wizard.sendByEmail') }}
                </label>
              </div>
              <UInput
                v-if="sendByEmail"
                v-model="clientEmail"
                type="email"
                :placeholder="$t('onboarding.wizard.emailAddress')"
                icon="i-lucide-mail"
              />
            </div>
          </div>

          <p v-if="submitError" class="text-sm text-error">{{ submitError }}</p>
        </div>
      </template>
    </template>

    <template v-if="!createdInvoiceId" #footer>
      <div class="flex items-center justify-between w-full">
        <!-- Try sample data button (steps 0 and 1) -->
        <UButton
          v-if="currentStep < 2"
          variant="ghost"
          color="neutral"
          size="sm"
          icon="i-lucide-flask-conical"
          @click="applySampleData"
        >
          {{ $t('onboarding.wizard.trySample') }}
        </UButton>
        <div v-else />

        <!-- Navigation buttons -->
        <div class="flex items-center gap-2">
          <UButton
            v-if="currentStep > 0"
            variant="ghost"
            color="neutral"
            @click="prevStep"
          >
            {{ $t('onboarding.wizard.back') }}
          </UButton>

          <UButton
            v-if="currentStep < steps.length - 1"
            :disabled="(currentStep === 0 && !step1Valid) || (currentStep === 1 && !step2Valid)"
            @click="nextStep"
          >
            {{ $t('onboarding.wizard.next') }}
          </UButton>

          <UButton
            v-else
            :loading="submitting"
            :disabled="!step1Valid || !step2Valid"
            icon="i-lucide-send"
            @click="submitInvoice"
          >
            {{ submitting ? $t('onboarding.wizard.sending') : $t('onboarding.wizard.createInvoice') }}
          </UButton>
        </div>
      </div>
    </template>
  </UModal>
</template>
