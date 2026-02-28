declare global {
  interface Window {
    chatwootSettings?: Record<string, unknown>
    chatwootSDK?: { run: (config: Record<string, unknown>) => void }
    $chatwoot?: {
      setUser: (id: string, attrs: Record<string, unknown>) => void
      setLocale: (locale: string) => void
      setCustomAttributes: (attrs: Record<string, unknown>) => void
      toggle: (state?: 'open' | 'close') => void
      reset: () => void
    }
  }
}

export default defineNuxtPlugin(() => {
  const config = useRuntimeConfig()
  const baseUrl = config.public.chatwootBaseUrl as string
  const websiteToken = config.public.chatwootWebsiteToken as string

  if (!baseUrl || !websiteToken) return

  const authStore = useAuthStore()
  const nuxtApp = useNuxtApp()

  // Delay script injection until after hydration to avoid DOM mismatches
  nuxtApp.hook('app:suspense:resolve', () => {
    // Extra tick to ensure Vue has fully settled before Chatwoot manipulates DOM
    setTimeout(() => {
      loadChatwootScript(baseUrl, websiteToken)
    }, 100)
  })

  window.addEventListener('chatwoot:ready', () => {
    identifyUser()
  })

  // Re-identify when user changes (login/logout)
  watch(() => authStore.user, (user) => {
    if (!window.$chatwoot) return
    if (user) {
      identifyUser()
    } else {
      window.$chatwoot.reset()
    }
  })

  async function fetchIdentifierHash(identifier: string): Promise<string | null> {
    try {
      const data = await $fetch<{ identifier_hash: string }>('/_chatwoot/hash', {
        params: { identifier },
      })
      return data.identifier_hash
    } catch {
      return null
    }
  }

  async function identifyUser() {
    const user = authStore.user
    if (!window.$chatwoot) return

    // No user — reset to anonymous visitor
    if (!user) {
      window.$chatwoot.reset()
      return
    }

    const companyStore = useCompanyStore()
    const company = companyStore.currentCompany

    const identifierHash = await fetchIdentifierHash(user.id)

    // Skip setUser if identity verification hash is unavailable —
    // calling setUser without it triggers a 401 from Chatwoot
    if (!identifierHash) return

    window.$chatwoot.setUser(user.id, {
      email: user.email,
      name: [user.firstName, user.lastName].filter(Boolean).join(' ') || user.email,
      phone_number: user.phone ?? null,
      avatar_url: null,
      company_name: company?.name ?? authStore.organization?.name ?? null,
      identifier_hash: identifierHash,
    })

    window.$chatwoot.setCustomAttributes({
      plan: authStore.effectivePlan || 'free',
      role: authStore.currentRole ?? null,
      organization: authStore.organization?.name ?? null,
      company_name: company?.name ?? null,
      company_cif: company?.cif ?? null,
      company_city: company?.city ?? null,
      email_verified: user.emailVerified,
      account_created: user.createdAt,
    })
  }
})

function loadChatwootScript(baseUrl: string, websiteToken: string) {
  const isMobile = window.innerWidth <= 768

  window.chatwootSettings = {
    position: 'right',
    locale: 'ro',
    type: 'standard',
    showPopoutButton: true,
    darkMode: 'auto',
  }

  const script = document.createElement('script')
  script.src = `${baseUrl}/packs/js/sdk.js`
  script.async = true
  script.defer = true
  script.onload = () => {
    window.chatwootSDK?.run({ websiteToken, baseUrl })
  }
  document.head.appendChild(script)
}
