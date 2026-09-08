/**
 * Paid-social attribution, browser side.
 *
 * Ads land on storno.ro or app.storno.ro with `?fbclid=...`. Meta's
 * Conversions API wants that click echoed back as `_fbc` (fb.1.<ms>.<fbclid>)
 * plus a browser id `_fbp`, so we store both as first-party cookies on the
 * parent domain. The app reads them at registration and the backend reports
 * the sign-up server-side. No third-party script is loaded.
 */
export default defineNuxtPlugin(() => {
  if (typeof window === 'undefined') return

  // Share the cookies across storno.ro and app.storno.ro; leave host-only for
  // localhost and IP addresses, where a Domain attribute would be rejected.
  const host = window.location.hostname
  const isIp = /^\d+\.\d+\.\d+\.\d+$/.test(host)
  const domain = host.includes('.') && !isIp ? host.split('.').slice(-2).join('.') : undefined
  const ninetyDays = 60 * 60 * 24 * 90

  const fbc = useCookie<string | null>('_fbc', { maxAge: ninetyDays, path: '/', sameSite: 'lax', domain, default: () => null })
  const fbp = useCookie<string | null>('_fbp', { maxAge: ninetyDays, path: '/', sameSite: 'lax', domain, default: () => null })
  const utm = useCookie<Record<string, string> | null>('storno_utm', { maxAge: ninetyDays, path: '/', sameSite: 'lax', domain, default: () => null })

  const params = new URLSearchParams(window.location.search)

  const fbclid = params.get('fbclid')
  if (fbclid && /^[A-Za-z0-9_-]{1,512}$/.test(fbclid)) {
    fbc.value = `fb.1.${Date.now()}.${fbclid}`
  }

  if (!fbp.value) {
    fbp.value = `fb.1.${Date.now()}.${Math.floor(Math.random() * 1e10)}`
  }

  const utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term']
  const found: Record<string, string> = {}
  for (const key of utmKeys) {
    const value = params.get(key)
    if (value && value.length <= 200) found[key] = value
  }
  if (Object.keys(found).length > 0) {
    utm.value = { ...found, landing: window.location.origin + window.location.pathname }
  }
})
