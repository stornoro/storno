<script setup lang="ts">
const colorMode = useColorMode()

useHead({
  titleTemplate: (title) => title ? `${title} - Storno.ro` : 'Storno.ro',
  link: [
    { rel: 'icon', type: 'image/x-icon', href: '/favicon.ico' },
    { rel: 'icon', type: 'image/png', href: '/logo.png' },
    { rel: 'apple-touch-icon', href: '/logo.png' },
  ],
  meta: [
    { name: 'description', content: 'Gestioneaza facturile tale electronice ANAF cu Storno.ro.' },
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
