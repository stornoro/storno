import { Centrifuge } from 'centrifuge'
import type { Subscription, PublicationContext } from 'centrifuge'

let centrifuge: Centrifuge | null = null
let planGated = false
const subscriptions = new Map<string, Subscription>()

// Batch token fetching: collect channels and resolve all tokens in a single request
let pendingTokenRequests = new Map<string, { resolve: (token: string) => void, reject: (err: any) => void }>()
let batchTimer: ReturnType<typeof setTimeout> | null = null
let apiFetchRef: ReturnType<typeof useApi>['apiFetch'] | null = null

function scheduleBatchTokenFetch() {
  if (batchTimer) return
  batchTimer = setTimeout(async () => {
    batchTimer = null
    const pending = pendingTokenRequests
    pendingTokenRequests = new Map()

    if (pending.size === 0 || !apiFetchRef) return

    // Single channel — use simple endpoint for backwards compat
    if (pending.size === 1) {
      const [channel, { resolve, reject }] = [...pending.entries()][0]
      try {
        const res = await apiFetchRef<{ token: string }>('/v1/centrifugo/subscription-token', {
          method: 'POST',
          body: { channel },
          skipAuthRedirect: true,
        })
        resolve(res.token)
      }
      catch (e) {
        console.warn(`[Centrifugo] Failed to get subscription token for ${channel}:`, e)
        reject(e)
      }
      return
    }

    // Batch request
    const channels = [...pending.keys()]
    try {
      const res = await apiFetchRef<{ tokens: Record<string, string> }>('/v1/centrifugo/subscription-token', {
        method: 'POST',
        body: { channels },
        skipAuthRedirect: true,
      })
      for (const [channel, { resolve }] of pending) {
        resolve(res.tokens[channel] || '')
      }
    }
    catch (e) {
      console.warn('[Centrifugo] Failed to batch-fetch subscription tokens:', e)
      for (const [, { reject }] of pending) {
        reject(e)
      }
    }
  }, 10)
}

function getSubscriptionToken(channel: string): Promise<string> {
  return new Promise((resolve, reject) => {
    pendingTokenRequests.set(channel, { resolve, reject })
    scheduleBatchTokenFetch()
  })
}

/**
 * Composable for real-time communication via Centrifugo WebSocket.
 * Only connects on the client side.
 */
export function useCentrifugo() {
  const config = useRuntimeConfig()
  const { apiFetch } = useApi()

  // Store ref for batch token fetcher
  apiFetchRef = apiFetch

  function getClient(): Centrifuge | null {
    if (!import.meta.client) return null

    // Don't attempt connection if plan doesn't support realtime
    if (planGated) return null

    if (centrifuge) return centrifuge

    const wsUrl = config.public.centrifugoWs || 'ws://localhost:8444/connection/websocket'

    let tokenFailed = false

    centrifuge = new Centrifuge(wsUrl, {
      getToken: async () => {
        try {
          const res = await apiFetch<{ token: string }>('/v1/centrifugo/connection-token', { method: 'POST', skipAuthRedirect: true })
          tokenFailed = false
          return res.token
        }
        catch (e: any) {
          const status = e?.response?.status ?? e?.statusCode
          // Plan gate or not authenticated — stop retrying entirely
          if (status === 402 || status === 401) {
            planGated = true
            centrifuge?.disconnect()
            centrifuge = null
            return ''
          }
          tokenFailed = true
          console.warn('[Centrifugo] Failed to get connection token:', e)
          return ''
        }
      },
    })

    centrifuge.on('error', (ctx) => {
      // Don't log errors caused by token fetch failures
      if (!tokenFailed) {
        console.warn('[Centrifugo] Connection error:', ctx)
      }
    })

    centrifuge.connect()
    return centrifuge
  }

  function subscribe(channel: string, callback: (data: any) => void, options?: { recover?: boolean }): Subscription | null {
    const client = getClient()
    if (!client) return null

    // Return existing subscription if already subscribed in our map
    const existing = subscriptions.get(channel)
    if (existing) {
      existing.on('publication', (ctx: PublicationContext) => callback(ctx.data))
      return existing
    }

    // Check if the Centrifuge client already has this subscription (e.g. from a previous navigation)
    let sub = client.getSubscription(channel)
    if (sub) {
      // Re-use the existing subscription from the client's internal registry
      sub.removeAllListeners()
    }
    else {
      sub = client.newSubscription(channel, {
        getToken: () => getSubscriptionToken(channel),
        ...(options?.recover ? { recover: true } : {}),
      })
    }

    sub.on('publication', (ctx: PublicationContext) => callback(ctx.data))

    sub.on('error', (ctx) => {
      console.error(`[Centrifugo] Subscription error on ${channel}:`, ctx)
    })

    sub.subscribe()
    subscriptions.set(channel, sub)
    return sub
  }

  function unsubscribe(channel: string): void {
    const sub = subscriptions.get(channel)
    if (sub) {
      sub.unsubscribe()
      sub.removeAllListeners()
      centrifuge?.removeSubscription(sub)
      subscriptions.delete(channel)
    }
  }

  function disconnect(): void {
    subscriptions.forEach((sub) => {
      sub.unsubscribe()
      sub.removeAllListeners()
      centrifuge?.removeSubscription(sub)
    })
    subscriptions.clear()

    if (centrifuge) {
      centrifuge.disconnect()
      centrifuge = null
    }
    planGated = false
  }

  return {
    subscribe,
    unsubscribe,
    disconnect,
    getClient,
  }
}
