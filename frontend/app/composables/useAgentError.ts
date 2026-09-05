/**
 * Turns the raw failures of the local Storno Agent, the USB token and the network
 * into something a person can act on ("the agent is not running", "plug in the
 * token", "wrong PIN") instead of `[POST] "https://…": <no response> Load failed`.
 */
export type AgentErrorCode =
  | 'agent-offline'
  | 'agent-timeout'
  | 'token-missing'
  | 'pin-required'
  | 'pin-wrong'
  | 'pin-locked'
  | 'anaf-unreachable'
  | 'anaf-session'
  | 'storno-unreachable'
  | 'unknown'

export class AgentError extends Error {
  constructor(public readonly code: AgentErrorCode, message: string, public readonly details?: string) {
    super(message)
    this.name = 'AgentError'
  }
}

const NETWORK_FAILURE = /<no response>|Load failed|Failed to fetch|NetworkError|network error|ERR_CONNECTION|ECONNREFUSED|ECONNRESET|fetch failed/i
const TOKEN_MISSING = /CKR_TOKEN_NOT_PRESENT|CKR_SLOT_ID_INVALID|CKR_DEVICE_(REMOVED|ERROR)|no slot|No slots|token not present|not present|no certificates?|certificate not found|Certificate .* not found|cannot load client cert|could not load|PKCS11_get_private_key|PKCS11_enumerate|engine .*(fail|error)|No such certificate|certificateId .*unknown|toolchain incomplete|PKCS#11 module/i
const PIN_REQUIRED = /PIN_REQUIRED|PIN-ul certificatului lipseste|PIN lipsa|pin (is )?required/i
const PIN_LOCKED = /CKR_PIN_LOCKED|PIN blocat|PIN locked/i
const PIN_WRONG = /CKR_PIN_INCORRECT|CKR_PIN_INVALID|CKR_PIN_LEN_RANGE|PIN verification failed|Failed to set PIN|incorrect PIN|PIN incorect|PIN gresit/i
const ANAF_UNREACHABLE = /anaf\.ro|SPVWS2|StareD112|WAS6DUS|Could not resolve host|Connection timed out|Operation timed out|SSL connect|Recv failure|curl:? ?\(\d+\)|ANAF (returned|a raspuns|nu a raspuns)|HTTP 5\d\d/i
const ANAF_SESSION = /SPV_UNPARSEABLE|Pagina logout|sesiunea SPV a expirat|login\.anaf|nu are drepturi/i

function messageOf(e: unknown): string {
  if (!e) return ''
  const any = e as any
  const parts = [any?.data?.error, any?.data?.details, any?.details, any?.message, typeof e === 'string' ? e : '']
  return parts.filter(p => typeof p === 'string' && p).join(' | ')
}

/** Where did the failure come from: the agent on 127.0.0.1, Storno's API, or ANAF behind the agent. */
export function classifyAgentError(e: unknown): AgentErrorCode {
  if (e instanceof AgentError) return e.code
  const any = e as any
  const msg = messageOf(e)
  if (PIN_LOCKED.test(msg)) return 'pin-locked'
  if (PIN_WRONG.test(msg)) return 'pin-wrong'
  if (PIN_REQUIRED.test(msg)) return 'pin-required'
  if (TOKEN_MISSING.test(msg)) return 'token-missing'
  if (ANAF_SESSION.test(msg)) return 'anaf-session'
  if (ANAF_UNREACHABLE.test(msg)) return 'anaf-unreachable'
  // ofetch: request that never got a response (server restarting, offline, blocked)
  if (any?.name === 'FetchError' && !any?.response) return 'storno-unreachable'
  if (any?.name === 'AbortError' || any?.name === 'TimeoutError') return 'agent-timeout'
  if (any?.name === 'TypeError' && NETWORK_FAILURE.test(msg)) return 'agent-offline'
  if (NETWORK_FAILURE.test(msg)) return /api\.storno\.ro|\/api\/v1\//.test(msg) ? 'storno-unreachable' : 'agent-offline'
  return 'unknown'
}

export function useAgentError() {
  const { t } = useI18n()

  /**
   * Toast-ready title + description. Server messages meant for people (Romanian
   * text from Storno's API) are kept; raw transport errors are replaced.
   */
  function describeAgentError(e: unknown, fallbackTitle?: string): { title: string; description?: string; code: AgentErrorCode } {
    const code = classifyAgentError(e)
    if (code !== 'unknown') {
      return { code, title: t(`agentErrors.${code}.title`), description: t(`agentErrors.${code}.hint`) }
    }
    const any = e as any
    const server = typeof any?.data?.error === 'string' ? any.data.error : null
    const msg = server ?? (typeof any?.message === 'string' && !NETWORK_FAILURE.test(any.message) && !/^\[(GET|POST|PUT|DELETE)\]/.test(any.message) ? any.message : null)
    return { code, title: fallbackTitle ?? t('agentErrors.unknown.title'), description: msg ?? t('agentErrors.unknown.hint') }
  }

  return { describeAgentError, classifyAgentError }
}
