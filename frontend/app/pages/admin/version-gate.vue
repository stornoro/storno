<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('admin.versionGate.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UPageHeader
        :title="$t('admin.versionGate.title')"
        :description="$t('admin.versionGate.description')"
      />

      <div class="flex items-center gap-3 mb-6">
        <UButton icon="i-lucide-arrow-left" variant="ghost" to="/admin" />
      </div>

      <div v-if="loading" class="flex justify-center py-12">
        <UIcon name="i-lucide-loader-2" class="animate-spin text-2xl" />
      </div>

      <div v-else class="space-y-6">
        <UAlert
          icon="i-lucide-shield-alert"
          color="warning"
          variant="subtle"
          :title="$t('admin.versionGate.killSwitchWarning')"
          :description="$t('admin.versionGate.killSwitchWarningBody')"
        />

        <UCard v-for="row in platforms" :key="row.platform">
          <template #header>
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <UIcon :name="platformIcon(row.platform)" class="text-xl" />
                <h3 class="font-semibold capitalize">{{ row.platform }}</h3>
                <UBadge
                  v-if="row.override?.hasOverride"
                  color="warning"
                  variant="subtle"
                >
                  {{ $t('admin.versionGate.overridden') }}
                </UBadge>
              </div>
              <div v-if="row.override?.updatedAt" class="text-xs text-muted">
                {{ $t('admin.versionGate.lastChange', {
                  user: row.override.updatedBy ?? '?',
                  when: formatDate(row.override.updatedAt)
                }) }}
              </div>
            </div>
          </template>

          <div class="space-y-4">
            <div v-for="field in fieldDefs" :key="field.key" class="grid grid-cols-1 md:grid-cols-3 gap-2 md:gap-4 items-start">
              <div>
                <div class="text-sm font-medium">{{ $t(field.labelKey) }}</div>
                <div v-if="field.helpKey" class="text-xs text-muted mt-0.5">{{ $t(field.helpKey) }}</div>
              </div>
              <div class="text-sm text-muted font-mono break-all">
                {{ defaultValue(row, field.defaultKey) }}
              </div>
              <UInput
                :model-value="draftValue(row.platform, field.key)"
                :placeholder="$t('admin.versionGate.overridePlaceholder')"
                size="sm"
                @update:model-value="setDraftValue(row.platform, field.key, String($event ?? ''))"
              />
            </div>

            <div>
              <div class="text-sm font-medium mb-1">{{ $t('admin.versionGate.message') }}</div>
              <div class="text-xs text-muted mb-2">{{ $t('admin.versionGate.messageHelp') }}</div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <UInput
                  v-for="locale in messageLocales"
                  :key="locale"
                  :model-value="messageValue(row.platform, locale)"
                  :placeholder="`${locale.toUpperCase()} — ${$t('admin.versionGate.messagePlaceholder')}`"
                  @update:model-value="setMessageValue(row.platform, locale, String($event ?? ''))"
                />
              </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-default">
              <UButton
                color="neutral"
                variant="ghost"
                :loading="saving === row.platform"
                @click="reset(row.platform)"
              >
                {{ $t('admin.versionGate.clearAll') }}
              </UButton>
              <UButton
                color="primary"
                icon="i-lucide-save"
                :loading="saving === row.platform"
                @click="save(row.platform)"
              >
                {{ $t('admin.versionGate.save') }}
              </UButton>
            </div>
          </div>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
const toast = useToast()
const intlLocale = useIntlLocale()

const messageLocales = ['ro', 'en', 'de', 'fr'] as const

const fieldDefs = [
  { key: 'minOverride', defaultKey: 'min', labelKey: 'admin.versionGate.minVersion', helpKey: 'admin.versionGate.minVersionHelp' },
  { key: 'latestOverride', defaultKey: 'latest', labelKey: 'admin.versionGate.latestVersion', helpKey: 'admin.versionGate.latestVersionHelp' },
  { key: 'storeUrlOverride', defaultKey: 'storeUrl', labelKey: 'admin.versionGate.storeUrl', helpKey: '' },
  { key: 'releaseNotesUrlOverride', defaultKey: 'releaseNotesUrl', labelKey: 'admin.versionGate.releaseNotesUrl', helpKey: '' },
] as const

interface PlatformRow {
  platform: 'ios' | 'android' | 'huawei'
  defaults: {
    min: string
    latest: string
    storeUrl: string | null
    releaseNotesUrl: string | null
    message: Record<string, string> | null
  }
  effective: {
    min: string
    latest: string
    storeUrl: string | null
    releaseNotesUrl: string | null
    message: Record<string, string> | null
  }
  override: {
    minOverride: string | null
    latestOverride: string | null
    storeUrlOverride: string | null
    releaseNotesUrlOverride: string | null
    messageOverride: Record<string, string> | null
    updatedAt: string | null
    updatedBy: string | null
    hasOverride: boolean
  } | null
}

interface Draft {
  minOverride: string
  latestOverride: string
  storeUrlOverride: string
  releaseNotesUrlOverride: string
  messageOverride: Record<string, string>
}

const loading = ref(true)
const saving = ref<string | null>(null)
const platforms = ref<PlatformRow[]>([])
const drafts = ref<Record<string, Draft>>({})

function emptyDraft(): Draft {
  return {
    minOverride: '',
    latestOverride: '',
    storeUrlOverride: '',
    releaseNotesUrlOverride: '',
    messageOverride: { ro: '', en: '', de: '', fr: '' },
  }
}

function hydrate(rows: PlatformRow[]) {
  platforms.value = rows
  for (const row of rows) {
    const draft = emptyDraft()
    if (row.override) {
      draft.minOverride = row.override.minOverride ?? ''
      draft.latestOverride = row.override.latestOverride ?? ''
      draft.storeUrlOverride = row.override.storeUrlOverride ?? ''
      draft.releaseNotesUrlOverride = row.override.releaseNotesUrlOverride ?? ''
      if (row.override.messageOverride) {
        for (const locale of messageLocales) {
          draft.messageOverride[locale] = row.override.messageOverride[locale] ?? ''
        }
      }
    }
    drafts.value[row.platform] = draft
  }
}

function defaultValue(row: PlatformRow, key: string): string {
  const v = (row.defaults as Record<string, unknown>)[key]
  return v === null || v === undefined || v === '' ? '—' : String(v)
}

type DraftField = 'minOverride' | 'latestOverride' | 'storeUrlOverride' | 'releaseNotesUrlOverride'
type LocaleKey = (typeof messageLocales)[number]

function draftValue(platform: string, field: string): string {
  const draft = drafts.value[platform]
  if (!draft) return ''
  return (draft as unknown as Record<string, string>)[field] ?? ''
}

function setDraftValue(platform: string, field: string, value: string) {
  const draft = drafts.value[platform]
  if (!draft) return
  ;(draft as unknown as Record<string, string>)[field] = value
}

function messageValue(platform: string, locale: LocaleKey): string {
  return drafts.value[platform]?.messageOverride[locale] ?? ''
}

function setMessageValue(platform: string, locale: LocaleKey, value: string) {
  const draft = drafts.value[platform]
  if (!draft) return
  draft.messageOverride[locale] = value
}

async function load() {
  loading.value = true
  try {
    const { get } = useApi()
    const data = await get<{ platforms: PlatformRow[] }>('/v1/admin/version-overrides')
    hydrate(data.platforms)
  } catch (err: unknown) {
    toast.add({ title: $t('common.error'), description: String(err), color: 'error' })
  } finally {
    loading.value = false
  }
}

async function save(platform: string) {
  saving.value = platform
  try {
    const draft = drafts.value[platform]!
    const message = Object.fromEntries(
      Object.entries(draft.messageOverride).filter(([, v]) => v.trim() !== ''),
    )
    const body = {
      minOverride: draft.minOverride.trim() || null,
      latestOverride: draft.latestOverride.trim() || null,
      storeUrlOverride: draft.storeUrlOverride.trim() || null,
      releaseNotesUrlOverride: draft.releaseNotesUrlOverride.trim() || null,
      messageOverride: Object.keys(message).length ? message : null,
    }
    const { put } = useApi()
    await put(`/v1/admin/version-overrides/${platform}`, body)
    toast.add({ title: $t('admin.versionGate.savedTitle'), description: $t('admin.versionGate.savedBody', { platform }), color: 'success' })
    await load()
  } catch (err: unknown) {
    toast.add({ title: $t('common.error'), description: String(err), color: 'error' })
  } finally {
    saving.value = null
  }
}

async function reset(platform: string) {
  drafts.value[platform] = emptyDraft()
  await save(platform)
}

function platformIcon(platform: string): string {
  return {
    ios: 'i-simple-icons-apple',
    android: 'i-simple-icons-android',
    huawei: 'i-simple-icons-huawei',
  }[platform] ?? 'i-lucide-smartphone'
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString(intlLocale)
}

onMounted(load)
</script>
