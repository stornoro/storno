# Storno.ro — ce este produsul

**Storno.ro** este o platformă românească de facturare și conformitate fiscală pentru firme mici,
PFA-uri, freelanceri și firme de contabilitate. Slogan curent: **„Facturează cum vrei tu.”**

## Ce face (funcționalități reale, toate există în cod)
- Facturi conforme **e-Factura ANAF** (UBL 2.1): emitere, validare, trimitere automată în SPV, PDF + XML.
- Proforme, facturi storno, facturi recurente, avize de însoțire + **e-Transport** (cod UIT), bonuri fiscale.
- **POS pe mobil**: grilă de produse, scaner cod de bare, plăți mixte, rambursări, SGR, funcționează offline.
- **Sincronizare SPV**: facturi primite de la furnizori, mesaje și somații ANAF, notificări push.
- **Declarații**: D406 (SAF-T) generat și validat cu DUK, D212 (declarația unică), C168 (contracte de închiriere), D300; semnare și depunere prin agentul desktop cu certificat digital.
- **Dosare**: dosare pe contract/an fiscal, acțiuni, termene, documente atașate.
- Calendar fiscal, parc auto cu expirări (ITP, RCA, rovinietă), notificări.
- Clienți (import automat din Registrul Comerțului prin CUI / VIES), produse, furnizori, extrase bancare (26 formate PDF de bănci), borderouri.
- Rapoarte: analiză vânzări, raport TVA, solduri, registru de casă.
- Export pentru contabil: SAGA XML, CSV, Excel, ZIP cu PDF + XML.
- Încasare online prin **Stripe** (link de plată în fiecare factură/email).
- **API REST**, webhook-uri, OAuth2 apps, **server MCP** pentru asistenți AI (Claude, ChatGPT, Cursor), CLI.
- **Self-hosting gratuit** (Community Edition, Docker, `curl -fsSL https://get.storno.ro | bash`), cod open-source pe GitHub.
- Multi-companie, echipă cu permisiuni, white-label (logo, domeniu, email sender), MFA, passkeys.
- Instrumente gratuite fără cont: generator factură storno, verificare CUI, calculatoare (salariu, TVA, dividende, tichete de masă).
- 4 limbi: **RO (principal), EN, DE, FR**. Temă light + dark peste tot.

## Planuri
| Plan | Preț | Limite |
|---|---|---|
| Freemium | 0 RON, pentru totdeauna | 1 firmă, 100 facturi/lună |
| Starter | 19 RON/lună (14 zile trial, fără card) | 3 firme, 500 facturi/lună, email + PDF |
| Professional | 39 RON/lună | 10 firme |
| Business | custom | nelimitat |
| Community Edition (self-host) | gratuit | egal cu Starter, fără limită de timp |

## Public țintă
1. Antreprenor cu firmă nouă / PFA — vrea să intre în e-Factura fără să înțeleagă ANAF.
2. Firmă mică cu vânzare directă (magazin, service, târg) — bonuri fiscale de pe telefon.
3. Contabil / firmă de contabilitate — gestionează multe firme, vrea export lunar curat și SPV centralizat.
4. Firmă tech / e-commerce — API, webhook-uri, MCP, self-hosting.

## Poziționare și ton
- Sinceri, direcți, fără jargon fiscal inutil. Explicăm ANAF în limbajul omului.
- Diferențiatori față de piața RO (SmartBill, Oblio, FGO, Facturis, Saga): **open-source + self-host gratuit**, **aplicație mobilă nativă cu POS**, **MCP/AI**, preț la jumătate față de media pieței, tot ce e ANAF într-un singur loc (facturi, SPV, declarații, dosare).
- **Storno.ro este furnizor privat, NU este afiliat ANAF** — nicio pagină nu trebuie să arate ca un site guvernamental.
- Nu folosim „revoluționar”, „AI-powered” ca titlu, nu promitem ce nu există.

## Ortografie
Conținutul actual al site-ului e scris **fără diacritice** („Factureaza”, „incaseaza”). În machete folosește **diacritice corecte** (ă, â, î, ș, ț) — redesign-ul este ocazia să le introducem. Aplicația mobilă și dashboard-ul folosesc la fel texte fără diacritice în i18n azi; machetele le scriu cu diacritice.
