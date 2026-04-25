<script setup lang="ts">
import type { CatalogWidget, WidgetCategory } from '~/stores/dashboardConfig'
import { WIDGET_CATALOG } from '~/stores/dashboardConfig'

const props = defineProps<{
  modelValue: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'add': [id: string]
}>()

const { t: $t } = useI18n()
const configStore = useDashboardConfigStore()

const isOpen = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

// Widgets that are currently hidden (can be added)
const hiddenWidgetIds = computed(() => new Set(configStore.hiddenWidgets.map(w => w.id)))

const availableWidgets = computed<CatalogWidget[]>(() =>
  WIDGET_CATALOG.filter(c => hiddenWidgetIds.value.has(c.id)),
)

// Group by category
const categories: WidgetCategory[] = ['sales', 'expenses', 'clients', 'activity', 'charts', 'system']

const categoryLabel: Record<WidgetCategory, string> = {
  sales: 'dashboard.edit.categories.sales',
  expenses: 'dashboard.edit.categories.expenses',
  clients: 'dashboard.edit.categories.clients',
  activity: 'dashboard.edit.categories.activity',
  charts: 'dashboard.edit.categories.charts',
  system: 'dashboard.edit.categories.system',
}

const categoryIcon: Record<WidgetCategory, string> = {
  sales: 'i-lucide-trending-up',
  expenses: 'i-lucide-receipt',
  clients: 'i-lucide-users',
  activity: 'i-lucide-activity',
  charts: 'i-lucide-bar-chart-3',
  system: 'i-lucide-settings',
}

const groupedWidgets = computed(() => {
  const result: { category: WidgetCategory; label: string; icon: string; widgets: CatalogWidget[] }[] = []

  for (const cat of categories) {
    const widgets = availableWidgets.value.filter(w => w.category === cat)
    if (widgets.length > 0) {
      result.push({
        category: cat,
        label: categoryLabel[cat] ?? cat,
        icon: categoryIcon[cat] ?? 'i-lucide-grid',
        widgets,
      })
    }
  }

  return result
})

const sizeLabel: Record<string, string> = {
  sm: 'dashboard.edit.size.sm',
  md: 'dashboard.edit.size.md',
  lg: 'dashboard.edit.size.lg',
  xl: 'dashboard.edit.size.xl',
}

function handleAdd(id: string) {
  emit('add', id)
}
</script>

<template>
  <UModal v-model:open="isOpen" :title="$t('dashboard.edit.addWidget')" :ui="{ body: 'p-0' }">
    <template #body>
      <div class="p-4">
        <template v-if="groupedWidgets.length">
          <div v-for="group in groupedWidgets" :key="group.category" class="mb-6 last:mb-0">
            <div class="flex items-center gap-2 mb-3">
              <UIcon :name="group.icon" class="size-4 text-(--ui-text-muted)" />
              <h4 class="text-sm font-semibold text-(--ui-text-muted) uppercase tracking-wide">
                {{ $t(group.label) }}
              </h4>
            </div>

            <div class="space-y-2">
              <div
                v-for="widget in group.widgets"
                :key="widget.id"
                class="flex items-center justify-between p-3 rounded-lg border border-(--ui-border) bg-(--ui-bg-elevated)/50 hover:bg-(--ui-bg-elevated) transition-colors"
              >
                <div class="flex-1 min-w-0 mr-3">
                  <div class="flex items-center gap-2 mb-0.5">
                    <span class="text-sm font-medium text-(--ui-text)">{{ $t(widget.name_key) }}</span>
                    <UBadge color="neutral" variant="subtle" size="xs">
                      {{ $t(sizeLabel[widget.size] ?? widget.size) }}
                    </UBadge>
                  </div>
                  <p class="text-xs text-(--ui-text-muted) truncate">{{ $t(widget.description_key) }}</p>
                </div>
                <UButton
                  size="sm"
                  color="primary"
                  variant="soft"
                  icon="i-lucide-plus"
                  @click="handleAdd(widget.id)"
                >
                  {{ $t('common.add') }}
                </UButton>
              </div>
            </div>
          </div>
        </template>

        <div v-else class="flex flex-col items-center justify-center py-12 text-center">
          <UIcon name="i-lucide-layout-grid" class="size-12 text-(--ui-text-muted) mb-3" />
          <p class="text-sm font-medium text-(--ui-text)">{{ $t('dashboard.edit.allWidgetsAdded') }}</p>
          <p class="text-xs text-(--ui-text-muted) mt-1">{{ $t('dashboard.edit.allWidgetsAddedDesc') }}</p>
        </div>
      </div>
    </template>
  </UModal>
</template>
