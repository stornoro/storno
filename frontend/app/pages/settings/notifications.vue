<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('notificationPreferences.title') })
const { get, put, post } = useApi()

interface Preference {
  eventType: string
  emailEnabled: boolean
  inAppEnabled: boolean
  pushEnabled: boolean
  telegramEnabled: boolean
  whatsappEnabled: boolean
  smsEnabled: boolean
}

interface TelegramStatus {
  linked: boolean
  configured: boolean
}

const loading = ref(true)
const saving = ref(false)
const preferences = ref<Preference[]>([])
const telegramStatus = ref<TelegramStatus | null>(null)
const telegramLinking = ref(false)
const telegramUnlinking = ref(false)

const authStore = useAuthStore()
const { patch } = useApi()
const respectQuietHours = ref<boolean>(authStore.user?.respectQuietHours ?? true)
const quietHoursSaving = ref(false)

watch(() => authStore.user?.respectQuietHours, (v) => {
  if (typeof v === 'boolean') respectQuietHours.value = v
})

async function toggleQuietHours(value: boolean) {
  const previous = respectQuietHours.value
  respectQuietHours.value = value
  quietHoursSaving.value = true
  try {
    await patch('/v1/me', { respectQuietHours: value })
    if (authStore.user) authStore.user.respectQuietHours = value
    useToast().add({ title: $t('notificationPreferences.saveSuccess'), color: 'success' })
  }
  catch {
    respectQuietHours.value = previous
    useToast().add({ title: $t('notificationPreferences.saveError'), color: 'error' })
  }
  finally {
    quietHoursSaving.value = false
  }
}

const categories = [
  {
    key: 'invoices',
    label: $t('notificationPreferences.categories.invoices'),
    events: ['invoice.validated', 'invoice.rejected', 'invoice.due_soon', 'invoice.due_today', 'invoice.overdue'],
  },
  {
    key: 'proformas',
    label: $t('notificationPreferences.categories.proformas'),
    events: ['proforma.expiring_soon', 'proforma.expired'],
  },
  {
    key: 'sync',
    label: $t('notificationPreferences.categories.sync'),
    events: ['sync.error', 'efactura.new_documents'],
  },
  {
    key: 'tokens',
    label: $t('notificationPreferences.categories.tokens'),
    events: ['token.expiring_soon', 'token.refresh_failed'],
  },
  {
    key: 'exports',
    label: $t('notificationPreferences.categories.exports'),
    events: ['export_ready'],
  },
  {
    key: 'reports',
    label: $t('notificationPreferences.categories.reports'),
    events: ['report.monthly_summary'],
  },
  {
    key: 'system',
    label: $t('notificationPreferences.categories.system'),
    events: ['backup_ready', 'restore_completed'],
  },
]

function getPreference(eventType: string, field: keyof Preference): boolean {
  const pref = preferences.value.find(p => p.eventType === eventType)
  return pref ? (pref[field] as boolean) : false
}

function setPreference(eventType: string, field: keyof Preference, value: boolean) {
  const pref = preferences.value.find(p => p.eventType === eventType)
  if (pref) {
    ;(pref as any)[field] = value
  }
}

async function fetchPreferences() {
  loading.value = true
  try {
    const res = await get<{ data: Preference[] }>('/v1/notification-preferences')
    preferences.value = res.data
  }
  catch {
    useToast().add({ title: $t('error.generic'), color: 'error' })
  }
  finally {
    loading.value = false
  }
}

async function savePreferences() {
  saving.value = true
  try {
    const res = await put<{ data: Preference[] }>('/v1/notification-preferences', {
      preferences: preferences.value,
    })
    preferences.value = res.data
    useToast().add({ title: $t('notificationPreferences.saveSuccess'), color: 'success' })
  }
  catch {
    useToast().add({ title: $t('notificationPreferences.saveError'), color: 'error' })
  }
  finally {
    saving.value = false
  }
}

async function fetchTelegramStatus() {
  try {
    telegramStatus.value = await get<TelegramStatus>('/v1/telegram/status')
  }
  catch {
    telegramStatus.value = null
  }
}

async function linkTelegram() {
  telegramLinking.value = true
  try {
    const res = await post<{ url: string }>('/v1/telegram/link', {})
    window.open(res.url, '_blank')
  }
  catch {
    useToast().add({ title: $t('error.generic'), color: 'error' })
  }
  finally {
    telegramLinking.value = false
  }
}

async function unlinkTelegram() {
  telegramUnlinking.value = true
  try {
    await post('/v1/telegram/unlink', {})
    if (telegramStatus.value) {
      telegramStatus.value.linked = false
    }
    useToast().add({ title: $t('notificationPreferences.unlinkTelegram'), color: 'success' })
  }
  catch {
    useToast().add({ title: $t('error.generic'), color: 'error' })
  }
  finally {
    telegramUnlinking.value = false
  }
}

onMounted(() => {
  fetchPreferences()
  fetchTelegramStatus()
})
</script>

<template>
  <div>
    <UPageCard
      :title="$t('notificationPreferences.title')"
      :description="$t('notificationPreferences.description')"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        :label="$t('common.save')"
        color="neutral"
        icon="i-lucide-save"
        :loading="saving"
        class="w-fit lg:ms-auto"
        @click="savePreferences"
      />
    </UPageCard>

    <UPageCard variant="subtle" class="mb-8">
      <div class="flex items-start justify-between gap-4">
        <div class="flex-1">
          <div class="text-sm font-medium flex items-center gap-2">
            <UIcon name="i-lucide-moon" class="size-4 text-(--ui-text-muted)" />
            {{ $t('notificationPreferences.quietHours.title') }}
          </div>
          <p class="text-xs text-(--ui-text-muted) mt-1">
            {{ $t('notificationPreferences.quietHours.description') }}
          </p>
        </div>
        <USwitch
          :model-value="respectQuietHours"
          :loading="quietHoursSaving"
          size="md"
          @update:model-value="toggleQuietHours"
        />
      </div>
    </UPageCard>

    <!-- Telegram linking -->
    <div v-if="telegramStatus !== null" class="mb-8">
      <UPageCard
        :title="'Telegram'"
        :description="$t('notificationPreferences.telegramDescription')"
        variant="naked"
        class="mb-4"
      />
      <UPageCard variant="subtle">
        <div v-if="!telegramStatus.configured" class="text-sm text-muted">
          {{ $t('notificationPreferences.telegramNotConfigured') }}
        </div>
        <div v-else class="flex items-center justify-between gap-4">
          <div class="flex items-center gap-2">
            <UBadge
              :color="telegramStatus.linked ? 'success' : 'neutral'"
              variant="subtle"
            >
              {{ telegramStatus.linked ? $t('notificationPreferences.telegramLinked') : $t('notificationPreferences.telegramNotLinked') }}
            </UBadge>
          </div>
          <div class="flex items-center gap-2">
            <UButton
              v-if="!telegramStatus.linked"
              :label="$t('notificationPreferences.linkTelegram')"
              color="neutral"
              icon="i-lucide-send"
              :loading="telegramLinking"
              @click="linkTelegram"
            />
            <UButton
              v-else
              :label="$t('notificationPreferences.unlinkTelegram')"
              color="error"
              variant="soft"
              icon="i-lucide-unlink"
              :loading="telegramUnlinking"
              @click="unlinkTelegram"
            />
          </div>
        </div>
      </UPageCard>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <UIcon name="i-lucide-loader-2" class="size-6 animate-spin text-muted" />
    </div>

    <template v-else>
      <div v-for="(section, index) in categories" :key="section.key" :class="index > 0 ? 'mt-8' : ''">
        <UPageCard
          :title="section.label"
          variant="naked"
          class="mb-4"
        />

        <UPageCard variant="subtle">
          <div
            v-for="eventType in section.events"
            :key="eventType"
            class="flex items-center justify-between not-last:pb-4 gap-2"
          >
            <span class="text-sm">{{ $t(`notificationPreferences.events.${eventType}`) }}</span>
            <div class="flex items-center gap-6">
              <label class="flex items-center gap-2">
                <USwitch
                  :model-value="getPreference(eventType, 'emailEnabled')"
                  size="sm"
                  @update:model-value="setPreference(eventType, 'emailEnabled', $event)"
                />
                <span class="text-xs text-muted">{{ $t('notificationPreferences.email') }}</span>
              </label>
              <label class="flex items-center gap-2">
                <USwitch
                  :model-value="getPreference(eventType, 'inAppEnabled')"
                  size="sm"
                  @update:model-value="setPreference(eventType, 'inAppEnabled', $event)"
                />
                <span class="text-xs text-muted">{{ $t('notificationPreferences.inApp') }}</span>
              </label>
              <label class="flex items-center gap-2">
                <USwitch
                  :model-value="getPreference(eventType, 'pushEnabled')"
                  size="sm"
                  @update:model-value="setPreference(eventType, 'pushEnabled', $event)"
                />
                <span class="text-xs text-muted">{{ $t('notificationPreferences.push') }}</span>
              </label>
              <label class="flex items-center gap-2">
                <USwitch
                  :model-value="getPreference(eventType, 'telegramEnabled')"
                  size="sm"
                  @update:model-value="setPreference(eventType, 'telegramEnabled', $event)"
                />
                <span class="text-xs text-muted">{{ $t('notificationPreferences.telegram') }}</span>
              </label>
              <label class="flex items-center gap-2">
                <USwitch
                  :model-value="getPreference(eventType, 'whatsappEnabled')"
                  size="sm"
                  @update:model-value="setPreference(eventType, 'whatsappEnabled', $event)"
                />
                <span class="text-xs text-muted">{{ $t('notificationPreferences.whatsapp') }}</span>
              </label>
              <label class="flex items-center gap-2">
                <USwitch
                  :model-value="getPreference(eventType, 'smsEnabled')"
                  size="sm"
                  @update:model-value="setPreference(eventType, 'smsEnabled', $event)"
                />
                <span class="text-xs text-muted">{{ $t('notificationPreferences.sms') }}</span>
              </label>
            </div>
          </div>
        </UPageCard>
      </div>
    </template>
  </div>
</template>
