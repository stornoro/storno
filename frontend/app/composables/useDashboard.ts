import { createSharedComposable } from '@vueuse/core'

const _useDashboard = () => {
  const route = useRoute()
  const router = useRouter()
  const isNotificationsSlideoverOpen = ref(false)
  const isShortcutsModalOpen = ref(false)

  if (import.meta.client) {
    defineShortcuts({
      // Navigation shortcuts (g = go)
      'g-h': () => router.push('/dashboard'),
      'g-i': () => router.push('/invoices'),
      'g-o': () => router.push('/proforma-invoices'),
      'g-a': () => router.push('/delivery-notes'),
      'g-t': () => router.push('/recurring-invoices'),
      'g-c': () => router.push('/clients'),
      'g-p': () => router.push('/products'),
      'g-e': () => router.push('/efactura'),
      'g-s': () => router.push('/settings/profile'),
      'g-f': () => router.push('/suppliers'),
      'g-r': () => router.push('/reports'),
      'g-m': () => router.push('/efactura?tab=messages'),

      // Create shortcuts (c = create)
      'c-i': () => router.push('/invoices?create=true'),
      'c-p': () => router.push('/proforma-invoices?create=true'),
      'c-a': () => router.push('/delivery-notes?create=true'),
      'c-r': () => router.push('/recurring-invoices?create=true'),

      // Toggles
      'n': () => isNotificationsSlideoverOpen.value = !isNotificationsSlideoverOpen.value,
      'shift_/': () => isShortcutsModalOpen.value = !isShortcutsModalOpen.value,
    })
  }

  watch(() => route.fullPath, () => {
    isNotificationsSlideoverOpen.value = false
  })

  return {
    isNotificationsSlideoverOpen,
    isShortcutsModalOpen,
  }
}

export const useDashboard = createSharedComposable(_useDashboard)
