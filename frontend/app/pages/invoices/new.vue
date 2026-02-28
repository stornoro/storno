<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const route = useRoute()
const refundOf = route.query.refundOf as string | undefined

// Redirect to invoices index which handles creation via slideover
if (refundOf) {
  navigateTo(`/invoices?refundOf=${refundOf}`, { replace: true })
}
else {
  navigateTo('/invoices?create=true', { replace: true })
}
</script>
