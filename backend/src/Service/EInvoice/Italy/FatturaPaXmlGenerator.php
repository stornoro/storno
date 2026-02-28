<?php

namespace App\Service\EInvoice\Italy;

use App\Entity\Invoice;
use App\Enum\DocumentType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generates FatturaPA XML (FatturaElettronica) for Italian SDI e-invoicing.
 *
 * FatturaPA uses a completely different XML schema from UBL.
 * Root: <p:FatturaElettronica> with namespace http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2
 *
 * @see https://www.fatturapa.gov.it/it/norme-e-regole/documentazione-fattura-elettronica/formato-fattura-elettronica/
 */
class FatturaPaXmlGenerator
{
    private const NAMESPACE_URI = 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2';
    private const SCHEMA_LOCATION = 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2 https://www.fatturapa.gov.it/export/documenti/fatturapa/v1.2.2/Schema_del_file_xml_FatturaPA_v1.2.2.xsd';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function generate(Invoice $invoice): string
    {
        $company = $invoice->getCompany();

        $filters = $this->entityManager->getFilters();
        $filterWasEnabled = $filters->isEnabled('soft_delete');
        if ($filterWasEnabled) {
            $filters->disable('soft_delete');
        }
        $client = $invoice->getClient();
        if ($filterWasEnabled) {
            $filters->enable('soft_delete');
        }

        if ($invoice->getDocumentType() === DocumentType::PROFORMA) {
            throw new \InvalidArgumentException('Proforma invoices cannot be submitted to SDI.');
        }

        $isCreditNote = $invoice->getDocumentType() === DocumentType::CREDIT_NOTE;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        // Root element
        $root = $dom->createElementNS(self::NAMESPACE_URI, 'p:FatturaElettronica');
        $root->setAttribute('versione', 'FPR12'); // B2B/B2C format
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:ds',
            'http://www.w3.org/2000/09/xmldsig#'
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:xsi',
            'http://www.w3.org/2001/XMLSchema-instance'
        );
        $root->setAttributeNS(
            'http://www.w3.org/2001/XMLSchema-instance',
            'xsi:schemaLocation',
            self::SCHEMA_LOCATION
        );
        $dom->appendChild($root);

        // === FatturaElettronicaHeader ===
        $header = $dom->createElement('FatturaElettronicaHeader');
        $root->appendChild($header);

        $this->addDatiTrasmissione($dom, $header, $company, $client);
        $this->addCedentePrestatore($dom, $header, $company);
        $this->addCessionarioCommittente($dom, $header, $client);

        // === FatturaElettronicaBody ===
        $body = $dom->createElement('FatturaElettronicaBody');
        $root->appendChild($body);

        $this->addDatiGenerali($dom, $body, $invoice, $isCreditNote);
        $this->addDatiBeniServizi($dom, $body, $invoice);
        $this->addDatiPagamento($dom, $body, $invoice, $company);

        return $dom->saveXML();
    }

    /**
     * 1.1 DatiTrasmissione — Transmission data (sender/receiver routing).
     */
    private function addDatiTrasmissione(\DOMDocument $dom, \DOMElement $header, $company, $client): void
    {
        $dt = $dom->createElement('DatiTrasmissione');
        $header->appendChild($dt);

        // IdTrasmittente (sender's tax ID)
        $idTrasmittente = $dom->createElement('IdTrasmittente');
        $this->addEl($dom, $idTrasmittente, 'IdPaese', $company?->getCountry() ?? 'IT');
        $this->addEl($dom, $idTrasmittente, 'IdCodice', (string) ($company?->getCif() ?? ''));
        $dt->appendChild($idTrasmittente);

        // Progressive number (unique per transmission)
        $this->addEl($dom, $dt, 'ProgressivoInvio', substr(md5(uniqid('', true)), 0, 10));

        // FormatoTrasmissione: FPR12 for B2B/B2C, FPA12 for B2G
        $this->addEl($dom, $dt, 'FormatoTrasmissione', 'FPR12');

        // CodiceDestinatario — 7-char routing code for B2B, 0000000 + PEC for individuals,
        // XXXXXXX for foreign recipients
        $codiceDestinatario = '0000000';
        $isForeignRecipient = false;
        if ($client !== null) {
            $clientCountry = $client->getCountry();
            $isForeignRecipient = $clientCountry !== null && strtoupper($clientCountry) !== 'IT';

            if ($isForeignRecipient) {
                $codiceDestinatario = 'XXXXXXX';
            } else {
                $identifiers = $client->getEinvoiceIdentifier('sdi');
                if ($identifiers !== null && !empty($identifiers['codiceDestinatario'])) {
                    $codiceDestinatario = $identifiers['codiceDestinatario'];
                }
            }
        }
        $this->addEl($dom, $dt, 'CodiceDestinatario', $codiceDestinatario);

        // PEC address for individual recipients (when CodiceDestinatario is 0000000, not foreign)
        if ($codiceDestinatario === '0000000' && $client !== null && !$isForeignRecipient) {
            $identifiers = $client->getEinvoiceIdentifier('sdi');
            $pecAddress = $identifiers['pecAddress'] ?? null;
            if ($pecAddress) {
                $this->addEl($dom, $dt, 'PECDestinatario', $pecAddress);
            }
        }
    }

    /**
     * 1.2 CedentePrestatore — Supplier (seller) data.
     */
    private function addCedentePrestatore(\DOMDocument $dom, \DOMElement $header, $company): void
    {
        $cp = $dom->createElement('CedentePrestatore');
        $header->appendChild($cp);

        // DatiAnagrafici
        $datiAnagrafici = $dom->createElement('DatiAnagrafici');
        $cp->appendChild($datiAnagrafici);

        $idFiscaleIVA = $dom->createElement('IdFiscaleIVA');
        $this->addEl($dom, $idFiscaleIVA, 'IdPaese', $company?->getCountry() ?? 'IT');
        $this->addEl($dom, $idFiscaleIVA, 'IdCodice', (string) ($company?->getCif() ?? ''));
        $datiAnagrafici->appendChild($idFiscaleIVA);

        $anagrafica = $dom->createElement('Anagrafica');
        $this->addEl($dom, $anagrafica, 'Denominazione', $company?->getName() ?? '');
        $datiAnagrafici->appendChild($anagrafica);

        // RegimeFiscale — RF01 is the ordinary regime
        $this->addEl($dom, $datiAnagrafici, 'RegimeFiscale', 'RF01');

        // Sede (registered office address)
        $sede = $dom->createElement('Sede');
        $this->addEl($dom, $sede, 'Indirizzo', $company?->getAddress() ?? '');
        $this->addEl($dom, $sede, 'CAP', '00000'); // Postal code — default if not available
        $this->addEl($dom, $sede, 'Comune', $company?->getCity() ?? '');
        $this->addEl($dom, $sede, 'Nazione', $company?->getCountry() ?? 'IT');
        $cp->appendChild($sede);
    }

    /**
     * 1.4 CessionarioCommittente — Customer (buyer) data.
     */
    private function addCessionarioCommittente(\DOMDocument $dom, \DOMElement $header, $client): void
    {
        $cc = $dom->createElement('CessionarioCommittente');
        $header->appendChild($cc);

        $datiAnagrafici = $dom->createElement('DatiAnagrafici');
        $cc->appendChild($datiAnagrafici);

        if ($client !== null && $client->getCui()) {
            // Company buyer — Partita IVA (11 digits)
            $idFiscaleIVA = $dom->createElement('IdFiscaleIVA');
            $this->addEl($dom, $idFiscaleIVA, 'IdPaese', $client->getCountry() ?? 'IT');
            $this->addEl($dom, $idFiscaleIVA, 'IdCodice', $client->getCui());
            $datiAnagrafici->appendChild($idFiscaleIVA);
        } elseif ($client !== null && $client->getCnp()) {
            // Individual buyer — Codice Fiscale (16 chars)
            $this->addEl($dom, $datiAnagrafici, 'CodiceFiscale', $client->getCnp());
        }

        $anagrafica = $dom->createElement('Anagrafica');
        $this->addEl($dom, $anagrafica, 'Denominazione', $client?->getName() ?? '');
        $datiAnagrafici->appendChild($anagrafica);

        // Sede
        $sede = $dom->createElement('Sede');
        $this->addEl($dom, $sede, 'Indirizzo', $client?->getAddress() ?? '');
        $this->addEl($dom, $sede, 'CAP', $client?->getPostalCode() ?? '00000');
        $this->addEl($dom, $sede, 'Comune', $client?->getCity() ?? '');
        $this->addEl($dom, $sede, 'Nazione', $client?->getCountry() ?? 'IT');
        $cc->appendChild($sede);
    }

    /**
     * 2.1 DatiGenerali — General invoice data.
     */
    private function addDatiGenerali(\DOMDocument $dom, \DOMElement $body, Invoice $invoice, bool $isCreditNote): void
    {
        $dg = $dom->createElement('DatiGenerali');
        $body->appendChild($dg);

        $datiGeneraliDocumento = $dom->createElement('DatiGeneraliDocumento');
        $dg->appendChild($datiGeneraliDocumento);

        // TipoDocumento: TD01 (invoice), TD04 (credit note), TD06 (proforma)
        $tipoDocumento = $isCreditNote ? 'TD04' : 'TD01';
        $this->addEl($dom, $datiGeneraliDocumento, 'TipoDocumento', $tipoDocumento);

        $this->addEl($dom, $datiGeneraliDocumento, 'Divisa', $invoice->getCurrency());
        $this->addEl($dom, $datiGeneraliDocumento, 'Data', $invoice->getIssueDate()?->format('Y-m-d') ?? '');
        $this->addEl($dom, $datiGeneraliDocumento, 'Numero', $invoice->getNumber() ?? '');

        if ($invoice->getNotes()) {
            $this->addEl($dom, $datiGeneraliDocumento, 'Causale', mb_substr($invoice->getNotes(), 0, 200));
        }

        // Order reference (must come before DatiFattureCollegate per schema order)
        if ($invoice->getOrderNumber()) {
            $datiOrdineAcquisto = $dom->createElement('DatiOrdineAcquisto');
            $this->addEl($dom, $datiOrdineAcquisto, 'IdDocumento', $invoice->getOrderNumber());
            $dg->appendChild($datiOrdineAcquisto);
        }

        // Contract reference
        if ($invoice->getContractNumber()) {
            $datiContratto = $dom->createElement('DatiContratto');
            $this->addEl($dom, $datiContratto, 'IdDocumento', $invoice->getContractNumber());
            $dg->appendChild($datiContratto);
        }

        // Reference to original invoice for credit notes
        if ($isCreditNote && $invoice->getParentDocument() !== null) {
            $datiFattureCollegate = $dom->createElement('DatiFattureCollegate');
            $this->addEl($dom, $datiFattureCollegate, 'IdDocumento', $invoice->getParentDocument()->getNumber() ?? '');
            $this->addEl($dom, $datiFattureCollegate, 'Data', $invoice->getParentDocument()->getIssueDate()?->format('Y-m-d') ?? '');
            $dg->appendChild($datiFattureCollegate);
        }
    }

    /**
     * 2.2 DatiBeniServizi — Line items and VAT summary.
     */
    private function addDatiBeniServizi(\DOMDocument $dom, \DOMElement $body, Invoice $invoice): void
    {
        $dbs = $dom->createElement('DatiBeniServizi');
        $body->appendChild($dbs);

        // DettaglioLinee — one per invoice line
        $lineNum = 1;
        foreach ($invoice->getLines() as $line) {
            $dettaglio = $dom->createElement('DettaglioLinee');

            $this->addEl($dom, $dettaglio, 'NumeroLinea', (string) $lineNum);
            $this->addEl($dom, $dettaglio, 'Descrizione', $line->getDescription() ?? '');
            $this->addEl($dom, $dettaglio, 'Quantita', $this->formatDecimal($line->getQuantity(), 2));
            $this->addEl($dom, $dettaglio, 'PrezzoUnitario', $this->formatDecimal($line->getUnitPrice(), 2));
            $this->addEl($dom, $dettaglio, 'PrezzoTotale', $this->formatDecimal($line->getLineTotal(), 2));
            $this->addEl($dom, $dettaglio, 'AliquotaIVA', $this->formatDecimal($line->getVatRate(), 2));

            // Natura — required when AliquotaIVA is 0
            if (bccomp($line->getVatRate(), '0', 2) === 0) {
                $natura = $this->mapVatExemptionNatura($line->getVatCategoryCode());
                $this->addEl($dom, $dettaglio, 'Natura', $natura);
            }

            $dbs->appendChild($dettaglio);
            $lineNum++;
        }

        // DatiRiepilogo — VAT summary per rate
        $vatGroups = [];
        foreach ($invoice->getLines() as $line) {
            $key = $line->getVatRate();
            if (!isset($vatGroups[$key])) {
                $vatGroups[$key] = [
                    'rate' => $line->getVatRate(),
                    'categoryCode' => $line->getVatCategoryCode(),
                    'taxableAmount' => '0.00',
                    'taxAmount' => '0.00',
                ];
            }
            $vatGroups[$key]['taxableAmount'] = bcadd($vatGroups[$key]['taxableAmount'], $line->getLineTotal(), 2);
            $vatGroups[$key]['taxAmount'] = bcadd($vatGroups[$key]['taxAmount'], $line->getVatAmount(), 2);
        }

        foreach ($vatGroups as $group) {
            $riepilogo = $dom->createElement('DatiRiepilogo');
            $this->addEl($dom, $riepilogo, 'AliquotaIVA', $this->formatDecimal($group['rate'], 2));

            // Natura must come before ImponibileImporto/Imposta per schema order
            if (bccomp($group['rate'], '0', 2) === 0) {
                $natura = $this->mapVatExemptionNatura($group['categoryCode']);
                $this->addEl($dom, $riepilogo, 'Natura', $natura);
            }

            $this->addEl($dom, $riepilogo, 'ImponibileImporto', $this->formatDecimal($group['taxableAmount'], 2));
            $this->addEl($dom, $riepilogo, 'Imposta', $this->formatDecimal($group['taxAmount'], 2));

            // EsigibilitaIVA only for taxable lines (not for zero-rate/exempt)
            if (bccomp($group['rate'], '0', 2) !== 0) {
                $this->addEl($dom, $riepilogo, 'EsigibilitaIVA', 'I'); // I = immediata (immediate)
            }

            $dbs->appendChild($riepilogo);
        }
    }

    /**
     * 2.4 DatiPagamento — Payment data.
     */
    private function addDatiPagamento(\DOMDocument $dom, \DOMElement $body, Invoice $invoice, $company): void
    {
        $dp = $dom->createElement('DatiPagamento');
        $body->appendChild($dp);

        // CondizioniPagamento: TP01 (lump sum), TP02 (installments), TP03 (advance)
        $this->addEl($dom, $dp, 'CondizioniPagamento', 'TP02');

        $dettaglio = $dom->createElement('DettaglioPagamento');

        // ModalitaPagamento: MP05 (bank transfer), MP01 (cash), MP08 (card)
        $modalita = $this->mapPaymentMethod($invoice->getPaymentMethod());
        $this->addEl($dom, $dettaglio, 'ModalitaPagamento', $modalita);

        if ($invoice->getDueDate() !== null) {
            $this->addEl($dom, $dettaglio, 'DataScadenzaPagamento', $invoice->getDueDate()->format('Y-m-d'));
        }

        $this->addEl($dom, $dettaglio, 'ImportoPagamento', $this->formatDecimal($invoice->getTotal(), 2));

        // IBAN for bank transfer
        if ($company?->getBankAccount()) {
            $this->addEl($dom, $dettaglio, 'IBAN', str_replace(' ', '', $company->getBankAccount()));
        }

        $dp->appendChild($dettaglio);
    }

    private function addEl(\DOMDocument $dom, \DOMElement $parent, string $name, string $value): void
    {
        $element = $dom->createElement($name, htmlspecialchars($value, ENT_XML1, 'UTF-8'));
        $parent->appendChild($element);
    }

    private function formatDecimal(string $value, int $decimals): string
    {
        return number_format((float) $value, $decimals, '.', '');
    }

    /**
     * Map VAT category codes to Italian Natura codes (for 0% VAT lines).
     */
    private function mapVatExemptionNatura(string $categoryCode): string
    {
        return match ($categoryCode) {
            'E' => 'N4',    // Esenti (exempt)
            'AE' => 'N6.9', // Inversione contabile (reverse charge — other cases)
            'K' => 'N3.2',  // Cessioni intracomunitarie (intra-EU)
            'G' => 'N3.1',  // Esportazioni (exports)
            'O' => 'N2.2',  // Non soggette (not subject)
            'Z' => 'N3.1',  // Zero rate → exports
            default => 'N2.2',
        };
    }

    private function mapPaymentMethod(?string $method): string
    {
        return match ($method) {
            'cash' => 'MP01',
            'cheque' => 'MP02',
            'bank_transfer' => 'MP05',
            'card' => 'MP08',
            'direct_debit' => 'MP19',
            default => 'MP05',
        };
    }
}
