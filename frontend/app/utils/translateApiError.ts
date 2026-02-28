/**
 * Translates English API error messages to Romanian.
 *
 * Backend returns errors in English; this maps them to Romanian for the UI.
 * Already-Romanian messages and unrecognized strings pass through unchanged.
 */

const exactMap: Record<string, string> = {
  // ── Auth / Account ───────────────────────────────────────────────
  'Login failed.': 'Autentificarea a esuat.',
  'Google login failed.': 'Autentificarea cu Google a esuat.',
  'Authentication required.': 'Autentificarea este necesara.',
  'An account with this email already exists.': 'Exista deja un cont cu aceasta adresa de email.',
  'Email and password are required.': 'Adresa de email si parola sunt obligatorii.',
  'Email is required.': 'Adresa de email este obligatorie.',
  'Password must be at least 8 characters.': 'Parola trebuie sa aiba cel putin 8 caractere.',
  'Invalid Google token.': 'Token Google invalid.',
  'Too many registration attempts. Please try again later.': 'Prea multe incercari de inregistrare. Incercati din nou mai tarziu.',
  'Too many requests. Please try again later.': 'Prea multe cereri. Incercati din nou mai tarziu.',
  'Email already verified.': 'Adresa de email este deja verificata.',
  'Missing credential.': 'Credentiale lipsa.',
  'Unknown credential.': 'Credentiale necunoscute.',
  'Invalid or expired reset token.': 'Token de resetare invalid sau expirat.',
  'Confirmation token has expired.': 'Tokenul de confirmare a expirat.',
  'Invalid confirmation token.': 'Token de confirmare invalid.',
  'Token and new password are required.': 'Tokenul si noua parola sunt obligatorii.',
  'Token is required.': 'Tokenul este obligatoriu.',
  'Token not found.': 'Tokenul nu a fost gasit.',
  'Token and platform are required.': 'Tokenul si platforma sunt obligatorii.',
  'Challenge expired or not found.': 'Provocarea a expirat sau nu a fost gasita.',
  'Missing session ID.': 'ID-ul sesiunii lipseste.',

  // ── Permissions / Access ─────────────────────────────────────────
  'Access denied.': 'Acces interzis.',
  'Forbidden.': 'Acces interzis.',
  'Permission denied.': 'Permisiune refuzata.',
  'Permission denied': 'Permisiune refuzata.',
  'No company selected.': 'Nicio companie selectata.',
  'No organization found.': 'Nicio organizatie gasita.',
  'Organization not found.': 'Organizatia nu a fost gasita.',
  'Organization not found': 'Organizatia nu a fost gasita.',

  // ── Entity not found ─────────────────────────────────────────────
  'Invoice not found.': 'Factura nu a fost gasita.',
  'Client not found.': 'Clientul nu a fost gasit.',
  'Supplier not found.': 'Furnizorul nu a fost gasit.',
  'Product not found.': 'Produsul nu a fost gasit.',
  'Company not found.': 'Compania nu a fost gasita.',
  'Bank account not found.': 'Contul bancar nu a fost gasit.',
  'Email template not found.': 'Sablonul de email nu a fost gasit.',
  'Webhook endpoint not found.': 'Endpoint-ul webhook nu a fost gasit.',
  'Passkey not found.': 'Passkey-ul nu a fost gasit.',
  'Document series not found.': 'Seria de documente nu a fost gasita.',
  'Delivery note not found.': 'Avizul de insotire nu a fost gasit.',
  'Proforma not found.': 'Proforma nu a fost gasita.',
  'Recurring invoice not found.': 'Factura recurenta nu a fost gasita.',
  'VAT rate not found.': 'Cota TVA nu a fost gasita.',
  'API token not found.': 'Tokenul API nu a fost gasit.',
  'Message not found.': 'Mesajul nu a fost gasit.',
  'Notification not found.': 'Notificarea nu a fost gasita.',
  'Member not found.': 'Membrul nu a fost gasit.',
  'Payment not found.': 'Plata nu a fost gasita.',
  'Attachment not found.': 'Atasamentul nu a fost gasit.',
  'License key not found': 'Cheia de licenta nu a fost gasita.',
  'License key not found.': 'Cheia de licenta nu a fost gasita.',
  'User not found.': 'Utilizatorul nu a fost gasit.',
  'Share link not found.': 'Linkul de partajare nu a fost gasit.',
  'Invitation not found.': 'Invitatia nu a fost gasita.',
  'Transaction not found.': 'Tranzactia nu a fost gasita.',
  'Import job not found.': 'Job-ul de import nu a fost gasit.',
  'Backup job not found.': 'Job-ul de backup nu a fost gasit.',
  'Link not found.': 'Linkul nu a fost gasit.',
  'Link invalid or expired.': 'Linkul este invalid sau expirat.',

  // ── Validation ───────────────────────────────────────────────────
  'Invalid JSON body.': 'Corp JSON invalid.',
  'Invalid payload.': 'Date invalide.',
  'Invalid payload': 'Date invalide.',
  'Invalid role.': 'Rol invalid.',
  'Invalid month.': 'Luna invalida.',
  'Invalid year.': 'Anul invalid.',
  'Invalid platform.': 'Platforma invalida.',
  'Invalid response type.': 'Tip de raspuns invalid.',
  'Name cannot be empty.': 'Numele nu poate fi gol.',
  'Name is required.': 'Numele este obligatoriu.',
  'CIF is required.': 'CIF-ul este obligatoriu.',
  'CUI is required.': 'CUI-ul este obligatoriu.',
  'County is required.': 'Judetul este obligatoriu.',
  'City is required.': 'Orasul este obligatoriu.',
  'Address is required.': 'Adresa este obligatorie.',
  'Country is required.': 'Tara este obligatorie.',
  'Registration number is required.': 'Numarul de inregistrare este obligatoriu.',
  'Registration number is required for companies.': 'Numarul de inregistrare este obligatoriu pentru companii.',
  'County cannot be empty.': 'Judetul nu poate fi gol.',
  'City cannot be empty.': 'Orasul nu poate fi gol.',
  'Address cannot be empty.': 'Adresa nu poate fi goala.',
  'Country cannot be empty.': 'Tara nu poate fi goala.',
  'Registration number cannot be empty.': 'Numarul de inregistrare nu poate fi gol.',
  'At least one line is required.': 'Este necesara cel putin o linie.',
  'At least one event type is required.': 'Este necesar cel putin un tip de eveniment.',
  'At least one scope is required.': 'Este necesar cel putin un domeniu.',
  'A valid HTTPS URL is required.': 'Este necesara o adresa URL HTTPS valida.',
  'Valid email address required.': 'Este necesara o adresa de email valida.',
  'Amount must be positive.': 'Suma trebuie sa fie pozitiva.',
  'No invoice IDs provided.': 'Nu au fost furnizate ID-uri de facturi.',
  'No transactions specified.': 'Nu au fost specificate tranzactii.',
  'Provide between 1 and 100 IDs.': 'Furnizati intre 1 si 100 de ID-uri.',
  'Maximum 100 invoices per export.': 'Maximum 100 de facturi per export.',
  'Bank account with this IBAN already exists.': 'Exista deja un cont bancar cu acest IBAN.',
  'Series with this prefix already exists.': 'Exista deja o serie cu acest prefix.',
  'isActive is required.': 'Campul isActive este obligatoriu.',
  'columnMapping is required and must be an object.': 'Maparea coloanelor este obligatorie si trebuie sa fie un obiect.',
  'priceId is required': 'priceId este obligatoriu.',
  'licenseKey is required': 'Cheia de licenta este obligatorie.',

  // ── Field-specific validation ────────────────────────────────────
  'Field "amount" is required.': 'Campul "suma" este obligatoriu.',
  'Field "body" is required.': 'Campul "continut" este obligatoriu.',
  'Field "iban" is required.': 'Campul "IBAN" este obligatoriu.',
  'Field "name" is required.': 'Campul "nume" este obligatoriu.',
  'Field "paid" (bool) is required.': 'Campul "platit" este obligatoriu.',
  'Field "paymentMethod" is required.': 'Campul "metoda de plata" este obligatoriu.',
  'Field "prefix" is required.': 'Campul "prefix" este obligatoriu.',
  'Field "provider" is required.': 'Campul "furnizor" este obligatoriu.',
  'Field "subject" is required.': 'Campul "subiect" este obligatoriu.',
  'Field "direction" must be "outgoing" or "incoming".': 'Campul "directie" trebuie sa fie "emisa" sau "primita".',
  'Fields "rate" and "label" are required.': 'Campurile "rata" si "eticheta" sunt obligatorii.',

  // ── File operations ──────────────────────────────────────────────
  'No file uploaded.': 'Niciun fisier incarcat.',
  'File not found.': 'Fisierul nu a fost gasit.',
  'Only ZIP files are accepted.': 'Sunt acceptate doar fisiere ZIP.',
  'Unsupported file format. Allowed: csv, xlsx, xls': 'Format de fisier neacceptat. Formate permise: csv, xlsx, xls.',
  'Attachment content not available.': 'Continutul atasamentului nu este disponibil.',

  // ── Invoice / Document operations ────────────────────────────────
  'Could not generate XML.': 'Nu s-a putut genera XML-ul.',
  'Could not generate XML for PDF.': 'Nu s-a putut genera XML-ul pentru PDF.',
  'Recurring invoice has no company.': 'Factura recurenta nu are o companie asociata.',
  'Recurring invoice is not active.': 'Factura recurenta nu este activa.',
  'Company is not deleted.': 'Compania nu este stearsa.',
  'Company not found at ANAF.': 'Compania nu a fost gasita la ANAF.',
  'ANAF lookup failed.': 'Cautarea ANAF a esuat.',
  'CUI not found in ANAF.': 'CUI-ul nu a fost gasit in ANAF.',

  // ── Import / Export ──────────────────────────────────────────────
  'Import has already been completed.': 'Importul a fost deja finalizat.',
  'Import is already being processed.': 'Importul este deja in curs de procesare.',
  'Column mapping must be configured before executing the import.': 'Maparea coloanelor trebuie configurata inainte de executarea importului.',
  'No target fields found for this import type.': 'Nu au fost gasite campuri tinta pentru acest tip de import.',
  'Cannot modify a saved transaction.': 'Nu se poate modifica o tranzactie salvata.',

  // ── Backup / Restore ────────────────────────────────────────────
  'A backup or restore job is already in progress.': 'Un job de backup sau restaurare este deja in curs.',
  'Backup file not found.': 'Fisierul de backup nu a fost gasit.',
  'Backup is not ready for download.': 'Backup-ul nu este gata pentru descarcare.',
  'Failed to confirm': 'Confirmarea a esuat.',

  // ── Billing / Stripe ─────────────────────────────────────────────
  'Failed to create checkout session': 'Nu s-a putut crea sesiunea de plata.',
  'Failed to create dashboard link': 'Nu s-a putut crea linkul catre dashboard.',
  'Failed to create onboarding link': 'Nu s-a putut crea linkul de inregistrare.',
  'Failed to create payment session': 'Nu s-a putut crea sesiunea de plata.',
  'Failed to create portal session': 'Nu s-a putut crea sesiunea portalului.',
  'Failed to disconnect': 'Deconectarea a esuat.',

  // ── Licensing / Plans ────────────────────────────────────────────
  'Invalid or revoked license key': 'Cheie de licenta invalida sau revocata.',
  'Self-hosting license is not available on your current plan. Please upgrade to Business.': 'Licenta self-hosting nu este disponibila pe planul curent. Va rugam sa faceti upgrade la Business.',
  'Your current plan does not include self-hosting. Please upgrade to Business.': 'Planul curent nu include self-hosting. Va rugam sa faceti upgrade la Business.',
  'Self-hosted instances support a single organization. Ask the admin to invite you.': 'Instantele self-hosted suporta o singura organizatie. Cereti administratorului sa va invite.',
  'Grace period expired. Company cannot be restored.': 'Perioada de gratie a expirat. Compania nu poate fi restaurata.',

  // ── Borderou ─────────────────────────────────────────────────────
  'Invalid sourceType. Must be "borderou" or "bank_statement".': 'Tip sursa invalid. Trebuie sa fie "borderou" sau "extras bancar".',

  // ── Webhook ──────────────────────────────────────────────────────
  'Webhook error': 'Eroare webhook.',
  'Webhook not configured': 'Webhook-ul nu este configurat.',
  'Invalid signature': 'Semnatura invalida.',

  // ── Passkey ──────────────────────────────────────────────────────
  'efacturaDelayHours must be null or one of: 2, 24, 48, 72, 96.': 'efacturaDelayHours trebuie sa fie null sau una din valorile: 2, 24, 48, 72, 96.',
  'maxCompanies must be at least 1.': 'Numarul maxim de companii trebuie sa fie cel putin 1.',
  'maxUsers must be at least 1.': 'Numarul maxim de utilizatori trebuie sa fie cel putin 1.',
}

const patternMap: [RegExp, string | ((match: RegExpMatchArray) => string)][] = [
  // Payment amount cannot exceed the remaining balance of 1500.00
  [
    /^Payment amount cannot exceed the remaining balance of (.+)\.$/,
    (m) => `Suma platii nu poate depasi soldul ramas de ${m[1]}.`,
  ],
  // Failed to send email: <reason>
  [
    /^Failed to send email: (.+)$/,
    (m) => `Trimiterea emailului a esuat: ${m[1]}`,
  ],
  // PDF generation failed: <reason>
  [
    /^PDF generation failed(?:: (.+))?$/,
    (m) => m[1] ? `Generarea PDF a esuat: ${m[1]}` : 'Generarea PDF a esuat.',
  ],
  // File storage failed: <reason>
  [
    /^File storage failed(?:: (.+))?$/,
    (m) => m[1] ? `Stocarea fisierului a esuat: ${m[1]}` : 'Stocarea fisierului a esuat.',
  ],
  // Invalid importType. Allowed: clients, products, invoices
  [
    /^Invalid importType\. Allowed: (.+)$/,
    (m) => `Tip de import invalid. Valori permise: ${m[1]}`,
  ],
  // Invalid source. Allowed: csv, xlsx
  [
    /^Invalid source\. Allowed: (.+)$/,
    (m) => `Sursa invalida. Valori permise: ${m[1]}`,
  ],
  // Invalid type. Valid types: ...
  [
    /^Invalid type\. Valid types: (.+)$/,
    (m) => `Tip invalid. Tipuri valide: ${m[1]}`,
  ],
  // Invalid plan. Allowed: free, starter, professional
  [
    /^Invalid plan\. Allowed: (.+)$/,
    (m) => `Plan invalid. Planuri permise: ${m[1]}`,
  ],
  // Invalid scopes: read, write
  [
    /^Invalid scopes: (.+)$/,
    (m) => `Domenii invalide: ${m[1]}`,
  ],
  // Cannot grant scope "X" — you do not have this permission.
  [
    /^Cannot grant scope "(.+)" — you do not have this permission\.$/,
    (m) => `Nu puteti acorda domeniul "${m[1]}" — nu aveti aceasta permisiune.`,
  ],
  // Invalid BCC/CC email address: <email>
  [
    /^Invalid (BCC|CC) email address: (.+)$/,
    (m) => `Adresa de email ${m[1]} invalida: ${m[2]}`,
  ],
  // Invalid expiresAt date format.
  [
    /^Invalid expiresAt date format\.$/,
    () => 'Format de data invalid pentru expiresAt.',
  ],
  // Line N: description is required / quantity must be positive / etc.
  [
    /^Line (\d+): description is required\.$/,
    (m) => `Linia ${m[1]}: descrierea este obligatorie.`,
  ],
  [
    /^Line (\d+): quantity must be non-zero\.$/,
    (m) => `Linia ${m[1]}: cantitatea nu poate fi zero.`,
  ],
  [
    /^Line (\d+): quantity must be positive\.$/,
    (m) => `Linia ${m[1]}: cantitatea trebuie sa fie pozitiva.`,
  ],
  [
    /^Line (\d+): unitPrice must be non-negative\.$/,
    (m) => `Linia ${m[1]}: pretul unitar nu poate fi negativ.`,
  ],
  // Manage your subscription at <URL>
  [
    /^Manage your subscription at (.+)$/,
    (m) => `Gestionati abonamentul la ${m[1]}`,
  ],
  // Unsupported file format. Allowed <types>
  [
    /^Unsupported file format\. Allowed:? (.+)$/,
    (m) => `Format de fisier neacceptat. Formate permise: ${m[1]}`,
  ],
  // Organization already has an active subscription...
  [
    /^Organization already has an active subscription/,
    () => 'Organizatia are deja un abonament activ. Folositi portalul de facturare pentru a schimba planul.',
  ],
  // Assertion/Attestation verification failed: <reason>
  [
    /^(Assertion|Attestation) verification failed: (.+)$/,
    (m) => `Verificarea ${m[1] === 'Assertion' ? 'autentificarii' : 'inregistrarii'} a esuat: ${m[2]}`,
  ],
]

/**
 * Translates an English API error message to Romanian.
 * Already-Romanian messages pass through unchanged.
 */
export function translateApiError(message: string): string {
  if (!message) return message

  // 1. Exact match
  const exact = exactMap[message]
  if (exact) return exact

  // 2. Pattern match
  for (const [pattern, replacement] of patternMap) {
    const match = message.match(pattern)
    if (match) {
      return typeof replacement === 'function' ? replacement(match) : replacement
    }
  }

  // 3. Passthrough (already Romanian or unrecognized)
  return message
}
