<template>
  <div class="space-y-4">
    <!-- Invoice header -->
    <div class="space-y-2">
      <div class="grid gap-x-2 gap-y-0.5" :style="{ gridTemplateColumns: (!invoice || invoice.status === 'draft') ? '1.2fr 1fr 0.7fr 1fr' : '1.2fr 0.7fr 1fr' }">
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.invoiceTypeCode') }}</span>
        <span v-if="!invoice || invoice.status === 'draft'" class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.selectSeries') }}</span>
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.currency') }}</span>
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.documentLanguage') }}</span>

        <USelectMenu
          v-model="form.invoiceTypeCode"
          :items="invoiceTypeCodeOptions"
          value-key="value"
          :placeholder="$t('invoices.invoiceTypeCode')"
          size="sm"
        >
          <template #leading="{ modelValue }">
            <span v-if="modelValue" class="text-xs font-bold text-primary tabular-nums">{{ invoiceTypeCodeShort[modelValue as string] || 'F' }}</span>
          </template>
          <template #item="{ item }">
            <div class="flex items-center gap-3">
              <span class="text-xs font-bold text-(--ui-text-muted) w-4 text-center tabular-nums">{{ invoiceTypeCodeShort[item.value] || '?' }}</span>
              <span>{{ item.label }}</span>
            </div>
          </template>
        </USelectMenu>

        <template v-if="!invoice || invoice.status === 'draft'">
          <USelectMenu
            v-if="seriesOptions.length > 0"
            v-model="form.documentSeriesId"
            :items="seriesOptions"
            value-key="value"
            :placeholder="$t('invoices.selectSeries')"
            size="sm"
          />
          <div v-else class="flex items-center justify-center rounded-md border border-dashed border-amber-300 dark:border-amber-700 bg-amber-50/50 dark:bg-amber-950/20">
            <UIcon name="i-lucide-alert-triangle" class="size-3.5 text-amber-500" />
          </div>
        </template>

        <USelectMenu
          v-model="form.currency"
          :items="currencyOptions"
          value-key="value"
          size="sm"
        />
        <USelectMenu
          v-model="form.language"
          :items="documentLanguageOptions"
          value-key="value"
          size="sm"
        />
      </div>

      <div class="grid gap-x-2 gap-y-0.5" style="grid-template-columns: 1fr 1fr;">
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.issueDate') }}</span>
        <span class="text-[10px] text-(--ui-text-dimmed)">{{ $t('invoices.dueDate') }}</span>
        <UInput v-model="form.issueDate" type="date" size="sm" />
        <UInput v-model="form.dueDate" type="date" size="sm" />
      </div>

      <!-- No series — inline quick-create -->
      <div
        v-if="(!invoice || invoice.status === 'draft') && seriesOptions.length === 0"
        class="rounded-lg border border-amber-200 dark:border-amber-800/60 bg-amber-50/80 dark:bg-amber-950/30 overflow-hidden"
      >
        <!-- Header row -->
        <div class="flex items-center gap-3 px-3 py-2.5">
          <div class="flex items-center justify-center size-8 rounded-full bg-amber-100 dark:bg-amber-900/40 shrink-0">
            <UIcon name="i-lucide-hash" class="size-4 text-amber-600 dark:text-amber-400" />
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">{{ $t('invoices.noSeries') }}</p>
            <p class="text-xs text-amber-600/80 dark:text-amber-400/70 mt-0.5">{{ $t('invoices.noSeriesDescription') }}</p>
          </div>
          <UButton
            v-if="!showQuickSeriesForm"
            size="xs"
            color="warning"
            variant="soft"
            icon="i-lucide-plus"
            @click="showQuickSeriesForm = true"
          >
            {{ $t('invoices.createSeries') }}
          </UButton>
        </div>

        <!-- Inline create form -->
        <div v-if="showQuickSeriesForm" class="border-t border-amber-200 dark:border-amber-800/60 px-3 py-3 space-y-3 bg-white/50 dark:bg-gray-900/30">
          <div class="grid grid-cols-2 gap-2">
            <UFormField :label="$t('documentSeries.prefix')" required size="sm">
              <UInput v-model="quickSeriesPrefix" placeholder="ex: FACT" size="sm" />
            </UFormField>
            <UFormField :label="$t('documentSeries.startNumber')" size="sm">
              <UInput v-model.number="quickSeriesStartNumber" type="number" :min="0" size="sm" />
            </UFormField>
          </div>
          <div v-if="quickSeriesPrefix" class="flex items-center gap-2 text-xs text-(--ui-text-muted)">
            <UIcon name="i-lucide-eye" class="size-3.5 shrink-0" />
            <span>{{ $t('documentSeries.nextNumberPreview') }}: <span class="font-mono font-medium text-(--ui-text)">{{ quickSeriesPrefix }}{{ String((quickSeriesStartNumber || 0) + 1).padStart(4, '0') }}</span></span>
          </div>
          <div class="flex items-center gap-2">
            <UButton
              size="xs"
              :loading="quickSeriesSaving"
              :disabled="!quickSeriesPrefix"
              icon="i-lucide-check"
              @click="onQuickCreateSeries"
            >
              {{ $t('invoices.createSeriesAndContinue') }}
            </UButton>
            <UButton
              size="xs"
              variant="ghost"
              color="neutral"
              @click="showQuickSeriesForm = false"
            >
              {{ $t('common.cancel') }}
            </UButton>
          </div>
        </div>
      </div>

      <!-- Exchange rate info -->
      <div v-if="form.currency !== 'RON' && exchangeRates[form.currency]" class="flex items-center gap-2 px-2.5 py-1 rounded-md bg-blue-50/50 dark:bg-blue-950/20 border border-blue-200/50 dark:border-blue-800/30">
        <UIcon name="i-lucide-info" class="size-3.5 text-blue-500 shrink-0" />
        <span class="text-xs text-blue-600 dark:text-blue-400">1 {{ form.currency }} = {{ exchangeRates[form.currency]?.toFixed(4) }} RON (BNR {{ defaults?.exchangeRateDate ?? '' }})</span>
      </div>
    </div>

    <!-- Client -->
    <div class="space-y-2">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <UIcon name="i-lucide-building-2" class="size-4 text-(--ui-text-muted)" />
          <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('invoices.client') }}</span>
        </div>
        <UButton
          v-if="form.clientId"
          icon="i-lucide-x"
          variant="ghost"
          color="neutral"
          size="xs"
          @click="clearClient"
        />
      </div>

      <!-- Selected client display -->
      <div v-if="selectedClient" class="flex items-center gap-3 p-3 rounded-lg bg-(--ui-bg-elevated) border border-(--ui-border)">
        <div class="flex items-center justify-center size-10 rounded-full bg-primary/10 text-primary shrink-0">
          <UIcon name="i-lucide-building-2" class="size-5" />
        </div>
        <div class="flex-1 min-w-0">
          <div class="font-semibold text-sm truncate">{{ selectedClient.name }}</div>
          <div class="text-xs text-(--ui-text-muted)">{{ selectedClient.cui || selectedClient.cnp || '-' }}</div>
          <div v-if="selectedClient.city" class="text-xs text-(--ui-text-muted)">{{ selectedClient.city }}<span v-if="selectedClient.country">, {{ selectedClient.country }}</span></div>
        </div>
        <UButton
          icon="i-lucide-repeat"
          variant="ghost"
          color="neutral"
          size="xs"
          @click="clearClient"
        />
      </div>
      <SharedClientRecentDocuments v-if="form.clientId" :client-id="form.clientId" />

      <!-- Reverse charge indicator -->
      <div v-if="reverseChargeActive" class="flex items-center gap-2 p-2 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800">
        <UIcon name="i-lucide-info" class="size-4 text-amber-600 dark:text-amber-400 shrink-0" />
        <span class="text-xs text-amber-700 dark:text-amber-300">{{ $t('clients.reverseChargeInfo') }}</span>
        <UBadge color="warning" variant="subtle" size="xs" class="ml-auto shrink-0">{{ $t('clients.reverseCharge') }}</UBadge>
      </div>

      <!-- OSS indicator -->
      <div v-else-if="ossActive" class="flex items-center gap-2 p-2 rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800">
        <UIcon name="i-lucide-info" class="size-4 text-blue-600 dark:text-blue-400 shrink-0" />
        <span class="text-xs text-blue-700 dark:text-blue-300">{{ $t('clients.ossInfo') }}</span>
        <UBadge color="info" variant="subtle" size="xs" class="ml-auto shrink-0">{{ $t('clients.ossActive') }}</UBadge>
      </div>

      <!-- Unified client search (clients + registry) -->
      <InvoicesRegistrySearchSection
        v-else
        @select-client="onClientSelected"
        @select-registry="onClientSelected"
        @prefill-create="onPrefillCreate"
        @create="showClientCreateModal = true"
      />
    </div>

    <!-- Client Create Modal -->
    <SharedClientFormModal
      v-model:open="showClientCreateModal"
      :prefill="clientPrefill"
      @saved="onClientSelected"
    />

    <!-- Lines -->
    <div class="space-y-2">
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-list" class="size-4 text-(--ui-text-muted)" />
        <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('invoices.invoiceLines') }}</span>
      </div>

      <div class="space-y-4">
        <div v-for="(line, index) in form.lines" :key="index" class="p-4 rounded-lg border border-(--ui-border) space-y-3">
          <!-- Line header: #N + product picker + delete -->
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-(--ui-text-muted)">#{{ index + 1 }}</span>
            <div class="flex items-center gap-1">
              <UButton
                icon="i-lucide-package-search"
                size="xs"
                variant="ghost"
                color="primary"
                @click="openProductPicker(index)"
              />
              <UButton
                v-if="form.lines.length > 1"
                icon="i-lucide-trash-2"
                size="xs"
                color="error"
                variant="ghost"
                @click="removeLine(index)"
              />
            </div>
          </div>

          <!-- Description full width -->
          <UFormField :label="$t('invoices.lineDescription')" class="w-full">
            <UInput v-model="line.description" :placeholder="$t('invoices.lineDescription')" class="w-full" />
          </UFormField>

          <!-- Qty / Unit / Price / Discount -->
          <div class="grid gap-3" style="grid-template-columns: 0.6fr 0.8fr 1fr 0.6fr;">
            <UFormField :label="$t('invoices.quantity')">
              <UInput v-model="line.quantity" type="number" step="0.01" :min="form.parentDocumentId ? undefined : 0.01" />
            </UFormField>
            <UFormField :label="$t('invoices.unit')">
              <USelectMenu
                v-model="line.unitOfMeasure"
                :items="unitOfMeasureOptions"
                value-key="value"
                :search-input="true"
              />
            </UFormField>
            <UFormField :label="$t('invoices.unitPrice')">
              <div class="flex">
                <UInput v-model="line.unitPrice" type="number" step="0.01" min="0" class="flex-1" :ui="form.currency === 'RON' && hasExchangeRates ? { root: '[&_input]:rounded-r-none' } : {}" />
                <UPopover v-if="form.currency === 'RON' && hasExchangeRates" :ui="{ content: 'p-0 w-64' }">
                  <UButton
                    icon="i-lucide-arrow-left-right"
                    variant="outline"
                    color="neutral"
                    class="rounded-l-none border-l-0"
                    :ui="{ base: 'h-full' }"
                  />
                  <template #content>
                    <div class="p-3 space-y-2.5">
                      <p class="text-xs text-(--ui-text-muted) italic">{{ $t('invoices.convertDescription') }}</p>

                      <!-- Currency + rate on same row -->
                      <div class="grid grid-cols-2 gap-2">
                        <UFormField :label="$t('invoices.convertCurrency')" size="sm">
                          <USelectMenu
                            :model-value="getLineConvertCurrency(index)"
                            :items="foreignCurrencyOptions"
                            value-key="value"
                            size="sm"
                            @update:model-value="lineConvertCurrency[index] = $event"
                          />
                        </UFormField>
                        <UFormField :label="$t('invoices.exchangeRate')" size="sm">
                          <UInput :model-value="lineConvertRate(index).toFixed(4)" disabled size="sm" />
                        </UFormField>
                      </div>

                      <div v-if="defaults?.exchangeRateDate" class="text-xs text-(--ui-text-muted) text-right">
                        BNR {{ defaults.exchangeRateDate }}
                      </div>

                      <!-- Amount + markup on same row -->
                      <div class="grid grid-cols-2 gap-2">
                        <UFormField :label="$t('invoices.foreignValue')" size="sm">
                          <UInput
                            v-model="lineConvertAmount[index]"
                            type="number"
                            step="0.01"
                            min="0"
                            size="sm"
                            placeholder="0.00"
                          />
                        </UFormField>
                        <UFormField :label="$t('invoices.additionalPercent')" size="sm">
                          <UInput
                            v-model="lineConvertMarkup[index]"
                            type="number"
                            step="0.1"
                            min="0"
                            size="sm"
                            placeholder="0"
                          >
                            <template #trailing>
                              <span class="text-xs text-(--ui-text-muted)">%</span>
                            </template>
                          </UInput>
                        </UFormField>
                      </div>

                      <!-- Result + Apply -->
                      <div v-if="lineConvertedRon(index) > 0" class="p-2 rounded-lg bg-primary/5 text-center">
                        <div class="text-xs text-(--ui-text-muted)">{{ $t('invoices.unitPriceRon') }}</div>
                        <div class="text-base font-bold text-primary">{{ formatAmount(lineConvertedRon(index)) }} RON</div>
                      </div>

                      <UButton size="sm" block :disabled="lineConvertedRon(index) <= 0" @click="applyLineConversion(index)">
                        {{ $t('invoices.applyAmount') }}
                      </UButton>
                    </div>
                  </template>
                </UPopover>
              </div>
            </UFormField>
            <UFormField :label="$t('invoices.discount')">
              <UInput v-model="line.discount" type="number" step="0.01" min="0" />
            </UFormField>
          </div>

          <!-- VAT rate chips -->
          <div>
            <label class="text-xs font-medium text-(--ui-text-muted) block mb-1.5">TVA %</label>
            <div class="flex gap-2 flex-wrap">
              <button
                v-for="vr in vatRateChipOptions"
                :key="vr.value"
                type="button"
                class="px-3 py-1 rounded-full text-xs font-semibold border transition-colors"
                :class="line.vatRate === vr.value
                  ? 'bg-primary/10 border-primary text-primary'
                  : 'bg-(--ui-bg-elevated) border-(--ui-border) text-(--ui-text-muted) hover:border-(--ui-text-muted)'"
                @click="selectVatRate(index, vr)"
              >
                {{ vr.chipLabel }}
              </button>
            </div>
          </div>

          <!-- Additional details (collapsible) -->
          <div>
            <button
              type="button"
              class="flex items-center gap-1 text-xs text-primary hover:underline"
              @click="line.showDetails = !line.showDetails"
            >
              <UIcon :name="line.showDetails ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="size-3" />
              {{ $t('invoices.lineAdditionalDetails') }}
            </button>
            <div v-if="line.showDetails" class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-2">
              <UFormField :label="$t('invoices.lineProductCode')">
                <UInput v-model="line.productCode" />
              </UFormField>
              <UFormField :label="$t('invoices.lineCpvCode')">
                <USelectMenu
                  v-model="line.cpvCode"
                  v-model:search-term="cpvSearchTerms[index]"
                  :items="cpvSearchResults[index] || []"
                  value-key="value"
                  :ignore-filter="true"
                  :loading="cpvSearchLoading[index]"
                  placeholder="e.g. 03000000-1"
                  @update:search-term="(val: string) => debouncedCpvSearch(index, val)"
                />
              </UFormField>
              <UFormField :label="$t('invoices.lineBuyerItemId')">
                <UInput v-model="line.buyerItemIdentification" />
              </UFormField>
              <UFormField :label="$t('invoices.lineStandardItemId')">
                <UInput v-model="line.standardItemIdentification" />
              </UFormField>
              <UFormField :label="$t('invoices.lineBuyerAccountingRef')">
                <UInput v-model="line.buyerAccountingRef" />
              </UFormField>
              <UFormField :label="$t('invoices.lineNote')" class="md:col-span-2">
                <UInput v-model="line.lineNote" />
              </UFormField>
            </div>
          </div>

          <!-- Line total with separator -->
          <div class="flex justify-end items-center pt-2 border-t border-(--ui-border)">
            <span class="text-xs text-(--ui-text-muted) mr-2">{{ $t('invoices.total') }}:</span>
            <span class="text-sm font-semibold">{{ formatLineTotal(line) }}</span>
          </div>
        </div>

        <!-- Add line button (dashed border) -->
        <button
          type="button"
          class="w-full flex items-center justify-center gap-2 py-3 rounded-lg border border-dashed border-primary/40 text-primary text-sm font-semibold hover:bg-primary/5 transition-colors"
          @click="addLine"
        >
          <UIcon name="i-lucide-plus" class="size-4" />
          {{ $t('invoices.addLine') }}
        </button>
      </div>
    </div>

    <!-- Totals -->
    <div class="p-4 rounded-lg bg-(--ui-bg-elevated) border border-(--ui-border) space-y-1">
      <div class="flex justify-between text-sm">
        <span>{{ $t('invoices.subtotal') }}</span>
        <span class="font-medium">{{ formatMoney(computedTotals.subtotal, form.currency) }}</span>
      </div>
      <div class="flex justify-between text-sm">
        <span>TVA</span>
        <span class="font-medium">{{ formatMoney(computedTotals.vat, form.currency) }}</span>
      </div>
      <div v-if="computedTotals.discount > 0" class="flex justify-between text-sm">
        <span>{{ $t('invoices.discount') }}</span>
        <span class="font-medium">-{{ formatMoney(computedTotals.discount, form.currency) }}</span>
      </div>
      <div class="flex justify-between items-center pt-2 mt-2 border-t border-(--ui-border)">
        <span class="text-base font-bold">{{ $t('invoices.total') }}</span>
        <span class="text-lg font-bold">{{ formatMoney(computedTotals.total, form.currency) }}</span>
      </div>
      <div v-if="form.currency !== 'RON' && exchangeRates[form.currency] && computedTotals.total > 0" class="flex justify-between text-xs text-(--ui-text-muted) pt-1">
        <span>{{ $t('invoices.ronEquivalent') }}</span>
        <span>~ {{ formatMoney(computedTotals.total * (exchangeRates[form.currency] ?? 0), 'RON') }}</span>
      </div>
    </div>

    <!-- Collapsible sections -->
    <div class="space-y-1">
      <!-- Options -->
      <div>
        <button type="button" class="flex items-center gap-2 w-full py-2 group" @click="showOptions = !showOptions">
          <UIcon name="i-lucide-settings-2" class="size-4 text-(--ui-text-muted)" />
          <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('invoices.optionsSection') }}</span>
          <div class="flex-1 border-t border-(--ui-border) mx-2" />
          <UIcon
            :name="showOptions ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
            class="size-3.5 text-(--ui-text-muted) group-hover:text-(--ui-text) transition-colors"
          />
        </button>
        <div v-if="showOptions" class="pb-3 space-y-3 pl-6">
          <div>
            <div class="flex items-center justify-between">
              <span class="text-sm">{{ $t('invoices.tvaLaIncasare') }}</span>
              <USwitch v-model="form.tvaLaIncasare" :disabled="isEditing && invoice?.status !== 'draft'" />
            </div>
            <p v-if="form.tvaLaIncasare && anafVatStatus && !anafVatStatus.vatOnCollection" class="text-xs text-amber-600 mt-1">
              {{ $t('invoices.anafVatOnCollectionWarning') }}
            </p>
          </div>
          <div>
            <div class="flex items-center justify-between">
              <span class="text-sm">{{ $t('invoices.platitorTva') }}</span>
              <USwitch v-model="form.platitorTva" :disabled="isEditing && invoice?.status !== 'draft'" />
            </div>
            <p v-if="form.platitorTva && anafVatStatus && !anafVatStatus.vatPayer" class="text-xs text-amber-600 mt-1">
              {{ $t('invoices.anafVatPayerWarning') }}
            </p>
          </div>
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-1.5">
              <span class="text-sm">{{ $t('invoices.plataOnline') }}</span>
              <UTooltip v-if="!stripeConnected" :text="$t('invoices.plataOnlineStripeRequired')">
                <UIcon name="i-lucide-info" class="size-3.5 text-amber-500" />
              </UTooltip>
            </div>
            <USwitch v-model="form.plataOnline" :disabled="!stripeConnected" />
          </div>
        </div>
      </div>

      <!-- Client Balance -->
      <div>
        <button type="button" class="flex items-center gap-2 w-full py-2 group" @click="showClientBalance = !showClientBalance">
          <UIcon name="i-lucide-wallet" class="size-4 text-(--ui-text-muted)" />
          <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('invoices.clientBalanceSection') }}</span>
          <div class="flex-1 border-t border-(--ui-border) mx-2" />
          <UIcon
            :name="showClientBalance ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
            class="size-3.5 text-(--ui-text-muted) group-hover:text-(--ui-text) transition-colors"
          />
        </button>
        <div v-if="showClientBalance" class="pb-3 space-y-3 pl-6">
          <div class="flex items-center justify-between">
            <span class="text-sm">{{ $t('invoices.showClientBalance') }}</span>
            <USwitch v-model="form.showClientBalance" />
          </div>
          <div v-if="form.showClientBalance" class="grid grid-cols-2 gap-3">
            <UFormField :label="$t('invoices.clientBalanceExisting')">
              <UInput v-model="form.clientBalanceExisting" type="number" step="0.01" />
            </UFormField>
            <UFormField :label="$t('invoices.clientBalanceOverdue')">
              <UInput v-model="form.clientBalanceOverdue" type="number" step="0.01" />
            </UFormField>
          </div>
        </div>
      </div>

      <!-- e-Factura Additional Info -->
      <div>
        <button type="button" class="flex items-center gap-2 w-full py-2 group" @click="showEfacturaInfo = !showEfacturaInfo">
          <UIcon name="i-lucide-file-text" class="size-4 text-(--ui-text-muted)" />
          <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('invoices.efacturaInfoSection') }}</span>
          <div class="flex-1 border-t border-(--ui-border) mx-2" />
          <UIcon
            :name="showEfacturaInfo ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
            class="size-3.5 text-(--ui-text-muted) group-hover:text-(--ui-text) transition-colors"
          />
        </button>
        <div v-if="showEfacturaInfo" class="pb-3 pl-6 grid grid-cols-2 gap-3">
          <UFormField :label="$t('invoices.paymentTerms')">
            <UInput v-model="form.paymentTerms" />
          </UFormField>
          <UFormField :label="$t('invoices.projectReference')">
            <UInput v-model="form.projectReference" />
          </UFormField>
          <UFormField :label="$t('invoices.taxPointDate')">
            <div class="flex gap-1">
              <UInput v-model="form.taxPointDate" type="date" :disabled="!!form.taxPointDateCode" class="flex-1" />
              <UButton v-if="form.taxPointDate" icon="i-lucide-x" size="xs" variant="ghost" color="neutral" @click="form.taxPointDate = ''" />
            </div>
          </UFormField>
          <UFormField :label="$t('invoices.taxPointDateCode')">
            <div class="flex gap-1">
              <USelectMenu
                v-model="form.taxPointDateCode"
                :items="taxPointDateCodeOptions"
                value-key="value"
                :placeholder="$t('invoices.taxPointDateCode')"
                :disabled="!!form.taxPointDate"
                class="flex-1"
              />
              <UButton v-if="form.taxPointDateCode" icon="i-lucide-x" size="xs" variant="ghost" color="neutral" @click="form.taxPointDateCode = undefined" />
            </div>
          </UFormField>
          <UFormField :label="$t('invoices.buyerReference')">
            <UInput v-model="form.buyerReference" />
          </UFormField>
          <UFormField :label="$t('invoices.orderNumber')">
            <UInput v-model="form.orderNumber" />
          </UFormField>
          <UFormField :label="$t('invoices.contractNumber')">
            <UInput v-model="form.contractNumber" />
          </UFormField>
          <UFormField :label="$t('invoices.receivingAdviceReference')">
            <UInput v-model="form.receivingAdviceReference" />
          </UFormField>
          <UFormField :label="$t('invoices.despatchAdviceReference')">
            <UInput v-model="form.despatchAdviceReference" />
          </UFormField>
          <UFormField :label="$t('invoices.tenderOrLotReference')">
            <UInput v-model="form.tenderOrLotReference" />
          </UFormField>
          <UFormField :label="$t('invoices.invoicedObjectIdentifier')">
            <UInput v-model="form.invoicedObjectIdentifier" />
          </UFormField>
          <UFormField :label="$t('invoices.buyerAccountingReference')">
            <UInput v-model="form.buyerAccountingReference" />
          </UFormField>
          <UFormField :label="$t('invoices.businessProcessType')">
            <UInput v-model="form.businessProcessType" />
          </UFormField>
          <UFormField :label="$t('invoices.payeeName')">
            <UInput v-model="form.payeeName" />
          </UFormField>
          <UFormField :label="$t('invoices.payeeIdentifier')">
            <UInput v-model="form.payeeIdentifier" />
          </UFormField>
          <UFormField :label="$t('invoices.payeeLegalRegistrationIdentifier')">
            <UInput v-model="form.payeeLegalRegistrationIdentifier" />
          </UFormField>
        </div>
      </div>

      <!-- Notes -->
      <div>
        <button type="button" class="flex items-center gap-2 w-full py-2 group" @click="showNotes = !showNotes">
          <UIcon name="i-lucide-message-square-text" class="size-4 text-(--ui-text-muted)" />
          <span class="text-xs font-semibold uppercase tracking-wide text-(--ui-text-muted)">{{ $t('common.notes') }}</span>
          <div class="flex-1 border-t border-(--ui-border) mx-2" />
          <UIcon
            :name="showNotes ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
            class="size-3.5 text-(--ui-text-muted) group-hover:text-(--ui-text) transition-colors"
          />
        </button>
        <div v-if="showNotes" class="pb-3 pl-6 space-y-3">
          <UFormField :label="$t('invoices.deliveryLocation')">
            <UInput v-model="form.deliveryLocation" />
          </UFormField>
          <UFormField :label="$t('common.notes')">
            <UTextarea v-model="form.notes" :rows="4" class="w-full" />
          </UFormField>
        </div>
      </div>
    </div>

    <!-- Validation Errors -->
    <div v-if="lastValidation && !lastValidation.valid && lastValidation.errors.length" class="p-4 rounded-lg border border-red-200 dark:border-red-800 bg-red-50/50 dark:bg-red-950/20 space-y-3">
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-circle-x" class="text-red-500 size-5" />
        <span class="font-semibold text-red-600 dark:text-red-400">{{ $t('invoices.validationFailed') }}</span>
        <UBadge color="error" variant="subtle" size="sm">
          {{ lastValidation.errors.length }} {{ lastValidation.errors.length === 1 ? $t('invoices.validationErrorCount') : $t('invoices.validationErrorsCount') }}
        </UBadge>
      </div>
      <p class="text-sm text-red-600 dark:text-red-400">
        {{ $t('invoices.validationSavedWithErrors') }}
      </p>
      <div class="space-y-1.5">
        <div v-for="(err, i) in lastValidation.errors" :key="i" class="flex items-start gap-2 text-sm">
          <UIcon name="i-lucide-x" class="text-red-500 mt-0.5 shrink-0 size-4" />
          <div class="min-w-0">
            <span class="font-medium text-red-700 dark:text-red-300">{{ localizeValidationMessage(err.message) }}</span>
            <span v-if="err.ruleId" class="text-red-400 dark:text-red-500 ml-1 text-xs">[{{ err.ruleId }}]</span>
            <UBadge v-if="err.source" variant="subtle" size="xs" class="ml-1">{{ err.source }}</UBadge>
          </div>
        </div>
      </div>
      <div v-if="lastValidation.warnings.length" class="pt-3 border-t border-red-200 dark:border-red-800 space-y-1">
        <div v-for="(warn, i) in lastValidation.warnings" :key="i" class="flex items-start gap-2 text-sm text-amber-600 dark:text-amber-400">
          <UIcon name="i-lucide-alert-triangle" class="mt-0.5 shrink-0 size-4" />
          <span>{{ localizeValidationMessage(warn) }}</span>
        </div>
      </div>
    </div>

    <!-- Footer buttons -->
    <div class="space-y-2">
      <UButton class="w-full justify-center" icon="i-lucide-check" :loading="saving" @click="onSave">
        {{ lastValidation && !lastValidation.valid ? $t('invoices.saveAndRevalidate') : $t('invoices.saveDraft') }}
      </UButton>
      <UButton v-if="lastValidation && !lastValidation.valid" class="w-full justify-center" variant="outline" icon="i-lucide-arrow-right" @click="closeWithErrors">
        {{ $t('invoices.closeWithErrors') }}
      </UButton>
      <UButton class="w-full justify-center" variant="ghost" @click="emit('cancel')">
        {{ $t('common.cancel') }}
      </UButton>
      <NuxtLink v-if="!invoice && !refundOf" to="/recurring-invoices?create=true" class="flex items-center justify-center gap-1.5 text-sm text-muted hover:text-primary transition-colors pt-2">
        <UIcon name="i-lucide-repeat" class="size-4" />
        {{ $t('invoices.createRecurring') }}
      </NuxtLink>
    </div>

    <!-- Product Picker Modal -->
    <SharedProductPickerModal
      v-model:open="productPickerOpen"
      @select="onProductSelected"
    />

  </div>
</template>

<script setup lang="ts">
import type { Invoice, CreateInvoicePayload, UpdateInvoicePayload, InvoiceLinePayload, Client, Product, ValidationResponse } from '~/types'

const props = defineProps<{
  invoice?: Invoice | null
  refundOf?: string
  copyOf?: string
  prefillClientId?: string
}>()

const emit = defineEmits<{
  saved: [invoice: Invoice, validation: ValidationResponse | null]
  cancel: []
}>()

const { t: $t, locale } = useI18n()

function localizeValidationMessage(message: string): string {
  const hashIndex = message.indexOf('#')
  if (hashIndex === -1) return message
  const parts = [message.substring(0, hashIndex).trim(), message.substring(hashIndex + 1).trim()]
  return locale.value === 'en' ? parts[1] : parts[0]
}
const invoiceStore = useInvoiceStore()
const clientStore = useClientStore()
const seriesStore = useDocumentSeriesStore()
const pdfConfigStore = usePdfTemplateConfigStore()
const {
  defaults,
  fetchDefaults,
  fetchDefaultsForClient,
  vatRateOptions,
  currencyOptions,
  unitOfMeasureOptions,
  defaultCurrency,
  defaultVatRate,
  defaultUnitOfMeasure,
  exchangeRates,
} = useInvoiceDefaults()
const { formatMoney, lineNet, lineVat, normalizeVatRate, normalizeVatCategoryCode } = useLineCalc()

const documentLanguageOptions = [
  { label: 'Romana', value: 'ro' },
  { label: 'English', value: 'en' },
  { label: 'Deutsch', value: 'de' },
  { label: 'Français', value: 'fr' },
]

const isEditing = computed(() => !!props.invoice)
const saving = ref(false)
const clients = ref<Client[]>([])
const showNotes = ref(false)
const showOptions = ref(false)
const showClientBalance = ref(false)
const showEfacturaInfo = ref(false)
const reverseChargeActive = ref(false)
const ossActive = ref(false)
const ossVatRate = ref<{ rate: string; label: string; categoryCode: string } | null>(null)
const ossVatRates = ref<{ rate: string; label: string; categoryCode: string; default: boolean }[]>([])
const companyStore = useCompanyStore()
const stripeConnected = computed(() => {
  const sc = companyStore.currentCompany?.stripeConnect
  return !!sc?.connected && sc?.chargesEnabled !== false
})
const productPickerOpen = ref(false)
const productPickerLineIndex = ref(0)
const lastValidation = ref<ValidationResponse | null>(null)
const lastSavedInvoice = ref<Invoice | null>(null)

// ── Quick series creation (slideover) ────────────────────────────────
const showQuickSeriesForm = ref(false)
const quickSeriesPrefix = ref('')
const quickSeriesStartNumber = ref(0)
const quickSeriesSaving = ref(false)
const toast = useToast()

async function onQuickCreateSeries() {
  if (!quickSeriesPrefix.value) return
  quickSeriesSaving.value = true
  const result = await seriesStore.createSeries({
    prefix: quickSeriesPrefix.value,
    type: form.documentType,
    currentNumber: quickSeriesStartNumber.value,
  })
  quickSeriesSaving.value = false
  if (result) {
    toast.add({ title: $t('documentSeries.createSuccess'), color: 'success' })
    showQuickSeriesForm.value = false
    quickSeriesPrefix.value = ''
    quickSeriesStartNumber.value = 0
    // Auto-select the newly created series
    await nextTick()
    if (seriesOptions.value.length > 0) {
      form.documentSeriesId = seriesOptions.value[0]?.value
    }
  }
  else if (seriesStore.error) {
    toast.add({ title: seriesStore.error, color: 'error' })
  }
}

const today = new Date().toISOString().split('T')[0]!

interface LineForm {
  description: string
  quantity: string
  unitOfMeasure: string
  unitPrice: string
  vatRate: string
  vatCategoryCode: string
  discount: string
  discountPercent: string
  productCode: string
  lineNote: string
  buyerAccountingRef: string
  buyerItemIdentification: string
  standardItemIdentification: string
  cpvCode: string
  showDetails: boolean
}

function emptyLine(): LineForm {
  return {
    description: '',
    quantity: '1.00',
    unitOfMeasure: defaultUnitOfMeasure.value,
    unitPrice: '0.00',
    vatRate: defaultVatRate.value,
    vatCategoryCode: 'S',
    discount: '0.00',
    discountPercent: '0.00',
    productCode: '',
    lineNote: '',
    buyerAccountingRef: '',
    buyerItemIdentification: '',
    standardItemIdentification: '',
    cpvCode: '',
    showDetails: false,
  }
}

const form = reactive({
  documentSeriesId: undefined as string | undefined,
  documentType: 'invoice' as string,
  invoiceTypeCode: 'standard' as string | undefined,
  clientId: null as string | null,
  receiverName: '' as string,
  receiverCif: '' as string,
  parentDocumentId: '' as string,
  issueDate: today,
  dueDate: '',
  currency: 'RON',
  language: 'ro',
  notes: '',
  paymentTerms: '',
  deliveryLocation: '',
  projectReference: '',
  // Options
  tvaLaIncasare: false,
  platitorTva: false,
  plataOnline: false,
  // Client balance
  showClientBalance: false,
  clientBalanceExisting: '0.00',
  clientBalanceOverdue: '0.00',
  // e-Factura BT fields
  taxPointDate: '',
  taxPointDateCode: undefined as string | undefined,
  buyerReference: '',
  orderNumber: '',
  contractNumber: '',
  receivingAdviceReference: '',
  despatchAdviceReference: '',
  tenderOrLotReference: '',
  invoicedObjectIdentifier: '',
  buyerAccountingReference: '',
  businessProcessType: '',
  payeeName: '',
  payeeIdentifier: '',
  payeeLegalRegistrationIdentifier: '',
  lines: [emptyLine()] as LineForm[],
})

// Populate form from existing invoice (edit mode)
if (props.invoice) {
  form.documentType = props.invoice.documentType
  form.documentSeriesId = props.invoice.documentSeries?.id || undefined
  form.invoiceTypeCode = props.invoice.invoiceTypeCode || 'standard'
  form.clientId = props.invoice.client?.id || null
  form.receiverName = props.invoice.receiverName || ''
  form.receiverCif = props.invoice.receiverCif || ''
  form.issueDate = props.invoice.issueDate?.split('T')[0] || today
  form.dueDate = props.invoice.dueDate?.split('T')[0] || ''
  form.currency = props.invoice.currency
  form.language = props.invoice.language || 'ro'
  form.notes = props.invoice.notes || ''
  form.paymentTerms = props.invoice.paymentTerms || ''
  form.deliveryLocation = props.invoice.deliveryLocation || ''
  form.projectReference = props.invoice.projectReference || ''
  // Options
  form.tvaLaIncasare = props.invoice.tvaLaIncasare || false
  form.platitorTva = props.invoice.platitorTva || false
  form.plataOnline = props.invoice.plataOnline || false
  // Client balance
  form.showClientBalance = props.invoice.showClientBalance || false
  form.clientBalanceExisting = props.invoice.clientBalanceExisting ?? '0.00'
  form.clientBalanceOverdue = props.invoice.clientBalanceOverdue ?? '0.00'
  // e-Factura BT fields
  form.taxPointDate = props.invoice.taxPointDate?.split('T')[0] || ''
  form.taxPointDateCode = props.invoice.taxPointDateCode || undefined
  form.buyerReference = props.invoice.buyerReference || ''
  form.orderNumber = props.invoice.orderNumber || ''
  form.contractNumber = props.invoice.contractNumber || ''
  form.receivingAdviceReference = props.invoice.receivingAdviceReference || ''
  form.despatchAdviceReference = props.invoice.despatchAdviceReference || ''
  form.tenderOrLotReference = props.invoice.tenderOrLotReference || ''
  form.invoicedObjectIdentifier = props.invoice.invoicedObjectIdentifier || ''
  form.buyerAccountingReference = props.invoice.buyerAccountingReference || ''
  form.businessProcessType = props.invoice.businessProcessType || ''
  form.payeeName = props.invoice.payeeName || ''
  form.payeeIdentifier = props.invoice.payeeIdentifier || ''
  form.payeeLegalRegistrationIdentifier = props.invoice.payeeLegalRegistrationIdentifier || ''

  form.lines = props.invoice.lines.length > 0
    ? props.invoice.lines.map(l => ({
        description: l.description,
        quantity: l.quantity,
        unitOfMeasure: l.unitOfMeasure,
        unitPrice: l.unitPrice,
        vatRate: l.vatRate,
        vatCategoryCode: normalizeVatCategoryCode(l.vatCategoryCode, l.vatRate),
        discount: l.discount,
        discountPercent: l.discountPercent,
        productCode: l.productCode || '',
        lineNote: l.lineNote || '',
        buyerAccountingRef: l.buyerAccountingRef || '',
        buyerItemIdentification: l.buyerItemIdentification || '',
        standardItemIdentification: l.standardItemIdentification || '',
        cpvCode: l.cpvCode || '',
        showDetails: !!(l.productCode || l.lineNote || l.buyerAccountingRef || l.buyerItemIdentification || l.standardItemIdentification || l.cpvCode),
      }))
    : [emptyLine()]

  // Auto-expand notes if editing and any notes field has data
  if (props.invoice.notes || props.invoice.deliveryLocation) {
    showNotes.value = true
  }
  // Auto-expand options if any option is set
  if (props.invoice.tvaLaIncasare || props.invoice.platitorTva || props.invoice.plataOnline) {
    showOptions.value = true
  }
  // Auto-expand client balance if showing
  if (props.invoice.showClientBalance) {
    showClientBalance.value = true
  }
  // Auto-expand e-Factura info if any BT field has data
  if (props.invoice.taxPointDate || props.invoice.taxPointDateCode || props.invoice.buyerReference ||
      props.invoice.orderNumber || props.invoice.contractNumber || props.invoice.receivingAdviceReference ||
      props.invoice.despatchAdviceReference || props.invoice.tenderOrLotReference || props.invoice.invoicedObjectIdentifier ||
      props.invoice.buyerAccountingReference || props.invoice.businessProcessType || props.invoice.payeeName ||
      props.invoice.payeeIdentifier || props.invoice.payeeLegalRegistrationIdentifier ||
      props.invoice.paymentTerms || props.invoice.projectReference) {
    showEfacturaInfo.value = true
  }
}


// Series options - sorted: efactura first, then default, then manual
const sourceOrder: Record<string, number> = { efactura: 0, default: 1, manual: 2 }

const seriesOptions = computed(() =>
  seriesStore.items
    .filter(s => s.active && s.type === form.documentType)
    .sort((a, b) => (sourceOrder[a.source] ?? 2) - (sourceOrder[b.source] ?? 2))
    .map((s) => ({ label: `${s.prefix} — ${s.nextNumber}`, value: s.id })),
)

const invoiceTypeCodeOptions = computed(() => [
  { label: $t('invoiceTypeCodes.standard'), value: 'standard' },
  { label: $t('invoiceTypeCodes.reverse_charge'), value: 'reverse_charge' },
  { label: $t('invoiceTypeCodes.exempt_with_deduction'), value: 'exempt_with_deduction' },
  { label: $t('invoiceTypeCodes.services_art_311'), value: 'services_art_311' },
  { label: $t('invoiceTypeCodes.sales_art_312'), value: 'sales_art_312' },
  { label: $t('invoiceTypeCodes.non_taxable'), value: 'non_taxable' },
  { label: $t('invoiceTypeCodes.special_regime_art_314_315'), value: 'special_regime_art_314_315' },
  { label: $t('invoiceTypeCodes.non_transfer'), value: 'non_transfer' },
  { label: $t('invoiceTypeCodes.simplified'), value: 'simplified' },
  { label: $t('invoiceTypeCodes.services_art_278'), value: 'services_art_278' },
  { label: $t('invoiceTypeCodes.exempt_art_294_ab'), value: 'exempt_art_294_ab' },
  { label: $t('invoiceTypeCodes.exempt_art_294_cd'), value: 'exempt_art_294_cd' },
  { label: $t('invoiceTypeCodes.self_billing'), value: 'self_billing' },
])

const taxPointDateCodeOptions = computed(() => [
  { label: $t('invoices.taxPointDateCodes.3'), value: '3' },
  { label: $t('invoices.taxPointDateCodes.35'), value: '35' },
  { label: $t('invoices.taxPointDateCodes.432'), value: '432' },
])

// VAT rate chip options
const vatRateChipOptions = computed(() => {
  // When OSS is active, show destination country's VAT rates
  if (ossActive.value && ossVatRates.value.length > 0) {
    return ossVatRates.value.map(vr => ({
      value: parseFloat(vr.rate).toFixed(2),
      chipLabel: `${parseFloat(vr.rate)}%`,
      categoryCode: vr.categoryCode,
    }))
  }
  return vatRateOptions.value.map(vr => ({
    value: vr.value,
    chipLabel: `${parseFloat(vr.value)}%`,
    categoryCode: vr.categoryCode,
  }))
})

function selectVatRate(index: number, vr: { value: string, categoryCode: string }) {
  const line = form.lines[index]
  if (!line) return
  line.vatRate = vr.value
  line.vatCategoryCode = vr.categoryCode
  syncInvoiceTypeFromVat()
}

function syncInvoiceTypeFromVat() {
  form.invoiceTypeCode = resolveInvoiceTypeCode(form.lines, form.invoiceTypeCode)
}

// Reverse sync: when invoice regime changes, update VAT category codes on 0% lines
watch(() => form.invoiceTypeCode, (newTypeCode) => {
  const targetCategory = typeCodeToVatCategory[newTypeCode!]
  for (const line of form.lines) {
    const rate = parseFloat(line.vatRate) || 0
    if (rate === 0) {
      line.vatCategoryCode = targetCategory || 'Z'
    }
  }
})

// Product picker
function openProductPicker(index: number) {
  productPickerLineIndex.value = index
  productPickerOpen.value = true
}

function normalizeUnitOfMeasure(unit: string): string {
  const options = unitOfMeasureOptions.value as { value: string; code?: string }[]
  // If it's already a known short label, return as-is
  if (options.some(o => o.value === unit)) return unit
  // Try reverse-mapping UNECE code from defaults
  const match = options.find(o => o.code === unit.toUpperCase())
  return match?.value || unit
}

function onProductSelected(product: Product) {
  const index = productPickerLineIndex.value
  const line = form.lines[index]
  if (line) {
    line.description = product.description || product.name
    line.unitPrice = product.defaultPrice
    line.vatRate = normalizeVatRate(product.vatRate)
    line.vatCategoryCode = normalizeVatCategoryCode(product.vatCategoryCode, line.vatRate)
    line.unitOfMeasure = normalizeUnitOfMeasure(product.unitOfMeasure)
    line.productCode = product.code || ''
    syncInvoiceTypeFromVat()
  }
}

// Currency converter (per-line)
const hasExchangeRates = computed(() => Object.keys(exchangeRates.value).length > 0)

const foreignCurrencies = computed(() => {
  const currencies = defaults.value?.currencies ?? ['EUR', 'USD']
  return currencies.filter(c => c !== 'RON' && exchangeRates.value[c])
})

const foreignCurrencyOptions = computed(() =>
  foreignCurrencies.value.map(c => ({ label: c, value: c })),
)

const lineConvertCurrency = reactive<Record<number, string>>({})
const lineConvertAmount = reactive<Record<number, string>>({})
const lineConvertMarkup = reactive<Record<number, string>>({})

function getLineConvertCurrency(index: number): string {
  return lineConvertCurrency[index] || foreignCurrencies.value[0] || 'EUR'
}

function lineConvertRate(index: number): number {
  return exchangeRates.value[getLineConvertCurrency(index)] ?? 0
}

function lineConvertedRon(index: number): number {
  const amount = parseFloat(lineConvertAmount[index] ?? '0') || 0
  const rate = lineConvertRate(index)
  const markup = parseFloat(lineConvertMarkup[index] ?? '0') || 0
  const base = amount * rate
  return base + (base * markup / 100)
}

function applyLineConversion(index: number) {
  const ron = lineConvertedRon(index)
  if (ron <= 0 || !form.lines[index]) return
  form.lines[index].unitPrice = ron.toFixed(2)
  lineConvertAmount[index] = ''
  lineConvertMarkup[index] = ''
}

function formatAmount(num: number): string {
  return new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(num)
}

// Client
const showClientCreateModal = ref(false)
const clientPrefill = ref<Record<string, any> | null>(null)

function onPrefillCreate(data: Record<string, any>) {
  clientPrefill.value = data
  showClientCreateModal.value = true
}

watch(showClientCreateModal, (isOpen) => {
  if (!isOpen) clientPrefill.value = null
})

const selectedClient = computed(() =>
  clients.value.find(c => c.id === form.clientId) || null,
)

function clearClient() {
  form.clientId = null
  form.receiverName = ''
  form.receiverCif = ''
  // Revert reverse charge if it was active
  if (reverseChargeActive.value) {
    reverseChargeActive.value = false
    revertToStandardVat()
  }
  // Revert OSS if it was active
  if (ossActive.value) {
    ossActive.value = false
    ossVatRate.value = null
    ossVatRates.value = []
    revertOssToStandardVat()
  }
}

async function onClientSelected(client: Client) {
  form.clientId = client.id
  form.receiverCif = ''
  form.receiverName = ''
  if (!clients.value.find(c => c.id === client.id)) {
    clients.value = [client, ...clients.value]
  }
  // Auto-check ANAF VAT status for new invoices
  if (!isEditing.value && client.type === 'company' && client.cui) {
    checkAnafVatStatus(client.cui)
  }
  // Auto-apply reverse charge / OSS for foreign clients
  await checkClientVatRules(client)
}

async function checkClientVatRules(client: Client, applyToLines = true) {
  // Reset both flags first
  if (reverseChargeActive.value) {
    reverseChargeActive.value = false
    if (applyToLines) revertToStandardVat()
  }
  if (ossActive.value) {
    ossActive.value = false
    ossVatRate.value = null
    ossVatRates.value = []
    if (applyToLines) revertOssToStandardVat()
  }

  // Only check for foreign clients
  if (client.country === 'RO') return

  // Ask backend for reverse charge / OSS defaults
  const clientDefaults = await fetchDefaultsForClient(client.id)

  if (clientDefaults?.reverseCharge) {
    reverseChargeActive.value = true
    if (applyToLines) {
      // Switch all standard-rated lines to reverse charge
      for (const line of form.lines) {
        if (line.vatCategoryCode === 'S') {
          line.vatCategoryCode = 'AE'
          line.vatRate = '0.00'
        }
      }
      syncInvoiceTypeFromVat()
    }
  } else if (clientDefaults?.ossApplicable && clientDefaults.ossVatRate) {
    ossActive.value = true
    ossVatRate.value = clientDefaults.ossVatRate
    ossVatRates.value = clientDefaults.ossVatRates || []
    if (applyToLines) {
      const ossRate = parseFloat(clientDefaults.ossVatRate.rate).toFixed(2)
      // Switch all standard-rated lines to OSS default (standard) rate
      for (const line of form.lines) {
        if (line.vatCategoryCode === 'S') {
          line.vatRate = ossRate
        }
      }
    }
  }
}

function revertToStandardVat() {
  const defRate = defaultVatRate.value
  for (const line of form.lines) {
    if (line.vatCategoryCode === 'AE') {
      line.vatCategoryCode = 'S'
      line.vatRate = defRate
    }
  }
  syncInvoiceTypeFromVat()
}

function revertOssToStandardVat() {
  const defRate = defaultVatRate.value
  for (const line of form.lines) {
    if (line.vatCategoryCode === 'S') {
      line.vatRate = defRate
    }
  }
}

const { get: apiGet } = useApi()

// CPV code search per line
const cpvSearchTerms = reactive<Record<number, string>>({})
const cpvSearchResults = reactive<Record<number, { label: string; value: string }[]>>({})
const cpvSearchLoading = reactive<Record<number, boolean>>({})

const debouncedCpvSearch = useDebounceFn(async (index: number, term: string) => {
  if (!term || term.length < 2) {
    cpvSearchResults[index] = []
    cpvSearchLoading[index] = false
    return
  }
  cpvSearchLoading[index] = true
  try {
    const results = await apiGet<{ cod: string; denumire: string }[]>('/v1/cpv-codes', { search: term, limit: 20 })
    cpvSearchResults[index] = results.map(cpv => ({
      label: `${cpv.cod} - ${cpv.denumire}`,
      value: cpv.cod,
    }))
  } catch {
    cpvSearchResults[index] = []
  } finally {
    cpvSearchLoading[index] = false
  }
}, 300)

const anafVatStatus = ref<{ vatPayer: boolean; vatOnCollection: boolean } | null>(null)

async function checkAnafVatStatus(cif: string, autoFill = true) {
  try {
    const data = await apiGet<{ vatPayer: boolean; vatOnCollection: boolean }>(`/v1/anaf/vat-status/${cif}`)
    anafVatStatus.value = data
    if (autoFill) {
      form.platitorTva = data.vatPayer
      form.tvaLaIncasare = data.vatOnCollection
      if (data.vatPayer || data.vatOnCollection) {
        showOptions.value = true
      }
    }
  } catch {
    anafVatStatus.value = null
  }
}

// Totals
function formatLineTotal(line: LineForm): string {
  return formatMoney(lineNet(line) + lineVat(line), form.currency)
}

const computedTotals = computed(() => {
  let subtotal = 0
  let vat = 0
  let discount = 0
  for (const line of form.lines) {
    subtotal += lineNet(line)
    vat += lineVat(line)
    discount += parseFloat(line.discount) || 0
  }
  return {
    subtotal,
    vat,
    discount,
    total: subtotal + vat,
  }
})

// Clear validation errors when user modifies the form
watch(() => form, () => {
  if (lastValidation.value) {
    lastValidation.value = null
    lastSavedInvoice.value = null
  }
}, { deep: true })

function addLine() {
  const line = emptyLine()
  if (reverseChargeActive.value) {
    line.vatCategoryCode = 'AE'
    line.vatRate = '0.00'
  } else if (ossActive.value && ossVatRate.value) {
    line.vatRate = parseFloat(ossVatRate.value.rate).toFixed(2)
  }
  form.lines.push(line)
}

function removeLine(index: number) {
  form.lines.splice(index, 1)
}

async function onSave() {
  saving.value = true

  const lines: InvoiceLinePayload[] = form.lines.map(l => ({
    description: l.description,
    quantity: l.quantity,
    unitOfMeasure: l.unitOfMeasure,
    unitPrice: l.unitPrice,
    vatRate: l.vatRate,
    vatCategoryCode: l.vatCategoryCode,
    discount: l.discount,
    discountPercent: l.discountPercent,
    productCode: l.productCode || null,
    lineNote: l.lineNote || null,
    buyerAccountingRef: l.buyerAccountingRef || null,
    buyerItemIdentification: l.buyerItemIdentification || null,
    standardItemIdentification: l.standardItemIdentification || null,
    cpvCode: l.cpvCode || null,
  }))

  let result: { invoice: Invoice, validation: ValidationResponse } | null = null

  // Common fields for both create and update
  const commonFields = {
    tvaLaIncasare: form.tvaLaIncasare,
    platitorTva: form.platitorTva,
    plataOnline: form.plataOnline,
    showClientBalance: form.showClientBalance,
    clientBalanceExisting: form.showClientBalance && form.clientBalanceExisting ? form.clientBalanceExisting : undefined,
    clientBalanceOverdue: form.showClientBalance && form.clientBalanceOverdue ? form.clientBalanceOverdue : undefined,
    taxPointDate: form.taxPointDate || undefined,
    taxPointDateCode: form.taxPointDateCode || undefined,
    buyerReference: form.buyerReference || undefined,
    orderNumber: form.orderNumber || undefined,
    contractNumber: form.contractNumber || undefined,
    receivingAdviceReference: form.receivingAdviceReference || undefined,
    despatchAdviceReference: form.despatchAdviceReference || undefined,
    tenderOrLotReference: form.tenderOrLotReference || undefined,
    invoicedObjectIdentifier: form.invoicedObjectIdentifier || undefined,
    buyerAccountingReference: form.buyerAccountingReference || undefined,
    businessProcessType: form.businessProcessType || undefined,
    payeeName: form.payeeName || undefined,
    payeeIdentifier: form.payeeIdentifier || undefined,
    payeeLegalRegistrationIdentifier: form.payeeLegalRegistrationIdentifier || undefined,
  }

  if (props.invoice) {
    const payload: UpdateInvoicePayload = {
      documentSeriesId: props.invoice.status === 'draft' ? (form.documentSeriesId || undefined) : undefined,
      documentType: form.documentType,
      invoiceTypeCode: form.invoiceTypeCode || null,
      clientId: form.clientId || undefined,
      receiverName: !form.clientId && form.receiverName ? form.receiverName : undefined,
      receiverCif: !form.clientId && form.receiverCif ? form.receiverCif : undefined,
      issueDate: form.issueDate,
      dueDate: form.dueDate || undefined,
      currency: form.currency,
      language: form.language,
      notes: form.notes || null,
      paymentTerms: form.paymentTerms || null,
      deliveryLocation: form.deliveryLocation || null,
      projectReference: form.projectReference || null,
      ...commonFields,
      lines,
    }
    result = await invoiceStore.updateInvoice(props.invoice.id, payload)
  }
  else {
    const payload: CreateInvoicePayload = {
      documentSeriesId: form.documentSeriesId || undefined,
      documentType: form.documentType,
      invoiceTypeCode: form.invoiceTypeCode || null,
      clientId: form.clientId || undefined,
      receiverName: !form.clientId && form.receiverName ? form.receiverName : undefined,
      receiverCif: !form.clientId && form.receiverCif ? form.receiverCif : undefined,
      parentDocumentId: form.parentDocumentId || undefined,
      issueDate: form.issueDate,
      dueDate: form.dueDate || undefined,
      currency: form.currency,
      language: form.language,
      notes: form.notes || undefined,
      paymentTerms: form.paymentTerms || undefined,
      deliveryLocation: form.deliveryLocation || undefined,
      projectReference: form.projectReference || undefined,
      ...commonFields,
      lines,
    }
    result = await invoiceStore.createInvoice(payload)
  }

  saving.value = false
  if (result) {
    lastValidation.value = result.validation ?? null
    lastSavedInvoice.value = result.invoice
    if (result.validation && !result.validation.valid && result.validation.errors.length > 0) {
      // Keep form open so user can fix errors
      useToast().add({
        title: isEditing.value ? $t('invoices.updateSuccess') : $t('invoices.createSuccess'),
        description: $t('invoices.validationFixHint'),
        color: 'warning',
        icon: 'i-lucide-alert-triangle',
      })
      return
    }
    emit('saved', result.invoice, result.validation)
  }
}

function closeWithErrors() {
  if (lastSavedInvoice.value) {
    emit('saved', lastSavedInvoice.value, lastValidation.value)
  }
}

// Load clients, series, and defaults on mount
onMounted(async () => {
  await Promise.all([
    seriesStore.fetchSeries(),
    fetchDefaults(),
    clientStore.fetchClients(),
  ])
  // Auto-select first available series when creating
  if (!props.invoice && !form.documentSeriesId && seriesOptions.value.length > 0) {
    form.documentSeriesId = seriesOptions.value[0]?.value
  }
  // Set defaults when creating
  if (!props.invoice) {
    form.currency = defaultCurrency.value
    // Default plataOnline from Stripe Connect paymentEnabledByDefault
    if (companyStore.currentCompany?.stripeConnect?.paymentEnabledByDefault) {
      form.plataOnline = true
    }
    // Pre-fill notes and payment terms from PDF template config defaults
    await pdfConfigStore.fetchConfig()
    const cfg = pdfConfigStore.config
    if (cfg?.defaultNotes && !form.notes) {
      form.notes = cfg.defaultNotes
      showNotes.value = true
    }
    if (cfg?.defaultPaymentTerms && !form.paymentTerms) {
      form.paymentTerms = cfg.defaultPaymentTerms
      showEfacturaInfo.value = true
    }
  }
  clients.value = clientStore.items

  // Auto-check ANAF VAT status when opening with a client
  // For new invoices: auto-fill the toggles. For editing: only fetch status for warnings.
  const clientForVat = props.invoice?.client || (form.clientId ? clientStore.items.find(c => c.id === form.clientId) : null)
  if (clientForVat && clientForVat.type === 'company' && clientForVat.cui) {
    checkAnafVatStatus(clientForVat.cui, !isEditing.value)
  }

  // Load OSS/reverse-charge rate options for the current client
  // For editing: only load the rate options (don't override line rates)
  // For creating: also apply the default OSS rate to lines
  if (clientForVat && clientForVat.country && clientForVat.country !== 'RO') {
    await checkClientVatRules(clientForVat as Client, !isEditing.value)
  }

  // Pre-fill from parent invoice for refund
  // Uses standard invoice (code 380) per Romanian e-Factura best practice
  if (props.refundOf && !props.invoice) {
    const parent = await invoiceStore.fetchInvoice(props.refundOf)
    if (parent) {
      form.parentDocumentId = parent.id
      form.clientId = parent.client?.id || null
      form.currency = parent.currency
      form.language = parent.language || 'ro'
      form.notes = $t('invoices.refundNotes', { number: parent.number, date: parent.issueDate?.split('T')[0] || '' })
      form.paymentTerms = parent.paymentTerms || ''
      form.deliveryLocation = parent.deliveryLocation || ''
      form.projectReference = parent.projectReference || ''
      showNotes.value = true
      // Refund: negate quantities (minus on quantity, price stays positive)
      form.lines = parent.lines.length > 0
        ? parent.lines.map(l => {
            const qty = parseFloat(l.quantity) || 0
            return {
              description: l.description,
              quantity: String(-Math.abs(qty)),
              unitOfMeasure: l.unitOfMeasure,
              unitPrice: l.unitPrice,
              vatRate: l.vatRate,
              vatCategoryCode: l.vatCategoryCode,
              discount: l.discount,
              discountPercent: l.discountPercent,
              productCode: l.productCode || '',
              lineNote: l.lineNote || '',
              buyerAccountingRef: l.buyerAccountingRef || '',
              buyerItemIdentification: l.buyerItemIdentification || '',
              standardItemIdentification: l.standardItemIdentification || '',
              cpvCode: l.cpvCode || '',
              showDetails: !!(l.lineNote || l.buyerAccountingRef || l.buyerItemIdentification || l.standardItemIdentification || l.cpvCode),
            }
          })
        : [emptyLine()]
      // Ensure parent's client is in the options list
      if (parent.client && !clients.value.find(c => c.id === parent.client!.id)) {
        clients.value = [parent.client as Client, ...clients.value]
      }
    }
  }

  // Pre-fill from source invoice for copy
  if (props.copyOf && !props.invoice) {
    const source = await invoiceStore.fetchInvoice(props.copyOf)
    if (source) {
      form.clientId = source.client?.id || null
      form.receiverName = source.receiverName || ''
      form.receiverCif = source.receiverCif || ''
      form.currency = source.currency
      form.language = source.language || 'ro'
      form.invoiceTypeCode = source.invoiceTypeCode || 'standard'
      form.notes = source.notes || ''
      form.paymentTerms = source.paymentTerms || ''
      form.deliveryLocation = source.deliveryLocation || ''
      form.projectReference = source.projectReference || ''
      // Copy options
      form.tvaLaIncasare = source.tvaLaIncasare || false
      form.platitorTva = source.platitorTva || false
      form.plataOnline = source.plataOnline || false
      form.showClientBalance = source.showClientBalance || false
      form.clientBalanceExisting = source.clientBalanceExisting ?? '0.00'
      form.clientBalanceOverdue = source.clientBalanceOverdue ?? '0.00'
      // Copy e-Factura BT fields
      form.orderNumber = source.orderNumber || ''
      form.contractNumber = source.contractNumber || ''
      form.buyerReference = source.buyerReference || ''
      form.businessProcessType = source.businessProcessType || ''
      form.payeeName = source.payeeName || ''
      form.payeeIdentifier = source.payeeIdentifier || ''
      form.payeeLegalRegistrationIdentifier = source.payeeLegalRegistrationIdentifier || ''
      if (source.notes || source.deliveryLocation) {
        showNotes.value = true
      }
      if (source.tvaLaIncasare || source.platitorTva || source.plataOnline) {
        showOptions.value = true
      }
      if (source.showClientBalance) {
        showClientBalance.value = true
      }
      if (source.paymentTerms || source.projectReference || source.orderNumber || source.contractNumber || source.buyerReference || source.businessProcessType || source.payeeName) {
        showEfacturaInfo.value = true
      }
      form.lines = source.lines.length > 0
        ? source.lines.map(l => ({
            description: l.description,
            quantity: l.quantity,
            unitOfMeasure: l.unitOfMeasure,
            unitPrice: l.unitPrice,
            vatRate: l.vatRate,
            vatCategoryCode: l.vatCategoryCode,
            discount: l.discount,
            discountPercent: l.discountPercent,
            productCode: l.productCode || '',
            lineNote: l.lineNote || '',
            buyerAccountingRef: l.buyerAccountingRef || '',
            buyerItemIdentification: l.buyerItemIdentification || '',
            standardItemIdentification: l.standardItemIdentification || '',
            cpvCode: l.cpvCode || '',
            showDetails: !!(l.productCode || l.lineNote || l.buyerAccountingRef || l.buyerItemIdentification || l.standardItemIdentification || l.cpvCode),
          }))
        : [emptyLine()]
      // Ensure source's client is in the options list
      if (source.client && !clients.value.find(c => c.id === source.client!.id)) {
        clients.value = [source.client as Client, ...clients.value]
      }
    }
  }

  // Pre-fill client from query param (e.g. creating invoice from client page)
  if (props.prefillClientId && !props.invoice && !props.refundOf && !props.copyOf) {
    const prefillClient = clients.value.find(c => c.id === props.prefillClientId)
    if (prefillClient) {
      await onClientSelected(prefillClient)
    }
  }

})
</script>
