export interface ToggleableColumn {
  key: string
  label: string
  default: boolean
}

export function useColumnVisibility(storageKey: string, toggleableColumns: ToggleableColumn[]) {
  function load(): Record<string, boolean> {
    try {
      const stored = localStorage.getItem(storageKey)
      if (stored) return JSON.parse(stored)
    }
    catch {}
    return Object.fromEntries(toggleableColumns.map(c => [c.key, c.default]))
  }

  const visibility = ref<Record<string, boolean>>(load())

  function toggle(key: string) {
    visibility.value[key] = !visibility.value[key]
    localStorage.setItem(storageKey, JSON.stringify(visibility.value))
  }

  function filterColumns<T extends { _always?: boolean, _toggle?: string }>(allDefs: T[]): T[] {
    return allDefs.filter(c => c._always || (c._toggle && visibility.value[c._toggle]))
  }

  return { visibility, toggle, filterColumns, toggleableColumns }
}
