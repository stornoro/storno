export function useTableSelection<T extends Record<string, any>>(
  items: Ref<T[]> | ComputedRef<T[]>,
  opts?: { idKey?: string, canSelect?: (item: T) => boolean },
) {
  const idKey = opts?.idKey ?? 'id'
  const canSelect = opts?.canSelect

  const selectedIds = ref<string[]>([]) as Ref<string[]>

  const selectableItems = computed(() => {
    const list = unref(items)
    return canSelect ? list.filter(canSelect) : list
  })

  const allSelected = computed({
    get: () => {
      const selectable = selectableItems.value
      return selectable.length > 0 && selectable.every(item => selectedIds.value.includes(String(item[idKey])))
    },
    set: (val: boolean) => {
      if (val) {
        selectedIds.value = selectableItems.value.map(item => String(item[idKey]))
      }
      else {
        selectedIds.value = []
      }
    },
  })

  function toggle(id: string) {
    const idx = selectedIds.value.indexOf(id)
    if (idx === -1) {
      selectedIds.value = [...selectedIds.value, id]
    }
    else {
      selectedIds.value = selectedIds.value.filter(r => r !== id)
    }
  }

  function isSelected(id: string): boolean {
    return selectedIds.value.includes(id)
  }

  function clear() {
    selectedIds.value = []
  }

  const count = computed(() => selectedIds.value.length)

  return {
    selectedIds,
    allSelected,
    toggle,
    isSelected,
    clear,
    count,
  }
}
