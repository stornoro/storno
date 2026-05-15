export default defineI18nConfig(() => ({
  legacy: false,
  messageResolver(obj: unknown, path: string): unknown {
    if (obj == null || typeof obj !== 'object') return null
    if (Object.prototype.hasOwnProperty.call(obj, path)) {
      return (obj as Record<string, unknown>)[path]
    }
    const parts = path.split('.')
    let cur: unknown = obj
    for (let i = 0; i < parts.length; i++) {
      if (cur == null || typeof cur !== 'object') return null
      const remaining = parts.slice(i).join('.')
      if (Object.prototype.hasOwnProperty.call(cur, remaining)) {
        return (cur as Record<string, unknown>)[remaining]
      }
      cur = (cur as Record<string, unknown>)[parts[i] as string]
    }
    return cur ?? null
  },
}))
