<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('companies.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-plus" @click="openCreate">{{ $t('companies.addCompany') }}</UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UPageHeader :title="$t('companies.title')" :description="$t('companies.description')" />

      <div v-if="loading" class="text-center py-12">
        <UIcon name="i-lucide-loader-2" class="animate-spin h-8 w-8 mx-auto text-muted" />
      </div>

      <div v-else-if="storeError" class="text-center py-12">
        <div class="text-error">{{ storeError }}</div>
      </div>

      <UEmpty v-else-if="!companies.length" icon="i-lucide-building-2" :title="$t('companies.noCompanies')" :description="$t('companies.noCompaniesDesc')">
        <template #actions>
          <UButton icon="i-lucide-plus" @click="openCreate">{{ $t('companies.addCompany') }}</UButton>
        </template>
      </UEmpty>

      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <UCard
          v-for="company in companies"
          :key="company.id"
          class="h-full cursor-pointer transition-shadow hover:shadow-md"
          :class="{ 'ring-2 ring-primary': company.id === companyStore.currentCompanyId }"
          :ui="{ root: 'flex flex-col', body: 'flex-1' }"
          @click="switchCompany(company)"
        >
          <div class="flex flex-col h-full gap-3">
            <div class="flex items-center justify-between">
              <div>
                <h3 class="font-semibold text-lg">{{ company.name }}</h3>
                <div class="text-sm text-muted">{{ $t('companies.cif') }}: {{ company.cif }}</div>
              </div>
              <UIcon
                v-if="company.id === companyStore.currentCompanyId"
                name="i-lucide-check-circle"
                class="size-5 text-primary shrink-0"
              />
            </div>

            <div class="flex items-center gap-2">
              <UBadge
                :color="company.syncEnabled ? 'success' : 'neutral'"
                variant="subtle"
              >
                {{ company.syncEnabled ? $t('companies.syncEnabled') : $t('companies.syncDisabled') }}
              </UBadge>
            </div>

            <div v-if="company.lastSyncedAt" class="text-xs text-muted">
              {{ $t('companies.lastSynced') }}: {{ formatDate(company.lastSyncedAt) }}
            </div>

            <div class="mt-auto pt-2">
              <UButton
                variant="outline"
                block
                @click.stop="openEdit(company)"
              >
                {{ $t('companies.edit') }}
              </UButton>
            </div>
          </div>
        </UCard>
      </div>

      <!-- Pending Deletion Section -->
      <div v-if="deletedCompanies.length > 0" class="mt-8">
        <h3 class="text-lg font-semibold text-warning-600 dark:text-warning-400 mb-4">
          <UIcon name="i-lucide-clock" class="size-5 inline-block mr-1" />
          {{ $t('companies.pendingDeletion') }}
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          <UCard v-for="company in deletedCompanies" :key="company.id" class="border border-warning-300 dark:border-warning-700">
            <div class="space-y-3">
              <div>
                <h3 class="font-semibold text-lg">{{ company.name }}</h3>
                <div class="text-sm text-muted">{{ $t('companies.cif') }}: {{ company.cif }}</div>
              </div>
              <div class="text-sm text-warning-600 dark:text-warning-400 font-medium">
                {{ $t('companies.daysRemaining', getDaysRemaining(company.hardDeleteAt), { count: getDaysRemaining(company.hardDeleteAt) }) }}
              </div>
              <UButton
                icon="i-lucide-undo-2"
                color="warning"
                variant="soft"
                block
                :loading="restoring === company.id"
                @click="handleRestore(company.id)"
              >
                {{ $t('companies.restore') }}
              </UButton>
            </div>
          </UCard>
        </div>
      </div>

      <!-- Create Company Slideover -->
      <USlideover v-model:open="createOpen" :ui="{ content: 'sm:max-w-lg' }">
        <template #header>
          <h3 class="text-lg font-semibold">{{ $t('companies.newCompany') }}</h3>
        </template>
        <template #body>
          <UForm
            :state="createState"
            :schema="createSchema"
            @submit="onCreateSubmit"
          >
            <div class="space-y-4">
              <div class="relative">
                <!-- Click-outside overlay to close registry dropdown -->
                <div
                  v-if="registryDropdownOpen"
                  class="fixed inset-0 z-[199]"
                  @click="registryDropdownOpen = false"
                />
                <UFormField name="cif" :label="$t('companies.cifLabel')">
                  <UInput
                    v-model="createState.cif"
                    :placeholder="$t('companies.cifSearchPlaceholder')"
                    icon="i-lucide-search"
                    size="xl"
                    class="w-full"
                    :loading="registryLoading"
                    :disabled="creating"
                    @input="onCifInput"
                  />
                </UFormField>
                <!-- Registry search dropdown -->
                <div
                  v-if="registryDropdownOpen && registryResults.length > 0"
                  class="absolute left-0 right-0 top-full z-[200] mt-1 max-h-60 overflow-y-auto rounded-md border border-(--ui-border) bg-(--ui-bg) shadow-lg"
                >
                  <button
                    v-for="r in registryResults"
                    :key="r.cod_unic"
                    type="button"
                    class="flex w-full items-start gap-3 px-3 py-2 text-left hover:bg-(--ui-bg-elevated) transition-colors"
                    @mousedown.prevent="selectRegistryCompany(r)"
                  >
                    <span
                      class="mt-1.5 size-2 shrink-0 rounded-full"
                      :class="r.radiat ? 'bg-red-500' : 'bg-green-500'"
                    />
                    <div class="min-w-0 flex-1">
                      <div class="truncate text-sm font-medium text-(--ui-text)">{{ r.denumire }}</div>
                      <div class="truncate text-xs text-(--ui-text-muted)">
                        {{ r.cod_unic }}
                        <span v-if="r.localitate"> &middot; {{ r.localitate }}</span>
                      </div>
                    </div>
                  </button>
                </div>
              </div>

              <div class="text-sm text-(--ui-text-muted)">
                {{ $t('companies.cifHelp') }}
              </div>

              <div v-if="createError && !showUpgrade" class="text-sm text-error">
                {{ createError }}
              </div>

              <UButton
                type="submit"
                :loading="creating"
                :disabled="creating"
              >
                {{ $t('companies.create') }}
              </UButton>
            </div>
          </UForm>
        </template>
      </USlideover>

      <!-- Edit Company Slideover -->
      <USlideover v-model:open="editOpen" :ui="{ content: 'sm:max-w-2xl' }">
        <template #header>
          <h3 class="text-lg font-semibold">{{ editCompany?.name || $t('companies.editCompany') }}</h3>
        </template>
        <template #body>
          <div v-if="editCompany" class="space-y-6">
            <!-- Company Info -->
            <div>
              <div class="flex items-center justify-between mb-3">
                <h4 class="text-sm font-semibold text-(--ui-text-muted) uppercase tracking-wide">{{ $t('companies.companyInfo') }}</h4>
                <div class="flex items-center gap-1">
                  <UButton
                    v-if="editingCompanyInfo"
                    icon="i-lucide-refresh-cw"
                    variant="ghost"
                    size="xs"
                    :loading="refreshingAnaf"
                    @click="handleRefreshAnaf"
                  >
                    {{ $t('companies.refreshAnaf') }}
                  </UButton>
                  <UButton
                    :icon="editingCompanyInfo ? 'i-lucide-x' : 'i-lucide-pencil'"
                    variant="ghost"
                    size="xs"
                    @click="toggleEditCompanyInfo"
                  >
                    {{ editingCompanyInfo ? $t('common.cancel') : $t('common.edit') }}
                  </UButton>
                </div>
              </div>

              <!-- Read-only view -->
              <div v-if="!editingCompanyInfo" class="space-y-2">
                <!-- Identity -->
                <div class="grid grid-cols-3 gap-x-4 gap-y-1.5">
                  <div>
                    <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.cifLabel') }}</div>
                    <div class="text-sm font-medium">{{ editCompany.cif }}</div>
                  </div>
                  <div v-if="editCompany.registrationNumber">
                    <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.registrationNumberLabel') }}</div>
                    <div class="text-sm font-medium">{{ editCompany.registrationNumber }}</div>
                  </div>
                  <div v-if="editCompany.capitalSocial">
                    <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.capitalSocialLabel') }}</div>
                    <div class="text-sm font-medium">{{ editCompany.capitalSocial }}</div>
                  </div>
                </div>
                <div>
                  <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.nameLabel') }}</div>
                  <div class="text-sm font-medium">{{ editCompany.name }}</div>
                </div>
                <!-- Tax -->
                <div class="flex flex-wrap items-center gap-2 pt-1">
                  <UBadge :color="editCompany.vatPayer ? 'success' : 'neutral'" variant="subtle" size="sm">
                    {{ $t('companies.vatPayerLabel') }}: {{ editCompany.vatPayer ? $t('common.yes') : $t('common.no') }}
                  </UBadge>
                  <UBadge v-if="editCompany.vatCode" variant="subtle" size="sm">{{ editCompany.vatCode }}</UBadge>
                  <UBadge v-if="editCompany.vatOnCollection" color="warning" variant="subtle" size="sm">{{ $t('companies.vatOnCollectionLabel') }}</UBadge>
                  <UBadge v-if="editCompany.oss" color="info" variant="subtle" size="sm">{{ $t('companies.ossLabel') }}</UBadge>
                </div>
                <div v-if="editCompany.vatIn || editCompany.eoriCode" class="grid grid-cols-3 gap-x-4 gap-y-1.5">
                  <div v-if="editCompany.vatIn">
                    <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.vatInLabel') }}</div>
                    <div class="text-sm font-medium">{{ editCompany.vatIn }}</div>
                  </div>
                  <div v-if="editCompany.eoriCode">
                    <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.eoriCodeLabel') }}</div>
                    <div class="text-sm font-medium">{{ editCompany.eoriCode }}</div>
                  </div>
                </div>
                <!-- Location -->
                <div class="grid grid-cols-3 gap-x-4 gap-y-1.5 pt-1">
                  <div>
                    <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.countryLabel') }}</div>
                    <div class="text-sm font-medium">{{ editCompany.country }}</div>
                  </div>
                  <div>
                    <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.stateLabel') }}</div>
                    <div class="text-sm font-medium">{{ editCompany.state }}</div>
                  </div>
                  <div>
                    <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.cityLabel') }}</div>
                    <div class="text-sm font-medium">{{ editCompany.city }}</div>
                  </div>
                </div>
                <div v-if="editCompany.address">
                  <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.addressLabel') }}</div>
                  <div class="text-sm font-medium">{{ editCompany.address }}</div>
                </div>
                <!-- Contact -->
                <div v-if="editCompany.phone || editCompany.email || editCompany.website" class="grid grid-cols-3 gap-x-4 gap-y-1.5 pt-1">
                  <div v-if="editCompany.phone">
                    <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.phoneLabel') }}</div>
                    <div class="text-sm font-medium">{{ editCompany.phone }}</div>
                  </div>
                  <div v-if="editCompany.email">
                    <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.emailLabel') }}</div>
                    <div class="text-sm font-medium truncate">{{ editCompany.email }}</div>
                  </div>
                  <div v-if="editCompany.website">
                    <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.websiteLabel') }}</div>
                    <div class="text-sm font-medium truncate">{{ editCompany.website }}</div>
                  </div>
                </div>
                <!-- Representative -->
                <div v-if="editCompany.representative" class="grid grid-cols-2 gap-x-4 pt-1">
                  <div>
                    <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.representativeLabel') }}</div>
                    <div class="text-sm font-medium">{{ editCompany.representative }}</div>
                  </div>
                  <div v-if="editCompany.representativeRole">
                    <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.representativeRoleLabel') }}</div>
                    <div class="text-sm font-medium">{{ editCompany.representativeRole }}</div>
                  </div>
                </div>
              </div>

              <!-- Editable form -->
              <div v-else class="space-y-3">
                <UFormField :label="$t('companies.nameLabel')">
                  <UInput v-model="editForm.name" class="w-100" />
                </UFormField>
                <div class="grid grid-cols-3 gap-3">
                  <UFormField :label="$t('companies.cifLabel')">
                    <UInput :model-value="String(editCompany.cif)" disabled />
                  </UFormField>
                  <UFormField :label="$t('companies.registrationNumberLabel')">
                    <UInput v-model="editForm.registrationNumber" />
                  </UFormField>
                  <UFormField :label="$t('companies.capitalSocialLabel')">
                    <UInput v-model="editForm.capitalSocial" />
                  </UFormField>
                </div>
                <div class="grid grid-cols-3 gap-3 items-end">
                  <UFormField :label="$t('companies.vatCodeLabel')">
                    <UInput v-model="editForm.vatCode" />
                  </UFormField>
                  <UFormField :label="$t('companies.vatInLabel')">
                    <UInput v-model="editForm.vatIn" />
                  </UFormField>
                  <UFormField :label="$t('companies.eoriCodeLabel')">
                    <UInput v-model="editForm.eoriCode" />
                  </UFormField>
                </div>
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                  <UFormField :label="$t('companies.vatPayerLabel')">
                    <USwitch v-model="editForm.vatPayer" class="mt-1" />
                  </UFormField>
                  <UFormField :label="$t('companies.vatOnCollectionLabel')">
                    <USwitch v-model="editForm.vatOnCollection" class="mt-1" />
                  </UFormField>
                  <UFormField :label="$t('companies.ossLabel')">
                    <USwitch v-model="editForm.oss" class="mt-1" />
                  </UFormField>
                </div>
                <div class="grid grid-cols-3 gap-3">
                  <UFormField :label="$t('companies.countryLabel')">
                    <USelectMenu
                      v-model="editForm.country"
                      :items="countryOptions"
                      value-key="value"
                      :placeholder="$t('clients.selectCountry')"
                      :search-input="true"
                    />
                  </UFormField>
                  <UFormField :label="$t('companies.stateLabel')">
                    <USelectMenu
                      v-if="editForm.country === 'RO'"
                      v-model="editForm.state"
                      :items="countyOptions"
                      value-key="value"
                      :placeholder="$t('clients.selectCounty')"
                      :search-input="true"
                    />
                    <UInput v-else v-model="editForm.state" />
                  </UFormField>
                  <UFormField :label="$t('companies.cityLabel')">
                    <USelectMenu
                      v-if="editForm.country === 'RO' && editForm.state"
                      v-model="editForm.city"
                      :items="cityOptions"
                      value-key="value"
                      :placeholder="$t('clients.selectCity')"
                      :search-input="true"
                      :ignore-filter="true"
                      @update:search-term="onCitySearch"
                    />
                    <UInput v-else v-model="editForm.city" />
                  </UFormField>
                </div>
                <div class="grid grid-cols-[2fr_1fr] gap-3">
                  <UFormField :label="$t('companies.addressLabel')">
                    <UInput v-model="editForm.address" class="w-full" />
                  </UFormField>
                  <UFormField :label="$t('companies.phoneLabel')">
                    <UInput v-model="editForm.phone" />
                  </UFormField>
                </div>
                <div class="grid grid-cols-2 gap-3">
                  <UFormField :label="$t('companies.emailLabel')">
                    <UInput v-model="editForm.email" />
                  </UFormField>
                  <UFormField :label="$t('companies.websiteLabel')">
                    <UInput v-model="editForm.website" />
                  </UFormField>
                </div>
                <div class="grid grid-cols-2 gap-3">
                  <UFormField :label="$t('companies.representativeLabel')">
                    <UInput v-model="editForm.representative" />
                  </UFormField>
                  <UFormField :label="$t('companies.representativeRoleLabel')">
                    <UInput v-model="editForm.representativeRole" />
                  </UFormField>
                </div>
                <UButton
                  :loading="savingCompanyInfo"
                  :disabled="!companyInfoDirty"
                  @click="saveCompanyInfo"
                >
                  {{ $t('common.save') }}
                </UButton>
              </div>
            </div>

            <USeparator />

            <!-- Sync Settings -->
            <div>
              <h4 class="text-sm font-semibold text-(--ui-text-muted) uppercase tracking-wide mb-3">{{ $t('companies.syncSettings') }}</h4>
              <div class="space-y-4">
                <div class="flex items-center justify-between">
                  <div>
                    <div class="text-sm font-medium">{{ $t('companies.syncEnabled') }}</div>
                    <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.syncEnabledHelp') }}</div>
                  </div>
                  <USwitch
                    :model-value="editCompany.syncEnabled"
                    :loading="togglingSync"
                    :disabled="!anafConnected"
                    @update:model-value="handleToggleSync"
                  />
                </div>
                <p v-if="!anafConnected" class="text-xs text-warning-600 dark:text-warning-400">
                  {{ $t('companies.efacturaRequiresAnaf') }}
                </p>

                <div v-if="editCompany.syncDaysBack" class="flex justify-between">
                  <span class="text-sm text-(--ui-text-muted)">{{ $t('companies.syncDaysBack') }}</span>
                  <span class="text-sm font-medium">{{ editCompany.syncDaysBack }} {{ $t('common.days') }}</span>
                </div>

                <div v-if="editCompany.lastSyncedAt" class="flex justify-between">
                  <span class="text-sm text-(--ui-text-muted)">{{ $t('companies.lastSynced') }}</span>
                  <span class="text-sm font-medium">{{ formatDate(editCompany.lastSyncedAt) }}</span>
                </div>

                <USeparator />

                <div class="flex items-center justify-between">
                  <div>
                    <div class="text-sm font-medium">{{ $t('companies.anafConnection') }}</div>
                    <div class="text-xs text-(--ui-text-muted)">{{ $t('companies.anafConnectionHelp') }}</div>
                  </div>
                  <UBadge
                    :color="anafConnected ? 'success' : 'error'"
                    variant="subtle"
                  >
                    {{ anafConnected ? $t('companies.connected') : $t('companies.disconnected') }}
                  </UBadge>
                </div>
                <UButton
                  icon="i-lucide-link"
                  variant="outline"
                  @click="connectAnaf"
                >
                  {{ anafConnected ? $t('companies.reconnectAnaf') : $t('companies.connectAnaf') }}
                </UButton>
              </div>
            </div>

            <USeparator />

            <!-- e-Factura auto-submission -->
            <div>
              <div class="flex items-center justify-between mb-3">
                <h4 class="text-sm font-semibold text-(--ui-text-muted) uppercase tracking-wide">{{ $t('companies.efacturaAutoSubmit') }}</h4>
                <USwitch
                  :model-value="autoSubmitEnabled"
                  :disabled="!anafConnected"
                  @update:model-value="handleAutoSubmitToggle"
                />
              </div>
              <p v-if="!anafConnected" class="text-xs text-warning-600 dark:text-warning-400 mb-3">
                {{ $t('companies.efacturaRequiresAnaf') }}
              </p>
              <p class="text-xs text-(--ui-text-muted) italic mb-3">{{ $t('companies.efacturaAutoSubmitHelp') }}</p>
              <div v-if="autoSubmitEnabled">
                <div class="text-sm font-medium mb-2">{{ $t('companies.efacturaDelayLabel') }}</div>
                <URadioGroup
                  :model-value="selectedDelay"
                  :items="delayOptions"
                  orientation="horizontal"
                  @update:model-value="handleDelayChange"
                />
              </div>
            </div>

            <USeparator />

            <!-- Danger Zone -->
            <div>
              <h4 class="text-sm font-semibold text-error uppercase tracking-wide mb-3">{{ $t('companies.dangerZone') }}</h4>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="space-y-2 rounded-lg border border-error/20 p-3">
                  <div class="flex items-center gap-2 text-error">
                    <UIcon name="i-lucide-trash-2" class="size-4" />
                    <span class="font-medium text-sm">{{ $t('settings.dangerZone.deleteCompany.title') }}</span>
                  </div>
                  <p class="text-xs text-(--ui-text-muted)">{{ $t('settings.dangerZone.deleteCompany.description') }}</p>
                  <UButton
                    icon="i-lucide-trash-2"
                    color="error"
                    variant="soft"
                    size="sm"
                    @click="showDeleteModal = true; deleteConfirmInput = ''"
                  >
                    {{ $t('settings.dangerZone.deleteCompany.button') }}
                  </UButton>
                </div>
                <div class="space-y-2 rounded-lg border border-error/20 p-3">
                  <div class="flex items-center gap-2 text-error">
                    <UIcon name="i-lucide-rotate-ccw" class="size-4" />
                    <span class="font-medium text-sm">{{ $t('settings.dangerZone.resetCompany.title') }}</span>
                  </div>
                  <p class="text-xs text-(--ui-text-muted)">{{ $t('settings.dangerZone.resetCompany.description') }}</p>
                  <UButton
                    icon="i-lucide-rotate-ccw"
                    color="error"
                    variant="soft"
                    size="sm"
                    @click="showResetModal = true; resetConfirmInput = ''"
                  >
                    {{ $t('settings.dangerZone.resetCompany.button') }}
                  </UButton>
                </div>
              </div>
            </div>
          </div>
        </template>
      </USlideover>

      <!-- Delete Confirmation Modal -->
      <UModal v-model:open="showDeleteModal">
        <template #content>
          <div class="p-6 space-y-4">
            <div class="flex items-center gap-2 text-error">
              <UIcon name="i-lucide-alert-triangle" class="size-5" />
              <h3 class="text-lg font-semibold">{{ $t('settings.dangerZone.deleteCompany.modalTitle') }}</h3>
            </div>
            <p class="text-sm text-muted">
              {{ $t('settings.dangerZone.deleteCompany.modalDescription', { name: editCompany?.name }) }}
            </p>
            <UFormField :label="$t('settings.dangerZone.deleteCompany.confirmLabel')">
              <UInput
                v-model="deleteConfirmInput"
                :placeholder="$t('settings.dangerZone.deleteCompany.confirmPlaceholder')"
              />
              <template #hint>
                <span class="text-xs">{{ $t('settings.dangerZone.deleteCompany.confirmHint', { name: editCompany?.name }) }}</span>
              </template>
            </UFormField>
            <div class="flex justify-end gap-2 pt-2">
              <UButton variant="ghost" @click="showDeleteModal = false">
                {{ $t('common.cancel') }}
              </UButton>
              <UButton
                color="error"
                :loading="deleting"
                :disabled="!canConfirmDelete"
                @click="handleDelete"
              >
                {{ $t('settings.dangerZone.deleteCompany.button') }}
              </UButton>
            </div>
          </div>
        </template>
      </UModal>

      <!-- Reset Confirmation Modal -->
      <UModal v-model:open="showResetModal">
        <template #content>
          <div class="p-6 space-y-4">
            <div class="flex items-center gap-2 text-error">
              <UIcon name="i-lucide-alert-triangle" class="size-5" />
              <h3 class="text-lg font-semibold">{{ $t('settings.dangerZone.resetCompany.modalTitle') }}</h3>
            </div>

            <div class="rounded-lg bg-warning-50 dark:bg-warning-950/20 p-3 border border-warning-200 dark:border-warning-800">
              <div class="flex items-center gap-2 text-sm font-medium text-warning-700 dark:text-warning-300">
                <UIcon name="i-lucide-triangle-alert" class="size-4 shrink-0" />
                {{ $t('settings.dangerZone.warning') }}
              </div>
            </div>

            <p class="text-sm text-muted">
              {{ $t('settings.dangerZone.resetCompany.modalDescription', { name: editCompany?.name }) }}
            </p>

            <ul class="text-sm text-muted space-y-1.5 pl-1">
              <li class="flex items-start gap-2">
                <UIcon name="i-lucide-check" class="size-4 text-success-500 shrink-0 mt-0.5" />
                {{ $t('settings.dangerZone.resetCompany.bulletPoints.access') }}
              </li>
              <li class="flex items-start gap-2">
                <UIcon name="i-lucide-x" class="size-4 text-error shrink-0 mt-0.5" />
                {{ $t('settings.dangerZone.resetCompany.bulletPoints.dataWipe') }}
              </li>
              <li class="flex items-start gap-2">
                <UIcon name="i-lucide-check" class="size-4 text-success-500 shrink-0 mt-0.5" />
                {{ $t('settings.dangerZone.resetCompany.bulletPoints.usersKept') }}
              </li>
              <li class="flex items-start gap-2">
                <UIcon name="i-lucide-rotate-ccw" class="size-4 text-warning-500 shrink-0 mt-0.5" />
                {{ $t('settings.dangerZone.resetCompany.bulletPoints.syncRestart') }}
              </li>
            </ul>

            <UFormField :label="$t('settings.dangerZone.resetCompany.confirmLabel')">
              <UInput
                v-model="resetConfirmInput"
                :placeholder="$t('settings.dangerZone.resetCompany.confirmPlaceholder')"
              />
              <template #hint>
                <span class="text-xs">{{ $t('settings.dangerZone.resetCompany.confirmHint') }}</span>
              </template>
            </UFormField>

            <div class="flex justify-end gap-2 pt-2">
              <UButton variant="ghost" @click="showResetModal = false">
                {{ $t('common.cancel') }}
              </UButton>
              <UButton
                color="error"
                :loading="resettingCompany"
                :disabled="!canConfirmReset"
                @click="handleReset"
              >
                {{ $t('common.continue') }}
              </UButton>
            </div>
          </div>
        </template>
      </UModal>

      <!-- Upgrade modal for plan limit -->
      <SharedUpgradeModal
        v-model:open="showUpgrade"
        :feature="$t('plan.maxCompanies').toLowerCase()"
        :current-limit="maxCompanies"
      />
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
import { z } from 'zod'
import type { Company } from '~/types'
import type { RegistryCompany } from '~/composables/useRegistrySearch'

definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('companies.title') })
const companyStore = useCompanyStore()
const authStore = useAuthStore()
const router = useRouter()
const toast = useToast()
const { get } = useApi()
const { fetchDefaults, countryOptions, countyOptions } = useInvoiceDefaults()

const cityOptions = ref<{ label: string, value: string }[]>([])
const citySearchTimeout = ref<ReturnType<typeof setTimeout>>()
const skipCityReset = ref(false)

function switchCompany(company: Company) {
  if (company.id === companyStore.currentCompanyId) {
    navigateTo('/')
    return
  }
  companyStore.selectCompany(company.id)
  toast.add({ title: $t('companies.switchedTo', { name: company.name }), color: 'success' })
  navigateTo('/')
}

const companies = computed(() => companyStore.companies)
const deletedCompanies = computed(() => companyStore.deletedCompanies)
const loading = computed(() => companyStore.loading)
const storeError = computed(() => companyStore.error)
const restoring = ref<string | null>(null)

// ── Create state ────────────────────────────────────────────────
const createOpen = ref(false)
const creating = ref(false)
const createError = ref<string | null>(null)
const showUpgrade = ref(false)
const createState = reactive({ cif: '' })

const createSchema = z.object({
  cif: z.string().min(2, $t('validation.cifMin')).max(20, $t('validation.cifFormat')),
})

// ── Registry search ──────────────────────────────────────────────
const { results: registryResults, loading: registryLoading, onRegistrySearch, clear: clearRegistry } = useRegistrySearch()
const registryDropdownOpen = ref(false)

function onCifInput() {
  const q = createState.cif.trim()
  if (q.length >= 2) {
    registryDropdownOpen.value = true
    onRegistrySearch(q)
  } else {
    registryDropdownOpen.value = false
    clearRegistry()
  }
}

function selectRegistryCompany(r: RegistryCompany) {
  registryDropdownOpen.value = false
  clearRegistry()
  createState.cif = r.cod_unic
}

const maxCompanies = computed(() => {
  const max = authStore.plan?.features?.maxCompanies
  if (!max || max >= 999999) return undefined
  return max
})

function openCreate() {
  createState.cif = ''
  createError.value = null
  registryDropdownOpen.value = false
  clearRegistry()
  createOpen.value = true
}

async function onCreateSubmit() {
  creating.value = true
  createError.value = null

  try {
    const company = await companyStore.createCompany(createState.cif)
    if (company) {
      toast.add({ title: $t('companies.createSuccess'), color: 'success' })
      createOpen.value = false
    } else {
      if (companyStore.errorCode === 'PLAN_LIMIT') {
        showUpgrade.value = true
      } else {
        createError.value = companyStore.error || $t('companies.createError')
      }
    }
  } catch (err: any) {
    createError.value = err?.data?.error || $t('companies.createError')
  } finally {
    creating.value = false
  }
}

// ── Edit state ──────────────────────────────────────────────────
const editOpen = ref(false)
const editCompany = ref<Company | null>(null)
const editingCompanyInfo = ref(false)
const togglingSync = ref(false)
const savingCompanyInfo = ref(false)
const refreshingAnaf = ref(false)
const showDeleteModal = ref(false)
const deleting = ref(false)
const deleteConfirmInput = ref('')
const showResetModal = ref(false)
const resettingCompany = ref(false)
const resetConfirmInput = ref('')

const editForm = reactive({
  name: '',
  registrationNumber: '',
  vatCode: '',
  vatPayer: false,
  address: '',
  country: 'RO',
  state: '' as string | undefined,
  city: '',
  phone: '',
  email: '',
  website: '',
  capitalSocial: '',
  vatOnCollection: false,
  oss: false,
  vatIn: '',
  eoriCode: '',
  representative: '',
  representativeRole: '',
})

const canConfirmDelete = computed(() =>
  deleteConfirmInput.value.trim().toLowerCase() === (editCompany.value?.name ?? '').trim().toLowerCase(),
)
const canConfirmReset = computed(() =>
  resetConfirmInput.value.trim().toLowerCase() === 'confirm',
)
const companyInfoDirty = computed(() => {
  if (!editCompany.value) return false
  const c = editCompany.value
  return editForm.name !== (c.name ?? '')
    || editForm.registrationNumber !== (c.registrationNumber ?? '')
    || editForm.vatCode !== (c.vatCode ?? '')
    || editForm.vatPayer !== c.vatPayer
    || editForm.address !== (c.address ?? '')
    || editForm.country !== (c.country ?? 'RO')
    || editForm.state !== (c.state ?? '')
    || editForm.city !== (c.city ?? '')
    || editForm.phone !== (c.phone ?? '')
    || editForm.email !== (c.email ?? '')
    || editForm.website !== (c.website ?? '')
    || editForm.capitalSocial !== (c.capitalSocial ?? '')
    || editForm.vatOnCollection !== c.vatOnCollection
    || editForm.oss !== c.oss
    || editForm.vatIn !== (c.vatIn ?? '')
    || editForm.eoriCode !== (c.eoriCode ?? '')
    || editForm.representative !== (c.representative ?? '')
    || editForm.representativeRole !== (c.representativeRole ?? '')
})

const anafConnected = ref(false)
const autoSubmitEnabled = ref(false)
const selectedDelay = ref(24)

const delayOptions = [
  { label: $t('companies.efacturaDelayOptions.2'), value: 2 },
  { label: $t('companies.efacturaDelayOptions.24'), value: 24 },
  { label: $t('companies.efacturaDelayOptions.48'), value: 48 },
  { label: $t('companies.efacturaDelayOptions.72'), value: 72 },
  { label: $t('companies.efacturaDelayOptions.96'), value: 96 },
]

function toggleEditCompanyInfo() {
  editingCompanyInfo.value = !editingCompanyInfo.value
}

// Fetch cities when county changes
watch(() => editForm.state, (newState) => {
  if (skipCityReset.value) {
    skipCityReset.value = false
  } else {
    editForm.city = ''
  }
  cityOptions.value = []
  if (newState && editForm.country === 'RO') {
    fetchCities(newState)
  }
})

async function fetchCities(county: string, search = '') {
  try {
    const res = await get<{ data: { label: string, value: string }[] }>('/v1/company-registry/cities', { county, q: search })
    cityOptions.value = res?.data ?? []
  } catch {
    cityOptions.value = []
  }
}

function onCitySearch(term: string) {
  clearTimeout(citySearchTimeout.value)
  if (!editForm.state) return
  citySearchTimeout.value = setTimeout(() => {
    fetchCities(editForm.state!, term)
  }, 300)
}

async function openEdit(company: Company) {
  editCompany.value = company
  editingCompanyInfo.value = false
  editForm.name = company.name ?? ''
  editForm.registrationNumber = company.registrationNumber ?? ''
  editForm.vatCode = company.vatCode ?? ''
  editForm.vatPayer = company.vatPayer
  editForm.address = company.address ?? ''
  editForm.country = company.country ?? 'RO'
  skipCityReset.value = true
  editForm.state = company.state ?? ''
  editForm.city = company.city ?? ''
  editForm.phone = company.phone ?? ''
  editForm.email = company.email ?? ''
  editForm.website = company.website ?? ''
  editForm.capitalSocial = company.capitalSocial ?? ''
  editForm.vatOnCollection = company.vatOnCollection
  editForm.oss = company.oss
  editForm.vatIn = company.vatIn ?? ''
  editForm.eoriCode = company.eoriCode ?? ''
  editForm.representative = company.representative ?? ''
  editForm.representativeRole = company.representativeRole ?? ''
  autoSubmitEnabled.value = company.efacturaDelayHours != null
  selectedDelay.value = company.efacturaDelayHours ?? 24
  editOpen.value = true
  await fetchDefaults()
  if (company.state && company.country === 'RO') {
    fetchCities(company.state)
  }
  await fetchAnafStatus()
}

async function fetchAnafStatus() {
  try {
    const data = await get<{ connected: boolean }>('/v1/anaf/status')
    anafConnected.value = data.connected
  } catch {
    anafConnected.value = false
  }
}

async function saveCompanyInfo() {
  if (!editCompany.value || !companyInfoDirty.value) return
  savingCompanyInfo.value = true
  try {
    const result = await companyStore.updateCompany(editCompany.value.id, {
      name: editForm.name,
      registrationNumber: editForm.registrationNumber || null,
      vatCode: editForm.vatCode || null,
      vatPayer: editForm.vatPayer,
      address: editForm.address || null,
      state: editForm.state || '',
      city: editForm.city,
      country: editForm.country,
      phone: editForm.phone || null,
      email: editForm.email || null,
      website: editForm.website || null,
      capitalSocial: editForm.capitalSocial || null,
      vatOnCollection: editForm.vatOnCollection,
      oss: editForm.oss,
      vatIn: editForm.vatIn || null,
      eoriCode: editForm.eoriCode || null,
      representative: editForm.representative || null,
      representativeRole: editForm.representativeRole || null,
    } as Partial<Company>)
    if (result) {
      toast.add({ title: $t('common.saved'), color: 'success' })
    } else {
      toast.add({ title: companyStore.error || $t('common.error'), color: 'error' })
    }
  } catch {
    toast.add({ title: $t('common.error'), color: 'error' })
  } finally {
    savingCompanyInfo.value = false
  }
}

async function handleRefreshAnaf() {
  if (!editCompany.value) return
  refreshingAnaf.value = true
  try {
    const result = await companyStore.refreshFromAnaf(editCompany.value.id)
    if (result) {
      // Update form with refreshed data
      editForm.name = result.name ?? ''
      editForm.registrationNumber = result.registrationNumber ?? ''
      editForm.vatCode = result.vatCode ?? ''
      editForm.vatPayer = result.vatPayer
      editForm.address = result.address ?? ''
      editForm.country = result.country ?? 'RO'
      skipCityReset.value = true
      editForm.state = result.state ?? ''
      editForm.city = result.city ?? ''
      if (result.state && result.country === 'RO') {
        fetchCities(result.state)
      }
      editForm.phone = result.phone ?? ''
      editForm.email = result.email ?? ''
      editForm.vatOnCollection = result.vatOnCollection
      toast.add({ title: $t('common.saved'), color: 'success' })
    } else {
      toast.add({ title: companyStore.error || $t('common.error'), color: 'error' })
    }
  } catch {
    toast.add({ title: $t('common.error'), color: 'error' })
  } finally {
    refreshingAnaf.value = false
  }
}

function formatDate(date: string) {
  return new Date(date).toLocaleString('ro-RO', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

async function handleToggleSync() {
  if (!editCompany.value) return
  togglingSync.value = true
  try {
    const enabled = await companyStore.toggleSync(editCompany.value.id)
    if (companyStore.error) {
      toast.add({ title: companyStore.error, color: 'error' })
    } else {
      toast.add({
        title: enabled ? $t('companies.syncEnabledSuccess') : $t('companies.syncDisabledSuccess'),
        color: 'success',
      })
    }
  } catch {
    toast.add({ title: $t('companies.toggleSyncError'), color: 'error' })
  } finally {
    togglingSync.value = false
  }
}

async function handleAutoSubmitToggle(enabled: boolean) {
  if (!editCompany.value) return
  const newValue = enabled ? selectedDelay.value : null
  const result = await companyStore.updateCompany(editCompany.value.id, { efacturaDelayHours: newValue } as any)
  if (result) {
    autoSubmitEnabled.value = enabled
    toast.add({ title: $t('common.saved'), color: 'success' })
  } else {
    toast.add({ title: companyStore.error || $t('common.error'), color: 'error' })
  }
}

async function handleDelayChange(value: number) {
  if (!editCompany.value) return
  selectedDelay.value = value
  const result = await companyStore.updateCompany(editCompany.value.id, { efacturaDelayHours: value } as any)
  if (result) {
    toast.add({ title: $t('common.saved'), color: 'success' })
  } else {
    toast.add({ title: companyStore.error || $t('common.error'), color: 'error' })
    selectedDelay.value = editCompany.value.efacturaDelayHours ?? 24
  }
}

function connectAnaf() {
  if (!editCompany.value) return
  editOpen.value = false
  navigateTo(`/companies/${editCompany.value.id}/anaf`)
}

async function handleDelete() {
  if (!editCompany.value) return
  deleting.value = true
  try {
    const success = await companyStore.deleteCompany(editCompany.value.id)
    if (success) {
      toast.add({ title: $t('companies.deleteGracePeriod'), color: 'warning' })
      showDeleteModal.value = false
      editOpen.value = false
    } else {
      toast.add({ title: companyStore.error || $t('companies.deleteError'), color: 'error' })
    }
  } catch {
    toast.add({ title: $t('companies.deleteError'), color: 'error' })
  } finally {
    deleting.value = false
    showDeleteModal.value = false
  }
}

async function handleReset() {
  if (!editCompany.value || !canConfirmReset.value) return
  resettingCompany.value = true
  try {
    const success = await companyStore.resetCompany(editCompany.value.id)
    if (success) {
      toast.add({ title: $t('settings.dangerZone.resetCompany.success'), color: 'success' })
      showResetModal.value = false
      resetConfirmInput.value = ''
    } else {
      toast.add({ title: companyStore.error || $t('settings.dangerZone.resetCompany.error'), color: 'error' })
    }
  } catch {
    toast.add({ title: $t('settings.dangerZone.resetCompany.error'), color: 'error' })
  } finally {
    resettingCompany.value = false
  }
}

function getDaysRemaining(hardDeleteAt?: string | null): number {
  if (!hardDeleteAt) return 0
  const now = new Date()
  const deadline = new Date(hardDeleteAt)
  const diff = Math.ceil((deadline.getTime() - now.getTime()) / (1000 * 60 * 60 * 24))
  return Math.max(0, diff)
}

async function handleRestore(companyId: string) {
  restoring.value = companyId
  try {
    const success = await companyStore.restoreCompany(companyId)
    if (success) {
      toast.add({ title: $t('companies.restoreSuccess'), color: 'success' })
    } else {
      toast.add({ title: companyStore.error || $t('companies.restoreError'), color: 'error' })
    }
  } catch {
    toast.add({ title: $t('companies.restoreError'), color: 'error' })
  } finally {
    restoring.value = null
  }
}

// Keep editCompany ref in sync with store changes
watch(() => companyStore.companies, (updated) => {
  if (editCompany.value) {
    const fresh = updated.find(c => c.id === editCompany.value!.id)
    if (fresh) editCompany.value = fresh
  }
}, { deep: true })

onMounted(() => {
  companyStore.fetchCompanies()
  companyStore.fetchDeletedCompanies()
})
</script>
