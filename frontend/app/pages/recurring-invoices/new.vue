<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
navigateTo('/recurring-invoices?create=true', { replace: true })
</script>
