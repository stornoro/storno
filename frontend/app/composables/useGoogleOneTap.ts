declare global {
  interface Window {
    google?: {
      accounts: {
        id: {
          initialize: (config: any) => void
          prompt: (callback?: (notification: any) => void) => void
          renderButton: (element: HTMLElement, config: any) => void
          cancel: () => void
        }
      }
    }
  }
}

export function useGoogleOneTap() {
  const config = useRuntimeConfig()
  const authStore = useAuthStore()
  const isReady = ref(false)
  const isLoading = ref(false)

  function loadScript(): Promise<void> {
    return new Promise((resolve, reject) => {
      if (window.google?.accounts?.id) {
        resolve()
        return
      }

      const existing = document.querySelector('script[src*="accounts.google.com/gsi/client"]')
      if (existing) {
        existing.addEventListener('load', () => resolve())
        return
      }

      const script = document.createElement('script')
      script.src = 'https://accounts.google.com/gsi/client'
      script.async = true
      script.defer = true
      script.onload = () => resolve()
      script.onerror = () => reject(new Error('Failed to load Google Identity Services'))
      document.head.appendChild(script)
    })
  }

  async function initialize() {
    const clientId = config.public.googleClientId
    if (!clientId) return

    try {
      await loadScript()

      window.google!.accounts.id.initialize({
        client_id: clientId,
        callback: handleCredential,
        auto_select: false,
        cancel_on_tap_outside: true,
      })

      isReady.value = true
    } catch (err) {
      console.warn('Google One Tap initialization failed:', err)
    }
  }

  async function handleCredential(response: { credential: string }) {
    isLoading.value = true
    try {
      const result = await authStore.loginWithGoogle(response.credential)
      if (result === 'mfa_required') {
        await navigateTo('/mfa-verify')
      } else if (result === true) {
        await navigateTo('/dashboard')
      } else {
        useToast().add({ title: authStore.error || 'Google login failed', color: 'error' })
      }
    } catch {
      useToast().add({ title: 'Google login failed', color: 'error' })
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Reliable Google Sign-In using a hidden rendered button.
   * Unlike prompt() (One Tap), this always opens the Google account picker popup
   * and doesn't suffer from cooldowns or dismissal issues.
   */
  function signIn() {
    if (!isReady.value) return

    // Create an offscreen container for the official Google button
    const container = document.createElement('div')
    container.style.position = 'fixed'
    container.style.top = '-9999px'
    container.style.left = '-9999px'
    document.body.appendChild(container)

    // Render the official Google button (which always works)
    window.google!.accounts.id.renderButton(container, {
      type: 'standard',
      size: 'large',
    })

    // The rendered button creates a div[role="button"] inside an iframe,
    // but there's also a clickable div wrapper. Click it to trigger the popup.
    requestAnimationFrame(() => {
      const btn = container.querySelector('div[role="button"]') as HTMLElement
      if (btn) {
        btn.click()
      } else {
        // Fallback: try clicking the first interactive element
        const clickable = container.querySelector('[tabindex="0"]') as HTMLElement
        if (clickable) clickable.click()
      }
      // Clean up after a delay
      setTimeout(() => container.remove(), 1000)
    })
  }

  function prompt() {
    if (!isReady.value) return
    window.google!.accounts.id.prompt((notification: any) => {
      // If One Tap can't display, fall back to the button-based popup
      if (notification.isNotDisplayed?.() || notification.isSkippedMoment?.()) {
        signIn()
      }
    })
  }

  function renderButton(element: HTMLElement, options?: any) {
    if (!isReady.value) return
    window.google!.accounts.id.renderButton(element, {
      type: 'standard',
      theme: 'outline',
      size: 'large',
      width: '100%',
      ...options,
    })
  }

  return {
    initialize,
    isReady,
    isLoading,
    prompt,
    signIn,
    renderButton,
    handleCredential,
  }
}
