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
      <div v-if="invoice" class="space-y-6">
      <div class="flex flex-col gap-3">
        <!-- Title row -->
        <div class="flex items-center gap-3 min-w-0">
          <UButton icon="i-lucide-arrow-left" variant="ghost" to="/invoices" class="shrink-0" />
          <h1 class="text-2xl font-bold truncate">
            <span v-if="invoice.invoiceTypeCode" class="text-sm font-bold text-primary tabular-nums mr-1.5">{{ invoiceTypeCodeShort[invoice.invoiceTypeCode] || '' }}</span>{{ invoice.number }}
          </h1>
          <div class="flex items-center gap-1.5 flex-wrap">
            <UBadge :color="directionColor(invoice.direction)" variant="subtle">
              {{ invoice.direction === 'incoming' ? $t('common.incoming') : $t('common.outgoing') }}
            </UBadge>
            <UBadge :color="statusColor(invoice.status)" variant="subtle">
              {{ $t(`documentStatus.${invoice.status}`) }}
            </UBadge>
            <UBadge v-if="invoice.paidAt" color="success" variant="subtle">
              {{ $t('documentStatus.paid') }}
            </UBadge>
            <UBadge v-else-if="!invoice.paidAt && Number(invoice.amountPaid) > 0" color="warning" variant="subtle">
              {{ $t('documentStatus.partially_paid') }}
            </UBadge>
            <UBadge v-if="isOverdue" color="error" variant="subtle">
              {{ $t('documentStatus.overdue') }}
            </UBadge>
            <UBadge v-if="invoice.isDuplicate" color="warning" variant="subtle">
              {{ $t('invoices.isDuplicate') }}
            </UBadge>
            <UBadge v-if="invoice.isLateSubmission" color="error" variant="subtle">
              {{ $t('invoices.isLateSubmission') }}
            </UBadge>
            <UBadge v-if="invoice.cancelledAt && invoice.status !== 'cancelled'" color="error" variant="subtle">
              {{ $t('documentStatus.cancelled') }}
            </UBadge>
          </div>
        </div>
        <!-- Actions row -->
        <div class="flex items-center gap-2 flex-wrap">
          <!-- Primary CTA -->
          <UButton v-if="invoice.status === 'draft'" icon="i-lucide-file-check" color="primary" :loading="issuing" @click="issueModalOpen = true">
            {{ $t('invoices.issue') }}
          </UButton>
          <UButton
            v-else-if="invoice.status !== 'cancelled' && invoice.status !== 'sent_to_provider' && (!invoice.anafUploadId || invoice.status === 'rejected')"
            icon="i-lucide-send"
            color="primary"
            :loading="submitting"
            @click="submitModalOpen = true"
          >
            {{ $t('invoices.submitToAnaf') }}
          </UButton>

          <!-- Payment -->
          <UButton
            v-if="invoice.status !== 'draft' && invoice.status !== 'cancelled' && !invoice.paidAt"
            icon="i-lucide-banknote"
            color="primary"
            variant="soft"
            @click="paymentModalOpen = true"
          >
            {{ $t('invoices.recordPayment') }}
          </UButton>
          <UButton
            v-else-if="invoice.status !== 'draft' && invoice.status !== 'cancelled' && invoice.paidAt"
            icon="i-lucide-circle-check"
            color="success"
            variant="soft"
            :loading="updatingPayment"
            @click="markUnpaidModalOpen = true"
          >
            {{ $t('invoices.markUnpaid') }}
          </UButton>

          <!-- Separator -->
          <div class="w-px h-6 bg-gray-200 dark:bg-gray-700 hidden sm:block" />

          <!-- Documents dropdown -->
          <UDropdownMenu :items="downloadMenuItems">
            <UButton icon="i-lucide-file-down" variant="outline" :loading="viewingPdf || downloadingPdf">
              {{ $t('invoices.documents') }}
              <UIcon name="i-lucide-chevron-down" class="size-3.5" />
            </UButton>
          </UDropdownMenu>

          <!-- Share Link -->
          <UTooltip v-if="invoice.direction === 'outgoing' && !['draft', 'cancelled'].includes(invoice.status)" :text="$t('invoices.copyShareLink')">
            <UButton
              icon="i-lucide-link"
              :color="paymentLinkCopied ? 'success' : 'neutral'"
              variant="outline"
              :loading="creatingPaymentLink"
              @click="copyPaymentLink"
            >
              <UIcon v-if="paymentLinkCopied" name="i-lucide-check" class="size-4" />
            </UButton>
          </UTooltip>

          <!-- Email (outgoing, not draft/cancelled) -->
          <UTooltip v-if="invoice.direction === 'outgoing' && !['draft', 'cancelled'].includes(invoice.status)" :text="$t('invoices.sendEmail')">
            <UButton icon="i-lucide-mail" variant="outline" @click="emailModalOpen = true" />
          </UTooltip>

          <!-- Verify signature -->
          <UTooltip v-if="invoice.anafMessageId" :text="signatureLabel">
            <UButton
              :icon="signatureIcon"
              :color="signatureColor"
              :variant="signatureResult ? 'subtle' : 'outline'"
              :loading="verifyingSignature"
              @click="verifySignature"
            />
          </UTooltip>

          <!-- More actions dropdown -->
          <UDropdownMenu :items="moreActionsItems">
            <UButton icon="i-lucide-ellipsis" variant="outline" />
          </UDropdownMenu>
        </div>
      </div>

      <!-- Non-editable banner — only for invoices sent to ANAF, not already refunded -->
      <UCard v-if="invoice.anafUploadId && invoice.status !== 'rejected' && invoice.direction === 'outgoing' && !invoice.cancelledAt && !invoice.parentDocumentId && !invoice.refundInvoices?.length" class="border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30">
        <div class="flex items-start justify-between gap-3">
          <div class="flex items-start gap-3 text-sm">
            <UIcon name="i-lucide-info" class="text-amber-500 mt-0.5 shrink-0 size-5" />
            <span class="text-amber-800 dark:text-amber-200">{{ $t('invoices.notEditableBanner') }}</span>
          </div>
          <UButton
            icon="i-lucide-file-minus"
            color="warning"
            variant="subtle"
            size="sm"
            @click="openRefundSlideover"
          >
            {{ $t('invoices.createRefund') }}
          </UButton>
        </div>
      </UCard>

      <!-- Refund relationship banner — this is a refund invoice, link to parent -->
      <UCard v-if="invoice.parentDocumentId" variant="outline" class="border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30">
        <div class="flex items-center justify-between gap-3">
          <div class="flex items-center gap-2 text-sm">
            <UIcon name="i-lucide-file-minus" class="text-amber-500 shrink-0 size-5" />
            <span class="text-amber-800 dark:text-amber-200">{{ $t('invoices.refundOfBanner', { number: invoice.parentDocumentNumber }) }}</span>
          </div>
          <UButton
            icon="i-lucide-external-link"
            variant="subtle"
            color="warning"
            size="sm"
            :to="`/invoices/${invoice.parentDocumentId}`"
          >
            {{ $t('invoices.refundOfLink') }}
          </UButton>
        </div>
      </UCard>

      <!-- Refunded banner — this invoice was refunded, link to refund children -->
      <UCard v-if="invoice.refundInvoices?.length" variant="outline" class="border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30">
        <div class="flex items-center justify-between gap-3">
          <div class="flex items-center gap-2 text-sm">
            <UIcon name="i-lucide-file-x" class="text-amber-500 shrink-0 size-5" />
            <span class="text-amber-800 dark:text-amber-200">{{ $t('invoices.refundedBanner') }}</span>
          </div>
          <div class="flex flex-wrap gap-2">
            <UButton
              v-for="refundInv in invoice.refundInvoices"
              :key="refundInv.id"
              icon="i-lucide-external-link"
              variant="subtle"
              color="warning"
              size="sm"
              :to="`/invoices/${refundInv.id}`"
            >
              {{ $t('invoices.refundedLink') }} {{ refundInv.number }}
            </UButton>
          </div>
        </div>
      </UCard>

      <!-- Scheduled ANAF send info -->
      <UCard v-if="invoice.scheduledSendAt && !invoice.anafUploadId" class="border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/30">
        <div class="flex items-center gap-2 text-sm">
          <UIcon name="i-lucide-clock" class="text-blue-500 shrink-0 size-5" />
          <span class="text-blue-800 dark:text-blue-200">{{ $t('invoices.scheduledSendAt', { date: formatDateTime(invoice.scheduledSendAt) }) }}</span>
        </div>
      </UCard>


      <!-- Validation loading -->
      <div v-if="validating && !validationResult" class="flex items-center gap-2 text-sm text-(--ui-text-muted) p-3">
        <UIcon name="i-lucide-loader-2" class="animate-spin size-4" />
        {{ $t('invoices.validating') }}
      </div>

      <!-- Validation Results — success (clean inline) -->
      <div v-if="validationResult && validationResult.valid && !validationResult.errors.length && !validationResult.warnings.length" class="flex items-center gap-2 text-sm text-green-600 dark:text-green-400 px-3 py-2">
        <UIcon name="i-lucide-circle-check" class="size-4 shrink-0" />
        <span class="font-medium">{{ $t('invoices.validationPassed') }}</span>
        <UBadge v-if="!validationResult.schematronAvailable" color="warning" variant="subtle" size="sm">
          {{ $t('invoices.schematronUnavailable') }}
        </UBadge>
      </div>

      <!-- Validation Results — errors/warnings (card) -->
      <UCard v-else-if="validationResult" class="border-red-200 dark:border-red-800">
        <template #header>
          <div class="flex items-center gap-2">
            <UIcon name="i-lucide-circle-x" class="text-red-500 size-5" />
            <h3 class="font-semibold text-red-600">{{ $t('invoices.validationFailed') }}</h3>
            <UBadge v-if="!validationResult.schematronAvailable" color="warning" variant="subtle" size="sm">
              {{ $t('invoices.schematronUnavailable') }}
            </UBadge>
          </div>
        </template>
        <div v-if="validationResult.errors.length" class="space-y-2">
          <div v-for="(err, i) in validationResult.errors" :key="i" class="flex items-start gap-2 text-sm">
            <UIcon name="i-lucide-x" class="text-red-500 mt-0.5 shrink-0" />
            <div>
              <span class="font-medium">{{ localizeValidationMessage(err.message) }}</span>
              <span v-if="err.ruleId" class="text-(--ui-text-muted) ml-1">[{{ err.ruleId }}]</span>
              <UBadge variant="subtle" size="xs" class="ml-1">{{ err.source }}</UBadge>
              <span v-if="err.location" class="text-(--ui-text-muted) ml-1 text-xs">{{ err.location }}</span>
            </div>
          </div>
        </div>
        <div v-if="validationResult.warnings.length" class="mt-3 space-y-1">
          <div v-for="(warn, i) in validationResult.warnings" :key="i" class="flex items-start gap-2 text-sm text-amber-600">
            <UIcon name="i-lucide-alert-triangle" class="mt-0.5 shrink-0" />
            <span>{{ localizeValidationMessage(warn) }}</span>
          </div>
        </div>
      </UCard>

      <UTabs :items="tabs" v-model="activeTab">
        <template #details>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4">
            <UCard>
              <template #header>
                <h3 class="font-semibold">{{ $t('invoices.seller') }}</h3>
              </template>
              <dl class="space-y-2 text-sm">
                <div><dt class="text-(--ui-text-muted)">{{ $t('common.name') }}</dt><dd class="font-medium">{{ invoice.senderName || '-' }}</dd></div>
                <div><dt class="text-(--ui-text-muted)">CIF</dt><dd>{{ formatCif(invoice.senderCif, invoice.direction === 'outgoing' ? companyStore.currentCompany?.vatPayer : invoice.supplier?.isVatPayer) || '-' }}</dd></div>
              </dl>
            </UCard>
            <UCard>
              <template #header>
                <h3 class="font-semibold">{{ $t('invoices.buyer') }}</h3>
              </template>
              <dl class="space-y-2 text-sm">
                <div><dt class="text-(--ui-text-muted)">{{ $t('common.name') }}</dt><dd class="font-medium">{{ invoice.receiverName || '-' }}</dd></div>
                <div><dt class="text-(--ui-text-muted)">CIF</dt><dd>{{ formatCif(invoice.receiverCif, invoice.direction === 'outgoing' ? invoice.client?.isVatPayer : companyStore.currentCompany?.vatPayer) || $t('clients.typeIndividual') }}</dd></div>
              </dl>
            </UCard>
          </div>

          <!-- Invoice metadata -->
          <UCard class="mt-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('invoices.issueDate') }}</dt>
                <dd class="font-medium">{{ formatDate(invoice.issueDate) }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('invoices.dueDate') }}</dt>
                <dd class="font-medium">{{ formatDate(invoice.dueDate) }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('invoices.currency') }}</dt>
                <dd class="font-medium">{{ invoice.currency }}</dd>
              </div>
              <div v-if="invoice.invoiceTypeCode">
                <dt class="text-(--ui-text-muted)">{{ $t('invoices.invoiceTypeCode') }}</dt>
                <dd class="font-medium">{{ $t(`invoiceTypeCodes.${invoice.invoiceTypeCode}`) }}</dd>
              </div>
              <div v-if="invoice.paymentTerms">
                <dt class="text-(--ui-text-muted)">{{ $t('invoices.paymentTerms') }}</dt>
                <dd class="font-medium">{{ invoice.paymentTerms }}</dd>
              </div>
              <div v-if="invoice.deliveryLocation">
                <dt class="text-(--ui-text-muted)">{{ $t('invoices.deliveryLocation') }}</dt>
                <dd class="font-medium">{{ invoice.deliveryLocation }}</dd>
              </div>
              <div v-if="invoice.projectReference">
                <dt class="text-(--ui-text-muted)">{{ $t('invoices.projectReference') }}</dt>
                <dd class="font-medium">{{ invoice.projectReference }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('invoices.amountPaid') }}</dt>
                <dd class="font-medium">{{ formatMoney(invoice.amountPaid, invoice.currency) }}</dd>
              </div>
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('invoices.balance') }}</dt>
                <dd class="font-medium" :class="Number(invoice.balance) > 0 ? 'text-amber-600' : 'text-green-600'">
                  {{ formatMoney(invoice.balance, invoice.currency) }}
                </dd>
              </div>
              <div v-if="invoice.paidAt">
                <dt class="text-(--ui-text-muted)">{{ $t('invoices.paidAt') }}</dt>
                <dd class="font-medium">{{ formatDate(invoice.paidAt) }}</dd>
              </div>
              <div v-if="invoice.paymentMethod">
                <dt class="text-(--ui-text-muted)">{{ $t('invoices.paymentMethod') }}</dt>
                <dd class="font-medium">{{ $t(`invoices.paymentMethods.${invoice.paymentMethod}`) }}</dd>
              </div>
            </div>
          </UCard>

          <!-- Cancellation info -->
          <UCard v-if="invoice.cancelledAt" class="mt-6 border-red-200 dark:border-red-800">
            <template #header>
              <h3 class="font-semibold text-red-600">{{ $t('invoices.cancelledAt') }}</h3>
            </template>
            <dl class="space-y-2 text-sm">
              <div>
                <dt class="text-(--ui-text-muted)">{{ $t('common.date') }}</dt>
                <dd class="font-medium">{{ formatDate(invoice.cancelledAt) }}</dd>
              </div>
              <div v-if="invoice.cancellationReason">
                <dt class="text-(--ui-text-muted)">{{ $t('invoices.cancellationReason') }}</dt>
                <dd class="font-medium">{{ invoice.cancellationReason }}</dd>
              </div>
            </dl>
          </UCard>

          <!-- Client info (for outgoing invoices) -->
          <UCard v-if="invoice.client && invoice.direction === 'outgoing'" class="mt-6">
            <template #header>
              <div class="flex items-center justify-between">
                <h3 class="font-semibold">{{ $t('clients.details') }}</h3>
                <UButton variant="ghost" size="sm" :to="`/clients/${invoice.client.id}`">
                  {{ $t('common.view') }}
                </UButton>
              </div>
            </template>
            <dl class="grid grid-cols-2 gap-3 text-sm">
              <div><dt class="text-(--ui-text-muted)">{{ $t('common.name') }}</dt><dd class="font-medium">{{ invoice.client.name }}</dd></div>
              <div v-if="invoice.client.cui"><dt class="text-(--ui-text-muted)">CIF</dt><dd class="font-medium">{{ formatCif(invoice.client.cui, invoice.client.isVatPayer) }}</dd></div>
            </dl>
          </UCard>

          <!-- Supplier info (for incoming invoices) -->
          <UCard v-if="invoice.supplier && invoice.direction === 'incoming'" class="mt-6">
            <template #header>
              <div class="flex items-center justify-between">
                <h3 class="font-semibold">{{ $t('suppliers.details') }}</h3>
                <UButton variant="ghost" size="sm" :to="`/suppliers/${invoice.supplier.id}`">
                  {{ $t('common.view') }}
                </UButton>
              </div>
            </template>
            <dl class="grid grid-cols-2 gap-3 text-sm">
              <div><dt class="text-(--ui-text-muted)">{{ $t('common.name') }}</dt><dd class="font-medium">{{ invoice.supplier.name }}</dd></div>
              <div v-if="invoice.supplier.cif"><dt class="text-(--ui-text-muted)">CIF</dt><dd class="font-medium">{{ formatCif(invoice.supplier.cif, invoice.supplier.isVatPayer) }}</dd></div>
            </dl>
          </UCard>

          <UCard class="mt-6">
            <template #header>
              <h3 class="font-semibold">{{ $t('invoices.lines') }}</h3>
            </template>
            <UTable :data="invoice.lines || []" :columns="lineColumns" />
            <div class="flex justify-end mt-4 space-y-1 text-right">
              <div class="space-y-1">
                <div class="text-sm">{{ $t('invoices.subtotal') }}: <strong>{{ formatMoney(invoice.subtotal, invoice.currency) }}</strong></div>
                <div class="text-sm">TVA: <strong>{{ formatMoney(invoice.vatTotal, invoice.currency) }}</strong></div>
                <div class="text-lg font-bold">{{ $t('invoices.total') }}: {{ formatMoney(invoice.total, invoice.currency) }}</div>
              </div>
            </div>
          </UCard>

          <UCard v-if="invoice.notes" class="mt-6">
            <template #header>
              <h3 class="font-semibold">{{ $t('common.notes') }}</h3>
            </template>
            <p class="text-sm">{{ invoice.notes }}</p>
          </UCard>
        </template>

        <template #xml>
          <UCard class="mt-4">
            <template #header>
              <div class="flex items-center justify-between">
                <h3 class="font-semibold">XML</h3>
                <UButton
                  v-if="xmlContent"
                  :icon="copied ? 'i-lucide-check' : 'i-lucide-copy'"
                  variant="ghost"
                  size="sm"
                  @click="copyXml"
                >
                  {{ copied ? $t('invoices.xmlCopied') : $t('invoices.xmlCopy') }}
                </UButton>
              </div>
            </template>
            <pre
              v-if="xmlContent"
              class="text-xs overflow-auto max-h-[600px] bg-(--ui-bg-elevated) p-4 rounded font-mono leading-relaxed"
              v-html="highlightedXml"
            />
            <div v-else-if="loadingXml" class="text-center py-8">
              <UIcon name="i-lucide-loader-2" class="animate-spin h-6 w-6 mx-auto text-(--ui-text-muted)" />
            </div>
            <div v-else class="text-center py-8 text-(--ui-text-muted)">{{ $t('invoices.noXml') }}</div>
          </UCard>
        </template>

        <template #events>
          <UCard class="mt-4">
            <div v-if="events.length" class="space-y-0">
              <div v-for="(event, idx) in events" :key="event.id" class="flex gap-3">
                <!-- Timeline line + dot -->
                <div class="flex flex-col items-center">
                  <div class="size-3 rounded-full shrink-0 mt-1.5" :class="{
                    'bg-green-500': event.newStatus === 'validated',
                    'bg-red-500': event.newStatus === 'rejected' || event.newStatus === 'cancelled',
                    'bg-amber-500': event.newStatus === 'sent_to_provider',
                    'bg-blue-500': event.newStatus === 'issued' || event.newStatus === 'synced',
                    'bg-(--ui-text-muted)': !['validated','rejected','cancelled','sent_to_provider','issued','synced'].includes(event.newStatus),
                  }" />
                  <div v-if="idx < events.length - 1" class="w-px flex-1 bg-(--ui-border) my-1" />
                </div>
                <!-- Content -->
                <div class="pb-5 min-w-0 flex-1">
                  <p class="text-sm font-medium">{{ getEventTitle(event) }}</p>
                  <p class="text-xs text-(--ui-text-muted) mt-0.5">
                    {{ formatDateTime(event.createdAt) }}
                    <span v-if="event.createdBy"> · {{ event.createdBy }}</span>
                  </p>
                  <!-- Rejection error callout -->
                  <div
                    v-if="event.metadata?.action === 'anaf_rejected' && event.metadata?.error"
                    class="mt-2 rounded-lg bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 px-3 py-2"
                  >
                    <div class="flex items-start gap-2">
                      <UIcon name="i-lucide-alert-circle" class="size-4 shrink-0 text-red-500 mt-0.5" />
                      <p class="text-xs text-red-700 dark:text-red-300 break-words">{{ event.metadata.error }}</p>
                    </div>
                    <button
                      v-if="invoice?.anafDownloadId"
                      class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-red-600 dark:text-red-400 hover:underline cursor-pointer"
                      @click="downloadAnafResponse"
                    >
                      <UIcon name="i-lucide-download" class="size-3.5" />
                      {{ $t('invoices.downloadAnafError') }}
                    </button>
                  </div>
                  <!-- Cancellation reason callout -->
                  <div
                    v-else-if="event.metadata?.action === 'cancelled' && event.metadata?.reason"
                    class="mt-2 flex items-start gap-2 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 px-3 py-2"
                  >
                    <UIcon name="i-lucide-message-circle" class="size-4 shrink-0 text-amber-500 mt-0.5" />
                    <p class="text-xs text-amber-700 dark:text-amber-300">{{ event.metadata.reason }}</p>
                  </div>
                  <!-- Scheduled send info -->
                  <div
                    v-else-if="event.metadata?.action === 'submitted' && event.metadata?.scheduledSendAt"
                    class="mt-2 flex items-start gap-2 rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 px-3 py-2"
                  >
                    <UIcon name="i-lucide-clock" class="size-4 shrink-0 text-blue-500 mt-0.5" />
                    <p class="text-xs text-blue-700 dark:text-blue-300">{{ $t('invoices.scheduledSendAt', { date: formatDateTime(event.metadata.scheduledSendAt) }) }}</p>
                  </div>
                  <!-- Auto submit scheduled -->
                  <div
                    v-else-if="event.metadata?.action === 'issued' && event.metadata?.efacturaDelayHours"
                    class="mt-2 flex items-start gap-2 rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 px-3 py-2"
                  >
                    <UIcon name="i-lucide-timer" class="size-4 shrink-0 text-blue-500 mt-0.5" />
                    <p class="text-xs text-blue-700 dark:text-blue-300">{{ $t('invoices.autoSubmitScheduled') }}</p>
                  </div>
                </div>
              </div>
            </div>
            <UEmpty v-else icon="i-lucide-calendar" :title="$t('common.noData')" class="py-8" />
          </UCard>
        </template>

        <template #payments>
          <UCard class="mt-4">
            <InvoicesPaymentHistory
              :payments="payments"
              :invoice="invoice"
              :can-delete="invoice.status !== 'cancelled'"
              @deleted="refreshInvoiceData"
            />
          </UCard>
        </template>

        <template #emails>
          <!-- Scheduled email banner -->
          <div v-if="invoice.scheduledEmailAt" class="mt-4 rounded-lg border p-3" :class="isEmailBlocked ? 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30' : 'border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/30'">
            <div class="flex items-center justify-between gap-3">
              <div class="flex items-center gap-2 text-sm">
                <UIcon name="i-lucide-mail" :class="isEmailBlocked ? 'text-amber-500' : 'text-blue-500'" class="shrink-0 size-5" />
                <span :class="isEmailBlocked ? 'text-amber-800 dark:text-amber-200' : 'text-blue-800 dark:text-blue-200'">
                  {{ isEmailBlocked
                    ? $t('invoices.scheduledEmailBlocked', { date: formatDateTime(invoice.scheduledEmailAt) })
                    : $t('invoices.scheduledEmailAt', { date: formatDateTime(invoice.scheduledEmailAt) })
                  }}
                </span>
              </div>
              <UButton
                icon="i-lucide-x"
                :color="isEmailBlocked ? 'warning' : 'primary'"
                variant="subtle"
                size="sm"
                @click="cancelScheduledEmail"
              >
                {{ $t('invoices.cancelScheduledEmail') }}
              </UButton>
            </div>
          </div>

          <UCard class="mt-4">
            <div v-if="emailLogs.length" class="space-y-4">
              <div v-for="log in emailLogs" :key="log.id" class="border border-(--ui-border) rounded-lg overflow-hidden">
                <!-- Email log header (always visible) -->
                <button
                  type="button"
                  class="w-full flex items-center justify-between p-4 hover:bg-(--ui-bg-elevated)/50 transition-colors text-left"
                  @click="toggleEmailExpanded(log.id)"
                >
                  <div class="flex items-center gap-3 min-w-0 flex-1">
                    <UIcon name="i-lucide-mail" class="size-5 shrink-0 text-(--ui-text-muted)" />
                    <div class="min-w-0 flex-1">
                      <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm font-medium truncate">{{ log.toEmail }}</span>
                        <UBadge :color="emailStatusColor(log.status)" variant="subtle" size="sm">
                          {{ $t(`emailStatus.${log.status}`) }}
                        </UBadge>
                      </div>
                      <p class="text-xs text-(--ui-text-muted) mt-0.5 truncate">{{ log.subject }}</p>
                    </div>
                  </div>
                  <div class="flex items-center gap-2 shrink-0 ml-3">
                    <span class="text-xs text-(--ui-text-muted)">{{ formatDateTime(log.sentAt) }}</span>
                    <UIcon
                      :name="expandedEmails.has(log.id) ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                      class="size-4 text-(--ui-text-muted)"
                    />
                  </div>
                </button>

                <!-- Expanded: event timeline -->
                <div v-if="expandedEmails.has(log.id)" class="border-t border-(--ui-border) bg-(--ui-bg-elevated)/30 px-4 py-3">
                  <!-- From/CC/BCC info -->
                  <div v-if="log.fromEmail || log.ccEmails?.length || log.bccEmails?.length" class="mb-3 space-y-1 text-xs text-(--ui-text-muted)">
                    <div v-if="log.fromEmail">
                      <span class="font-medium">{{ $t('emailAudit.from') }}:</span> {{ log.fromName ? `${log.fromName} <${log.fromEmail}>` : log.fromEmail }}
                    </div>
                    <div v-if="log.ccEmails?.length">
                      <span class="font-medium">CC:</span> {{ log.ccEmails.join(', ') }}
                    </div>
                    <div v-if="log.bccEmails?.length">
                      <span class="font-medium">BCC:</span> {{ log.bccEmails.join(', ') }}
                    </div>
                  </div>

                  <!-- Events timeline -->
                  <div v-if="log.events?.length" class="space-y-0">
                    <div v-for="(event, idx) in log.events" :key="event.id" class="flex gap-3">
                      <!-- Timeline line + dot -->
                      <div class="flex flex-col items-center">
                        <div class="size-3 rounded-full shrink-0 mt-1" :class="emailEventDotClass(event.eventType)" />
                        <div v-if="idx < log.events.length - 1" class="w-px flex-1 bg-(--ui-border) my-1" />
                      </div>
                      <!-- Content -->
                      <div class="pb-4 min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                          <UIcon :name="`i-lucide-${emailEventIcon(event.eventType)}`" class="size-3.5" :class="emailEventTextClass(event.eventType)" />
                          <span class="text-sm font-medium">{{ $t(`emailEventType.${event.eventType}`) }}</span>
                        </div>
                        <p class="text-xs text-(--ui-text-muted) mt-0.5">
                          {{ emailEventRelativeTime(log.sentAt, event.timestamp) }}
                          <span v-if="event.eventDetail"> · {{ event.eventDetail }}</span>
                        </p>
                        <!-- Bounce details -->
                        <div v-if="event.bounceType" class="mt-1.5 flex items-start gap-2 rounded bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 px-2.5 py-1.5">
                          <UIcon name="i-lucide-alert-triangle" class="size-3.5 shrink-0 text-red-500 mt-0.5" />
                          <span class="text-xs text-red-700 dark:text-red-300">{{ event.bounceType }}{{ event.bounceSubType ? ` / ${event.bounceSubType}` : '' }}</span>
                        </div>
                        <!-- Link clicked -->
                        <div v-if="event.linkClicked" class="mt-1 text-xs text-(--ui-text-muted) truncate">
                          <UIcon name="i-lucide-external-link" class="size-3 inline-block mr-1" />{{ event.linkClicked }}
                        </div>
                      </div>
                    </div>
                  </div>
                  <div v-else class="text-xs text-(--ui-text-muted) italic">{{ $t('emailAudit.noEvents') }}</div>
                </div>
              </div>
            </div>
            <UEmpty v-else icon="i-lucide-mail" :title="$t('invoices.noEmails')" class="py-8" />
          </UCard>
        </template>

        <template #attachments>
          <UCard class="mt-4">
            <div class="space-y-3">
              <div v-for="att in invoice.attachments" :key="att.id" class="flex items-center justify-between p-3 rounded border border-(--ui-border)">
                <div class="flex items-center gap-3">
                  <UIcon name="i-lucide-paperclip" class="text-(--ui-text-muted)" />
                  <div>
                    <div class="font-medium text-sm">{{ att.filename }}</div>
                    <div class="text-xs text-(--ui-text-muted)">{{ att.mimeType }} {{ att.size ? `(${formatSize(att.size)})` : '' }}</div>
                  </div>
                </div>
                <UButton icon="i-lucide-download" variant="ghost" size="sm" @click="downloadAttachment(att)">
                  {{ $t('common.download') }}
                </UButton>
              </div>
              <div v-if="!invoice.attachments?.length" class="text-center py-4 text-(--ui-text-muted)">{{ $t('invoices.noAttachments') }}</div>
            </div>
          </UCard>
        </template>
      </UTabs>

      <!-- Payment Modal -->
      <InvoicesPaymentModal
        v-if="invoice"
        v-model:open="paymentModalOpen"
        :invoice="invoice"
        @recorded="refreshInvoiceData"
      />

      <!-- Email Modal -->
      <InvoicesEmailModal
        v-if="invoice"
        v-model:open="emailModalOpen"
        :invoice="invoice"
        @sent="refreshInvoiceData"
      />
      <!-- Issue Modal -->
      <SharedConfirmModal
        v-model:open="issueModalOpen"
        :title="$t('invoices.issueConfirmTitle')"
        :description="issueModalDescription"
        icon="i-lucide-file-check"
        :confirm-label="$t('invoices.issue')"
        :loading="issuing"
        @confirm="onIssue"
      />

      <!-- Submit to SPV Modal -->
      <SharedConfirmModal
        v-model:open="submitModalOpen"
        :title="$t('invoices.submitConfirmTitle')"
        :description="$t('invoices.submitConfirmDescription')"
        icon="i-lucide-send"
        color="warning"
        :confirm-label="$t('invoices.submitToAnaf')"
        :loading="submitting"
        @confirm="onSubmit"
      >
        <div class="mt-3 flex items-start gap-2 rounded-md bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 p-3">
          <UIcon name="i-lucide-alert-triangle" class="size-4 shrink-0 text-amber-500 mt-0.5" />
          <p class="text-sm text-amber-700 dark:text-amber-300">{{ $t('invoices.submitConfirmWarning') }}</p>
        </div>
      </SharedConfirmModal>

      <!-- Mark Unpaid Modal -->
      <SharedConfirmModal
        v-model:open="markUnpaidModalOpen"
        :title="$t('invoices.markUnpaidConfirmTitle')"
        :description="$t('invoices.markUnpaidConfirmDescription')"
        icon="i-lucide-banknote"
        color="warning"
        :confirm-label="$t('invoices.markUnpaid')"
        :loading="updatingPayment"
        @confirm="togglePayment"
      />

      <!-- Cancel Modal -->
      <UModal v-model:open="cancelModalOpen">
        <template #header>
          <div class="flex items-center gap-2">
            <UIcon name="i-lucide-ban" class="size-5 shrink-0 text-amber-500" />
            <h3 class="font-semibold">{{ $t('invoices.cancelInvoice') }}</h3>
          </div>
        </template>
        <template #body>
          <div class="space-y-4">
            <!-- Invoice summary -->
            <div class="rounded-lg bg-(--ui-bg-elevated) p-4 space-y-2">
              <div class="flex items-center justify-between">
                <span class="font-semibold text-lg"><span v-if="invoice.invoiceTypeCode" class="text-xs font-bold text-primary tabular-nums mr-1">{{ invoiceTypeCodeShort[invoice.invoiceTypeCode] || '' }}</span>{{ invoice.number }}</span>
                <span class="font-semibold text-lg">{{ formatMoney(invoice.total, invoice.currency as any) }}</span>
              </div>
              <div class="flex items-center justify-between text-sm text-(--ui-text-muted)">
                <span>{{ invoice.clientName || invoice.receiverName }}</span>
                <span>{{ formatDate(invoice.issueDate) }}</span>
              </div>
              <div v-if="Number(invoice.amountPaid) > 0" class="flex items-center justify-between text-sm">
                <span class="text-(--ui-text-muted)">{{ $t('invoices.amountPaid') }}</span>
                <span class="text-green-600 dark:text-green-400 font-medium">{{ formatMoney(invoice.amountPaid, invoice.currency as any) }}</span>
              </div>
              <div v-if="invoice.anafUploadId" class="flex items-center gap-1.5 text-sm text-blue-600 dark:text-blue-400 pt-1">
                <UIcon name="i-lucide-cloud" class="size-3.5" />
                <span>{{ $t('invoices.sentToAnaf') }}</span>
              </div>
            </div>

            <!-- Warning -->
            <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 p-3 flex gap-2.5">
              <UIcon name="i-lucide-triangle-alert" class="size-4 shrink-0 text-amber-500 mt-0.5" />
              <p class="text-sm text-amber-800 dark:text-amber-200">
                {{ $t('invoices.cancelWarning') }}
              </p>
            </div>

            <!-- Reason -->
            <UFormField :label="$t('invoices.cancelReason')">
              <UTextarea v-model="cancelReason" :rows="3" :placeholder="$t('invoices.cancelReasonPlaceholder')" />
            </UFormField>
          </div>
        </template>
        <template #footer>
          <div class="flex justify-end gap-2">
            <UButton variant="ghost" @click="cancelModalOpen = false">{{ $t('common.cancel') }}</UButton>
            <UButton color="warning" :loading="cancelling" @click="onCancel">
              {{ $t('invoices.cancelInvoice') }}
            </UButton>
          </div>
        </template>
      </UModal>

      <!-- Delete Modal -->
      <SharedConfirmModal
        v-model:open="deleteModalOpen"
        :title="$t('invoices.deleteInvoice')"
        :description="$t('invoices.deleteConfirmDescription')"
        icon="i-lucide-trash-2"
        color="error"
        :confirm-label="$t('common.delete')"
        :loading="deleting"
        @confirm="onDelete"
      />

      <!-- PDF Preview Modal -->
      <UModal
        v-model:open="pdfModalOpen"
        fullscreen
        @after:leave="cleanupPdfPreview"
      >
        <template #content>
          <div class="flex flex-col h-screen">
            <div class="flex items-center justify-between p-4 border-b border-(--ui-border)">
              <h3 class="font-semibold">{{ $t('invoices.viewPdf') }}</h3>
              <div class="flex items-center gap-2">
                <UButton icon="i-lucide-download" variant="ghost" size="sm" @click="downloadPdf">
                  {{ $t('invoices.downloadPdf') }}
                </UButton>
                <UButton icon="i-lucide-x" variant="ghost" size="sm" @click="pdfModalOpen = false" />
              </div>
            </div>
            <iframe
              v-if="pdfPreviewUrl"
              :src="pdfPreviewUrl"
              class="flex-1 w-full border-0"
            />
          </div>
        </template>
      </UModal>

      <!-- Manual Copy Link Modal -->
      <UModal v-model:open="manualCopyModalOpen">
        <template #header>
          <div class="flex items-center gap-2">
            <UIcon name="i-lucide-link" class="size-5 shrink-0 text-(--ui-primary)" />
            <h3 class="font-semibold">{{ $t('invoices.shareLinkReady') }}</h3>
          </div>
        </template>
        <template #body>
          <div class="space-y-3">
            <p class="text-sm text-(--ui-text-muted)">{{ $t('invoices.shareLinkManualCopy') }}</p>
            <div class="flex items-center gap-2">
              <code class="flex-1 rounded bg-(--ui-bg-elevated) px-3 py-2 font-mono text-xs break-all select-all">{{ manualCopyUrl }}</code>
              <UButton icon="i-lucide-copy" variant="ghost" size="sm" @click="copyToClipboard(manualCopyUrl).catch(() => {})" />
            </div>
          </div>
        </template>
        <template #footer>
          <div class="flex justify-end">
            <UButton @click="manualCopyModalOpen = false">{{ $t('common.close') }}</UButton>
          </div>
        </template>
      </UModal>

      <!-- Edit Invoice Slideover -->
      <USlideover
        v-model:open="editSlideoverOpen"
        :ui="{ content: 'sm:max-w-2xl' }"
      >
        <template #header>
          <span class="text-lg font-semibold">{{ $t('invoices.editInvoice') }}</span>
        </template>
        <template #body>
          <InvoicesInvoiceForm
            v-if="editSlideoverOpen"
            :invoice="invoice"
            @saved="onEditSaved"
            @cancel="editSlideoverOpen = false"
          />
        </template>
      </USlideover>

      <!-- Refund Invoice Slideover -->
      <USlideover
        v-model:open="refundSlideoverOpen"
        :ui="{ content: 'sm:max-w-2xl' }"
      >
        <template #header>
          <span class="text-lg font-semibold">{{ $t('invoices.createRefund') }}</span>
        </template>
        <template #body>
          <InvoicesInvoiceForm
            v-if="refundSlideoverOpen"
            :refund-of="invoice.id"
            @saved="onRefundSaved"
            @cancel="refundSlideoverOpen = false"
          />
        </template>
      </USlideover>

      <!-- Copy Invoice Slideover -->
      <USlideover
        v-model:open="copySlideoverOpen"
        :ui="{ content: 'sm:max-w-2xl' }"
      >
        <template #header>
          <span class="text-lg font-semibold">{{ $t('invoices.copyInvoice') }}</span>
        </template>
        <template #body>
          <InvoicesInvoiceForm
            v-if="copySlideoverOpen"
            :copy-of="invoice.id"
            @saved="onCopySaved"
            @cancel="copySlideoverOpen = false"
          />
        </template>
      </USlideover>
    </div>
    <div v-else class="text-center py-20">
      <USkeleton class="h-8 w-64 mx-auto mb-4" />
      <USkeleton class="h-4 w-48 mx-auto" />
    </div>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t, locale } = useI18n()
const route = useRoute()
const invoiceStore = useInvoiceStore()
const { get: apiGet, post: apiPost } = useApi()
const { formatMoney } = useMoney()
const { formatDate, formatDateTime } = useDate()
const { formatAndHighlight } = useXmlFormatter()

const invoice = ref<any>(null)

useHead({ title: computed(() => invoice.value ? `${$t('invoices.title')} ${invoice.value.number}` : $t('invoices.title')) })
const events = ref<any[]>([])
const xmlContent = ref<string | null>(null)
const loadingXml = ref(false)
const activeTab = ref('0')
const copied = ref(false)
const downloadingPdf = ref(false)
const viewingPdf = ref(false)
const pdfModalOpen = ref(false)
const pdfPreviewUrl = ref<string | null>(null)
const verifyingSignature = ref(false)
const signatureResult = ref<{ valid: boolean, message: string } | null>(null)
const updatingPayment = ref(false)
const paymentModalOpen = ref(false)
const payments = ref<any[]>([])
const emailModalOpen = ref(false)
const emailLogs = ref<any[]>([])
const issueModalOpen = ref(false)
const issuing = ref(false)
const submitModalOpen = ref(false)
const markUnpaidModalOpen = ref(false)
const submitting = ref(false)
const cancelModalOpen = ref(false)
const cancelling = ref(false)
const cancelReason = ref('')
const deleteModalOpen = ref(false)
const deleting = ref(false)
const editSlideoverOpen = ref(false)
const refundSlideoverOpen = ref(false)
const copySlideoverOpen = ref(false)
const stripeConnected = ref(false)
const creatingPaymentLink = ref(false)
const paymentLinkCopied = ref(false)
const manualCopyModalOpen = ref(false)
const manualCopyUrl = ref('')
const { copy: copyToClipboard } = useClipboard()
const validating = ref(false)
const validationResult = ref<any>(null)
const autoValidated = ref(false)

// Scheduled email is blocked when invoice was submitted to ANAF but not yet validated
const isEmailBlocked = computed(() => {
  if (!invoice.value?.scheduledEmailAt) return false
  return !!invoice.value.anafUploadId && invoice.value.status !== 'validated'
})

async function cancelScheduledEmail() {
  try {
    const { post } = useApi()
    await post(`/v1/invoices/${route.params.uuid}/cancel-scheduled-email`)
    invoice.value.scheduledEmailAt = null
    useToast().add({ title: $t('invoices.cancelScheduledEmailSuccess'), color: 'success' })
  } catch {
    useToast().add({ title: $t('invoices.updateError'), color: 'error' })
  }
}

function localizeValidationMessage(message: string): string {
  const hashIndex = message.indexOf('#')
  if (hashIndex === -1) return message
  const parts = [message.substring(0, hashIndex).trim(), message.substring(hashIndex + 1).trim()]
  return locale.value === 'en' ? parts[1] : parts[0]
}

const downloadMenuItems = computed(() => [
  [
    { label: $t('invoices.viewPdf'), icon: 'i-lucide-eye', onSelect: () => viewPdf() },
    { label: $t('invoices.downloadPdf'), icon: 'i-lucide-download', onSelect: () => downloadPdf() },
    { label: $t('invoices.downloadXml'), icon: 'i-lucide-file-code', onSelect: () => downloadXml() },
    { label: $t('invoices.downloadSignature'), icon: 'i-lucide-file-check', onSelect: () => downloadSignature() },
  ],
])

const moreActionsItems = computed(() => {
  const editGroup: any[] = []
  const statusGroup: any[] = []
  const dangerGroup: any[] = []

  // Edit
  if (invoice.value && invoice.value.status !== 'cancelled' && invoice.value.status !== 'sent_to_provider' && (!invoice.value.anafUploadId || invoice.value.status === 'rejected')) {
    editGroup.push({ label: $t('common.edit'), icon: 'i-lucide-pencil', onSelect: () => openEditSlideover() })
  }

  // Copy
  editGroup.push({ label: $t('common.copy'), icon: 'i-lucide-copy', onSelect: () => openCopySlideover() })

  // Storno
  if (invoice.value && ['issued', 'sent_to_provider', 'validated', 'synced'].includes(invoice.value.status) && invoice.value.direction === 'outgoing' && !invoice.value.cancelledAt && !invoice.value.parentDocumentId && !invoice.value.refundInvoices?.length) {
    statusGroup.push({ label: $t('invoices.stornoInvoice'), icon: 'i-lucide-file-minus', onSelect: () => openRefundSlideover() })
  }

  // Cancel (hide if already uploaded to ANAF)
  if (invoice.value && ((invoice.value.status === 'draft') || (invoice.value.status === 'issued' && !invoice.value.cancelledAt && !invoice.value.anafUploadId))) {
    dangerGroup.push({ label: $t('invoices.cancelInvoice'), icon: 'i-lucide-ban', onSelect: () => { cancelModalOpen.value = true } })
  }

  // Delete (draft only)
  if (invoice.value && invoice.value.status === 'draft') {
    dangerGroup.push({ label: $t('invoices.deleteInvoice'), icon: 'i-lucide-trash-2', color: 'error', onSelect: () => { deleteModalOpen.value = true } })
  }

  const items: any[][] = []
  if (editGroup.length) items.push(editGroup)
  if (statusGroup.length) items.push(statusGroup)
  if (dangerGroup.length) items.push(dangerGroup)
  return items
})

const expandedEmails = ref<Set<string>>(new Set())

function toggleEmailExpanded(id: string) {
  const s = new Set(expandedEmails.value)
  if (s.has(id)) s.delete(id)
  else s.add(id)
  expandedEmails.value = s
}

function emailStatusColor(status: string): 'info' | 'success' | 'warning' | 'error' | 'neutral' {
  const map: Record<string, 'info' | 'success' | 'warning' | 'error' | 'neutral'> = { sent: 'info', delivered: 'success', bounced: 'warning', failed: 'error' }
  return map[status] || 'neutral'
}

function emailEventDotClass(eventType: string) {
  const map: Record<string, string> = {
    send: 'bg-blue-500', delivery: 'bg-green-500', bounce: 'bg-orange-500',
    complaint: 'bg-red-500', reject: 'bg-red-500', open: 'bg-sky-500', click: 'bg-sky-500',
  }
  return map[eventType] || 'bg-(--ui-text-muted)'
}

function emailEventTextClass(eventType: string) {
  const map: Record<string, string> = {
    send: 'text-blue-500', delivery: 'text-green-500', bounce: 'text-orange-500',
    complaint: 'text-red-500', reject: 'text-red-500', open: 'text-sky-500', click: 'text-sky-500',
  }
  return map[eventType] || 'text-(--ui-text-muted)'
}

function emailEventIcon(eventType: string) {
  const map: Record<string, string> = {
    send: 'send', delivery: 'check-circle', bounce: 'alert-triangle',
    complaint: 'flag', reject: 'x-circle', open: 'eye', click: 'mouse-pointer-click',
  }
  return map[eventType] || 'circle'
}

function emailEventRelativeTime(sentAt: string, eventTimestamp: string) {
  const sent = new Date(sentAt).getTime()
  const event = new Date(eventTimestamp).getTime()
  const diffMs = event - sent

  if (diffMs < 1000) return formatDateTime(eventTimestamp)

  const diffMin = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMs / 3600000)
  const diffDays = Math.floor(diffMs / 86400000)

  let relative = ''
  if (diffDays > 0) {
    const remainHours = Math.floor((diffMs % 86400000) / 3600000)
    relative = `+${diffDays}z${remainHours > 0 ? ` ${remainHours}h` : ''}`
  } else if (diffHours > 0) {
    const remainMin = Math.floor((diffMs % 3600000) / 60000)
    relative = `+${diffHours}h${remainMin > 0 ? ` ${remainMin}min` : ''}`
  } else {
    relative = `+${diffMin}min`
  }

  return `${formatDateTime(eventTimestamp)} (${relative})`
}

const companyStore = useCompanyStore()

const isOverdue = computed(() => {
  if (!invoice.value?.dueDate || invoice.value.paidAt) return false
  return new Date(invoice.value.dueDate) < new Date()
})

const issueModalDescription = computed(() => {
  const base = $t('invoices.issueConfirmDescription')
  const delayHours = companyStore.currentCompany?.efacturaDelayHours
  if (delayHours) {
    const label = delayHours < 24
      ? $t('companies.efacturaDelayOptions.2')
      : $t('companies.efacturaDelayOptions.' + delayHours)
    return `${base}\n\n${$t('invoices.autoSubmitAfter', { delay: label })}`
  }
  return base
})

const tabs = computed(() => {
  const t = [
    { label: $t('invoices.details'), slot: 'details' },
    { label: `${$t('invoices.payments')} (${payments.value.length})`, slot: 'payments' },
    { label: `${$t('invoices.emails')} (${emailLogs.value.length})`, slot: 'emails' },
    { label: 'XML', slot: 'xml' },
    { label: $t('invoices.events'), slot: 'events' },
  ]
  if (invoice.value?.attachments?.length) {
    t.push({ label: `${$t('invoices.attachments')} (${invoice.value.attachments.length})`, slot: 'attachments' })
  }
  return t
})

const lineColumns = [
  { accessorKey: 'description', header: $t('invoices.lineDescription') },
  { accessorKey: 'quantity', header: $t('invoices.quantity') },
  { accessorKey: 'unitOfMeasure', header: $t('invoices.unit') },
  { accessorKey: 'unitPrice', header: $t('invoices.unitPrice') },
  { accessorKey: 'vatRate', header: 'TVA %' },
  { accessorKey: 'vatAmount', header: $t('invoices.vatTotal') },
  { accessorKey: 'lineTotal', header: $t('invoices.total') },
]


const highlightedXml = computed(() => {
  if (!xmlContent.value) return ''
  return formatAndHighlight(xmlContent.value)
})

const statusIconMap: Record<string, string> = {
  synced: 'i-lucide-refresh-cw',
  validated: 'i-lucide-check-circle',
  rejected: 'i-lucide-x-circle',
  sent_to_provider: 'i-lucide-send',
  draft: 'i-lucide-file-edit',
  issued: 'i-lucide-file-check',
  paid: 'i-lucide-banknote',
  cancelled: 'i-lucide-ban',
}

function getEventTitle(event: any): string {
  const meta = event.metadata
  if (meta?.action === 'created') return $t('invoices.eventCreated')
  if (meta?.action === 'created_from_recurring') return $t('invoices.eventCreatedFromRecurring')
  if (meta?.action === 'submitted') return $t('invoices.eventSubmitted')
  if (meta?.action === 'cancelled') return $t('invoices.eventCancelled')
  if (meta?.action === 'submitted_to_anaf') return $t('invoices.eventSentToAnaf')
  if (meta?.action === 'anaf_validated') return $t('invoices.eventAnafValidated')
  if (meta?.action === 'anaf_rejected') return $t('invoices.eventAnafRejected')
  if (event.previousStatus) {
    return `${$t(`documentStatus.${event.previousStatus}`)} → ${$t(`documentStatus.${event.newStatus}`)}`
  }
  return $t(`documentStatus.${event.newStatus}`)
}

function getEventDetail(event: any): string | null {
  const meta = event.metadata
  if (!meta) return null
  if (meta.action === 'cancelled' && meta.reason) return meta.reason
  if (meta.action === 'anaf_rejected' && meta.error) return meta.error
  if (meta.action === 'submitted' && meta.scheduledSendAt) {
    return $t('invoices.scheduledSendAt', { date: formatDateTime(meta.scheduledSendAt) })
  }
  if (meta.action === 'issued' && meta.efacturaDelayHours) {
    return $t('invoices.autoSubmitScheduled')
  }
  if (meta.action === 'created_from_recurring' && meta.recurringInvoiceReference) {
    return meta.recurringInvoiceReference
  }
  return null
}

const timelineItems = computed(() => {
  return events.value.map((event: any) => {
    const title = getEventTitle(event)
    const detail = getEventDetail(event)

    const parts: string[] = [formatDateTime(event.createdAt)]
    if (event.createdBy) parts.push(event.createdBy)
    if (detail) parts.push(detail)

    return {
      title,
      description: parts.join(' · '),
      icon: statusIconMap[event.newStatus] || 'i-lucide-circle',
    }
  })
})

function directionColor(dir: string) { return dir === 'incoming' ? 'blue' : 'green' }
function statusColor(status: string) {
  const map: Record<string, string> = { draft: 'neutral', issued: 'info', synced: 'info', validated: 'success', rejected: 'error', sent_to_provider: 'warning', cancelled: 'neutral', refund: 'warning', refunded: 'warning' }
  return map[status] || 'neutral'
}

const signatureIcon = computed(() => {
  if (signatureResult.value?.valid) return 'i-lucide-shield-check'
  if (signatureResult.value && !signatureResult.value.valid) return 'i-lucide-shield-x'
  return 'i-lucide-shield'
})

const signatureColor = computed(() => {
  if (signatureResult.value?.valid) return 'success' as const
  if (signatureResult.value && !signatureResult.value.valid) return 'error' as const
  return 'neutral' as const
})

const signatureLabel = computed(() => {
  if (signatureResult.value?.valid) return $t('invoices.signatureValid')
  if (signatureResult.value && !signatureResult.value.valid) return $t('invoices.signatureInvalid')
  return $t('invoices.verifySignature')
})

function openEditSlideover() {
  editSlideoverOpen.value = true
}

function openRefundSlideover() {
  refundSlideoverOpen.value = true
}

function openCopySlideover() {
  copySlideoverOpen.value = true
}

async function onCopySaved(newInvoice: any, _validation: any) {
  copySlideoverOpen.value = false
  useToast().add({
    title: $t('invoices.createSuccess'),
    color: 'success',
    icon: 'i-lucide-check',
  })
  navigateTo(`/invoices/${newInvoice.id}`)
}

async function onEditSaved(_invoice: any, validation: any) {
  editSlideoverOpen.value = false
  useToast().add({
    title: $t('invoices.updateSuccess'),
    color: 'success',
    icon: 'i-lucide-check',
  })
  await refreshInvoiceData()
  // Use validation from save response directly (no need to re-validate)
  if (validation) {
    validationResult.value = validation
    autoValidated.value = true
  }
}

async function onRefundSaved(newInvoice: any, _validation: any) {
  refundSlideoverOpen.value = false
  useToast().add({
    title: $t('invoices.createSuccess'),
    color: 'success',
    icon: 'i-lucide-check',
  })
  navigateTo(`/invoices/${newInvoice.id}`)
}

async function copyPaymentLink() {
  if (!invoice.value) return
  creatingPaymentLink.value = true

  try {
    // Check for existing active share link first
    const response = await apiGet<any>(`/v1/invoices/${route.params.uuid}/share-links`)
    const links = Array.isArray(response) ? response : []
    let url: string | null = null

    const activeLink = links.find((l: any) => l.isValid)
    if (activeLink) {
      url = activeLink.url
    } else {
      // Create a new share link (payment auto-enabled by backend if Connect is active)
      const newLink = await apiPost<{ url: string }>(`/v1/invoices/${route.params.uuid}/share-links`, {})
      url = newLink.url
    }

    if (url) {
      try {
        await copyToClipboard(url)
        paymentLinkCopied.value = true
        setTimeout(() => { paymentLinkCopied.value = false }, 2000)
      } catch {
        // Clipboard failed — show modal for manual copy
        manualCopyUrl.value = url
        manualCopyModalOpen.value = true
      }
    }
  } catch (err) {
    console.error('copyPaymentLink failed:', err)
    useToast().add({ title: $t('invoices.shareLinkError'), color: 'error' })
  } finally {
    creatingPaymentLink.value = false
  }
}

async function togglePayment() {
  if (!invoice.value) return
  updatingPayment.value = true
  const ok = await invoiceStore.deleteAllPayments(route.params.uuid as string)
  if (ok) {
    markUnpaidModalOpen.value = false
    await refreshInvoiceData()
    useToast().add({ title: $t('invoices.paymentUpdated'), color: 'success' })
  }
  else {
    useToast().add({ title: invoiceStore.error || $t('invoices.paymentError'), color: 'error' })
  }
  updatingPayment.value = false
}

async function autoValidate() {
  validating.value = true
  validationResult.value = null
  const result = await invoiceStore.validateInvoice(route.params.uuid as string, 'full')
  validating.value = false
  if (result) {
    validationResult.value = result
  }
  return result
}

async function onIssue() {
  issuing.value = true
  issueModalOpen.value = false

  // Auto-validate before issuing
  const validation = await autoValidate()
  if (!validation?.valid) {
    issuing.value = false
    useToast().add({
      title: $t('invoices.validationFailed'),
      description: $t('invoices.fixErrorsBeforeSubmit'),
      color: 'error',
      icon: 'i-lucide-circle-x',
    })
    return
  }

  const result = await invoiceStore.issueInvoice(route.params.uuid as string)
  issuing.value = false
  if (result) {
    const description = result.scheduledSendAt
      ? $t('invoices.scheduledSendAt', { date: formatDateTime(result.scheduledSendAt) })
      : undefined
    useToast().add({
      title: $t('invoices.issueSuccess'),
      description,
      color: 'success',
      icon: 'i-lucide-file-check',
    })
    await refreshInvoiceData()
  }
  else {
    useToast().add({ title: $t('invoices.issueError'), color: 'error' })
  }
}

async function onSubmit() {
  submitting.value = true
  submitModalOpen.value = false

  // Auto-validate before submitting to ANAF
  const validation = await autoValidate()
  if (!validation?.valid) {
    submitting.value = false
    useToast().add({
      title: $t('invoices.validationFailed'),
      description: $t('invoices.fixErrorsBeforeSubmit'),
      color: 'error',
      icon: 'i-lucide-circle-x',
    })
    return
  }

  const result = await invoiceStore.submitInvoice(route.params.uuid as string)
  submitting.value = false
  if (result) {
    useToast().add({
      title: $t('invoices.submitSuccess'),
      color: 'success',
      icon: 'i-lucide-send',
    })
    await refreshInvoiceData()
  }
  else {
    useToast().add({
      title: $t('invoices.submitError'),
      description: invoiceStore.error || undefined,
      color: 'error',
      icon: 'i-lucide-circle-x',
    })
  }
}

async function onCancel() {
  cancelling.value = true
  const result = await invoiceStore.cancelInvoice(route.params.uuid as string, cancelReason.value || undefined)
  cancelling.value = false
  if (result) {
    cancelModalOpen.value = false
    cancelReason.value = ''
    useToast().add({ title: $t('invoices.cancelSuccess'), color: 'success' })
    await refreshInvoiceData()
  }
  else {
    useToast().add({ title: $t('invoices.cancelError'), color: 'error' })
  }
}

async function onDelete() {
  deleting.value = true
  const ok = await invoiceStore.deleteInvoice(route.params.uuid as string)
  deleting.value = false
  if (ok) {
    deleteModalOpen.value = false
    useToast().add({ title: $t('invoices.deleteSuccess'), color: 'success' })
    navigateTo('/invoices')
  }
  else {
    useToast().add({ title: $t('invoices.deleteError'), color: 'error' })
  }
}

async function downloadAttachment(att: any) {
  const { apiFetch } = useApi()
  try {
    const blob = await apiFetch<Blob>(`/v1/invoices/${route.params.uuid}/attachments/${att.id}`, {
      responseType: 'blob',
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = att.filename
    a.click()
    URL.revokeObjectURL(url)
  }
  catch {
    useToast().add({ title: $t('invoices.downloadAttachment') + ' failed', color: 'error' })
  }
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

async function verifySignature() {
  const { post } = useApi()
  verifyingSignature.value = true
  try {
    const result = await post<{ valid: boolean, message: string }>(`/v1/invoices/${route.params.uuid}/verify-signature`)
    signatureResult.value = result
    useToast().add({
      title: result.valid ? $t('invoices.signatureValid') : $t('invoices.signatureInvalid'),
      color: result.valid ? 'success' : 'error',
    })
  } catch {
    signatureResult.value = { valid: false, message: '' }
    useToast().add({ title: $t('invoices.noSignature'), color: 'warning' })
  } finally {
    verifyingSignature.value = false
  }
}

async function downloadXml() {
  const { get } = useApi()
  try {
    const xml = await get<string>(`/v1/invoices/${route.params.uuid}/xml`)
    const blob = new Blob([xml], { type: 'application/xml' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `invoice-${invoice.value?.number || 'download'}.xml`
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    useToast().add({ title: $t('invoices.noXml'), color: 'error' })
  }
}

async function downloadSignature() {
  const { get } = useApi()
  try {
    const xml = await get<string>(`/v1/invoices/${route.params.uuid}/signature`)
    const blob = new Blob([xml], { type: 'application/xml' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `signature-${invoice.value?.number || 'download'}.xml`
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    useToast().add({ title: $t('invoices.noSignature'), color: 'warning' })
  }
}

async function downloadAnafResponse() {
  const { get } = useApi()
  try {
    const data = await get<Blob>(`/v1/invoices/${route.params.uuid}/anaf-response`, { responseType: 'blob' })
    const url = URL.createObjectURL(data)
    const a = document.createElement('a')
    a.href = url
    a.download = `anaf-response-${invoice.value?.number || 'download'}.zip`
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    useToast().add({ title: $t('invoices.anafResponseUnavailable'), color: 'error' })
  }
}

function cleanupPdfPreview() {
  if (pdfPreviewUrl.value) {
    URL.revokeObjectURL(pdfPreviewUrl.value)
    pdfPreviewUrl.value = null
  }
}

async function viewPdf() {
  const { apiFetch } = useApi()
  viewingPdf.value = true
  try {
    const blob = await apiFetch<Blob>(`/v1/invoices/${route.params.uuid}/pdf`, {
      responseType: 'blob',
    })

    if (blob instanceof Blob && blob.type === 'application/json') {
      useToast().add({
        title: $t('invoices.pdfGenerating'),
        description: $t('invoices.pdfGeneratingDescription'),
        icon: 'i-lucide-loader-2',
        color: 'info',
      })
      return
    }

    if (pdfPreviewUrl.value) {
      URL.revokeObjectURL(pdfPreviewUrl.value)
    }
    pdfPreviewUrl.value = URL.createObjectURL(blob)
    pdfModalOpen.value = true
  } catch {
    useToast().add({ title: $t('invoices.pdfError'), color: 'error' })
  } finally {
    viewingPdf.value = false
  }
}

async function downloadPdf() {
  const { apiFetch } = useApi()
  downloadingPdf.value = true
  try {
    const blob = await apiFetch<Blob>(`/v1/invoices/${route.params.uuid}/pdf`, {
      responseType: 'blob',
    })

    // Backend returns JSON with 202 when PDF is being generated asynchronously
    if (blob instanceof Blob && blob.type === 'application/json') {
      useToast().add({
        title: $t('invoices.pdfGenerating'),
        description: $t('invoices.pdfGeneratingDescription'),
        icon: 'i-lucide-loader-2',
        color: 'info',
      })
      return
    }

    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `invoice-${invoice.value?.number || 'download'}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    useToast().add({ title: $t('invoices.pdfError'), color: 'error' })
  } finally {
    downloadingPdf.value = false
  }
}

async function copyXml() {
  if (!xmlContent.value) return
  try {
    await navigator.clipboard.writeText(xmlContent.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    useToast().add({ title: 'Copy failed', color: 'error' })
  }
}

async function fetchXmlContent() {
  loadingXml.value = true
  try {
    const { get } = useApi()
    xmlContent.value = await get<string>(`/v1/invoices/${route.params.uuid}/xml`)
  } catch {
    xmlContent.value = null
  } finally {
    loadingXml.value = false
  }
}

// Fetch XML when switching to the XML tab (index 3 now, after payments and emails)
watch(activeTab, (newTab) => {
  if (newTab === '3' && xmlContent.value === null && !loadingXml.value) {
    fetchXmlContent()
  }
})

async function refreshInvoiceData() {
  const uuid = route.params.uuid as string
  const [inv, evts, pmts, logs] = await Promise.all([
    invoiceStore.fetchInvoice(uuid),
    invoiceStore.fetchInvoiceEvents(uuid),
    invoiceStore.fetchPayments(uuid),
    invoiceStore.fetchEmailLogs(uuid),
  ])
  invoice.value = inv
  events.value = evts
  payments.value = pmts
  emailLogs.value = logs

  // Invalidate cached XML so it's re-fetched when the XML tab is viewed
  xmlContent.value = null
  if (activeTab.value === '3') {
    fetchXmlContent()
  }
}

const invoiceRealtime = useInvoiceRealtime(() => refreshInvoiceData())

onMounted(async () => {
  await refreshInvoiceData()

  // Stripe Connect status from company store (no extra API call)
  const sc = useCompanyStore().currentCompany?.stripeConnect
  stripeConnected.value = !!sc?.connected && sc?.chargesEnabled !== false

  // Restore persisted signature verification result
  if (invoice.value?.signatureValid !== null && invoice.value?.signatureValid !== undefined) {
    signatureResult.value = { valid: invoice.value.signatureValid, message: '' }
  }

  // Auto-validate draft invoices on load
  if (invoice.value?.status === 'draft' && !autoValidated.value) {
    autoValidated.value = true
    autoValidate()
  }

  invoiceRealtime.start()
})

onUnmounted(() => {
  invoiceRealtime.stop()
})
</script>
