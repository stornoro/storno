<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('emailSender.title') })

const { get, put, del, post } = useApi()
const toast = useToast()

interface MailerData {
  id: string
  enabled: boolean
  host: string
  port: number
  encryption: 'none' | 'tls' | 'ssl'
  username: string | null
  fromAddress: string
  fromName: string | null
  hasPassword: boolean
  lastTestedAt: string | null
}

const entitled = ref(false)
const loading = ref(true)
const saving = ref(false)
const testing = ref(false)
const hasConfig = ref(false)
const hasPassword = ref(false)
const lastTestedAt = ref<string | null>(null)

const form = reactive({
  enabled: false,
  host: '',
  port: 587,
  encryption: 'tls' as 'none' | 'tls' | 'ssl',
  username: '' as string,
  password: '' as string,
  fromAddress: '',
  fromName: '' as string,
})

const encryptionOptions = [
  { label: 'STARTTLS (587)', value: 'tls' },
  { label: 'SSL/TLS (465)', value: 'ssl' },
  { label: $t('emailSender.encryptionNone'), value: 'none' },
]

async function load() {
  loading.value = true
  try {
    const res = await get<{ entitled: boolean, data: MailerData | null }>('/v1/mailer-config')
    entitled.value = res.entitled
    const d = res.data
    hasConfig.value = !!d
    if (d) {
      form.enabled = d.enabled
      form.host = d.host
      form.port = d.port
      form.encryption = d.encryption
      form.username = d.username ?? ''
      form.fromAddress = d.fromAddress
      form.fromName = d.fromName ?? ''
      hasPassword.value = d.hasPassword
      lastTestedAt.value = d.lastTestedAt
    }
  }
  catch {
    toast.add({ title: $t('common.error'), color: 'error' })
  }
  finally {
    loading.value = false
  }
}

function payload() {
  const body: Record<string, unknown> = {
    enabled: form.enabled,
    host: form.host,
    port: form.port,
    encryption: form.encryption,
    username: form.username,
    fromAddress: form.fromAddress,
    fromName: form.fromName,
  }
  if (form.password) body.password = form.password
  return body
}

async function save() {
  saving.value = true
  try {
    await put('/v1/mailer-config', payload())
    form.password = ''
    await load()
    toast.add({ title: $t('emailSender.saved'), color: 'success' })
  }
  catch (err: any) {
    toast.add({ title: err?.data?.error ?? $t('common.error'), color: 'error' })
  }
  finally {
    saving.value = false
  }
}

async function test() {
  testing.value = true
  try {
    const res = await post<{ success: boolean, message?: string, error?: string }>('/v1/mailer-config/test', payload())
    if (res.success) {
      toast.add({ title: res.message ?? $t('emailSender.testOk'), color: 'success' })
      await load()
    }
    else {
      toast.add({ title: res.error ?? $t('emailSender.testFailed'), color: 'error' })
    }
  }
  catch (err: any) {
    toast.add({ title: err?.data?.error ?? $t('emailSender.testFailed'), color: 'error' })
  }
  finally {
    testing.value = false
  }
}

async function remove() {
  try {
    await del('/v1/mailer-config')
    hasConfig.value = false
    Object.assign(form, { enabled: false, host: '', port: 587, encryption: 'tls', username: '', password: '', fromAddress: '', fromName: '' })
    hasPassword.value = false
    lastTestedAt.value = null
    toast.add({ title: $t('emailSender.deleted'), color: 'success' })
  }
  catch (err: any) {
    toast.add({ title: err?.data?.error ?? $t('common.error'), color: 'error' })
  }
}

onMounted(load)
</script>

<template>
  <div>
    <UPageCard
      :title="$t('emailSender.title')"
      :description="$t('emailSender.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    />

    <UPageCard v-if="!loading && !entitled" variant="subtle">
      <div class="flex items-start gap-3">
        <UIcon name="i-lucide-lock" class="size-5 text-(--ui-text-muted) mt-0.5" />
        <div>
          <p class="font-medium">{{ $t('emailSender.businessOnly') }}</p>
          <p class="text-sm text-(--ui-text-muted)">{{ $t('emailSender.businessOnlyHint') }}</p>
          <UButton class="mt-3" to="/settings/billing" :label="$t('whiteLabel.upgradeCta')" color="primary" />
        </div>
      </div>
    </UPageCard>

    <UPageCard v-else-if="!loading" variant="subtle">
      <div class="space-y-5">
        <UFormField :label="$t('emailSender.enabled')" :description="$t('emailSender.enabledHint')">
          <USwitch v-model="form.enabled" />
        </UFormField>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <UFormField :label="$t('emailSender.host')">
            <UInput v-model="form.host" placeholder="smtp.example.com" class="w-full" />
          </UFormField>
          <UFormField :label="$t('emailSender.port')">
            <UInput v-model.number="form.port" type="number" class="w-full" />
          </UFormField>
        </div>

        <UFormField :label="$t('emailSender.encryption')">
          <USelect v-model="form.encryption" :items="encryptionOptions" class="w-full sm:w-64" />
        </UFormField>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <UFormField :label="$t('emailSender.username')">
            <UInput v-model="form.username" autocomplete="off" class="w-full" />
          </UFormField>
          <UFormField :label="$t('emailSender.password')" :description="hasPassword ? $t('emailSender.passwordSet') : undefined">
            <UInput v-model="form.password" type="password" autocomplete="new-password" :placeholder="hasPassword ? '••••••••' : ''" class="w-full" />
          </UFormField>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <UFormField :label="$t('emailSender.fromAddress')" :description="$t('emailSender.fromAddressHint')">
            <UInput v-model="form.fromAddress" type="email" placeholder="facturi@example.com" class="w-full" />
          </UFormField>
          <UFormField :label="$t('emailSender.fromName')">
            <UInput v-model="form.fromName" class="w-full" />
          </UFormField>
        </div>

        <p v-if="lastTestedAt" class="text-sm text-(--ui-text-muted)">
          {{ $t('emailSender.lastTested') }}: {{ new Date(lastTestedAt).toLocaleString() }}
        </p>

        <div class="flex flex-wrap justify-between gap-2 pt-2">
          <UButton
            v-if="hasConfig"
            variant="ghost"
            color="error"
            icon="i-lucide-trash-2"
            :label="$t('common.delete')"
            @click="remove"
          />
          <div class="flex gap-2 ms-auto">
            <UButton :loading="testing" color="neutral" icon="i-lucide-send" :label="$t('emailSender.sendTest')" @click="test" />
            <UButton :loading="saving" color="primary" :label="$t('common.save')" @click="save" />
          </div>
        </div>
      </div>
    </UPageCard>
  </div>
</template>
