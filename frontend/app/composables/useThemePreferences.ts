import { createSharedComposable, useDebounceFn } from '@vueuse/core'
import type { User } from '~/types'

const DEFAULT_PRIMARY = 'green'
const DEFAULT_NEUTRAL = 'slate'
const DEFAULT_COLOR_MODE = 'dark' as const

interface ThemePrefs {
  primaryColor?: string
  neutralColor?: string
  colorMode?: 'light' | 'dark'
}

const _useThemePreferences = () => {
  const appConfig = useAppConfig()
  const colorMode = useColorMode()
  const themeCookie = useCookie<ThemePrefs | null>('theme_prefs', {
    maxAge: 60 * 60 * 24 * 365,
    path: '/',
    default: () => null,
  })

  // Whether we've loaded from the user (prevents saving cookie-only values back to API)
  const initialized = ref(false)
  // Prevents save during apply
  let applying = false

  function apply(prefs: ThemePrefs | null | undefined) {
    if (!prefs) return
    applying = true
    if (prefs.primaryColor) {
      appConfig.ui.colors.primary = prefs.primaryColor
    }
    if (prefs.neutralColor) {
      appConfig.ui.colors.neutral = prefs.neutralColor
    }
    if (prefs.colorMode) {
      colorMode.preference = prefs.colorMode
    }
    nextTick(() => { applying = false })
  }

  // Apply from cookie immediately (SSR hydration — no flash)
  if (themeCookie.value) {
    apply(themeCookie.value)
  }

  function applyFromUser(user: User) {
    if (user.preferences) {
      apply(user.preferences)
      // Sync cookie with DB values
      themeCookie.value = user.preferences
    }
    initialized.value = true
  }

  const debouncedSave = useDebounceFn(async (prefs: ThemePrefs) => {
    try {
      const { patch } = useApi()
      await patch('/v1/me', { preferences: prefs })
    }
    catch {
      // Silently fail — preferences are non-critical
    }
  }, 500)

  function save(prefs: ThemePrefs) {
    themeCookie.value = prefs
    if (initialized.value) {
      debouncedSave(prefs)
    }
  }

  function getCurrentPrefs(): ThemePrefs {
    return {
      primaryColor: appConfig.ui.colors.primary,
      neutralColor: appConfig.ui.colors.neutral,
      colorMode: colorMode.preference === 'dark' ? 'dark' : 'light',
    }
  }

  // Watch for changes and auto-save
  if (import.meta.client) {
    watch(
      () => appConfig.ui.colors.primary,
      () => { if (!applying) save(getCurrentPrefs()) },
    )
    watch(
      () => appConfig.ui.colors.neutral,
      () => { if (!applying) save(getCurrentPrefs()) },
    )
    watch(
      () => colorMode.preference,
      () => { if (!applying) save(getCurrentPrefs()) },
    )
  }

  function reset() {
    applying = true
    appConfig.ui.colors.primary = DEFAULT_PRIMARY
    appConfig.ui.colors.neutral = DEFAULT_NEUTRAL
    colorMode.preference = DEFAULT_COLOR_MODE
    themeCookie.value = null
    initialized.value = false
    nextTick(() => { applying = false })
  }

  return {
    applyFromUser,
    reset,
  }
}

export const useThemePreferences = createSharedComposable(_useThemePreferences)
