export function useClipboard() {
  const toast = useToast()
  const { t } = useI18n()

  async function copy(text: string) {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
    } else {
      // Fallback for non-secure contexts (HTTP on non-localhost)
      const textarea = document.createElement('textarea')
      textarea.value = text
      textarea.style.position = 'fixed'
      textarea.style.opacity = '0'
      document.body.appendChild(textarea)
      textarea.select()
      document.execCommand('copy')
      document.body.removeChild(textarea)
    }
    toast.add({ title: t('common.copied'), color: 'success', icon: 'i-lucide-check' })
  }

  return { copy }
}
