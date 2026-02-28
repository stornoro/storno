<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
navigateTo('/delivery-notes?create=true', { replace: true })
</script>
