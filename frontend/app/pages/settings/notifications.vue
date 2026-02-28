<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('notificationPreferences.title') })
const { get, put } = useApi()

interface Preference {
  eventType: string
  emailEnabled: boolean
  inAppEnabled: boolean
  pushEnabled: boolean
  whatsappEnabled: boolean
}

const loading = ref(true)
const saving = ref(false)
const preferences = ref<Preference[]>([])

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
    events: ['sync.completed', 'sync.error', 'efactura.new_documents'],
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

onMounted(() => {
  fetchPreferences()
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
                  :model-value="getPreference(eventType, 'whatsappEnabled')"
                  size="sm"
                  @update:model-value="setPreference(eventType, 'whatsappEnabled', $event)"
                />
                <span class="text-xs text-muted">{{ $t('notificationPreferences.whatsapp') }}</span>
              </label>
            </div>
          </div>
        </UPageCard>
      </div>
    </template>
  </div>
</template>
