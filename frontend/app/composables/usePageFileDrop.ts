/**
 * Whole-page drag & drop for file imports: while a file is dragged over the window the page shows
 * an overlay, and dropping hands the files to the caller (bank statements, borderouri, marketplace
 * exports). Nested drag events are counted so moving over child elements does not flicker.
 */
export function usePageFileDrop(onFiles: (files: File[]) => void, options: { accept?: string[], enabled?: Ref<boolean>, onRejected?: (files: File[]) => void } = {}) {
  const dragging = ref(false)
  const accept = (options.accept ?? []).map(e => e.toLowerCase())
  let depth = 0

  function hasFiles(e: DragEvent): boolean {
    return !!e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')
  }
  function active(): boolean {
    return options.enabled ? options.enabled.value : true
  }
  function accepted(f: File): boolean {
    if (!accept.length) return true
    const name = f.name.toLowerCase()
    return accept.some(ext => name.endsWith(ext))
  }
  function onDragEnter(e: DragEvent) {
    if (!active() || !hasFiles(e)) return
    e.preventDefault()
    depth++
    dragging.value = true
  }
  function onDragOver(e: DragEvent) {
    if (!active() || !hasFiles(e)) return
    e.preventDefault()
    if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy'
  }
  function onDragLeave(e: DragEvent) {
    if (!active() || !hasFiles(e)) return
    depth = Math.max(0, depth - 1)
    if (depth === 0) dragging.value = false
  }
  function onDrop(e: DragEvent) {
    if (!active() || !hasFiles(e)) return
    e.preventDefault()
    depth = 0
    dragging.value = false
    const all = Array.from(e.dataTransfer?.files ?? [])
    const files = all.filter(accepted)
    const rejected = all.filter(f => !accepted(f))
    if (rejected.length) options.onRejected?.(rejected)
    if (files.length) onFiles(files)
  }

  onMounted(() => {
    window.addEventListener('dragenter', onDragEnter)
    window.addEventListener('dragover', onDragOver)
    window.addEventListener('dragleave', onDragLeave)
    window.addEventListener('drop', onDrop)
  })
  onBeforeUnmount(() => {
    window.removeEventListener('dragenter', onDragEnter)
    window.removeEventListener('dragover', onDragOver)
    window.removeEventListener('dragleave', onDragLeave)
    window.removeEventListener('drop', onDrop)
  })

  return { dragging }
}
