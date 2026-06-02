import { createSharedComposable } from '@vueuse/core'
import type { User } from '~/types'

export interface WhiteLabelConfig {
  appName?: string | null
  logoUrl?: string | null
  primaryColor?: string | null
  removeBranding?: boolean
}

const DEFAULT_NAME = 'Storno.ro'
const DEFAULT_LOGO = '/logo.png'

// Mix a hex color toward white or black by `amount` (0..1) in sRGB.
function mix(hex: string, toWhite: boolean, amount: number): string {
  const n = parseInt(hex.slice(1), 16)
  const r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255
  const t = toWhite ? 255 : 0
  const mc = (c: number) => Math.round(c + (t - c) * amount)
  return '#' + [mc(r), mc(g), mc(b)].map(c => c.toString(16).padStart(2, '0')).join('')
}

// Build an 11-stop Tailwind-style palette from a single base hex (base = 500).
function buildScale(hex: string): Record<string, string> {
  return {
    50: mix(hex, true, 0.95),
    100: mix(hex, true, 0.9),
    200: mix(hex, true, 0.75),
    300: mix(hex, true, 0.6),
    400: mix(hex, true, 0.3),
    500: hex,
    600: mix(hex, false, 0.12),
    700: mix(hex, false, 0.24),
    800: mix(hex, false, 0.36),
    900: mix(hex, false, 0.48),
    950: mix(hex, false, 0.6),
  }
}

const _useWhiteLabel = () => {
  const config = useState<WhiteLabelConfig | null>('white_label', () => null)
  const logoObjectUrl = useState<string | null>('white_label_logo', () => null)

  const isActive = computed(() => !!config.value)
  const appName = computed(() => config.value?.appName || DEFAULT_NAME)
  const logoSrc = computed(() => logoObjectUrl.value || DEFAULT_LOGO)
  const removeBranding = computed(() => config.value?.removeBranding === true)

  function applyColor(hex?: string | null) {
    if (!import.meta.client) return
    const id = 'wl-theme'
    document.getElementById(id)?.remove()
    if (!hex || !/^#[0-9a-fA-F]{6}$/.test(hex)) return
    const scale = buildScale(hex)
    const css = ':root,.dark{'
      + Object.entries(scale).map(([s, c]) => `--ui-color-primary-${s}:${c};`).join('')
      + `--ui-primary:${scale[500]};}`
    const style = document.createElement('style')
    style.id = id
    style.textContent = css
    document.head.appendChild(style)
  }

  async function loadLogo(url?: string | null) {
    if (!import.meta.client) return
    if (logoObjectUrl.value) {
      URL.revokeObjectURL(logoObjectUrl.value)
      logoObjectUrl.value = null
    }
    if (!url) return
    try {
      const { apiFetch } = useApi()
      const blob = await apiFetch<Blob>(url, { responseType: 'blob' })
      logoObjectUrl.value = URL.createObjectURL(blob)
    }
    catch {
      logoObjectUrl.value = null
    }
  }

  function applyFromUser(user: User) {
    const wl = (user.organization as { whiteLabel?: WhiteLabelConfig | null } | undefined)?.whiteLabel ?? null
    config.value = wl
    applyColor(wl?.primaryColor)
    loadLogo(wl?.logoUrl)
  }

  function reset() {
    config.value = null
    applyColor(null)
    loadLogo(null)
  }

  return {
    config,
    isActive,
    appName,
    logoSrc,
    removeBranding,
    applyFromUser,
    reset,
  }
}

export const useWhiteLabel = createSharedComposable(_useWhiteLabel)
