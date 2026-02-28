<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
navigateTo('/efactura?tab=messages', { redirectCode: 301 })
</script>
