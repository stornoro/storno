<script setup lang="ts">
const colorMode = useColorMode()
const { t: $t } = useI18n()
const { appName } = useWhiteLabel()

useHead({
  titleTemplate: (title) => title ? `${title} - ${appName.value}` : appName.value,
  link: [
    { rel: 'icon', type: 'image/x-icon', href: '/favicon.ico' },
    { rel: 'icon', type: 'image/png', href: '/logo.png' },
    { rel: 'apple-touch-icon', href: '/logo.png' },
  ],
  meta: [
    { name: 'description', content: $t('app.metaDescription') },
    { name: 'theme-color', content: computed(() => colorMode.value === 'dark' ? '#0a0a0a' : '#ffffff') },
  ],
})
</script>

<template>
  <UApp :toaster="{ position: 'bottom-left' }">
    <NuxtLoadingIndicator />

    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </UApp>
</template>
