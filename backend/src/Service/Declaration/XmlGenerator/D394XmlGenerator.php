<?php

namespace App\Service\Declaration\XmlGenerator;

use App\Entity\TaxDeclaration;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;

/**
 * Generates ANAF-compliant D394 XML per the official XSD schema (d394_20250917.xml).
 *
 * Root element: <declaratie394> with attributes:
 *   luna, an, d_rec, tip_D394, sistemTVA, op_efectuate,
 *   cui, den, adresa, telefon, fax, mail, caen,
 *   totalPlata_A (total sales VAT), totalPlata_B (total purchases VAT)
 *
 * Child elements:
 *   <op1> - operations per partner (livrari L / achizitii A)
 *     attributes: tip, tip_partener, cuiP, denP, taraP, locP, judP, nrFact, baza, tva, cota
 *   <rezumat1> - summary of op1 by rate
 *   <serieFacturi> - invoice series used
 */
class D394XmlGenerator implements DeclarationXmlGeneratorInterface
{
    public function supportsType(string $type): bool
    {
        return $type === 'd394';
    }

    public function generate(TaxDeclaration $declaration): string
    {
        $data = $declaration->getData();
        $company = $declaration->getCompany();

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        // Root element with all required attributes
        $root = $dom->createElement('declaratie394');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttribute('luna', (string) $declaration->getMonth());
        $root->setAttribute('an', (string) $declaration->getYear());
        $root->setAttribute('d_rec', '0'); // 0 = original, 1 = rectificativa
        $root->setAttribute('tip_D394', 'I'); // I = informatia initiala
        $root->setAttribute('sistemTVA', '0'); // 0 = normal, 1 = TVA la incasare
        $root->setAttribute('cui', (string) $company->getCif());
        $root->setAttribute('den', $company->getName() ?? '');
        $root->setAttribute('adresa', $company->getAddress() ?? '');
        $root->setAttribute('telefon', $company->getPhone() ?? '');
        $root->setAttribute('fax', '');
        $root->setAttribute('mail', $company->getEmail() ?? '');

        // Determine what operations are present
        $hasSales = !empty($data['sales']);
        $hasPurchases = !empty($data['purchases']);
        $opEffects = '0'; // 0=none
        if ($hasSales && $hasPurchases) {
            $opEffects = '3'; // 3=both
        } elseif ($hasSales) {
            $opEffects = '1'; // 1=sales only
        } elseif ($hasPurchases) {
            $opEffects = '2'; // 2=purchases only
        }
        $root->setAttribute('op_efectuate', $opEffects);

        // Total VAT amounts
        $root->setAttribute('totalPlata_A', $data['totals']['sales']['vatAmount'] ?? '0');
        $root->setAttribute('totalPlata_B', $data['totals']['purchases']['vatAmount'] ?? '0');

        $dom->appendChild($root);

        // ── op1: Sales partners (tip=L) ─────────────────────────────────
        if ($hasSales) {
            foreach ($data['sales'] as $partner) {
                $this->appendOp1($dom, $root, $partner, 'L');
            }
        }

        // ── op1: Purchase partners (tip=A) ──────────────────────────────
        if ($hasPurchases) {
            foreach ($data['purchases'] as $partner) {
                $this->appendOp1($dom, $root, $partner, 'A');
            }
        }

        // ── rezumat1: Summary by VAT rate ───────────────────────────────
        if (!empty($data['rezumat'])) {
            if (!empty($data['rezumat']['sales'])) {
                foreach ($data['rezumat']['sales'] as $rateSummary) {
                    $this->appendRezumat1($dom, $root, $rateSummary, 'L');
                }
            }
            if (!empty($data['rezumat']['purchases'])) {
                foreach ($data['rezumat']['purchases'] as $rateSummary) {
                    $this->appendRezumat1($dom, $root, $rateSummary, 'A');
                }
            }
        }

        // ── serieFacturi: Invoice series used ───────────────────────────
        if (!empty($data['serieFacturi'])) {
            foreach ($data['serieFacturi'] as $series) {
                $sf = $dom->createElement('serieFacturi');
                $sf->setAttribute('serie', $series['serie'] ?? '');
                $sf->setAttribute('nrI', $series['firstNumber'] ?? '');
                $sf->setAttribute('nrF', $series['lastNumber'] ?? '');
                $sf->setAttribute('nrT', (string) ($series['count'] ?? 0));
                $root->appendChild($sf);
            }
        }

        return $dom->saveXML();
    }

    /**
     * Append an <op1> element for a single partner.
     *
     * Per XSD: each partner × VAT rate combination is a separate <op1> row.
     */
    private function appendOp1(\DOMDocument $dom, \DOMElement $root, array $partner, string $tip): void
    {
        if (empty($partner['byRate'])) {
            // Single op1 with totals only
            $op1 = $dom->createElement('op1');
            $op1->setAttribute('tip', $tip);
            $op1->setAttribute('tip_partener', (string) ($partner['tipPartener'] ?? 1));
            $op1->setAttribute('cuiP', $partner['partnerCif'] ?? '');
            $op1->setAttribute('denP', $partner['partnerName'] ?? '');
            $op1->setAttribute('taraP', $partner['partnerCountry'] ?? 'RO');
            $op1->setAttribute('locP', $partner['partnerCity'] ?? '');
            $op1->setAttribute('judP', $partner['partnerCounty'] ?? '');
            $op1->setAttribute('nrFact', (string) ($partner['invoiceCount'] ?? 0));
            $op1->setAttribute('baza', $partner['total']['taxableBase'] ?? '0');
            $op1->setAttribute('tva', $partner['total']['vatAmount'] ?? '0');
            $root->appendChild($op1);

            return;
        }

        // One <op1> per VAT rate for this partner
        foreach ($partner['byRate'] as $rateKey => $amounts) {
            $op1 = $dom->createElement('op1');
            $op1->setAttribute('tip', $tip);
            $op1->setAttribute('tip_partener', (string) ($partner['tipPartener'] ?? 1));
            $op1->setAttribute('cuiP', $partner['partnerCif'] ?? '');
            $op1->setAttribute('denP', $partner['partnerName'] ?? '');
            $op1->setAttribute('taraP', $partner['partnerCountry'] ?? 'RO');
            $op1->setAttribute('locP', $partner['partnerCity'] ?? '');
            $op1->setAttribute('judP', $partner['partnerCounty'] ?? '');
            $op1->setAttribute('nrFact', (string) ($partner['invoiceCount'] ?? 0));
            $op1->setAttribute('cota', (string) ($amounts['cota'] ?? $rateKey));
            $op1->setAttribute('baza', $amounts['taxableBase'] ?? '0');
            $op1->setAttribute('tva', $amounts['vatAmount'] ?? '0');
            $root->appendChild($op1);
        }
    }

    /**
     * Append a <rezumat1> element (summary by VAT rate and operation type).
     */
    private function appendRezumat1(\DOMDocument $dom, \DOMElement $root, array $rateSummary, string $tip): void
    {
        $rez = $dom->createElement('rezumat1');
        $rez->setAttribute('tip', $tip);
        $rez->setAttribute('cota', (string) ($rateSummary['cota'] ?? 0));
        $rez->setAttribute('baza', $rateSummary['taxableBase'] ?? '0');
        $rez->setAttribute('tva', $rateSummary['vatAmount'] ?? '0');
        $rez->setAttribute('nrParteneri', (string) ($rateSummary['partnerCount'] ?? 0));
        $root->appendChild($rez);
    }
}
