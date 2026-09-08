<script setup lang="ts">
import type { RelatedItem, RelatedResponse, RelatedType } from '~/types'

/**
 * Everything in Storno connected to one record: the tenant's client behind a rental case file,
 * the invoices and recurring invoice of that client, the return filed from the case file, the SPV
 * message that answered it. One card, the same on every detail page; each row jumps to its page.
 */
const props = defineProps<{ type: RelatedType, id: string, exclude?: string[] }>()
const { get } = useApi()
const { t } = useI18n()

const data = ref<RelatedResponse | null>(null)
const loading = ref(false)
const failed = ref(false)

const icons: Record<string, string> = {
  dosare: 'i-lucide-folder-open', clients: 'i-lucide-users', suppliers: 'i-lucide-truck', invoices: 'i-lucide-file-text',
  recurringInvoices: 'i-lucide-repeat', declarations: 'i-lucide-landmark', spvRequests: 'i-lucide-send', spvDocuments: 'i-lucide-inbox',
}
const statusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral' | 'info'> = {
  paid: 'success', accepted: 'success', active: 'success', validated: 'info', issued: 'info', submitted: 'info', answered: 'success',
  overdue: 'error', rejected: 'error', error: 'error', attention: 'warning', pending: 'warning', requested: 'warning', unread: 'warning',
  draft: 'neutral', closed: 'neutral', paused: 'neutral', read: 'neutral', cancelled: 'neutral',
}

const groups = computed(() => {
  const g = data.value?.groups ?? {}
  return (Object.keys(g) as Array<keyof typeof g>)
    .filter(k => !(props.exclude ?? []).includes(k) && (g[k]?.length ?? 0) > 0)
    .map(k => ({ key: k, items: g[k] as RelatedItem[] }))
})

async function load() {
  loading.value = true
  failed.value = false
  try {
    data.value = await get<RelatedResponse>(`/v1/related/${props.type}/${props.id}`)
  } catch {
    failed.value = true
    data.value = null
  } finally {
    loading.value = false
  }
}
watch(() => [props.type, props.id], load, { immediate: true })
defineExpose({ reload: load })
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-link-2" class="size-4 text-(--ui-text-muted)" />
        <span class="font-semibold text-sm">{{ t('related.title') }}</span>
      </div>
    </template>
    <div v-if="loading && !data" class="space-y-2">
      <USkeleton class="h-4 w-2/3" />
      <USkeleton class="h-4 w-1/2" />
    </div>
    <p v-else-if="failed" class="text-sm text-(--ui-text-muted)">{{ t('related.error') }}</p>
    <p v-else-if="!groups.length" class="text-sm text-(--ui-text-muted)">{{ t('related.empty') }}</p>
    <div v-else class="space-y-4">
      <div v-for="g in groups" :key="g.key">
        <div class="flex items-center gap-1.5 text-xs uppercase tracking-wide text-(--ui-text-muted) mb-1.5">
          <UIcon :name="icons[g.key]" class="size-3.5" />
          <span>{{ t(`related.groups.${g.key}`) }}</span>
          <span class="tabular-nums">· {{ g.items.length }}</span>
        </div>
        <ul class="divide-y divide-(--ui-border)">
          <li v-for="it in g.items" :key="it.type + it.id">
            <NuxtLink :to="it.href ?? '#'" class="flex items-center justify-between gap-3 py-1.5 -mx-1 px-1 rounded hover:bg-(--ui-bg-elevated) transition-colors">
              <div class="min-w-0">
                <div class="text-sm font-medium truncate">{{ it.title }}</div>
                <div v-if="it.subtitle || it.date" class="text-xs text-(--ui-text-muted) truncate">
                  <span v-if="it.date">{{ it.date }}</span><span v-if="it.date && it.subtitle"> · </span><span v-if="it.subtitle">{{ it.subtitle }}</span>
                </div>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <span v-if="it.type === 'invoice' && (it.balance ?? 0) > 0" class="text-xs tabular-nums text-warning">{{ it.balance }}</span>
                <UBadge v-if="it.status" :color="statusColor[it.status] ?? 'neutral'" variant="subtle" size="xs">{{ it.status }}</UBadge>
                <UIcon name="i-lucide-arrow-up-right" class="size-3.5 text-(--ui-text-muted)" />
              </div>
            </NuxtLink>
          </li>
        </ul>
      </div>
    </div>
  </UCard>
</template>
