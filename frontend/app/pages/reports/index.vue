<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
navigateTo('/reports/sales', { replace: true })
</script>
