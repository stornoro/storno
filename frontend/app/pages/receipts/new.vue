<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
navigateTo('/receipts?create=true', { replace: true })
</script>
