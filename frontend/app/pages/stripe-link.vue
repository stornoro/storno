<script setup lang="ts">
definePageMeta({
  middleware: ['auth'],
  layout: 'auth',
})

const { t: $t } = useI18n()
const route = useRoute()
const { post } = useApi()
const companyStore = useCompanyStore()

useHead({ title: $t('stripeApp.title') })

const code = ref(((route.query.code as string) ?? '').toUpperCase())
const busy = ref(false)
const result = ref<'approved' | 'denied' | null>(null)
const error = ref<string | null>(null)

const selectedCompanyId = ref<string | null>(companyStore.currentCompanyId)

const companyOptions = computed(() =>
  companyStore.companies.map(c => ({
    label: `${c.name} (CIF ${c.cif})`,
    value: c.id,
  })),
)

async function decide(approve: boolean) {
  if (!code.value || code.value.length < 6) {
    error.value = $t('stripeApp.errorInvalidCode')
    return
  }

  if (approve && !selectedCompanyId.value) {
    error.value = $t('stripeApp.errorNoCompany')
    return
  }

  busy.value = true
  error.value = null

  try {
    await post('/v1/stripe-app/oauth/approve', {
      user_code: code.value,
      approve,
      ...(approve ? { company_id: selectedCompanyId.value } : {}),
    })
    result.value = approve ? 'approved' : 'denied'
  }
  catch (e: any) {
    const status = e?.response?.status
    if (status === 404) error.value = $t('stripeApp.errorInvalidCode')
    else if (status === 410) error.value = $t('stripeApp.errorExpired')
    else if (status === 409) error.value = $t('stripeApp.errorAlreadyUsed')
    else if (status === 403) error.value = $t('stripeApp.errorNoAccess')
    else error.value = e?.data?.message || $t('stripeApp.errorGeneric')
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center p-4">
    <div class="w-full max-w-md space-y-6">
      <div class="text-center">
        <img
          src="/logo.png"
          :alt="$t('app.name')"
          class="mx-auto h-12 w-auto"
        >
        <h1 class="mt-4 text-2xl font-bold text-gray-900 dark:text-white">
          {{ $t('stripeApp.title') }}
        </h1>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
          {{ $t('stripeApp.subtitle') }}
        </p>
      </div>

      <UCard>
        <div class="space-y-6">
          <div v-if="result === 'approved'" class="space-y-2 py-4 text-center">
            <UIcon name="i-lucide-circle-check" class="mx-auto h-10 w-10 text-success" />
            <p class="text-base font-semibold">{{ $t('stripeApp.approveSuccessTitle') }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $t('stripeApp.approveSuccessHint') }}</p>
          </div>

          <div v-else-if="result === 'denied'" class="space-y-2 py-4 text-center">
            <UIcon name="i-lucide-circle-x" class="mx-auto h-10 w-10 text-gray-400" />
            <p class="text-base font-semibold">{{ $t('stripeApp.denySuccessTitle') }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $t('stripeApp.denySuccessHint') }}</p>
          </div>

          <template v-else>
            <div>
              <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ $t('stripeApp.permissionsTitle') }}
              </p>
              <ul class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
                <li class="flex gap-2">
                  <UIcon name="i-lucide-check" class="h-4 w-4 mt-0.5 text-success" />
                  <span>{{ $t('stripeApp.permissionRead') }}</span>
                </li>
                <li class="flex gap-2">
                  <UIcon name="i-lucide-check" class="h-4 w-4 mt-0.5 text-success" />
                  <span>{{ $t('stripeApp.permissionWrite') }}</span>
                </li>
              </ul>
              <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                {{ $t('stripeApp.permissionDisconnect') }}
              </p>
            </div>

            <UFormField :label="$t('stripeApp.companyLabel')" required>
              <USelect
                v-model="selectedCompanyId"
                :options="companyOptions"
                :placeholder="$t('stripeApp.companyPlaceholder')"
                size="lg"
                class="w-full"
              />
            </UFormField>

            <UFormField :label="$t('stripeApp.codeLabel')" :hint="$t('stripeApp.enterCodeHint')">
              <UInput
                v-model="code"
                :placeholder="$t('stripeApp.codePlaceholder')"
                size="lg"
                class="w-full font-mono tracking-widest text-center"
                maxlength="8"
                @input="(e: Event) => code = (e.target as HTMLInputElement).value.toUpperCase()"
              />
            </UFormField>

            <UAlert
              v-if="error"
              color="error"
              variant="subtle"
              :title="error"
            />

            <div class="flex gap-2">
              <UButton
                color="neutral"
                variant="ghost"
                :disabled="busy"
                @click="decide(false)"
              >
                {{ $t('stripeApp.deny') }}
              </UButton>
              <UButton
                color="primary"
                :loading="busy"
                :disabled="busy || code.length < 6 || !selectedCompanyId"
                class="flex-1 justify-center"
                @click="decide(true)"
              >
                {{ busy ? $t('stripeApp.approving') : $t('stripeApp.authorize') }}
              </UButton>
            </div>
          </template>
        </div>
      </UCard>
    </div>
  </div>
</template>
