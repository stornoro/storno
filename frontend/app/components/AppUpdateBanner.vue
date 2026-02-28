<script setup lang="ts">
const { t: $t } = useI18n()
const authStore = useAuthStore()
const { get } = useApi()

const show = ref(false)
const currentVersion = ref('')
const latestVersion = ref('')
const isOrgAdmin = computed(() => {
  const orgId = authStore.organization?.id
  if (!orgId) return false
  return authStore.user?.memberships?.some(
    (m: any) => m.organization.id === orgId && (m.role === 'owner' || m.role === 'admin'),
  ) ?? false
})

onMounted(async () => {
  if (!authStore.isSuperAdmin && !isOrgAdmin.value) return

  try {
    const data = await get<{
      version: string
      latestVersion?: string
      updateAvailable?: boolean
    }>('/v1/system/health')

    if (data.updateAvailable && data.latestVersion) {
      currentVersion.value = data.version
      latestVersion.value = data.latestVersion
      show.value = true
    }
  }
  catch {
    // Silent fail — version check is non-critical
  }
})
</script>

<template>
  <div
    v-if="show"
    class="flex items-center justify-between gap-3 rounded-lg px-4 py-2.5 bg-primary/10 ring ring-primary/20"
  >
    <div class="flex items-center gap-2 text-sm">
      <UIcon name="i-lucide-arrow-up-circle" class="size-4 text-primary shrink-0" />
      <span>
        <span class="font-medium">{{ $t('system.updateAvailable', { version: latestVersion }) }}</span>
        <span class="text-muted ml-1">({{ $t('system.currentVersion', { version: currentVersion }) }})</span>
      </span>
    </div>
    <div class="flex items-center gap-2 shrink-0">
      <UButton
        size="xs"
        variant="soft"
        :label="$t('system.viewRelease')"
        to="https://github.com/stornoro/stornoro/releases/latest"
        target="_blank"
        external
      />
      <UButton
        size="xs"
        color="neutral"
        variant="ghost"
        icon="i-lucide-x"
        @click="show = false"
      />
    </div>
  </div>
</template>
