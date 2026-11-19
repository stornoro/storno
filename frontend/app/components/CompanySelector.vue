<script setup lang="ts">
import type { Company } from '~/types'

const { t: $t } = useI18n()

defineProps<{
  collapsed?: boolean
}>()

const companyStore = useCompanyStore()

// Below this many companies the menu stays a plain list: no search, no recents.
const SEARCH_THRESHOLD = 8
const RECENT_LIMIT = 3
const RECENT_KEY = 'storno:recent-companies'

const open = ref(false)
const query = ref('')
const activeIndex = ref(0)
const recentIds = ref<string[]>([])
const listEl = ref<HTMLElement | null>(null)

const selectedCompany = computed(() => companyStore.currentCompany)
const showSearch = computed(() => companyStore.companies.length >= SEARCH_THRESHOLD)

function normalize(value: string): string {
  return value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
}

function identifier(company: Company): string {
  if (company.isIndividual) return $t('companies.individualBadge')
  return company.vatPayer ? `RO ${company.cif}` : String(company.cif)
}

const sorted = computed(() =>
  [...companyStore.companies].sort((a, b) => a.name.localeCompare(b.name, 'ro', { sensitivity: 'base' })),
)

const needle = computed(() => normalize(query.value.trim()))

const matches = computed(() => {
  const q = needle.value
  if (!q) return sorted.value
  const digits = q.replace(/^ro\s*/, '').replace(/\s/g, '')
  return sorted.value.filter(c =>
    normalize(c.name).includes(q) || (digits !== '' && String(c.cif).includes(digits)),
  )
})

const recent = computed(() => {
  if (!showSearch.value || needle.value) return []
  return recentIds.value
    .filter(id => id !== companyStore.currentCompanyId)
    .map(id => companyStore.companies.find(c => c.id === id))
    .filter((c): c is Company => !!c)
    .slice(0, RECENT_LIMIT)
})

// One flat list for keyboard navigation: recents first, then every match.
const rows = computed(() => [
  ...recent.value.map(company => ({ key: `recent-${company.id}`, company })),
  ...matches.value.map(company => ({ key: company.id, company })),
])

function loadRecent() {
  try {
    const stored = JSON.parse(localStorage.getItem(RECENT_KEY) ?? '[]')
    recentIds.value = Array.isArray(stored) ? stored.filter(id => typeof id === 'string') : []
  }
  catch {
    recentIds.value = []
  }
}

function rememberRecent(id: string) {
  recentIds.value = [id, ...recentIds.value.filter(x => x !== id)].slice(0, RECENT_LIMIT + 1)
  try {
    localStorage.setItem(RECENT_KEY, JSON.stringify(recentIds.value))
  }
  catch {
    // Private mode or blocked storage: recents live for this page only.
  }
}

function select(company: Company) {
  const previous = companyStore.currentCompanyId
  if (previous && previous !== company.id) rememberRecent(previous)
  companyStore.selectCompany(company.id)
  open.value = false
}

function scrollActiveIntoView() {
  nextTick(() => {
    listEl.value?.querySelector<HTMLElement>('[data-active="true"]')?.scrollIntoView({ block: 'nearest' })
  })
}

function move(step: number) {
  if (!rows.value.length) return
  activeIndex.value = Math.max(0, Math.min(rows.value.length - 1, activeIndex.value + step))
  scrollActiveIntoView()
}

function onEnter() {
  const row = rows.value[activeIndex.value]
  if (row) select(row.company)
}

function onEscape(event: KeyboardEvent) {
  // First Escape clears the search, the second one closes the menu.
  if (query.value) {
    event.stopPropagation()
    query.value = ''
  }
}

watch(query, () => {
  activeIndex.value = 0
  if (listEl.value) listEl.value.scrollTop = 0
})

watch(open, (isOpen) => {
  if (!isOpen) return
  query.value = ''
  loadRecent()
  activeIndex.value = 0
})
</script>

<template>
  <UPopover
    v-model:open="open"
    :content="{ align: collapsed ? 'start' : 'center', collisionPadding: 12 }"
    :ui="{ content: collapsed ? 'w-72' : 'w-(--reka-popover-trigger-width) min-w-64' }"
  >
    <UButton
      v-bind="{
        label: collapsed ? undefined : selectedCompany?.name ?? $t('company.selectCompany'),
        trailingIcon: collapsed ? undefined : 'i-lucide-chevrons-up-down'
      }"
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      class="data-[state=open]:bg-elevated"
      :class="[!collapsed && 'py-2']"
      :ui="{
        trailingIcon: 'text-dimmed'
      }"
    >
      <template #leading>
        <UAvatar
          :text="selectedCompany?.name?.substring(0, 2)?.toUpperCase() ?? '??'"
          size="2xs"
        />
      </template>
    </UButton>

    <template #content>
      <div class="flex flex-col">
        <div v-if="showSearch" class="flex items-center gap-2 border-b border-default px-2 py-1.5">
          <UInput
            v-model="query"
            autofocus
            variant="none"
            size="sm"
            icon="i-lucide-search"
            class="flex-1 min-w-0"
            :placeholder="$t('company.searchPlaceholder')"
            :aria-label="$t('company.searchPlaceholder')"
            autocomplete="off"
            @keydown.down.prevent="move(1)"
            @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="onEnter"
            @keydown.esc="onEscape"
          />
          <span class="shrink-0 text-xs text-dimmed tabular-nums">
            {{ needle ? $t('company.countOf', { shown: matches.length, total: companyStore.companies.length }) : companyStore.companies.length }}
          </span>
        </div>

        <div ref="listEl" class="max-h-80 overflow-y-auto p-1" role="listbox">
          <p v-if="!rows.length" class="px-3 py-6 text-center text-sm text-muted">
            {{ $t('company.noResults') }}
          </p>

          <template v-for="(row, index) in rows" :key="row.key">
            <p
              v-if="recent.length && (index === 0 || index === recent.length)"
              class="px-2 pt-2 pb-1 text-[11px] font-semibold uppercase tracking-wide text-dimmed"
            >
              {{ index === 0 ? $t('company.recent') : $t('company.all') }}
            </p>
            <button
              type="button"
              role="option"
              :aria-selected="row.company.id === companyStore.currentCompanyId"
              :data-active="index === activeIndex"
              class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-elevated data-[active=true]:bg-elevated focus-visible:outline-2 focus-visible:outline-primary"
              @click="select(row.company)"
              @mousemove="activeIndex = index"
            >
              <UIcon v-if="row.company.isReadOnly" name="i-lucide-lock" class="size-5 shrink-0 text-dimmed" />
              <UAvatar v-else :text="row.company.name.substring(0, 2).toUpperCase()" :alt="row.company.name" size="2xs" />
              <span class="min-w-0 flex-1">
                <span class="block truncate" :class="row.company.id === companyStore.currentCompanyId && 'font-semibold'">{{ row.company.name }}</span>
                <span class="block truncate text-xs text-dimmed tabular-nums">
                  {{ identifier(row.company) }}<template v-if="row.company.isReadOnly"> · {{ $t('company.readOnly') }}</template>
                </span>
              </span>
              <UIcon v-if="row.company.id === companyStore.currentCompanyId" name="i-lucide-check" class="size-4 shrink-0 text-primary" />
            </button>
          </template>
        </div>

        <div class="flex flex-col border-t border-default p-1">
          <UButton
            to="/companies"
            color="neutral"
            variant="ghost"
            size="sm"
            icon="i-lucide-circle-plus"
            class="justify-start"
            :label="$t('company.addCompany')"
            @click="open = false"
          />
          <UButton
            to="/companies"
            color="neutral"
            variant="ghost"
            size="sm"
            icon="i-lucide-cog"
            class="justify-start"
            :label="$t('company.manageCompanies')"
            @click="open = false"
          />
        </div>
      </div>
    </template>
  </UPopover>
</template>
