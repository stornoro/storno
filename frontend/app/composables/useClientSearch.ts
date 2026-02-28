import type { Client } from '~/types'

export function useClientSearch() {
  const clientStore = useClientStore()
  const clients = ref<Client[]>([])

  const clientOptions = computed(() =>
    clients.value.map(c => ({
      label: `${c.name} (${c.cui || c.cnp || '-'})`,
      value: c.id,
    })),
  )

  function getSelectedClient(clientIdGetter: () => string | null) {
    return computed(() =>
      clients.value.find(c => c.id === clientIdGetter()) || null,
    )
  }

  const onClientSearch = useDebounceFn(async (val: string) => {
    if (!val || val.length < 2) return
    await clientStore.setSearch(val)
    await clientStore.fetchClients()
    clients.value = clientStore.items
  }, 300)

  async function loadClients() {
    await clientStore.fetchClients()
    clients.value = clientStore.items
  }

  function ensureClientInList(client: Client | null | undefined) {
    if (client && !clients.value.find(c => c.id === client.id)) {
      clients.value = [client, ...clients.value]
    }
  }

  return {
    clients,
    clientOptions,
    getSelectedClient,
    onClientSearch,
    loadClients,
    ensureClientInList,
  }
}
