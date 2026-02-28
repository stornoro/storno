<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
navigateTo('/proforma-invoices?create=true', { replace: true })
</script>
