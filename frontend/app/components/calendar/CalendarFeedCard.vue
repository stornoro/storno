<script setup lang="ts">
/**
 * The member's calendar subscription: a secret iCalendar link with the expiries (vehicles,
 * contracts, certificates) and the fiscal deadlines, for Apple Calendar, Google Calendar or Outlook.
 */
interface CalendarFeed {
  enabled: boolean
  url?: string
  webcalUrl?: string
  googleCalendarUrl?: string
  outlookUrl?: string
  includeExpiries?: boolean
  includeFiscal?: boolean
  canSeeExpiries: boolean
  canSeeFiscal: boolean
  createdAt?: string
  lastFetchedAt?: string | null
}

const { t: $t } = useI18n()
const { get, post, patch, del } = useApi()
const { copy } = useClipboard()
const toast = useToast()
const intlLocale = useIntlLocale()

const feed = ref<CalendarFeed | null>(null)
const loading = ref(true)
const busy = ref(false)

async function load() {
  loading.value = true
  try {
    feed.value = await get<CalendarFeed>('/v1/calendar-feed')
  }
  catch {
    feed.value = null
  }
  finally {
    loading.value = false
  }
}
onMounted(load)

async function run(action: () => Promise<CalendarFeed>, success?: string) {
  busy.value = true
  try {
    feed.value = await action()
    if (success) toast.add({ title: success, color: 'success' })
  }
  catch (e: any) {
    toast.add({ title: e?.data?.error ?? $t('common.error'), color: 'error' })
  }
  finally {
    busy.value = false
  }
}

const enable = () => run(() => post<CalendarFeed>('/v1/calendar-feed'), $t('calendarFeed.enabled'))
const setOption = (key: 'includeExpiries' | 'includeFiscal', value: boolean) => run(() => patch<CalendarFeed>('/v1/calendar-feed', { [key]: value }))
function regenerate() {
  if (!confirm($t('calendarFeed.confirmRegenerate'))) return
  run(() => post<CalendarFeed>('/v1/calendar-feed', { regenerate: true }), $t('calendarFeed.regenerated'))
}
function disable() {
  if (!confirm($t('calendarFeed.confirmDisable'))) return
  run(async () => {
    await del('/v1/calendar-feed')
    return { enabled: false, canSeeExpiries: feed.value?.canSeeExpiries ?? false, canSeeFiscal: feed.value?.canSeeFiscal ?? false }
  }, $t('calendarFeed.disabled'))
}

const lastFetched = computed(() => feed.value?.lastFetchedAt
  ? new Date(feed.value.lastFetchedAt).toLocaleString(intlLocale, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
  : null)
</script>

<template>
  <div>
    <div v-if="loading" class="space-y-2">
      <USkeleton class="h-5 w-2/3" />
      <USkeleton class="h-9 w-full" />
    </div>

    <div v-else-if="!feed" class="text-sm text-muted">
      {{ $t('common.error') }}
    </div>

    <div v-else-if="!feed.enabled" class="space-y-3">
      <p class="text-sm text-muted">{{ $t('calendarFeed.intro') }}</p>
      <UButton icon="i-lucide-calendar-plus" :loading="busy" @click="enable">
        {{ $t('calendarFeed.enable') }}
      </UButton>
    </div>

    <div v-else class="space-y-4">
      <p class="text-sm text-muted">{{ $t('calendarFeed.addTo') }}</p>
      <div class="flex flex-wrap gap-2">
        <UButton :to="feed.webcalUrl" external icon="i-simple-icons-apple" color="neutral" variant="outline">
          {{ $t('calendarFeed.apple') }}
        </UButton>
        <UButton :to="feed.googleCalendarUrl" external target="_blank" icon="i-simple-icons-googlecalendar" color="neutral" variant="outline">
          Google Calendar
        </UButton>
        <UButton :to="feed.outlookUrl" external target="_blank" icon="i-simple-icons-microsoftoutlook" color="neutral" variant="outline">
          Outlook
        </UButton>
      </div>

      <UFormField :label="$t('calendarFeed.link')" :help="$t('calendarFeed.linkHelp')">
        <div class="flex gap-2">
          <UInput :model-value="feed.url" readonly class="flex-1 font-mono text-xs" @focus="($event.target as HTMLInputElement).select()" />
          <UButton icon="i-lucide-copy" color="neutral" variant="soft" :aria-label="$t('calendarFeed.copy')" @click="copy(feed.url!)" />
        </div>
      </UFormField>

      <div class="space-y-2">
        <label class="flex items-center gap-3">
          <USwitch :model-value="feed.includeExpiries" :disabled="busy || !feed.canSeeExpiries" @update:model-value="setOption('includeExpiries', $event)" />
          <span class="text-sm">{{ $t('calendarFeed.includeExpiries') }}</span>
        </label>
        <label class="flex items-center gap-3">
          <USwitch :model-value="feed.includeFiscal" :disabled="busy || !feed.canSeeFiscal" @update:model-value="setOption('includeFiscal', $event)" />
          <span class="text-sm">{{ $t('calendarFeed.includeFiscal') }}</span>
        </label>
      </div>

      <p class="text-xs text-muted">
        {{ $t('calendarFeed.refreshNote') }}
        <template v-if="lastFetched"> {{ $t('calendarFeed.lastFetched', { date: lastFetched }) }}</template>
      </p>

      <div class="flex flex-wrap gap-2 pt-1">
        <UButton icon="i-lucide-refresh-cw" color="neutral" variant="ghost" size="sm" :loading="busy" @click="regenerate">
          {{ $t('calendarFeed.regenerate') }}
        </UButton>
        <UButton icon="i-lucide-calendar-x" color="error" variant="ghost" size="sm" :loading="busy" @click="disable">
          {{ $t('calendarFeed.disable') }}
        </UButton>
      </div>
    </div>
  </div>
</template>
