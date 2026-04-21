<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { t: $t } = useI18n()
useHead({ title: $t('authorizedApps.title') })

const toast = useToast()
const { get, del } = useApi()

interface AuthorizedApp {
  clientUuid: string
  name: string
  description: string | null
  logoUrl: string | null
  scopes: string[]
  lastActiveAt: string
}

const apps = ref<AuthorizedApp[]>([])
const loading = ref(true)
const revoking = ref<string | null>(null)

async function loadApps() {
  loading.value = true
  try {
    const res = await get<{ data: AuthorizedApp[] }>('/v1/me/authorized-apps')
    apps.value = res.data ?? []
  } catch (e: any) {
    toast.add({ color: 'error', title: $t('common.error'), description: e?.data?.error ?? String(e) })
  } finally {
    loading.value = false
  }
}

async function revoke(app: AuthorizedApp) {
  const confirmed = window.confirm($t('authorizedApps.confirmRevoke', { name: app.name }))
  if (!confirmed) return

  revoking.value = app.clientUuid
  try {
    await del(`/v1/me/authorized-apps/${app.clientUuid}`)
    apps.value = apps.value.filter(a => a.clientUuid !== app.clientUuid)
    toast.add({ color: 'success', title: $t('authorizedApps.revokedTitle'), description: $t('authorizedApps.revokedMsg', { name: app.name }) })
  } catch (e: any) {
    toast.add({ color: 'error', title: $t('common.error'), description: e?.data?.error ?? String(e) })
  } finally {
    revoking.value = null
  }
}

function formatDate(iso: string): string {
  try {
    return new Date(iso).toLocaleString()
  } catch {
    return iso
  }
}

onMounted(loadApps)
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar :title="$t('authorizedApps.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UPageHeader :title="$t('authorizedApps.title')" :description="$t('authorizedApps.description')" />

      <div v-if="loading" class="flex justify-center py-12">
        <UIcon name="i-lucide-loader-2" class="animate-spin text-2xl" />
      </div>

      <div v-else-if="!apps.length" class="text-center py-16 text-muted">
        <UIcon name="i-lucide-shield-check" class="text-5xl mb-3" />
        <p>{{ $t('authorizedApps.empty') }}</p>
      </div>

      <div v-else class="space-y-4 mt-6">
        <UCard v-for="app in apps" :key="app.clientUuid">
          <div class="flex items-start gap-4">
            <div class="shrink-0">
              <img
                v-if="app.logoUrl"
                :src="app.logoUrl"
                :alt="app.name"
                class="size-12 rounded-lg object-cover bg-elevated"
              >
              <div v-else class="size-12 rounded-lg bg-elevated flex items-center justify-center">
                <UIcon name="i-lucide-puzzle" class="text-xl text-muted" />
              </div>
            </div>

            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <h3 class="font-semibold text-highlighted">{{ app.name }}</h3>
              </div>
              <p v-if="app.description" class="text-sm text-muted mt-1">{{ app.description }}</p>

              <div v-if="app.scopes.length" class="flex flex-wrap gap-1.5 mt-3">
                <UBadge v-for="s in app.scopes" :key="s" color="neutral" variant="subtle" size="xs">{{ s }}</UBadge>
              </div>

              <p class="text-xs text-muted mt-3">
                {{ $t('authorizedApps.lastActive', { date: formatDate(app.lastActiveAt) }) }}
              </p>
            </div>

            <UButton
              color="error"
              variant="soft"
              size="sm"
              icon="i-lucide-x"
              :loading="revoking === app.clientUuid"
              :label="$t('authorizedApps.revoke')"
              @click="revoke(app)"
            />
          </div>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
