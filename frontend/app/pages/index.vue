<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

navigateTo('/dashboard', { redirectCode: 301 })
</script>

<template>
  <div />
</template>
