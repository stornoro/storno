import { Centrifuge } from 'centrifuge'
import type { Subscription, PublicationContext } from 'centrifuge'

let centrifuge: Centrifuge | null = null
let planGated = false
const subscriptions = new Map<string, Subscription>()

// Several components may listen on one channel (a list page and a detail page during a route
// change, the dashboard's two composables). Each keeps its own callback; the channel is left
// only when the last one goes.
type ChannelCallback = (data: any) => void
const listeners = new Map<string, Map<ChannelCallback, { resync: boolean }>>()

/** Sent to listeners that asked for it when the connection came back and missed messages. */
export const REALTIME_RESYNC = 'realtime.resync'

type TokenFetcher = <T>(path: string, body?: Record<string, unknown>) => Promise<T>
let fetchTokenRef: TokenFetcher | null = null

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

    if (pending.size === 0 || !fetchTokenRef) return

    // Single channel — use simple endpoint for backwards compat
    if (pending.size === 1) {
      const [channel, { resolve, reject }] = [...pending.entries()][0]
      try {
        const res = await fetchTokenRef<{ token: string }>('/v1/centrifugo/subscription-token', { channel })
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
      const res = await fetchTokenRef<{ tokens: Record<string, string> }>('/v1/centrifugo/subscription-token', { channels })
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
  const { apiFetch } = useApi()
  const authStore = useAuthStore()

  /**
   * A realtime token, asked for quietly. Realtime tokens and the app session both last an
   * hour, so a tab left open renews its realtime token just when the session has expired:
   * the quiet call gets a 401. A normal call then renews the session and is retried.
   */
  async function fetchToken<T>(path: string, body?: Record<string, unknown>): Promise<T> {
    try {
      return await apiFetch<T>(path, { method: 'POST', body, skipAuthRedirect: true })
    }
    catch (e: any) {
      const status = e?.response?.status ?? e?.statusCode
      if (status === 401 && authStore.token) {
        return await apiFetch<T>(path, { method: 'POST', body })
      }
      throw e
    }
  }

  // Store refs for the batch token fetcher
  apiFetchRef = apiFetch
  fetchTokenRef = fetchToken

  function getClient(): Centrifuge | null {
    if (!import.meta.client) return null

    // Don't attempt connection if plan doesn't support realtime
    if (planGated) return null

    if (centrifuge) return centrifuge

    const wsUrl = `${location.protocol === 'https:' ? 'wss:' : 'ws:'}//${location.host}/connection/websocket`

    let tokenFailed = false

    centrifuge = new Centrifuge(wsUrl, {
      getToken: async () => {
        try {
          const res = await fetchToken<{ token: string }>('/v1/centrifugo/connection-token')
          tokenFailed = false
          return res.token
        }
        catch (e: any) {
          const status = e?.response?.status ?? e?.statusCode
          // Plan gate: stop for good. Signed out: stop now, a later subscribe starts again.
          if (status === 402 || status === 401) {
            planGated = status === 402
            centrifuge?.disconnect()
            centrifuge = null
            subscriptions.clear()
            listeners.clear()
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

  function subscribe(
    channel: string,
    callback: ChannelCallback,
    options?: { recover?: boolean, resync?: boolean },
  ): Subscription | null {
    const client = getClient()
    if (!client) return null

    let callbacks = listeners.get(channel)
    if (!callbacks) {
      callbacks = new Map()
      listeners.set(channel, callbacks)
    }
    callbacks.set(callback, { resync: options?.resync ?? false })

    const existing = subscriptions.get(channel)
    if (existing) return existing

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

    const deliver = (data: any, onlyResync: boolean) => {
      for (const [cb, opts] of [...(listeners.get(channel) ?? [])]) {
        if (onlyResync && !opts.resync) continue
        try {
          cb(data)
        }
        catch (e) {
          console.error(`[Centrifugo] Listener failed on ${channel}:`, e)
        }
      }
    }

    sub.on('publication', (ctx: PublicationContext) => deliver(ctx.data, false))

    // Back after a drop (sleep, network change) with messages that could not be replayed:
    // the pages that asked for it refetch instead of showing stale data.
    sub.on('subscribed', (ctx) => {
      if (ctx.wasRecovering && !ctx.recovered) {
        deliver({ type: REALTIME_RESYNC }, true)
      }
    })

    sub.on('error', (ctx) => {
      console.error(`[Centrifugo] Subscription error on ${channel}:`, ctx)
    })

    sub.subscribe()
    subscriptions.set(channel, sub)
    return sub
  }

  /**
   * Stop listening. With a callback, only that listener goes and the channel stays open for
   * the others; without one, the channel is dropped for everybody.
   */
  function unsubscribe(channel: string, callback?: ChannelCallback): void {
    const callbacks = listeners.get(channel)
    if (callback && callbacks) {
      callbacks.delete(callback)
      if (callbacks.size > 0) return
    }
    listeners.delete(channel)

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
    listeners.clear()

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
