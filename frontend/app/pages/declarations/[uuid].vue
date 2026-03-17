<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="declaration" class="space-y-6">
        <!-- Title & Actions -->
        <div class="flex flex-col gap-3">
          <div class="flex items-center gap-3 min-w-0">
            <UButton icon="i-lucide-arrow-left" variant="ghost" to="/declarations" class="shrink-0" />
            <h1 class="text-2xl font-bold truncate">
              {{ declaration.type.toUpperCase() }} — {{ declaration.year }}-{{ String(declaration.month).padStart(2, '0') }}
            </h1>
            <UBadge :color="(DeclarationStatusColor[declaration.status as keyof typeof DeclarationStatusColor] ?? 'neutral') as any" variant="subtle">
              {{ $t(`declarations.statuses.${declaration.status}`) }}
            </UBadge>
          </div>

          <div v-if="can(P.DECLARATION_SUBMIT)" class="flex flex-wrap items-center gap-2">
            <UButton v-if="isDraft" icon="i-lucide-refresh-cw" variant="outline" :loading="actionLoading" @click="recalculate">
              {{ $t('declarations.recalculate') }}
            </UButton>
            <UButton v-if="isDraft" icon="i-lucide-check-circle" variant="outline" color="primary" :loading="actionLoading" @click="validate">
              {{ $t('declarations.validate') }}
            </UButton>
            <UButton v-if="canSubmit" icon="i-lucide-send" color="primary" :loading="actionLoading" @click="agentSubmitModalOpen = true">
              {{ $t('declarations.submit') }}
            </UButton>

            <div class="w-px h-6 bg-(--ui-border) hidden sm:block" />

            <UDropdownMenu :items="documentMenuItems">
              <UButton icon="i-lucide-file-down" variant="outline">
                {{ $t('invoices.documents') }}
                <UIcon name="i-lucide-chevron-down" class="size-3.5" />
              </UButton>
            </UDropdownMenu>

            <UButton
              icon="i-lucide-trash-2"
              variant="outline"
              color="error"
              @click="deleteModalOpen = true"
            >
              {{ $t('common.delete') }}
            </UButton>
          </div>
        </div>

        <!-- Error Banner -->
        <UAlert
          v-if="declaration.errorMessage"
          :title="$t('declarations.errorMessage')"
          :description="declaration.errorMessage"
          color="error"
          icon="i-lucide-alert-circle"
        />

        <!-- Info Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <UCard>
            <template #header>
              <h3 class="font-semibold">{{ $t('declarations.title') }}</h3>
            </template>
            <div class="grid grid-cols-2 gap-4 text-sm">
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('declarations.type') }}</dt>
                <dd class="font-medium font-mono">{{ declaration.type.toUpperCase() }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('declarations.status') }}</dt>
                <dd>
                  <UBadge :color="(DeclarationStatusColor[declaration.status as keyof typeof DeclarationStatusColor] ?? 'neutral') as any" variant="subtle" size="sm">
                    {{ $t(`declarations.statuses.${declaration.status}`) }}
                  </UBadge>
                </dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('declarations.period') }}</dt>
                <dd class="font-medium">{{ declaration.year }}-{{ String(declaration.month).padStart(2, '0') }}</dd>
              </div>
              <div v-if="declaration.submittedAt">
                <dt class="text-(--ui-text-muted)">{{ $t('declarations.submittedAt') }}</dt>
                <dd class="font-medium">{{ new Date(declaration.submittedAt).toLocaleString() }}</dd>
              </div>
              <div v-if="declaration.acceptedAt">
                <dt class="text-(--ui-text-muted)">{{ $t('declarations.acceptedAt') }}</dt>
                <dd class="font-medium">{{ new Date(declaration.acceptedAt).toLocaleString() }}</dd>
              </div>
            </div>
          </UCard>

          <!-- Metadata Card -->
          <UCard v-if="declaration.metadata && Object.keys(declaration.metadata).length">
            <template #header>
              <h3 class="font-semibold">{{ $t('declarations.metadata') }}</h3>
            </template>
            <pre class="text-xs text-(--ui-text-muted) overflow-auto max-h-48">{{ JSON.stringify(declaration.metadata, null, 2) }}</pre>
          </UCard>
        </div>

        <!-- D394: Sales/Purchases -->
        <template v-if="declaration.type === 'd394' && declaration.data">
          <!-- Totals -->
          <div v-if="declaration.data.totals" class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <UCard>
              <template #header>
                <h3 class="font-semibold">{{ $t('declarations.totalSales') }}</h3>
              </template>
              <div class="space-y-1">
                <p class="text-2xl font-bold">{{ formatAmount(declaration.data.totals.sales?.taxableBase) }}</p>
                <p class="text-sm text-(--ui-text-muted)">{{ $t('declarations.vatAmount') }}: {{ formatAmount(declaration.data.totals.sales?.vatAmount) }}</p>
              </div>
            </UCard>
            <UCard>
              <template #header>
                <h3 class="font-semibold">{{ $t('declarations.totalPurchases') }}</h3>
              </template>
              <div class="space-y-1">
                <p class="text-2xl font-bold">{{ formatAmount(declaration.data.totals.purchases?.taxableBase) }}</p>
                <p class="text-sm text-(--ui-text-muted)">{{ $t('declarations.vatAmount') }}: {{ formatAmount(declaration.data.totals.purchases?.vatAmount) }}</p>
              </div>
            </UCard>
          </div>

          <UAlert
            v-if="declaration.data.incompleteInvoices?.length"
            :title="$t('declarations.incompleteInvoices')"
            :description="$t('declarations.incompleteInvoicesDesc', { count: declaration.data.incompleteInvoices.length })"
            color="warning"
            icon="i-lucide-alert-triangle"
          />

          <!-- VAT Summary -->
          <UCard v-if="declaration.data.rezumat">
            <template #header>
              <h3 class="font-semibold">{{ $t('declarations.summary') }}</h3>
            </template>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
              <div v-if="declaration.data.rezumat.sales?.length" class="space-y-2">
                <h4 class="text-sm text-(--ui-text-muted)">{{ $t('declarations.totalSales') }}</h4>
                <div v-for="r in declaration.data.rezumat.sales" :key="r.cota" class="flex items-center justify-between text-sm p-2 rounded-lg bg-(--ui-bg-elevated)">
                  <span class="font-mono">TVA {{ r.cota }}%</span>
                  <span>{{ formatAmount(r.taxableBase) }} / {{ formatAmount(r.vatAmount) }}</span>
                </div>
              </div>
              <div v-if="declaration.data.rezumat.purchases?.length" class="space-y-2">
                <h4 class="text-sm text-(--ui-text-muted)">{{ $t('declarations.totalPurchases') }}</h4>
                <div v-for="r in declaration.data.rezumat.purchases" :key="r.cota" class="flex items-center justify-between text-sm p-2 rounded-lg bg-(--ui-bg-elevated)">
                  <span class="font-mono">TVA {{ r.cota }}%</span>
                  <span>{{ formatAmount(r.taxableBase) }} / {{ formatAmount(r.vatAmount) }}</span>
                </div>
              </div>
            </div>
          </UCard>

          <!-- Partners Tables -->
          <UCard v-if="declaration.data.sales?.length">
            <template #header>
              <h3 class="font-semibold">{{ $t('declarations.salesPartners') }} ({{ declaration.data.sales.length }})</h3>
            </template>
            <UTable :data="salesData" :columns="salesColumns">
              <template #tipPartener-cell="{ row }">
                <UBadge variant="subtle" color="neutral" size="xs">{{ tipPartenerLabel(row.original.tipPartener) }}</UBadge>
              </template>
            </UTable>
          </UCard>

          <UCard v-if="declaration.data.purchases?.length">
            <template #header>
              <h3 class="font-semibold">{{ $t('declarations.purchasesPartners') }} ({{ declaration.data.purchases.length }})</h3>
            </template>
            <UTable :data="purchasesData" :columns="salesColumns">
              <template #tipPartener-cell="{ row }">
                <UBadge variant="subtle" color="neutral" size="xs">{{ tipPartenerLabel(row.original.tipPartener) }}</UBadge>
              </template>
            </UTable>
          </UCard>

          <!-- Invoice Series -->
          <UCard v-if="declaration.data.serieFacturi?.length">
            <template #header>
              <h3 class="font-semibold">{{ $t('declarations.invoiceSeries') }}</h3>
            </template>
            <UTable :data="declaration.data.serieFacturi" :columns="seriesColumns" />
          </UCard>
        </template>

        <!-- D300: VAT Return -->
        <template v-else-if="declaration.type === 'd300' && declaration.data">
          <div v-if="declaration.data.totals" class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            <UCard>
              <template #header>
                <h3 class="font-semibold">{{ $t('declarations.d300.collected') }}</h3>
              </template>
              <p class="text-2xl font-bold">{{ formatAmount(declaration.data.totals.collected) }}</p>
            </UCard>
            <UCard>
              <template #header>
                <h3 class="font-semibold">{{ $t('declarations.d300.deductible') }}</h3>
              </template>
              <p class="text-2xl font-bold">{{ formatAmount(declaration.data.totals.deductible) }}</p>
            </UCard>
            <UCard :class="Number(declaration.data.totals.net) >= 0 ? 'border-red-200 dark:border-red-800' : 'border-green-200 dark:border-green-800'">
              <template #header>
                <h3 class="font-semibold">{{ $t('declarations.d300.net') }}</h3>
              </template>
              <p class="text-2xl font-bold">{{ formatAmount(declaration.data.totals.net) }}</p>
              <p class="text-sm text-(--ui-text-muted)">{{ Number(declaration.data.totals.net) >= 0 ? $t('declarations.d300.toPay') : $t('declarations.d300.toRecover') }}</p>
            </UCard>
          </div>

          <!-- D300 header info (from uploaded XML) -->
          <UCard v-if="d300Header && Object.keys(d300Header).length">
            <template #header>
              <h3 class="font-semibold">{{ $t('declarations.d300.headerInfo') }}</h3>
            </template>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
              <div v-for="(value, key) in d300Header" :key="key" class="flex items-center justify-between text-sm p-2 rounded-lg bg-(--ui-bg-elevated)">
                <span class="text-xs text-(--ui-text-muted)" :title="String(key)">{{ $t(`declarations.d300.fields.${key}`) }}</span>
                <span class="font-mono whitespace-nowrap ml-2">{{ value }}</span>
              </div>
            </div>
          </UCard>

          <!-- D300 row data (R*_* fields only, non-zero) -->
          <UCard v-if="d300Rows && Object.keys(d300Rows).length">
            <template #header>
              <h3 class="font-semibold">{{ $t('declarations.d300.rows') }}</h3>
            </template>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
              <div v-for="(value, key) in d300Rows" :key="key" class="flex items-center justify-between text-sm p-2 rounded-lg bg-(--ui-bg-elevated)">
                <span class="text-xs text-(--ui-text-muted)" :title="String(key)">{{ $t(`declarations.d300.fields.${key}`) }}</span>
                <span class="font-mono whitespace-nowrap ml-2">{{ formatAmount(value as string) }}</span>
              </div>
            </div>
          </UCard>
        </template>

        <!-- D390/D392/D393: EU Operations -->
        <template v-else-if="['d390', 'd392', 'd393'].includes(declaration.type) && declaration.data">
          <div v-if="declaration.data.rezumat" class="grid grid-cols-2 sm:grid-cols-4 gap-6">
            <UCard>
              <template #header>
                <h3 class="font-semibold">{{ $t('declarations.d390.operations') }}</h3>
              </template>
              <p class="text-2xl font-bold">{{ declaration.data.rezumat.nrOPI ?? 0 }}</p>
            </UCard>
            <UCard v-for="key in ['bazaL', 'bazaA', 'bazaP', 'bazaS'].filter(k => declaration.data.rezumat[k])" :key="key">
              <template #header>
                <h3 class="font-semibold">{{ $t(`declarations.d390.${key}`) }}</h3>
              </template>
              <p class="text-2xl font-bold">{{ formatAmount(declaration.data.rezumat[key]) }}</p>
            </UCard>
          </div>

          <UCard v-if="declaration.data.operations?.length">
            <template #header>
              <h3 class="font-semibold">{{ $t('declarations.d390.operationsList') }} ({{ declaration.data.operations.length }})</h3>
            </template>
            <UTable :data="declaration.data.operations" :columns="operationsColumns">
              <template #baza-cell="{ row }">
                {{ formatAmount(row.original.baza) }}
              </template>
              <template #tip-cell="{ row }">
                <UBadge variant="subtle" :color="({ L: 'success', A: 'info', P: 'warning', S: 'primary' }[row.original.tip as string] ?? 'neutral') as any" size="xs">
                  {{ row.original.tip }}
                </UBadge>
              </template>
            </UTable>
          </UCard>
        </template>

        <!-- Manual types: Editable rows -->
        <template v-else-if="isManualType && declaration.data">
          <UCard>
            <template #header>
              <div class="flex items-center justify-between">
                <h3 class="font-semibold">{{ $t('declarations.declarationData') }}</h3>
                <div v-if="isDraft" class="flex items-center gap-2">
                  <UButton
                    v-if="!editingData"
                    icon="i-lucide-pencil"
                    variant="outline"
                    size="xs"
                    @click="editingData = true"
                  >
                    {{ $t('common.edit') }}
                  </UButton>
                  <template v-else>
                    <UButton icon="i-lucide-check" size="xs" color="primary" :loading="savingData" @click="saveRows">
                      {{ $t('common.save') }}
                    </UButton>
                    <UButton variant="outline" size="xs" @click="editingData = false">
                      {{ $t('common.cancel') }}
                    </UButton>
                  </template>
                </div>
              </div>
            </template>

            <div v-if="editingData" class="space-y-2">
              <div v-for="(value, key) in editableRows" :key="key" class="flex items-center gap-2">
                <UInput :model-value="String(key)" :placeholder="$t('declarations.rowKey')" class="w-1/3 font-mono text-xs" disabled />
                <UInput v-model="editableRows[key as string]" :placeholder="$t('declarations.rowValue')" class="flex-1" />
                <UButton icon="i-lucide-x" variant="ghost" color="error" size="xs" @click="removeRow(key)" />
              </div>
              <UButton icon="i-lucide-plus" variant="outline" size="xs" @click="addRow">
                {{ $t('declarations.addRow') }}
              </UButton>
            </div>

            <div v-else>
              <div v-if="Object.keys(declaration.data.rows ?? {}).length" class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                <div v-for="(value, key) in declaration.data.rows" :key="key" class="flex items-center justify-between text-sm p-2 rounded-lg bg-(--ui-bg-elevated)">
                  <span class="font-mono text-xs text-(--ui-text-muted)">{{ key }}</span>
                  <span>{{ value }}</span>
                </div>
              </div>
              <p v-else class="text-sm text-(--ui-text-muted)">{{ $t('declarations.noData') }}</p>
            </div>
          </UCard>
        </template>
      </div>

      <!-- Loading skeleton -->
      <div v-else class="text-center py-20">
        <USkeleton class="h-8 w-64 mx-auto mb-4" />
        <USkeleton class="h-4 w-48 mx-auto" />
      </div>

      <!-- Delete confirmation -->
      <SharedConfirmModal
        v-model:open="deleteModalOpen"
        :title="$t('common.delete')"
        :description="$t('declarations.deleteConfirm')"
        icon="i-lucide-trash-2"
        color="error"
        :confirm-label="$t('common.delete')"
        :loading="actionLoading"
        @confirm="onDelete"
      />

      <!-- Agent submit modal -->
      <UModal v-model:open="agentSubmitModalOpen">
        <template #header>
          <h3 class="font-semibold">{{ !agentAvailable ? $t('anaf.agentStatus') : savedCertInfo ? $t('declarations.agentReadyTitle') : $t('declarations.agentSelectCert') }}</h3>
        </template>
        <template #body>
          <!-- State A: Agent not running -->
          <div v-if="!agentAvailable" class="space-y-4">
            <UAlert
              :title="$t('anaf.agentNotDetected')"
              :description="$t('anaf.agentInstallHint')"
              icon="i-lucide-monitor-down"
              color="warning"
            />
            <div class="flex flex-wrap items-center gap-2">
              <UButton
                icon="i-lucide-play"
                :loading="agentStarting"
                @click="onAutoStartAgent"
              >
                {{ agentStarting ? $t('anaf.agentStarting') : $t('anaf.agentAutoStart') }}
              </UButton>
              <UButton
                icon="i-lucide-download"
                variant="outline"
                :to="agentDownloadUrl"
                target="_blank"
              >
                {{ $t('anaf.agentDownload') }}
              </UButton>
            </div>
            <UButton variant="outline" icon="i-lucide-refresh-cw" :loading="agentChecking" @click="recheckAgent">
              {{ $t('anaf.agentRecheck') }}
            </UButton>
          </div>

          <!-- State B: Agent running + cert configured -->
          <div v-else-if="savedCertInfo" class="space-y-4">
            <p class="text-sm text-(--ui-text-muted)">{{ $t('declarations.agentReadyToSubmit') }}</p>
            <div class="p-3 rounded-lg border border-(--ui-border) bg-(--ui-bg-elevated) flex items-center justify-between">
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium truncate">{{ savedCertInfo.subject }}</p>
                <p class="text-xs text-(--ui-text-muted)">{{ savedCertInfo.source }} &middot; {{ savedCertInfo.id.substring(0, 16) }}...</p>
              </div>
              <NuxtLink
                v-if="declaration?.companyId"
                :to="`/companies/${declaration.companyId}/anaf`"
                class="text-xs text-primary hover:underline shrink-0 ml-3"
              >
                {{ $t('declarations.agentChangeCert') }}
              </NuxtLink>
            </div>
            <div v-if="agentSubmitting" class="flex items-center gap-2 py-2">
              <UIcon name="i-lucide-loader-2" class="animate-spin h-4 w-4 text-primary" />
              <span class="text-sm text-primary">{{ $t('declarations.agentWaitingPin') }}</span>
            </div>
          </div>

          <!-- State C: Agent running, no cert saved -->
          <div v-else class="space-y-4">
            <UAlert
              :title="$t('declarations.agentNoCertConfigured')"
              :description="$t('declarations.agentConfigureFirstHint')"
              icon="i-lucide-alert-triangle"
              color="warning"
            />
            <UButton
              v-if="declaration?.companyId"
              icon="i-lucide-settings"
              variant="outline"
              :to="`/companies/${declaration.companyId}/anaf`"
            >
              {{ $t('declarations.agentConfigureFirst') }}
            </UButton>

            <!-- Collapsible manual picker fallback -->
            <details class="group">
              <summary class="text-sm text-primary cursor-pointer select-none">
                {{ $t('declarations.agentPickManually') }}
              </summary>
              <div class="mt-3 space-y-2">
                <div v-if="loadingCerts" class="flex items-center gap-2 py-2">
                  <UIcon name="i-lucide-loader-2" class="animate-spin h-4 w-4" />
                  <span class="text-sm">{{ $t('common.loading') }}</span>
                </div>
                <div v-else-if="agentCerts.length === 0" class="py-2">
                  <p class="text-sm text-(--ui-text-muted)">{{ $t('anaf.agentNoCertificates') }}</p>
                </div>
                <template v-else>
                  <label
                    v-for="cert in agentCerts"
                    :key="cert.id"
                    class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer hover:bg-(--ui-bg-elevated) transition-colors"
                    :class="selectedCertId === cert.id ? 'border-primary bg-(--ui-bg-elevated)' : 'border-(--ui-border)'"
                  >
                    <input v-model="selectedCertId" type="radio" :value="cert.id" class="accent-primary" />
                    <div class="min-w-0 flex-1">
                      <p class="text-sm font-medium truncate">{{ certDisplayName(cert) }}</p>
                      <p class="text-xs text-(--ui-text-muted)">{{ certIssuerShort(cert) }}</p>
                      <p v-if="certExpiry(cert)" class="text-xs text-(--ui-text-muted)">{{ certExpiry(cert) }}</p>
                    </div>
                  </label>
                </template>
              </div>
            </details>

            <div v-if="agentSubmitting" class="flex items-center gap-2 py-2">
              <UIcon name="i-lucide-loader-2" class="animate-spin h-4 w-4 text-primary" />
              <span class="text-sm text-primary">{{ $t('declarations.agentWaitingPin') }}</span>
            </div>
          </div>
        </template>
        <template #footer>
          <div class="flex justify-end gap-2">
            <UButton variant="ghost" @click="agentSubmitModalOpen = false">
              {{ $t('common.cancel') }}
            </UButton>
            <UButton
              v-if="agentAvailable"
              icon="i-lucide-key-round"
              :loading="agentSubmitting"
              :disabled="!effectiveCertId || agentSubmitting"
              @click="submitWithAgent"
            >
              {{ $t('declarations.submitViaAgent') }}
            </UButton>
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
import { DeclarationStatusColor, AUTO_POPULATED_TYPES } from '~/types/enums'
import type { DeclarationType } from '~/types/enums'
import type { AgentCertificate } from '~/types'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const { can } = usePermissions()
const route = useRoute()
const router = useRouter()
const store = useDeclarationStore()
const toast = useToast()
const { agentAvailable, agentVersion, agentChecking, checkAgent, listCertificates, submitViaAgent, tryAutoStart, getPreferredCertId, certDisplayName, certIssuerShort, certExpiry } = useAnafAgent()

const agentStarting = ref(false)

const agentDownloadUrl = computed(() => {
  const base = 'https://downloads.storno.ro/agent/v1.0.0'
  const ua = navigator.userAgent.toLowerCase()
  if (ua.includes('win')) return `${base}/storno-agent-windows.exe`
  if (ua.includes('linux')) return `${base}/storno-agent-linux`
  return `${base}/storno-agent-macos`
})

async function recheckAgent() {
  const running = await checkAgent()
  if (running) {
    toast.add({ title: $t('anaf.agentRunning', { version: agentVersion.value ?? '?' }), color: 'success' })
    await loadModalCerts()
  }
}

async function onAutoStartAgent() {
  agentStarting.value = true
  try {
    const ok = await tryAutoStart()
    if (ok) {
      toast.add({ title: $t('anaf.agentStarted'), color: 'success' })
      await loadModalCerts()
    } else {
      toast.add({ title: $t('anaf.agentStartFailed'), color: 'warning' })
    }
  } finally {
    agentStarting.value = false
  }
}

// Saved cert lookup
const savedCertInfo = computed(() => {
  if (!declaration.value?.companyId) return null
  const savedId = getPreferredCertId(declaration.value.companyId)
  if (!savedId) return null
  return agentCerts.value.find(c => c.id === savedId) ?? null
})

const effectiveCertId = computed(() => {
  return savedCertInfo.value?.id ?? selectedCertId.value
})

const uuid = route.params.uuid as string
const declaration = ref<any>(null)
const loading = ref(true)
const actionLoading = ref(false)
const deleteModalOpen = ref(false)

// Agent submit state
const agentSubmitModalOpen = ref(false)
const agentCerts = ref<AgentCertificate[]>([])
const selectedCertId = ref<string | null>(null)
const loadingCerts = ref(false)
const agentSubmitting = ref(false)

useHead({ title: computed(() => declaration.value ? `${declaration.value.type.toUpperCase()} ${declaration.value.year}-${String(declaration.value.month).padStart(2, '0')}` : $t('declarations.title')) })

// ── Computed ────────────────────────────────────────────────────────
const isDraft = computed(() => declaration.value?.status === 'draft')
const isValidated = computed(() => declaration.value?.status === 'validated')
const canSubmit = computed(() => isDraft.value || isValidated.value)
const isTerminal = computed(() => ['accepted', 'rejected', 'error'].includes(declaration.value?.status))
const isAutoPopulated = computed(() => declaration.value && AUTO_POPULATED_TYPES.includes(declaration.value.type as DeclarationType))
const isManualType = computed(() => !isAutoPopulated.value)

// D300: separate header metadata from actual row data
const D300_HEADER_KEYS = new Set([
  'luna', 'an', 'depusReprezentant', 'bifa_interne', 'temei', 'cuiSuccesor',
  'prenume_declar', 'nume_declar', 'functie_declar', 'cui', 'den', 'adresa',
  'telefon', 'fax', 'mail', 'banca', 'cont', 'caen', 'tip_decont', 'pro_rata',
  'bifa_cereale', 'bifa_mob', 'bifa_disp', 'bifa_cons', 'solicit_ramb', 'nr_evid',
  'totalPlata_A', 'xmlns:xsi', 'xsi:schemaLocation', 'xmlns', 'uploadedXml',
])

const D300_SKIP_KEYS = new Set(['xmlns:xsi', 'xsi:schemaLocation', 'xmlns', 'uploadedXml'])

const d300Header = computed(() => {
  const rows = declaration.value?.data?.rows
  if (!rows) return null
  const header: Record<string, string> = {}
  for (const [key, value] of Object.entries(rows)) {
    if (D300_HEADER_KEYS.has(key) && !D300_SKIP_KEYS.has(key) && value !== '' && value !== null && value !== undefined) {
      header[key] = String(value)
    }
  }
  return Object.keys(header).length ? header : null
})

const d300Rows = computed(() => {
  const rows = declaration.value?.data?.rows
  if (!rows) return null
  const result: Record<string, string> = {}
  for (const [key, value] of Object.entries(rows)) {
    if (!D300_HEADER_KEYS.has(key) && !D300_SKIP_KEYS.has(key) && Number(value) !== 0) {
      result[key] = String(value)
    }
  }
  return Object.keys(result).length ? result : null
})

// ── Editable rows (manual types) ────────────────────────────────────
const editableRows = ref<Record<string, string>>({})
const editingData = ref(false)
const savingData = ref(false)

watch(declaration, (d) => {
  if (d?.data?.rows) {
    editableRows.value = { ...d.data.rows }
  }
}, { immediate: true })

function addRow() {
  editableRows.value[`field_${Date.now()}`] = ''
}

function removeRow(key: string) {
  delete editableRows.value[key]
}

// ── Table columns ───────────────────────────────────────────────────
const salesColumns = [
  { accessorKey: 'partnerCif', header: $t('declarations.partnerCif') },
  { accessorKey: 'partnerName', header: $t('declarations.partnerName') },
  { accessorKey: 'tipPartener', header: $t('declarations.partnerType') },
  { accessorKey: 'invoiceCount', header: $t('declarations.invoiceCount') },
  { accessorKey: 'taxableBase', header: $t('declarations.taxableBase') },
  { accessorKey: 'vatAmount', header: $t('declarations.vatAmount') },
]

const seriesColumns = [
  { accessorKey: 'serie', header: $t('declarations.series') },
  { accessorKey: 'firstNumber', header: $t('declarations.firstNumber') },
  { accessorKey: 'lastNumber', header: $t('declarations.lastNumber') },
  { accessorKey: 'count', header: $t('declarations.invoiceCount') },
]

const operationsColumns = [
  { accessorKey: 'tip', header: $t('declarations.d390.opType') },
  { accessorKey: 'tara', header: $t('declarations.d390.country') },
  { accessorKey: 'codO', header: 'CIF' },
  { accessorKey: 'denO', header: $t('declarations.partnerName') },
  { accessorKey: 'baza', header: $t('declarations.taxableBase') },
]

// ── Computed table data ─────────────────────────────────────────────
const salesData = computed(() =>
  (declaration.value?.data?.sales ?? []).map((p: any) => ({
    ...p,
    taxableBase: formatAmount(p.total?.taxableBase),
    vatAmount: formatAmount(p.total?.vatAmount),
  }))
)

const purchasesData = computed(() =>
  (declaration.value?.data?.purchases ?? []).map((p: any) => ({
    ...p,
    taxableBase: formatAmount(p.total?.taxableBase),
    vatAmount: formatAmount(p.total?.vatAmount),
  }))
)

// ── Menus ───────────────────────────────────────────────────────────
const documentMenuItems = computed(() => [
  [
    { label: $t('declarations.downloadXml'), icon: 'i-lucide-download', onSelect: () => downloadXml() },
    ...(['validated', 'submitted', 'processing', 'accepted', 'rejected'].includes(declaration.value?.status ?? '')
      ? [{ label: $t('declarations.downloadPdf'), icon: 'i-lucide-file-text', onSelect: () => downloadPdf() }]
      : []),
    ...(declaration.value?.status === 'accepted'
      ? [{ label: $t('declarations.downloadRecipisa'), icon: 'i-lucide-file-check-2', onSelect: () => downloadRecipisa() }]
      : []),
  ],
])

// ── Helpers ──────────────────────────────────────────────────────────
function formatAmount(val: string | number | undefined) {
  if (!val) return '0.00'
  return Number(val).toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function tipPartenerLabel(tip: any) {
  const labels: Record<number, string> = { 1: 'PJ Romania', 2: 'PF Romania', 3: 'UE', 4: 'Extra-UE' }
  return labels[tip] ?? String(tip)
}

// ── Actions ─────────────────────────────────────────────────────────
async function fetchDeclaration() {
  loading.value = true
  declaration.value = await store.fetchDeclaration(uuid)
  loading.value = false
}

async function recalculate() {
  actionLoading.value = true
  try {
    declaration.value = await store.recalculateDeclaration(uuid)
    toast.add({ title: $t('declarations.recalculateSuccess'), color: 'success' })
  } catch (e: any) {
    toast.add({ title: e?.message ?? $t('declarations.recalculateError'), color: 'error' })
  } finally {
    actionLoading.value = false
  }
}

async function validate() {
  actionLoading.value = true
  try {
    declaration.value = await store.validateDeclaration(uuid)
    toast.add({ title: $t('declarations.validateSuccess'), color: 'success' })
  } catch (e: any) {
    toast.add({ title: e?.message ?? $t('declarations.validateError'), color: 'error' })
  } finally {
    actionLoading.value = false
  }
}

async function onDelete() {
  actionLoading.value = true
  try {
    await store.deleteDeclaration(uuid)
    deleteModalOpen.value = false
    toast.add({ title: $t('declarations.deleteSuccess'), color: 'success' })
    router.push('/declarations')
  } catch (e: any) {
    toast.add({ title: e?.message ?? $t('declarations.deleteError'), color: 'error' })
  } finally {
    actionLoading.value = false
  }
}

async function saveRows() {
  savingData.value = true
  try {
    declaration.value = await store.updateDeclaration(uuid, {
      data: { ...declaration.value?.data, rows: editableRows.value },
    })
    editingData.value = false
    toast.add({ title: $t('declarations.dataSaved'), color: 'success' })
  } catch (e: any) {
    toast.add({ title: e?.message ?? $t('declarations.saveError'), color: 'error' })
  } finally {
    savingData.value = false
  }
}

async function downloadXml() {
  const { apiFetch } = useApi()
  try {
    const blob = await apiFetch<Blob>(`/v1/declarations/${uuid}/xml`, { responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${declaration.value?.type ?? 'declaration'}_${declaration.value?.year}_${String(declaration.value?.month).padStart(2, '0')}.xml`
    a.click()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.add({ title: e?.message ?? 'Download failed.', color: 'error' })
  }
}

async function downloadPdf() {
  const { apiFetch } = useApi()
  try {
    const blob = await apiFetch<Blob>(`/v1/declarations/${uuid}/pdf`, { responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${declaration.value?.type ?? 'declaration'}_${declaration.value?.year}_${String(declaration.value?.month).padStart(2, '0')}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.add({ title: e?.message ?? 'Download failed.', color: 'error' })
  }
}

async function downloadRecipisa() {
  const { apiFetch } = useApi()
  try {
    const blob = await apiFetch<Blob>(`/v1/declarations/${uuid}/recipisa`, { responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${declaration.value?.type ?? 'declaration'}_${declaration.value?.year}_${String(declaration.value?.month).padStart(2, '0')}_recipisa.pdf`
    a.click()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.add({ title: e?.message ?? 'Download failed.', color: 'error' })
  }
}

// ── Agent ────────────────────────────────────────────────────────────
async function loadModalCerts() {
  loadingCerts.value = true
  selectedCertId.value = null
  try {
    agentCerts.value = await listCertificates()
    if (agentCerts.value.length === 1) {
      selectedCertId.value = agentCerts.value[0].id
    }
  } catch {
    agentCerts.value = []
  } finally {
    loadingCerts.value = false
  }
}

watch(agentSubmitModalOpen, async (open) => {
  if (open) {
    await loadModalCerts()
  }
})

async function submitWithAgent() {
  const certId = effectiveCertId.value
  if (!certId) return

  agentSubmitting.value = true
  try {
    declaration.value = await submitViaAgent(uuid, certId)
    agentSubmitModalOpen.value = false
    toast.add({ title: $t('declarations.submitSuccess'), color: 'success' })
  } catch (e: any) {
    toast.add({ title: e?.message ?? $t('declarations.submitError'), color: 'error' })
  } finally {
    agentSubmitting.value = false
  }
}

onMounted(() => {
  fetchDeclaration()
  checkAgent()
})
</script>
