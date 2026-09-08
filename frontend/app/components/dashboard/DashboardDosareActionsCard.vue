<script setup lang="ts">
import type { DosarActionItem } from '~/types'

/** What the person has to do with ANAF: rejected filings, deadlines, expiring contracts, new answers. */
const { t: $t } = useI18n()
const store = useDosareStore()
const loading = ref(true)
const intlLocale = useIntlLocale()
function formatDate(d: string): string {
  return new Date(d).toLocaleDateString(intlLocale, { day: 'numeric', month: 'short' })
}

onMounted(async () => {
  try { await store.fetchActions() } catch { /* the card shows its empty state */ } finally { loading.value = false }
})

const todo = computed<DosarActionItem[]>(() => (store.actions?.todo ?? []).slice(0, 6))
const inProgress = computed(() => store.actions?.inProgress.length ?? 0)
const answers = computed(() => store.actions?.answers.length ?? 0)
const severityColor: Record<string, string> = { critical: 'bg-error', high: 'bg-warning', normal: 'bg-info', low: 'bg-(--ui-border-accented)' }
</script>

<template>
  <UCard class="h-full" :ui="{ body: 'p-4 sm:p-5' }">
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-folder-open" class="size-4 text-(--ui-text-muted)" />
        <span class="font-semibold text-sm">{{ $t('dashboard.widgets.dosareActions.name') }}</span>
      </div>
      <UButton to="/dosare" variant="ghost" size="xs" trailing-icon="i-lucide-arrow-right">{{ $t('common.viewAll') }}</UButton>
    </div>
    <div v-if="loading" class="space-y-2">
      <USkeleton class="h-4 w-3/4" />
      <USkeleton class="h-4 w-1/2" />
    </div>
    <template v-else>
      <ul v-if="todo.length" class="divide-y divide-(--ui-border)">
        <li v-for="a in todo" :key="a.kind + a.id">
          <NuxtLink :to="a.dosarId ? `/dosare/${a.dosarId}` : '/dosare'" class="flex items-start gap-2 py-2 -mx-1 px-1 rounded hover:bg-(--ui-bg-elevated) transition-colors">
            <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="severityColor[a.severity]" />
            <div class="min-w-0">
              <div class="text-sm truncate">{{ a.title }}</div>
              <div class="text-xs text-(--ui-text-muted) truncate">{{ [a.date ? formatDate(a.date) : null, a.subtitle].filter(Boolean).join(' · ') }}</div>
            </div>
          </NuxtLink>
        </li>
      </ul>
      <p v-else class="text-sm text-(--ui-text-muted) py-2">{{ $t('dashboard.widgets.dosareActions.nothing') }}</p>
      <div class="flex gap-4 text-xs text-(--ui-text-muted) pt-3 mt-1 border-t border-(--ui-border)">
        <span>{{ $t('dashboard.widgets.dosareActions.inProgress', { count: inProgress }) }}</span>
        <span>{{ $t('dashboard.widgets.dosareActions.answers', { count: answers }) }}</span>
      </div>
    </template>
  </UCard>
</template>
