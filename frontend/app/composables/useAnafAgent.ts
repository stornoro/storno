import type { AgentCertificate, AnafProxyRequest, AnafProxyResponse } from '~/types'

const AGENT_BASE = 'https://agent.storno.ro:17394'

export function useAnafAgent() {
  const agentAvailable = ref(false)
  const agentVersion = ref<string | null>(null)
  const agentChecking = ref(false)
  const agentUpdateAvailable = ref(false)
  const agentLatestVersion = ref<string | null>(null)

  async function agentFetch(path: string, opts?: RequestInit): Promise<Response> {
    return await fetch(`${AGENT_BASE}${path}`, opts)
  }

  async function checkAgent(): Promise<boolean> {
    if (!import.meta.client) return false
    agentChecking.value = true
    try {
      const res = await agentFetch('/health', {
        signal: AbortSignal.timeout(2000),
      })
      const data = await res.json()
      agentAvailable.value = data.status === 'ok'
      agentVersion.value = data.version ?? null
      agentUpdateAvailable.value = data.update?.available ?? false
      agentLatestVersion.value = data.update?.latest ?? null
      return agentAvailable.value
    } catch {
      agentAvailable.value = false
      agentVersion.value = null
      agentUpdateAvailable.value = false
      agentLatestVersion.value = null
      return false
    } finally {
      agentChecking.value = false
    }
  }

  async function triggerAgentUpdate(): Promise<{ success: boolean; message: string }> {
    try {
      const res = await agentFetch('/update', {
        method: 'POST',
        headers: { 'X-Storno-Agent': '1' },
        signal: AbortSignal.timeout(60_000),
      })
      return await res.json()
    } catch (err) {
      return { success: false, message: (err as Error).message }
    }
  }

  async function listCertificates(): Promise<AgentCertificate[]> {
    const res = await agentFetch('/certificates', {
      signal: AbortSignal.timeout(5000),
    })
    const data = await res.json()
    return data.certificates ?? []
  }

  async function proxyToAnaf(req: AnafProxyRequest): Promise<AnafProxyResponse> {
    const res = await agentFetch('/proxy', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Storno-Agent': '1',
      },
      body: JSON.stringify(req),
      signal: AbortSignal.timeout(130_000), // 120s for PIN + buffer
    })
    return await res.json()
  }

  async function submitViaAgent(declarationId: string, certificateId: string): Promise<any> {
    const { get, post } = useApi()

    // Step 1: Prepare — get XML, ANAF token, URL
    const prepared = await get<{
      xml: string
      anafUrl: string
      anafToken: string
      declarationType: string
      cif: string
    }>(`/v1/declarations/${declarationId}/prepare`)

    // Step 2: Proxy through local agent to ANAF
    const anafResponse = await proxyToAnaf({
      url: prepared.anafUrl,
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${prepared.anafToken}`,
        'Content-Type': 'application/xml',
      },
      body: prepared.xml,
      certificateId,
    })

    // Step 3: Send ANAF response back to server
    return await post(`/v1/declarations/${declarationId}/agent-result`, {
      statusCode: anafResponse.statusCode,
      headers: anafResponse.headers,
      body: anafResponse.body,
    })
  }

  interface PreparedItem {
    declarationId: string
    xml: string
    anafUrl: string
    anafToken: string
    declarationType: string
    cif: string
  }

  interface BulkSignedResult {
    declarationId: string
    statusCode: number
    headers: Record<string, string>
    body: string
    error?: string
  }

  type BulkPhase = 'preparing' | 'signing' | 'submitting'

  interface BulkProgress {
    phase: BulkPhase
    current: number
    total: number
  }

  interface BulkSubmitResult {
    processed: number
    errors: Array<{ declarationId: string; error: string }>
    /** Items that failed during signing — can be retried */
    retryableIds: string[]
  }

  async function bulkSubmitViaAgent(
    ids: string[],
    certificateId: string,
    onProgress?: (progress: BulkProgress) => void,
  ): Promise<BulkSubmitResult> {
    const { post } = useApi()

    // ── Phase 1: Prepare ──────────────────────────────────────────
    onProgress?.({ phase: 'preparing', current: 0, total: ids.length })

    const prepared = await post<{
      items: PreparedItem[]
      errors: Array<{ declarationId: string; error: string }>
    }>('/v1/declarations/batch-prepare', { ids })

    if (!prepared.items.length) {
      return { processed: 0, errors: prepared.errors, retryableIds: [] }
    }

    // ── Phase 2: Sign — one by one via /proxy for per-item progress ──
    const signed: BulkSignedResult[] = []
    const signErrors: Array<{ declarationId: string; error: string }> = []
    const retryableIds: string[] = []

    for (let i = 0; i < prepared.items.length; i++) {
      const item = prepared.items[i]
      onProgress?.({ phase: 'signing', current: i + 1, total: prepared.items.length })

      try {
        const result = await proxyToAnaf({
          url: item.anafUrl,
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${item.anafToken}`,
            'Content-Type': 'application/xml',
          },
          body: item.xml,
          certificateId,
        })
        signed.push({
          declarationId: item.declarationId,
          statusCode: result.statusCode,
          headers: result.headers,
          body: result.body,
        })
      } catch (err) {
        signErrors.push({ declarationId: item.declarationId, error: (err as Error).message })
        retryableIds.push(item.declarationId)
      }
    }

    if (!signed.length) {
      return { processed: 0, errors: [...prepared.errors, ...signErrors], retryableIds }
    }

    // ── Phase 3: Submit signed results to server ──────────────────
    onProgress?.({ phase: 'submitting', current: 0, total: signed.length })

    const batchResult = await post<{
      processed: number
      errors: Array<{ declarationId: string; error: string }>
    }>('/v1/declarations/batch-agent-result', { results: signed })

    return {
      processed: batchResult.processed,
      errors: [...prepared.errors, ...signErrors, ...batchResult.errors],
      retryableIds,
    }
  }

  async function checkStatusViaAgent(declarationId: string, certificateId: string): Promise<any> {
    const { get, post } = useApi()

    // Prepare for status check
    const prepared = await get<{
      anafUrl: string
      anafToken: string
      declarationType: string
      cif: string
    }>(`/v1/declarations/${declarationId}/prepare?operation=listMessages`)

    // Proxy through agent
    const anafResponse = await proxyToAnaf({
      url: prepared.anafUrl,
      method: 'GET',
      headers: {
        'Authorization': `Bearer ${prepared.anafToken}`,
      },
      body: '',
      certificateId,
    })

    return anafResponse
  }

  async function syncViaAgent(year: number, certificateId: string): Promise<{ created: number; updated: number }> {
    const { post } = useApi()

    // Step 1: Get ANAF URL + token for listaMesaje
    const prepared = await post<{
      anafUrl: string
      anafToken: string
      year: number
      cif: string
    }>('/v1/declarations/sync-prepare', { year })

    // Step 2: Proxy listaMesaje through agent
    const messagesResponse = await proxyToAnaf({
      url: prepared.anafUrl,
      method: 'GET',
      headers: { 'Authorization': `Bearer ${prepared.anafToken}` },
      body: '',
      certificateId,
    })

    // Step 3: Send ANAF response to backend for processing
    const result = await post<{
      stats: { created: number; updated: number }
      recipisas: Array<{ declarationId: string; downloadId: string; anafUrl: string; anafToken: string }>
    }>('/v1/declarations/sync-agent-result', {
      statusCode: messagesResponse.statusCode,
      body: messagesResponse.body,
      year,
    })

    // Step 4: Download all recipisas in parallel via agent
    await Promise.allSettled(result.recipisas.map(async (rec) => {
      const recipisaResponse = await proxyToAnaf({
        url: rec.anafUrl,
        method: 'GET',
        headers: { 'Authorization': `Bearer ${rec.anafToken}` },
        body: '',
        certificateId,
      })
      await post(`/v1/declarations/${rec.declarationId}/agent-recipisa`, {
        statusCode: recipisaResponse.statusCode,
        body: recipisaResponse.body,
      })
    }))

    return result.stats
  }

  async function refreshStatusesViaAgent(certificateId: string): Promise<{ accepted: number; rejected: number }> {
    const { post } = useApi()

    // Step 1: Get ANAF URL + token for listaMesaje
    const prepared = await post<{
      anafUrl: string
      anafToken: string
      cif: string
    }>('/v1/declarations/refresh-prepare')

    // Step 2: Proxy listaMesaje through agent
    const messagesResponse = await proxyToAnaf({
      url: prepared.anafUrl,
      method: 'GET',
      headers: { 'Authorization': `Bearer ${prepared.anafToken}` },
      body: '',
      certificateId,
    })

    // Step 3: Send ANAF response to backend for processing
    const result = await post<{
      stats: { accepted: number; rejected: number }
      recipisas: Array<{ declarationId: string; downloadId: string; anafUrl: string; anafToken: string }>
    }>('/v1/declarations/refresh-agent-result', {
      statusCode: messagesResponse.statusCode,
      body: messagesResponse.body,
    })

    // Step 4: Download all recipisas in parallel via agent
    await Promise.allSettled(result.recipisas.map(async (rec) => {
      const recipisaResponse = await proxyToAnaf({
        url: rec.anafUrl,
        method: 'GET',
        headers: { 'Authorization': `Bearer ${rec.anafToken}` },
        body: '',
        certificateId,
      })
      await post(`/v1/declarations/${rec.declarationId}/agent-recipisa`, {
        statusCode: recipisaResponse.statusCode,
        body: recipisaResponse.body,
      })
    }))

    return result.stats
  }

  function getPreferredCertId(companyId: string): string | null {
    if (!import.meta.client) return null
    return localStorage.getItem(`storno:agent:cert:${companyId}`)
  }

  function setPreferredCertId(companyId: string, certId: string) {
    if (!import.meta.client) return
    localStorage.setItem(`storno:agent:cert:${companyId}`, certId)
  }

  async function tryAutoStart(): Promise<boolean> {
    if (!import.meta.client) return false

    // Fire custom protocol to start the agent
    const a = document.createElement('a')
    a.href = 'storno-agent://start'
    a.style.display = 'none'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)

    // Poll /health for up to 15 seconds
    const deadline = Date.now() + 15_000
    while (Date.now() < deadline) {
      await new Promise(r => setTimeout(r, 1500))
      const ok = await checkAgent()
      if (ok) return true
    }
    return false
  }

  function certDisplayName(cert: AgentCertificate): string {
    const cn = cert.subject.match(/CN=([^,]+)/)?.[1] ?? cert.subject
    return cn.replace(/\b\w+/g, w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
  }

  function certIssuerShort(cert: AgentCertificate): string {
    return cert.issuer.match(/CN=([^,]+)/)?.[1] ?? cert.issuer
  }

  function certExpiry(cert: AgentCertificate): string | null {
    if (!cert.notAfter) return null
    const d = new Date(cert.notAfter)
    if (isNaN(d.getTime())) return null
    return `${d.getMonth() + 1}/${d.getDate()}/${d.getFullYear()}`
  }

  return {
    agentAvailable,
    agentVersion,
    agentChecking,
    agentUpdateAvailable,
    agentLatestVersion,
    checkAgent,
    listCertificates,
    proxyToAnaf,
    submitViaAgent,
    bulkSubmitViaAgent,
    checkStatusViaAgent,
    syncViaAgent,
    refreshStatusesViaAgent,
    getPreferredCertId,
    setPreferredCertId,
    tryAutoStart,
    triggerAgentUpdate,
    certDisplayName,
    certIssuerShort,
    certExpiry,
  }
}
