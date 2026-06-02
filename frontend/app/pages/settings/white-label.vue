<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('whiteLabel.title') })

const { apiFetch, get, put, post } = useApi()
const authStore = useAuthStore()
const toast = useToast()

interface DnsRecord { name: string, type: string, value: string }
interface WhiteLabelData {
  id: string
  enabled: boolean
  appName: string | null
  logoUrl: string | null
  primaryColor: string | null
  removeBranding: boolean
  customDomain: string | null
  customDomainVerified: boolean
  dnsRecord: DnsRecord | null
}

const entitled = ref(false)
const loading = ref(true)
const saving = ref(false)
const uploadingLogo = ref(false)
const logoObjectUrl = ref<string | null>(null)

const enabled = ref(false)
const appName = ref<string | null>(null)
const primaryColor = ref<string | null>(null)
const removeBranding = ref(false)
const hasLogo = ref(false)
const customDomain = ref<string>('')
const domainVerified = ref(false)
const dnsRecord = ref<DnsRecord | null>(null)
const verifying = ref(false)

async function load() {
  loading.value = true
  try {
    const res = await get<{ entitled: boolean, data: WhiteLabelData | null }>('/v1/white-label-config')
    entitled.value = res.entitled
    const d = res.data
    enabled.value = d?.enabled ?? false
    appName.value = d?.appName ?? null
    primaryColor.value = d?.primaryColor ?? null
    removeBranding.value = d?.removeBranding ?? false
    hasLogo.value = !!d?.logoUrl
    customDomain.value = d?.customDomain ?? ''
    domainVerified.value = d?.customDomainVerified ?? false
    dnsRecord.value = d?.dnsRecord ?? null
    if (d?.logoUrl) await loadLogoPreview()
  }
  catch {
    toast.add({ title: $t('common.error'), color: 'error' })
  }
  finally {
    loading.value = false
  }
}

async function loadLogoPreview() {
  try {
    const blob = await apiFetch<Blob>('/v1/white-label/logo', { responseType: 'blob' })
    if (logoObjectUrl.value) URL.revokeObjectURL(logoObjectUrl.value)
    logoObjectUrl.value = URL.createObjectURL(blob)
  }
  catch {
    logoObjectUrl.value = null
  }
}

async function save() {
  saving.value = true
  try {
    const res = await put<{ data: WhiteLabelData }>('/v1/white-label-config', {
      enabled: enabled.value,
      appName: appName.value,
      primaryColor: primaryColor.value,
      removeBranding: removeBranding.value,
      customDomain: customDomain.value || null,
    })
    domainVerified.value = res.data?.customDomainVerified ?? false
    dnsRecord.value = res.data?.dnsRecord ?? null
    await authStore.fetchUser()
    toast.add({ title: $t('whiteLabel.saved'), color: 'success' })
  }
  catch (err: any) {
    toast.add({ title: err?.data?.error ?? $t('common.error'), color: 'error' })
  }
  finally {
    saving.value = false
  }
}

async function onLogoChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  uploadingLogo.value = true
  try {
    const formData = new FormData()
    formData.append('logo', file)
    await apiFetch('/v1/white-label-config/logo', { method: 'POST', body: formData })
    hasLogo.value = true
    await loadLogoPreview()
    await authStore.fetchUser()
    toast.add({ title: $t('whiteLabel.logoUploaded'), color: 'success' })
  }
  catch (err: any) {
    toast.add({ title: err?.data?.error ?? $t('common.error'), color: 'error' })
  }
  finally {
    uploadingLogo.value = false
  }
}

async function removeLogo() {
  try {
    await apiFetch('/v1/white-label-config/logo', { method: 'DELETE' })
    hasLogo.value = false
    if (logoObjectUrl.value) {
      URL.revokeObjectURL(logoObjectUrl.value)
      logoObjectUrl.value = null
    }
    await authStore.fetchUser()
  }
  catch (err: any) {
    toast.add({ title: err?.data?.error ?? $t('common.error'), color: 'error' })
  }
}

async function verifyDomain() {
  verifying.value = true
  try {
    const res = await post<{ success: boolean, error?: string, data?: WhiteLabelData }>('/v1/white-label-config/domain/verify', {})
    if (res.success) {
      domainVerified.value = true
      dnsRecord.value = null
      toast.add({ title: $t('whiteLabel.domainVerified'), color: 'success' })
      await authStore.fetchUser()
    }
    else {
      toast.add({ title: res.error ?? $t('whiteLabel.domainNotVerified'), color: 'error' })
    }
  }
  catch (err: any) {
    toast.add({ title: err?.data?.error ?? $t('common.error'), color: 'error' })
  }
  finally {
    verifying.value = false
  }
}

async function copyDns() {
  if (dnsRecord.value) {
    await navigator.clipboard?.writeText(dnsRecord.value.value)
    toast.add({ title: $t('common.copied'), color: 'success' })
  }
}

onMounted(load)
onBeforeUnmount(() => {
  if (logoObjectUrl.value) URL.revokeObjectURL(logoObjectUrl.value)
})
</script>

<template>
  <div>
    <UPageCard
      :title="$t('whiteLabel.title')"
      :description="$t('whiteLabel.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    />

    <UPageCard v-if="!loading && !entitled" variant="subtle">
      <div class="flex items-start gap-3">
        <UIcon name="i-lucide-lock" class="size-5 text-(--ui-text-muted) mt-0.5" />
        <div>
          <p class="font-medium">{{ $t('whiteLabel.businessOnly') }}</p>
          <p class="text-sm text-(--ui-text-muted)">{{ $t('whiteLabel.businessOnlyHint') }}</p>
          <UButton class="mt-3" :to="'/settings/billing'" :label="$t('whiteLabel.upgradeCta')" color="primary" />
        </div>
      </div>
    </UPageCard>

    <UPageCard v-else-if="!loading" variant="subtle">
      <div class="space-y-6">
        <UFormField :label="$t('whiteLabel.enabled')" :description="$t('whiteLabel.enabledHint')">
          <USwitch v-model="enabled" />
        </UFormField>

        <UFormField :label="$t('whiteLabel.appName')" :description="$t('whiteLabel.appNameHint')">
          <UInput v-model="appName" :placeholder="'Storno.ro'" maxlength="100" class="w-full sm:w-80" />
        </UFormField>

        <UFormField :label="$t('whiteLabel.primaryColor')" :description="$t('whiteLabel.primaryColorHint')">
          <div class="flex items-center gap-3">
            <input
              type="color"
              :value="primaryColor || '#2563eb'"
              class="h-9 w-12 rounded cursor-pointer border border-(--ui-border)"
              @input="primaryColor = ($event.target as HTMLInputElement).value"
            >
            <UInput v-model="primaryColor" placeholder="#2563eb" class="w-32 font-mono" />
            <UButton
              v-if="primaryColor"
              variant="ghost"
              color="neutral"
              icon="i-lucide-x"
              @click="primaryColor = null"
            />
          </div>
        </UFormField>

        <UFormField :label="$t('whiteLabel.logo')" :description="$t('whiteLabel.logoHint')">
          <div class="flex items-center gap-4">
            <img
              v-if="logoObjectUrl"
              :src="logoObjectUrl"
              alt="logo"
              class="h-12 w-auto rounded bg-(--ui-bg-elevated) p-1"
            >
            <label class="inline-flex">
              <input type="file" accept="image/png,image/jpeg,image/svg+xml" class="hidden" @change="onLogoChange">
              <UButton
                as="span"
                :loading="uploadingLogo"
                icon="i-lucide-upload"
                color="neutral"
                :label="hasLogo ? $t('whiteLabel.replaceLogo') : $t('whiteLabel.uploadLogo')"
              />
            </label>
            <UButton
              v-if="hasLogo"
              variant="ghost"
              color="error"
              icon="i-lucide-trash-2"
              :label="$t('common.delete')"
              @click="removeLogo"
            />
          </div>
        </UFormField>

        <UFormField :label="$t('whiteLabel.removeBranding')" :description="$t('whiteLabel.removeBrandingHint')">
          <USwitch v-model="removeBranding" />
        </UFormField>

        <UFormField :label="$t('whiteLabel.customDomain')" :description="$t('whiteLabel.customDomainHint')">
          <div class="flex items-center gap-3">
            <UInput v-model="customDomain" placeholder="facturi.example.com" class="w-full sm:w-80 font-mono" />
            <UBadge v-if="customDomain && domainVerified" color="success" variant="subtle" :label="$t('whiteLabel.domainVerified')" />
            <UBadge v-else-if="customDomain" color="warning" variant="subtle" :label="$t('whiteLabel.domainPending')" />
          </div>
        </UFormField>

        <div v-if="dnsRecord" class="rounded-lg border border-(--ui-border) bg-(--ui-bg-elevated) p-4 space-y-3">
          <p class="text-sm text-(--ui-text-muted)">{{ $t('whiteLabel.dnsInstructions') }}</p>
          <div class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm font-mono">
            <span class="text-(--ui-text-muted)">Type</span><span>{{ dnsRecord.type }}</span>
            <span class="text-(--ui-text-muted)">Name</span><span class="break-all">{{ dnsRecord.name }}</span>
            <span class="text-(--ui-text-muted)">Value</span><span class="break-all">{{ dnsRecord.value }}</span>
          </div>
          <div class="flex gap-2">
            <UButton size="sm" color="neutral" variant="subtle" icon="i-lucide-copy" :label="$t('common.copy')" @click="copyDns" />
            <UButton size="sm" color="primary" :loading="verifying" icon="i-lucide-check" :label="$t('whiteLabel.verifyDomain')" @click="verifyDomain" />
          </div>
          <p class="text-xs text-(--ui-text-dimmed)">{{ $t('whiteLabel.dnsCnameNote') }}</p>
        </div>

        <div class="flex justify-end">
          <UButton :loading="saving" :label="$t('common.save')" color="primary" @click="save" />
        </div>
      </div>
    </UPageCard>
  </div>
</template>
