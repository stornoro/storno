<script setup lang="ts">
const props = defineProps<{
  dateFrom?: string
  dateTo?: string
  loading?: boolean
}>()

const { t: $t } = useI18n()
const intlLocale = useIntlLocale()
const { get } = useApi()

interface TopProduct {
  id: string
  name: string
  amount: string
  quantity: string
}

interface TopProductsResponse {
  currency: string
  products: TopProduct[]
}

const fetching = ref(false)
const data = ref<TopProductsResponse | null>(null)

async function fetchData() {
  if (!props.dateFrom || !props.dateTo) return
  fetching.value = true
  try {
    const params: Record<string, string | number> = {
      dateFrom: props.dateFrom,
      dateTo: props.dateTo,
      limit: 5,
    }
    data.value = await get<TopProductsResponse>('/v1/dashboard/top-products-revenue', params)
  }
  catch {
    data.value = null
  }
  finally {
    fetching.value = false
  }
}

watch([() => props.dateFrom, () => props.dateTo], () => {
  fetchData()
}, { immediate: true })

const isLoading = computed(() => props.loading || fetching.value)
const currency = computed(() => data.value?.currency ?? 'RON')
const products = computed(() => data.value?.products ?? [])

const maxAmount = computed(() => {
  if (!products.value.length) return 1
  return Math.max(...products.value.map(p => Number(p.amount)), 1)
})

function formatMoney(amount: string | number) {
  const num = Number(amount || 0)
  return new Intl.NumberFormat(intlLocale, { maximumFractionDigits: 0 }).format(num)
}

function formatQuantity(qty: string) {
  const num = Number(qty || 0)
  return new Intl.NumberFormat(intlLocale, { maximumFractionDigits: 3 }).format(num)
}

function getPercent(amount: string) {
  return Math.round((Number(amount) / maxAmount.value) * 100)
}
</script>

<template>
  <div class="rounded-lg border border-(--ui-border) bg-(--ui-bg) overflow-hidden flex flex-col h-full">
    <div class="px-5 pt-4 pb-2">
      <h3 class="text-sm font-bold tracking-wide text-(--ui-text) uppercase">
        {{ $t('dashboard.widgets.topProductsRevenue.name') }}
      </h3>
    </div>

    <div class="px-5 pb-5 flex-1 flex flex-col">
      <template v-if="isLoading">
        <div v-for="i in 5" :key="i" class="mb-3">
          <USkeleton class="w-full h-4 mb-1" />
          <USkeleton class="w-3/4 h-2" />
        </div>
      </template>
      <template v-else>
        <div v-if="products.length" class="space-y-3 flex-1">
          <div v-for="product in products" :key="product.id" class="space-y-1">
            <div class="flex items-center justify-between text-sm">
              <div class="min-w-0 flex-1 mr-2">
                <span class="text-(--ui-text-muted) truncate block">{{ product.name }}</span>
                <span class="text-xs text-(--ui-text-muted)">{{ formatQuantity(product.quantity) }} {{ $t('common.units').toLowerCase() }}</span>
              </div>
              <span class="font-semibold text-(--ui-text) tabular-nums whitespace-nowrap">
                {{ formatMoney(product.amount) }} {{ currency }}
              </span>
            </div>
            <div class="h-1.5 bg-(--ui-bg-elevated) rounded-full overflow-hidden">
              <div
                class="h-full bg-success rounded-full transition-all"
                :style="{ width: `${getPercent(product.amount)}%` }"
              />
            </div>
          </div>
        </div>

        <div v-else class="flex-1 flex flex-col items-center justify-center text-center py-4">
          <UIcon name="i-lucide-package" class="size-10 text-(--ui-text-muted) mb-3" />
          <p class="text-sm text-(--ui-text-muted)">{{ $t('dashboard.cards.noPeriodData') }}</p>
        </div>
      </template>
    </div>
  </div>
</template>
