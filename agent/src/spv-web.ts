/**
 * Requests to ANAF SPV through the *website form* (SNMD "Adaugare solicitare"),
 * for the document types the SPVWS2 web service does not implement (C168,
 * certificates, decisions, notices…). Same certificate, same result: ANAF
 * registers the request with an id and the answer lands in the SPV inbox
 * (listaMesaje) with that id_solicitare.
 *
 * Flow (all through curlProxy, so the certificate + cookie jar handling is shared):
 *   1. GET https://login.anaf.ro/status.html   → F5 APM certificate login, session cookies
 *   2. GET https://www.anaf.ro/anaf/myinternet/SPV   → portal session (PD-S-SESSION-ID…)
 *   3. GET https://www.anaf.ro/SNMD/solicitari.xhtml → JSF ViewState + submit button id
 *   4. POST the form (form1:cui, form1:TipDocument, form1:pui, form1:AnRaportare,
 *      form1:nrInreg, form1:LunaRaportare/LunaInceput/LunaSfarsit, javax.faces.ViewState)
 *   5. "Cererea dumneavoastra … a fost inregistrata cu id=N" → {id_solicitare: N}
 *
 * The answer is normalised to the shape of SPVWS2 `cerere`, so Storno's
 * spv/requests/{id}/agent-result stays unchanged.
 */

import { curlProxy, resetWebSession, type ProxyRequest } from './proxy/curl-proxy.js';
import type { AgentConfig } from './config.js';

export const SPV_LOGIN_URL = 'https://login.anaf.ro/status.html';
export const SPV_PORTAL_URL = 'https://www.anaf.ro/anaf/myinternet/SPV';
export const SPV_FORM_URL = 'https://www.anaf.ro/SNMD/solicitari.xhtml';

const BROWSER_HEADERS: Record<string, string> = {
  'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:154.0) Gecko/20100101 Firefox/154.0',
  'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
  'Accept-Language': 'ro-RO,ro;q=0.9,en;q=0.8',
};

export interface SpvWebRequestInput {
  certificateId: string;
  pin?: string;
  cif: string;
  tipDocument: string;
  params?: {
    an?: string;
    luna?: string;
    lunai?: string;
    lunas?: string;
    numar_inregistrare?: string;
    cui_pui?: string;
  };
}

export interface SpvWebRequestResult {
  statusCode: number;
  /** JSON text in the shape of SPVWS2 cerere: {id_solicitare, titlu, parametri} or {eroare, titlu} */
  body: string;
}

function stripTags(html: string): string {
  return html
    .replace(/<(script|style)[^>]*>[\s\S]*?<\/\1>/gi, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&#(\d+);/g, (_, n) => String.fromCharCode(Number(n)))
    .replace(/\s+/g, ' ')
    .trim();
}

function get(url: string, auth: Pick<ProxyRequest, 'certificateId' | 'pin'>, config: AgentConfig, extraHeaders: Record<string, string> = {}, forceCert = false) {
  return curlProxy({ url, method: 'GET', headers: { ...BROWSER_HEADERS, ...extraHeaders }, body: '', certificateId: auth.certificateId, pin: auth.pin, web: true, forceCert }, config);
}

export async function submitSpvWebRequest(input: SpvWebRequestInput, config: AgentConfig): Promise<SpvWebRequestResult> {
  const auth = { certificateId: input.certificateId, pin: input.pin };
  const cif = input.cif.replace(/\D/g, '');
  if (!cif || !input.tipDocument) throw new Error('cif and tipDocument are required');

  // 1-2. certificate login + portal session (fresh web jar: a stale APM cookie yields "Pagina logout")
  resetWebSession(input.certificateId);
  const login = await get(SPV_LOGIN_URL, auth, config, {}, true);
  if (login.statusCode >= 400) throw new Error(`ANAF login answered HTTP ${login.statusCode}`);
  await get(SPV_PORTAL_URL, auth, config);

  // 3. the form: ViewState + generated submit button name
  const page = await get(SPV_FORM_URL, auth, config, { Referer: SPV_PORTAL_URL });
  const html = page.body;
  const viewState = (html.match(/name="javax\.faces\.ViewState"[^>]*value="([^"]+)"/) || html.match(/value="([^"]+)"[^>]*name="javax\.faces\.ViewState"/))?.[1];
  const submitName = (html.match(/name="(form1:j_id[^"]+)"[^>]*value="Trimite"/) || html.match(/value="Trimite"[^>]*name="(form1:j_id[^"]+)"/))?.[1];
  if (!viewState || !submitName) {
    const text = stripTags(html).slice(0, 300);
    throw new Error(`SPV request form not available (HTTP ${page.statusCode}): ${text}`);
  }
  const options = new Set(Array.from(html.matchAll(/<option value="([^"]*)"/g), (m) => m[1]));
  if (options.size > 0 && !options.has(input.tipDocument)) {
    throw new Error(`ANAF's form does not offer "${input.tipDocument}" for this certificate`);
  }
  if (options.size > 0 && !options.has(cif)) {
    throw new Error(`The certificate has no SPV rights on CIF ${cif}`);
  }

  // 4. submit (field names as the browser sends them)
  const p = input.params ?? {};
  const fields: Array<[string, string]> = [
    ['form1:cui', cif],
    ['form1:TipDocument', input.tipDocument],
    ['form1:pui', p.cui_pui ?? ''],
    ['form1:AnRaportare', p.an ?? ''],
    ['form1:nrInreg', p.numar_inregistrare ?? ''],
  ];
  if (p.luna) fields.push(['form1:LunaRaportare', p.luna]);
  if (p.lunai) fields.push(['form1:LunaInceput', p.lunai]);
  if (p.lunas) fields.push(['form1:LunaSfarsit', p.lunas]);
  fields.push([submitName, 'Trimite'], ['form1_SUBMIT', '1'], ['javax.faces.ViewState', viewState]);
  const body = fields.map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');

  const answer = await curlProxy({
    url: SPV_FORM_URL,
    method: 'POST',
    headers: { ...BROWSER_HEADERS, 'Content-Type': 'application/x-www-form-urlencoded', Origin: 'https://www.anaf.ro', Referer: SPV_FORM_URL },
    body,
    certificateId: input.certificateId,
    pin: input.pin,
    web: true,
  }, config);

  // 5. normalise
  const text = stripTags(answer.body);
  const id = text.match(/inregistrata cu id\s*=\s*(\d+)/i)?.[1];
  const parametri = Object.entries({ cui: cif, ...p }).filter(([, v]) => v).map(([k, v]) => `${k}=${v}`).join(', ');
  if (id) {
    console.log(`[spv-web] ${input.tipDocument} for ${cif}: id_solicitare ${id}`);
    return { statusCode: 200, body: JSON.stringify({ titlu: `Transmitere cerere tip ${input.tipDocument}`, id_solicitare: id, parametri, canal: 'spv-web' }) };
  }
  const err = text.match(/(?:Eroare|eroare|Cererea nu|nu a putut|invalid|obligatoriu)[^.]{0,200}\.?/)?.[0] ?? text.slice(0, 300);
  console.log(`[spv-web] ${input.tipDocument} for ${cif}: no id in answer (HTTP ${answer.statusCode})`);
  return { statusCode: answer.statusCode >= 400 ? answer.statusCode : 200, body: JSON.stringify({ titlu: 'Cerere', eroare: err || `ANAF nu a confirmat cererea (HTTP ${answer.statusCode})`, canal: 'spv-web' }) };
}
