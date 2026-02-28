<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const route = useRoute()
const uuid = route.params.uuid as string

// Redirect to invoices index which handles editing via slideover
navigateTo(`/invoices?edit=${uuid}`, { replace: true })
</script>
