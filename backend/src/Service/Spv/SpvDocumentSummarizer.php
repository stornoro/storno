<?php

declare(strict_types=1);

namespace App\Service\Spv;

use App\Enum\SpvDocumentCategory;

/**
 * Turns an ANAF SPV message ("RECIPISA" + "recipisa pentru CIF 12345678, tip D406,
 * numar_inregistrare INTERNT-100000123-2026/31-08-2026, perioada raportare 7.2026")
 * into a sentence a business owner understands, in Romanian or English: what the
 * document is, which declaration and period it refers to, and what, if anything,
 * they must do. Deterministic, no AI: ANAF's own vocabulary, decoded.
 */
final class SpvDocumentSummarizer
{
    public const LOCALES = ['ro', 'en'];

    /** Declaration codes as they appear in `tip Dxxx` and in message types. */
    public const DECLARATIONS = [
        'D010' => ['ro' => 'Declarația de înregistrare fiscală (persoane juridice)', 'en' => 'Tax registration form (legal entities)'],
        'D020' => ['ro' => 'Declarația de înregistrare fiscală (persoane fizice)', 'en' => 'Tax registration form (individuals)'],
        'D070' => ['ro' => 'Declarația de înregistrare fiscală (PFA, II, IF)', 'en' => 'Tax registration form (sole traders)'],
        'D100' => ['ro' => 'Declarația privind obligațiile de plată la bugetul de stat (impozite și taxe)', 'en' => 'Return of taxes due to the state budget'],
        'D101' => ['ro' => 'Declarația privind impozitul pe profit', 'en' => 'Corporate income tax return'],
        'D106' => ['ro' => 'Declarația informativă privind dividendele', 'en' => 'Informative return on dividends'],
        'D112' => ['ro' => 'Declarația privind contribuțiile sociale, impozitul pe venit și evidența angajaților', 'en' => 'Payroll return: social contributions, income tax and employee records'],
        'D120' => ['ro' => 'Decontul privind accizele', 'en' => 'Excise duty return'],
        'D130' => ['ro' => 'Decontul privind impozitul la țiței', 'en' => 'Crude oil tax return'],
        'D177' => ['ro' => 'Cererea de redirecționare a impozitului pe profit / pe venitul microîntreprinderilor', 'en' => 'Request to redirect corporate / micro-enterprise tax to sponsorships'],
        'D180' => ['ro' => 'Nota de certificare a declarațiilor fiscale', 'en' => 'Tax consultant certification note'],
        'D200' => ['ro' => 'Declarația privind veniturile realizate din România (persoane fizice)', 'en' => 'Return on income earned in Romania (individuals)'],
        'D204' => ['ro' => 'Declarația anuală de venit pentru asocieri fără personalitate juridică', 'en' => 'Annual income return for unincorporated associations'],
        'D205' => ['ro' => 'Declarația informativă privind impozitul reținut la sursă', 'en' => 'Informative return on tax withheld at source'],
        'D208' => ['ro' => 'Declarația informativă privind transferul proprietăților imobiliare', 'en' => 'Informative return on real-estate transfers'],
        'D212' => ['ro' => 'Declarația unică privind impozitul pe venit și contribuțiile sociale (persoane fizice)', 'en' => 'Single return on income tax and social contributions (individuals)'],
        'D230' => ['ro' => 'Cererea de direcționare a 3,5% din impozitul pe venit', 'en' => 'Request to direct 3.5% of income tax to an NGO'],
        'D300' => ['ro' => 'Decontul de TVA', 'en' => 'VAT return'],
        'D301' => ['ro' => 'Decontul special de TVA', 'en' => 'Special VAT return'],
        'D307' => ['ro' => 'Declarația privind sumele rezultate din ajustarea TVA', 'en' => 'Return on VAT adjustments'],
        'D311' => ['ro' => 'Declarația privind TVA colectată de persoane cu codul de TVA anulat', 'en' => 'Return on VAT collected after the VAT code was cancelled'],
        'D390' => ['ro' => 'Declarația recapitulativă privind livrările și achizițiile intracomunitare', 'en' => 'Recapitulative statement of intra-EU supplies and acquisitions'],
        'D392' => ['ro' => 'Declarația informativă privind livrările de bunuri și prestările de servicii', 'en' => 'Informative return on supplies of goods and services'],
        'D393' => ['ro' => 'Declarația informativă privind biletele de transport internațional', 'en' => 'Informative return on international passenger transport tickets'],
        'D394' => ['ro' => 'Declarația informativă privind livrările și achizițiile pe teritoriul național', 'en' => 'Informative return on domestic supplies and purchases'],
        'D395' => ['ro' => 'Declarația informativă privind livrările de bunuri cu taxare inversă', 'en' => 'Informative return on reverse-charge supplies'],
        'D406' => ['ro' => 'Fișierul standard de control fiscal SAF-T', 'en' => 'SAF-T standard audit file'],
        'D600' => ['ro' => 'Declarația privind venitul asigurat la sistemul public de pensii', 'en' => 'Return on income insured in the public pension system'],
        'D700' => ['ro' => 'Declarația pentru modificarea vectorului fiscal', 'en' => 'Form to change the tax vector'],
        'D710' => ['ro' => 'Declarația rectificativă', 'en' => 'Amending return'],
        'C168' => ['ro' => 'Cererea de înregistrare a contractelor de închiriere', 'en' => 'Request to register rental contracts'],
    ];

    private const MONTHS = [
        'ro' => [1 => 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'],
        'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ];

    private const T = [
        'ro' => [
            'recipisa' => 'Confirmare de depunere (recipisă)',
            'for' => ' pentru %s',
            'registered' => ', înregistrată la ANAF cu numărul %s',
            'on' => ' în %s',
            'recipisa_trezorerie' => ' Este recipisa Trezoreriei pentru plata sau documentul transmis.',
            'recipisa_errors' => ' Atenție: recipisa menționează erori, deci depunerea NU este considerată validă. Deschide PDF-ul și corectează declarația.',
            'recipisa_ok' => ' Dacă PDF-ul nu menționează erori, declarația a fost acceptată și nu mai ai nimic de făcut.',
            'somatie' => 'Somație de plată: ANAF îți cere să plătești obligații restante într-un termen scurt (de regulă 15 zile). Neplata duce la executare silită (popriri pe conturi, sechestre). Verifică suma și termenul din PDF și plătește sau contestă în termen.',
            'poprire' => 'Poprire: ANAF a înființat sau a ridicat o poprire pe conturile bancare pentru recuperarea unor datorii. Băncile blochează sumele datorate. Verifică imediat situația cu contabilul și cu ANAF.',
            'executare' => 'Act de executare silită: ANAF a început recuperarea forțată a unor datorii (sechestru sau alt act de executare). Verifică urgent PDF-ul și contactează ANAF sau un consultant.',
            'risc' => 'Raport de analiză de risc: ANAF semnalează neconcordanțe sau riscuri fiscale identificate la firmă (de exemplu între declarații). Poate preceda o notificare de conformare sau un control. Verifică punctele semnalate.',
            'adresa' => 'Adresă oficială de la ANAF%s. Citește PDF-ul: de obicei cere un răspuns sau documente într-un termen.',
            'raspuns' => 'Răspunsul ANAF la o solicitare trimisă de tine%s.',
            'fisa_rol' => 'Fișa pe plătitor (fișa rol): situația detaliată a obligațiilor declarate, plătite și restante, așa cum le vede ANAF. Verifică soldurile restante și eventualele accesorii.',
            'sintetica' => 'Situația sintetică a datoriilor: totalul obligațiilor restante la ANAF pentru luna anterioară.',
            'obligatii' => 'Obligațiile de plată neachitate la ANAF, cu sumele și conturile de plată. Poate fi folosită pentru plata online sau la trezorerie.',
            'vector' => 'Vectorul fiscal: lista taxelor și declarațiilor la care firma este înregistrată (TVA, impozit pe profit sau micro, contribuții, accize) și periodicitatea lor.',
            'identificare' => 'Datele de identificare ale firmei așa cum sunt înregistrate la ANAF (denumire, adresă, stare, cod de TVA).',
            'certificat' => 'Certificat eliberat de ANAF%s. Documentul oficial este în PDF.',
            'cazier' => 'Cazierul fiscal: atestă dacă firma sau persoana are fapte înscrise în cazierul fiscal. Cerut la înființări, licitații, credite.',
            'adeverinta' => 'Adeverință de venit eliberată de ANAF, folosită la instituții (bănci, primării, școli).',
            'istoric' => 'Istoricul declarațiilor depuse și valide pentru anul solicitat.',
            'bilant' => 'Situații financiare (bilanț) depuse sau raport privind depunerea lor.',
            'copie' => 'Copie a declarației depuse%s%s. Document de arhivă, fără acțiune necesară.',
            'facturi' => 'Arhivă de facturi electronice (e-Factura) pusă la dispoziție de ANAF. Document de arhivă.',
            'extras' => 'Extras de cont de la Trezorerie: încasările și plățile pe contul fiscal.',
            'plata' => 'Confirmare de plată către ANAF / Trezorerie.',
            'ajutor' => 'Document privind un ajutor de stat sau o schemă de sprijin la care firma a aplicat.',
            'tezaur' => 'Document din Programul Tezaur (titluri de stat pentru populație).',
            'fallback_somatie' => 'Somație de plată de la ANAF. Verifică suma și termenul din PDF.',
            'fallback_recipisa' => 'Confirmare de depunere (recipisă) pentru o declarație.',
            'fallback' => '%s. Deschide PDF-ul pentru detalii.',
            'fallback_type' => 'Document ANAF de tip %s',
            'declaratia' => 'declarația %s',
            'luna' => 'luna %s %s', 'anul' => 'anul %s', 'trimestrul' => 'trimestrul %s din %s', 'semestrul' => 'semestrul %s din %s', 'perioada' => 'perioada %s',
            'dec_inactivare' => 'Decizie de declarare ca inactiv fiscal: firma pierde dreptul de deducere a TVA și partenerii nu pot deduce facturile ei. De obicei vine după declarații nedepuse sau sediu expirat. Remediază cauza și cere reactivarea.',
            'dec_reactivare' => 'Decizie de reactivare: firma redevine activă fiscal.',
            'dec_anulare_tva' => 'Decizie de anulare a codului de TVA: firma nu mai poate factura cu TVA și nu mai deduce TVA. Verifică motivul din PDF (de regulă declarații lipsă sau risc fiscal) și, dacă este cazul, cere reînregistrarea.',
            'dec_anulare' => 'Decizie de anulare emisă de ANAF. Citește PDF-ul pentru ce anume s-a anulat și ce trebuie făcut.',
            'dec_inreg_tva' => 'Decizie privind înregistrarea în scopuri de TVA (aprobare, respingere sau înregistrare din oficiu). Verifică data de la care se aplică.',
            'dec_respingere' => 'Decizie de respingere a unei cereri depuse de firmă. PDF-ul conține motivul.',
            'dec_impunere' => 'Decizie de impunere: ANAF a stabilit sume de plată (impozite, contribuții, accesorii). Verifică sumele și termenul de plată; poate fi contestată în 45 de zile.',
            'dec_accesorii' => 'Decizie privind dobânzi și penalități de întârziere calculate pentru plăți întârziate.',
            'dec_rambursare' => 'Decizie privind rambursarea de TVA.',
            'dec_esalonare' => 'Decizie privind eșalonarea la plată a datoriilor.',
            'dec_domiciliu' => 'Decizie privind domiciliul fiscal al firmei.',
            'dec_grup' => 'Decizie privind grupul fiscal (impozit pe profit sau TVA).',
            'dec_registru' => 'Decizie privind înscrierea sau radierea din registrul entităților / unităților de cult (sponsorizări).',
            'dec_default' => 'Decizie emisă de ANAF. Produce efecte juridice și poate fi contestată în termen: citește PDF-ul și verifică ce trebuie făcut.',
            'not_conformare' => 'Notificare de conformare: ANAF a găsit neconcordanțe și te invită să le corectezi (declarații rectificative, plăți) într-un termen, înainte de un control.',
            'not_nedepunere' => 'Notificare privind declarații nedepuse: depune declarațiile lipsă cât mai repede pentru a evita amenzi și inactivarea.',
            'not_oficiu' => 'Notificare privind înregistrarea sau anularea din oficiu în scopuri de TVA. Verifică termenul de răspuns.',
            'not_radiere' => 'Înștiințare privind radierea (de exemplu din registrul TVA intracomunitar).',
            'not_documente' => 'Înștiințare că ANAF cere documente suplimentare pentru o cerere depusă. Trimite-le în termenul indicat.',
            'not_redirectionare' => 'Notificare privind cererea de redirecționare a impozitului (sponsorizări).',
            'not_sesizare' => 'Răspuns la o sesizare sau reclamație trimisă către ANAF.',
            'not_invitatie' => 'Invitație la sediul ANAF sau la un control. Verifică data și ce documente trebuie aduse.',
            'not_control' => 'Aviz sau notificare privind o inspecție fiscală. Pregătește documentele contabile pentru perioada vizată.',
            'not_default' => 'Notificare de la ANAF: informativă sau cu termen de răspuns. Citește PDF-ul.',
        ],
        'en' => [
            'recipisa' => 'Filing receipt (confirmation of submission)',
            'for' => ' for the %s',
            'registered' => ', registered at ANAF under number %s',
            'on' => ' on %s',
            'recipisa_trezorerie' => ' This is the Treasury receipt for the payment or document sent.',
            'recipisa_errors' => ' Warning: the receipt mentions errors, so the filing is NOT considered valid. Open the PDF and correct the return.',
            'recipisa_ok' => ' If the PDF mentions no errors, the return was accepted and nothing else is needed.',
            'somatie' => 'Payment summons: ANAF demands payment of overdue obligations within a short deadline (usually 15 days). Non-payment leads to enforcement (bank account garnishment, seizure). Check the amount and deadline in the PDF and pay or contest in time.',
            'poprire' => 'Garnishment: ANAF has placed or lifted a garnishment on the bank accounts to recover debts. Banks block the amounts owed. Check the situation with your accountant and ANAF immediately.',
            'executare' => 'Enforcement act: ANAF has started forced recovery of debts (seizure or another enforcement act). Check the PDF urgently and contact ANAF or an advisor.',
            'risc' => 'Risk analysis report: ANAF flags inconsistencies or tax risks found at the company (for example between returns). It may precede a compliance notice or an audit. Review the points raised.',
            'adresa' => 'Official letter from ANAF%s. Read the PDF: it usually asks for an answer or documents within a deadline.',
            'raspuns' => "ANAF's answer to a request you sent%s.",
            'fisa_rol' => 'Taxpayer account statement (fișa rol): the detailed situation of declared, paid and outstanding obligations as ANAF sees them. Check outstanding balances and any penalties.',
            'sintetica' => 'Summary of debts: total obligations outstanding at ANAF for the previous month.',
            'obligatii' => 'Unpaid obligations at ANAF, with amounts and payment accounts. Can be used for online or Treasury payment.',
            'vector' => 'Tax vector: the taxes and returns the company is registered for (VAT, corporate or micro tax, contributions, excise) and their frequency.',
            'identificare' => "The company's identification data as registered at ANAF (name, address, status, VAT code).",
            'certificat' => 'Certificate issued by ANAF%s. The official document is the PDF.',
            'cazier' => 'Tax record certificate: attests whether the company or person has entries in the tax record. Required for incorporations, tenders, loans.',
            'adeverinta' => 'Income certificate issued by ANAF, used at institutions (banks, town halls, schools).',
            'istoric' => 'History of filed and valid returns for the requested year.',
            'bilant' => 'Financial statements (balance sheet) filed, or a report on their filing.',
            'copie' => 'Copy of a filed return%s%s. Archive document, no action needed.',
            'facturi' => 'Archive of electronic invoices (e-Factura) provided by ANAF. Archive document.',
            'extras' => 'Treasury account statement: receipts and payments on the tax account.',
            'plata' => 'Payment confirmation to ANAF / Treasury.',
            'ajutor' => 'Document about a state aid or support scheme the company applied for.',
            'tezaur' => 'Document from the Tezaur programme (government bonds for individuals).',
            'fallback_somatie' => 'Payment summons from ANAF. Check the amount and deadline in the PDF.',
            'fallback_recipisa' => 'Filing receipt for a return.',
            'fallback' => '%s. Open the PDF for details.',
            'fallback_type' => 'ANAF document of type %s',
            'declaratia' => 'return %s',
            'luna' => '%s %s', 'anul' => 'year %s', 'trimestrul' => 'quarter %s of %s', 'semestrul' => 'half-year %s of %s', 'perioada' => 'period %s',
            'dec_inactivare' => 'Decision declaring the company tax-inactive: it loses the right to deduct VAT and its partners cannot deduct its invoices. Usually follows unfiled returns or an expired registered office. Fix the cause and request reactivation.',
            'dec_reactivare' => 'Reactivation decision: the company is tax-active again.',
            'dec_anulare_tva' => 'Decision cancelling the VAT code: the company can no longer invoice with VAT or deduct VAT. Check the reason in the PDF (usually missing returns or tax risk) and, if applicable, request re-registration.',
            'dec_anulare' => 'Cancellation decision issued by ANAF. Read the PDF to see what was cancelled and what to do.',
            'dec_inreg_tva' => 'Decision on VAT registration (approval, rejection or ex-officio registration). Check the date it applies from.',
            'dec_respingere' => 'Decision rejecting a request filed by the company. The PDF states the reason.',
            'dec_impunere' => 'Tax assessment decision: ANAF has set amounts to pay (taxes, contributions, penalties). Check the amounts and deadline; it can be contested within 45 days.',
            'dec_accesorii' => 'Decision on interest and late-payment penalties for delayed payments.',
            'dec_rambursare' => 'Decision on a VAT refund.',
            'dec_esalonare' => 'Decision on a payment schedule (instalments) for debts.',
            'dec_domiciliu' => "Decision on the company's tax domicile.",
            'dec_grup' => 'Decision on the tax group (corporate tax or VAT).',
            'dec_registru' => 'Decision on entry into or removal from the register of entities / religious units (sponsorships).',
            'dec_default' => 'Decision issued by ANAF. It has legal effects and can be contested within the deadline: read the PDF and check what to do.',
            'not_conformare' => 'Compliance notice: ANAF found inconsistencies and invites you to correct them (amending returns, payments) within a deadline, before an audit.',
            'not_nedepunere' => 'Notice about unfiled returns: file the missing returns as soon as possible to avoid fines and inactivation.',
            'not_oficiu' => 'Notice about ex-officio VAT registration or cancellation. Check the reply deadline.',
            'not_radiere' => 'Notice of removal (for example from the intra-EU VAT register).',
            'not_documente' => 'Notice that ANAF requires additional documents for a filed request. Send them within the stated deadline.',
            'not_redirectionare' => 'Notice about the tax redirection request (sponsorships).',
            'not_sesizare' => 'Answer to a complaint or report sent to ANAF.',
            'not_invitatie' => 'Invitation to the ANAF office or to an audit. Check the date and the documents to bring.',
            'not_control' => 'Notice of a tax inspection. Prepare the accounting documents for the period concerned.',
            'not_default' => 'Notice from ANAF: informative or with a reply deadline. Read the PDF.',
        ],
    ];

    public function summarize(string $tip, ?string $detalii, ?SpvDocumentCategory $category = null, string $locale = 'ro'): string
    {
        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'ro';
        $t = self::T[$locale];
        $tipN = $this->normalize($tip);
        $det = trim((string) $detalii);
        $detN = $this->normalize($det);
        $facts = $this->parseDetails($det);
        $paren = $det !== '' ? ' (' . $this->shorten($det) . ')' : '';
        $colon = $det !== '' ? ': ' . $this->shorten($det) : '';

        if (str_contains($tipN, 'recipisa') || str_starts_with($detN, 'recipisa')) {
            $what = $this->describeDeclaration($facts['declaration'] ?? null, $locale);
            $period = $this->describePeriod($facts['period'] ?? null, $locale);
            $s = $t['recipisa'];
            $s .= $what ? sprintf($t['for'], $what) : '';
            $s .= $period ? ', ' . $period : '';
            if (!empty($facts['registration'])) {
                $s .= sprintf($t['registered'], $facts['registration']);
                $s .= !empty($facts['registeredOn']) ? sprintf($t['on'], $facts['registeredOn']) : '';
            }
            $s .= '.';
            if (str_contains($tipN, 'trezorerie')) {
                $s .= $t['recipisa_trezorerie'];
            } elseif (str_contains($detN, 'eroare') || str_contains($detN, 'erori')) {
                $s .= $t['recipisa_errors'];
            } else {
                $s .= $t['recipisa_ok'];
            }

            return $s;
        }

        if (str_contains($tipN, 'somatii') || str_contains($tipN, 'somatie')) {
            return $t['somatie'];
        }
        if (str_contains($detN, 'poprir') || str_contains($tipN, 'poprir')) {
            return $t['poprire'];
        }
        if (str_contains($detN, 'sechestru') || str_contains($detN, 'executare silita')) {
            return $t['executare'];
        }
        if (str_contains($tipN, 'analiza de risc') || str_contains($tipN, 'analiza risc')) {
            return $t['risc'];
        }
        if (str_contains($tipN, 'decizie') || str_starts_with($detN, 'decizie')) {
            return $this->describeDecision($tipN . ' ' . $detN, $t);
        }
        if (str_contains($tipN, 'notificare') || str_contains($tipN, 'instiintare') || str_contains($tipN, 'invitatie') || str_contains($tipN, 'informare')) {
            return $this->describeNotice($tipN . ' ' . $detN, $t);
        }
        if (str_contains($tipN, 'adrese') || str_contains($tipN, 'adresa')) {
            return sprintf($t['adresa'], $paren);
        }
        if (str_contains($tipN, 'raspuns')) {
            return sprintf($t['raspuns'], $colon);
        }
        if (str_contains($tipN, 'fisa rol') || str_contains($tipN, 'fisa pe platitor')) {
            return $t['fisa_rol'];
        }
        if (str_contains($tipN, 'situatie sintetica')) {
            return $t['sintetica'];
        }
        if (str_contains($tipN, 'obligatii de plata') || str_contains($tipN, 'nota obligatiilor')) {
            return $t['obligatii'];
        }
        if (str_contains($tipN, 'vector fiscal')) {
            return $t['vector'];
        }
        if (str_contains($tipN, 'date identificare')) {
            return $t['identificare'];
        }
        if (str_contains($tipN, 'certificat')) {
            return sprintf($t['certificat'], $colon);
        }
        if (str_contains($tipN, 'cazier')) {
            return $t['cazier'];
        }
        if (str_contains($tipN, 'adeverinta venit')) {
            return $t['adeverinta'];
        }
        if (str_contains($tipN, 'istoric declaratii')) {
            return $t['istoric'];
        }
        if (str_contains($tipN, 'bilant') || str_contains($tipN, 'situatii financiare')) {
            return $t['bilant'];
        }
        if (str_contains($tipN, 'declaratie') || preg_match('/^d\d{3}/', $tipN)) {
            $what = $this->describeDeclaration($facts['declaration'] ?? $this->extractCode($tip), $locale);
            $period = $this->describePeriod($facts['period'] ?? null, $locale);

            return sprintf($t['copie'], $what ? ': ' . $what : '', $period ? ', ' . $period : '');
        }
        if (str_contains($tipN, 'facturi arhiva') || str_contains($tipN, 'factur')) {
            return $t['facturi'];
        }
        if (str_contains($tipN, 'extras de cont') || str_contains($tipN, 'extras cont')) {
            return $t['extras'];
        }
        if (str_contains($tipN, 'plata')) {
            return $t['plata'];
        }
        if (str_contains($tipN, 'ajutor de stat')) {
            return $t['ajutor'];
        }
        if (str_contains($tipN, 'tezaur')) {
            return $t['tezaur'];
        }

        return match ($category) {
            SpvDocumentCategory::SOMATIE => $t['fallback_somatie'],
            SpvDocumentCategory::DECIZIE => $this->describeDecision($detN, $t),
            SpvDocumentCategory::NOTIFICARE => $this->describeNotice($detN, $t),
            SpvDocumentCategory::RECIPISA => $t['fallback_recipisa'],
            default => sprintf($t['fallback'], $det !== '' ? $this->shorten($det) : sprintf($t['fallback_type'], $tip)),
        };
    }

    /** @return array{declaration?: string, period?: string, registration?: string, registeredOn?: string, cif?: string} */
    public function parseDetails(string $details): array
    {
        $facts = [];
        if (preg_match('/\btip\s+([A-Z]\d{3,4})\b/i', $details, $m)) {
            $facts['declaration'] = strtoupper($m[1]);
        }
        if (preg_match('/perioada\s+raportare\s+([0-9.\/-]+)/i', $details, $m)) {
            $facts['period'] = $m[1];
        }
        if (preg_match('/numar_inregistrare\s+([A-Z0-9-]+)(?:\/(\d{2}-\d{2}-\d{4}))?/i', $details, $m)) {
            $facts['registration'] = $m[1];
            if (isset($m[2])) {
                $facts['registeredOn'] = str_replace('-', '.', $m[2]);
            }
        }
        if (preg_match('/\bCIF\s+(\d{2,13})\b/i', $details, $m)) {
            $facts['cif'] = $m[1];
        }

        return $facts;
    }

    private function describeDeclaration(?string $code, string $locale): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = strtoupper($code);
        $name = self::DECLARATIONS[$code][$locale] ?? null;

        return $name ? sprintf('%s (%s)', $name, $code) : sprintf(self::T[$locale]['declaratia'], $code);
    }

    /** "7.2026" → "luna iulie 2026" / "July 2026"; "2025" → "anul 2025" / "year 2025". */
    private function describePeriod(?string $raw, string $locale): ?string
    {
        if ($raw === null) {
            return null;
        }
        $t = self::T[$locale];
        if (preg_match('/^(\d{1,2})\.(\d{4})$/', $raw, $m)) {
            $month = (int) $m[1];
            if ($month >= 1 && $month <= 12) {
                return sprintf($t['luna'], self::MONTHS[$locale][$month], $m[2]);
            }
        }
        if (preg_match('/^(\d{4})$/', $raw, $m)) {
            return sprintf($t['anul'], $m[1]);
        }
        if (preg_match('/^T(\d)\.?(\d{4})$/i', $raw, $m)) {
            return sprintf($t['trimestrul'], $m[1], $m[2]);
        }
        if (preg_match('/^S(\d)\.?(\d{4})$/i', $raw, $m)) {
            return sprintf($t['semestrul'], $m[1], $m[2]);
        }

        return sprintf($t['perioada'], $raw);
    }

    /** @param array<string, string> $t */
    private function describeDecision(string $text, array $t): string
    {
        $x = $this->normalize($text);
        return match (true) {
            str_contains($x, 'inactivare') => $t['dec_inactivare'],
            str_contains($x, 'reactivare') => $t['dec_reactivare'],
            str_contains($x, 'anulare') && str_contains($x, 'tva') => $t['dec_anulare_tva'],
            str_contains($x, 'anulare') => $t['dec_anulare'],
            str_contains($x, 'inregistrare') && str_contains($x, 'tva') => $t['dec_inreg_tva'],
            str_contains($x, 'respingere') => $t['dec_respingere'],
            str_contains($x, 'impunere') => $t['dec_impunere'],
            str_contains($x, 'accesorii') || str_contains($x, 'dobanzi') || str_contains($x, 'penalitati') => $t['dec_accesorii'],
            str_contains($x, 'rambursare') => $t['dec_rambursare'],
            str_contains($x, 'esalonare') => $t['dec_esalonare'],
            str_contains($x, 'domiciliu fiscal') || str_contains($x, 'dom. fiscal') => $t['dec_domiciliu'],
            str_contains($x, 'grup') => $t['dec_grup'],
            str_contains($x, 'registru') => $t['dec_registru'],
            default => $t['dec_default'],
        };
    }

    /** @param array<string, string> $t */
    private function describeNotice(string $text, array $t): string
    {
        $x = $this->normalize($text);
        return match (true) {
            str_contains($x, 'conformare') => $t['not_conformare'],
            str_contains($x, 'nedepunere') || str_contains($x, 'nu ati depus') || str_contains($x, 'declaratii nedepuse') => $t['not_nedepunere'],
            str_contains($x, 'din oficiu') => $t['not_oficiu'],
            str_contains($x, 'radiere') => $t['not_radiere'],
            str_contains($x, 'documente suplimentare') => $t['not_documente'],
            str_contains($x, 'redirectionare') => $t['not_redirectionare'],
            str_contains($x, 'sesizare') => $t['not_sesizare'],
            str_contains($x, 'invitatie') => $t['not_invitatie'],
            str_contains($x, 'control') || str_contains($x, 'inspectie') => $t['not_control'],
            default => $t['not_default'],
        };
    }

    private function extractCode(string $text): ?string
    {
        return preg_match('/\b([CD]\d{3,4})\b/i', $text, $m) ? strtoupper($m[1]) : null;
    }

    private function shorten(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > 160 ? mb_substr($text, 0, 157) . '…' : $text;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);

        return preg_replace('/\s+/', ' ', $s) ?? $s;
    }
}
