// End-to-end smoke test of the realtime stack: the real backend service (PHP)
// publishes and signs tokens, the real browser client (centrifuge-js from the
// frontend) connects and subscribes. Run through ./run.sh, which starts the
// Centrifugo and Redis services of deploy/docker-compose.yml on a spare port.

import { test, before, after } from 'node:test'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { createRequire } from 'node:module'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { randomUUID } from 'node:crypto'

const here = dirname(fileURLToPath(import.meta.url))
const root = resolve(here, '../../..')
const require = createRequire(resolve(root, 'frontend/package.json'))
const { Centrifuge } = require('centrifuge')
const WS = require('ws')

const HTTP = process.env.CENTRIFUGO_HTTP_URL || 'http://127.0.0.1:18445'
const WS_URL = HTTP.replace(/^http/, 'ws') + '/connection/websocket'
const API_KEY = process.env.CENTRIFUGO_API_KEY
const ALLOWED_ORIGIN = process.env.CENTRIFUGO_TEST_ORIGIN || 'https://app.storno.test'
const EXPECT_MAJOR = process.env.CENTRIFUGO_EXPECT_MAJOR || ''

const USER = randomUUID()
const COMPANY = randomUUID()
const OTHER_COMPANY = randomUUID()

// ── helpers ──────────────────────────────────────────────────────────

function php(...args) {
  const out = execFileSync('php', [resolve(here, 'backend.php'), ...args.map(a => typeof a === 'string' ? a : JSON.stringify(a))], {
    env: { ...process.env, CENTRIFUGO_API_URL: HTTP + '/api' },
    encoding: 'utf8',
  })
  return JSON.parse(out)
}

function socketWithOrigin(origin) {
  return class extends WS {
    constructor(url, protocols) {
      super(url, protocols, origin ? { origin } : {})
    }
  }
}

const clients = []

function newClient({ origin = ALLOWED_ORIGIN, token } = {}) {
  const client = new Centrifuge(WS_URL, {
    websocket: socketWithOrigin(origin),
    token: token ?? php('token', USER).token,
    minReconnectDelay: 50,
    maxReconnectDelay: 200,
    timeout: 3000,
  })
  clients.push(client)
  return client
}

function connected(client) {
  return new Promise((ok, fail) => {
    const timer = setTimeout(() => fail(new Error('connect timeout')), 5000)
    client.on('connected', (ctx) => { clearTimeout(timer); ok(ctx) })
    client.on('disconnected', (ctx) => { clearTimeout(timer); fail(Object.assign(new Error(`disconnected: ${ctx.code} ${ctx.reason}`), ctx)) })
    client.connect()
  })
}

/** Subscribe the way the frontend does: a token per channel, minted by the backend. */
function subscribe(client, channel, { token, tokenChannel, collect } = {}) {
  const sub = client.newSubscription(channel, {
    ...(token === null ? {} : { token: token ?? php('subtoken', USER, tokenChannel ?? channel).token }),
  })
  const received = collect ?? []
  sub.on('publication', ctx => received.push(ctx.data))
  const ready = new Promise((ok, fail) => {
    const timer = setTimeout(() => fail(new Error(`subscribe timeout on ${channel}`)), 5000)
    sub.on('subscribed', (ctx) => { clearTimeout(timer); ok(ctx) })
    sub.on('error', (ctx) => { clearTimeout(timer); fail(Object.assign(new Error(`${ctx.error.code} ${ctx.error.message}`), ctx.error)) })
    sub.on('unsubscribed', (ctx) => { clearTimeout(timer); fail(Object.assign(new Error(`${ctx.code} ${ctx.reason}`), ctx)) })
  })
  sub.subscribe()
  return { sub, received, ready }
}

async function until(check, what, ms = 4000) {
  const start = Date.now()
  while (Date.now() - start < ms) {
    if (check()) return
    await new Promise(r => setTimeout(r, 25))
  }
  assert.fail(`timed out waiting for ${what}`)
}

function configNamespaces(file) {
  const cfg = JSON.parse(readFileSync(resolve(root, file), 'utf8'))
  return cfg.channel.namespaces
}

function helmNamespaces() {
  const tpl = readFileSync(resolve(root, 'deploy/helm/storno/templates/centrifugo-configmap.yaml'), 'utf8')
  return JSON.parse(tpl.slice(tpl.indexOf('config.json: |') + 'config.json: |'.length)).channel.namespaces
}

function controllerNamespaces() {
  const src = readFileSync(resolve(root, 'backend/src/Controller/Api/V1/CentrifugoController.php'), 'utf8')
  const list = src.match(/COMPANY_NAMESPACES = \[([^\]]+)\]/)[1]
  return [...list.matchAll(/'([a-z]+)'/g)].map(m => m[1])
}

const NAMESPACES = controllerNamespaces()

let main

before(async () => {
  main = newClient()
  if (process.env.DEBUG) {
    for (const event of ['connecting', 'connected', 'disconnected', 'error']) {
      main.on(event, ctx => console.error(`[main] ${event}`, JSON.stringify(ctx)))
    }
  }
  await connected(main)
})

after(() => {
  for (const client of clients) client.disconnect()
})

// ── configuration ────────────────────────────────────────────────────

test('the three server configs define exactly the namespaces the backend grants', () => {
  const expected = [...NAMESPACES].sort()
  const compose = configNamespaces('deploy/centrifugo.json')
  assert.deepEqual(compose.map(n => n.name).sort(), expected)
  assert.deepEqual(configNamespaces('backend/config/centrifugo.json'), compose, 'dev config drifted from deploy config')
  assert.deepEqual(helmNamespaces(), compose, 'Helm config drifted from deploy config')
})

test('no namespace lets a client subscribe or publish without a token', () => {
  for (const ns of configNamespaces('deploy/centrifugo.json')) {
    for (const key of ['allow_subscribe_for_client', 'allow_publish_for_client', 'allow_subscribe_for_anonymous', 'allow_publish_for_subscriber']) {
      assert.ok(!ns[key], `${ns.name}.${key} must stay off`)
    }
  }
})

// ── server API (what the backend calls) ──────────────────────────────

test('health endpoint answers', async () => {
  const res = await fetch(HTTP + '/health')
  assert.equal(res.status, 200)
})

test('info has the shape the health checks read', () => {
  const info = php('info')
  // The counter is refreshed every few seconds, so only its presence is checked.
  assert.equal(typeof info.result.nodes[0].num_clients, 'number')
  if (EXPECT_MAJOR) {
    assert.ok(info.result.nodes[0].version.startsWith(EXPECT_MAJOR + '.'), `server is ${info.result.nodes[0].version}`)
  }
})

test('the API refuses a call without the key', async () => {
  const call = key => fetch(HTTP + '/api/publish', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...(key ? { 'X-API-Key': key } : {}) },
    body: JSON.stringify({ channel: `invoices:company_${OTHER_COMPANY}`, data: {} }),
  })
  assert.equal((await call(null)).status, 401)
  assert.equal((await call('wrong')).status, 401)
  assert.equal((await call(API_KEY)).status, 200)
})

// ── connection ───────────────────────────────────────────────────────

test('a backend connection token is accepted', () => {
  assert.equal(main.state, 'connected')
})

test('a token signed with another secret is refused', async () => {
  const [h, p] = php('token', USER).token.split('.')
  const forged = newClient({ token: `${h}.${p}.${Buffer.from('not-the-signature').toString('base64url')}` })
  await assert.rejects(connected(forged), /3500/)
})

test('a browser on a foreign origin cannot connect', async () => {
  const foreign = newClient({ origin: 'https://evil.example' })
  let transportError = false
  foreign.on('error', () => { transportError = true })
  foreign.connect()
  await until(() => transportError, 'handshake refusal')
  assert.notEqual(foreign.state, 'connected')
  foreign.disconnect()
})

// ── subscriptions ────────────────────────────────────────────────────

for (const ns of NAMESPACES) {
  test(`${ns}: subscription token grants the company channel and publish() arrives`, async () => {
    const channel = `${ns}:company_${COMPANY}`
    const { received, ready } = subscribe(main, channel)
    await ready
    const reply = php('publish', channel, { type: 'smoke', ns })
    assert.equal(reply.error, undefined, JSON.stringify(reply))
    await until(() => received.length === 1, `publication on ${channel}`)
    assert.deepEqual(received[0], { type: 'smoke', ns })
  })
}

test('personal notifications channel works', async () => {
  const channel = `notifications:user_${USER}`
  const { received, ready } = subscribe(main, channel)
  await ready
  php('publish', channel, { type: 'notification' })
  await until(() => received.length === 1, 'personal notification')
})

// A refused subscription can cost the whole connection, so each of these gets its own.
async function freshClient() {
  const client = newClient()
  await connected(client)
  return client
}

test('subscribing without a token is refused', async () => {
  const client = await freshClient()
  await assert.rejects(subscribe(client, `invoices:company_${OTHER_COMPANY}`, { token: null }).ready, /103/)
})

test('a token minted for another channel is refused', async () => {
  const client = await freshClient()
  const other = `dashboard:company_${OTHER_COMPANY}`
  const { received, ready } = subscribe(client, other, { tokenChannel: `dashboard:company_${COMPANY}` })
  await assert.rejects(ready)
  php('publish', other, { leak: true })
  await new Promise(r => setTimeout(r, 300))
  assert.deepEqual(received, [])
})

test('an expired subscription token is refused', async () => {
  const client = await freshClient()
  const channel = `user:company_${OTHER_COMPANY}`
  const token = php('expired-subtoken', USER, channel).token
  await assert.rejects(subscribe(client, channel, { token }).ready)
})

test('a channel outside the configured namespaces is unknown', async () => {
  const client = await freshClient()
  await assert.rejects(subscribe(client, `secrets:company_${COMPANY}`).ready, /102/)
})

test('clients cannot publish', async () => {
  const sub = main.getSubscription(`invoices:company_${COMPANY}`)
  await assert.rejects(sub.publish({ forged: true }), err => err.code === 103)
})

// ── fan-out paths of CentrifugoService ───────────────────────────────

test('broadcast() reaches every channel', async () => {
  const a = main.getSubscription(`invoices:company_${COMPANY}`)
  const b = main.getSubscription(`dashboard:company_${COMPANY}`)
  const got = []
  a.on('publication', ctx => got.push(['a', ctx.data]))
  b.on('publication', ctx => got.push(['b', ctx.data]))
  const reply = php('broadcast', [a.channel, b.channel], { type: 'broadcast' })
  assert.equal(reply.error, undefined, JSON.stringify(reply))
  await until(() => got.length === 2, 'broadcast on both channels')
  assert.deepEqual(got.map(g => g[0]).sort(), ['a', 'b'])
})

test('batch() with publish and broadcast commands delivers all of them', async () => {
  const channels = ['import', 'backup', 'user'].map(ns => `${ns}:company_${COMPANY}`)
  const got = []
  for (const ch of channels) main.getSubscription(ch).on('publication', ctx => got.push(ctx.data.n))
  const { replies } = php('batch', [
    { publish: { channel: channels[0], data: { n: 1 } } },
    { publish: { channel: channels[1], data: { n: 2 } } },
    { broadcast: { channels: [channels[1], channels[2]], data: { n: 3 } } },
  ])
  assert.equal(replies.length, 3)
  for (const reply of replies) assert.equal(reply.error, undefined, JSON.stringify(reply))
  await until(() => got.length === 4, 'four batch publications')
  assert.deepEqual(got.sort(), [1, 2, 3, 3])
})

test('queue() + flush() sends the buffered commands in one go', async () => {
  const channels = ['import', 'backup'].map(ns => `${ns}:company_${COMPANY}`)
  const got = []
  for (const ch of channels) main.getSubscription(ch).on('publication', ctx => got.push(ctx.data.q))
  const res = php('queue', [
    { channel: channels[0], data: { q: 'one' } },
    { channels, data: { q: 'both' } },
  ])
  assert.deepEqual(res, { queued: 2, left: 0 })
  await until(() => got.length === 3, 'three queued publications')
})

// ── history, presence, recovery ──────────────────────────────────────

test('history is readable where the namespace keeps it', async () => {
  const sub = main.getSubscription(`invoices:company_${COMPANY}`)
  const { publications } = await sub.history({ limit: 10 })
  assert.ok(publications.length >= 2, `got ${publications.length}`)
  assert.deepEqual(publications[0].data, { type: 'smoke', ns: 'invoices' })
})

test('history is refused where the namespace keeps none', async () => {
  const sub = main.getSubscription(`dashboard:company_${COMPANY}`)
  await assert.rejects(sub.history({ limit: 10 }))
})

test('presence is readable on notifications and dashboard only', async () => {
  const { clients: present } = await main.getSubscription(`notifications:company_${COMPANY}`).presence()
  assert.equal(Object.values(present)[0].user, USER)
  await main.getSubscription(`dashboard:company_${COMPANY}`).presence()
  await assert.rejects(main.getSubscription(`invoices:company_${COMPANY}`).presence())
})

test('notifications missed while offline are recovered on reconnect', async () => {
  const channel = `notifications:company_${OTHER_COMPANY}`
  const client = newClient()
  await connected(client)
  const { sub, received, ready } = subscribe(client, channel)
  await ready
  php('publish', channel, { n: 1 })
  await until(() => received.length === 1, 'first notification')

  // Drop the transport without unsubscribing, as a network blip would.
  const resubscribed = new Promise(ok => sub.on('subscribed', ok))
  client.disconnect()
  php('publish', channel, { n: 2 })
  php('publish', channel, { n: 3 })
  client.connect()

  const ctx = await resubscribed
  assert.equal(ctx.wasRecovering, true)
  assert.equal(ctx.recovered, true)
  await until(() => received.length === 3, 'recovered notifications')
  assert.deepEqual(received.map(d => d.n), [1, 2, 3])
})
