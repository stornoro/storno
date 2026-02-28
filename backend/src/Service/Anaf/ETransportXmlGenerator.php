<?php

namespace App\Service\Anaf;

use App\Entity\DeliveryNote;
use App\Entity\DeliveryNoteLine;

/**
 * Generates XML documents for the Romanian e-Transport system (schema v2).
 *
 * Supported operations:
 *   - notificare  : initial transport notification
 *   - corectie    : correction of an existing UIT (same payload + <corectie> child)
 *   - stergere    : deletion of an existing UIT
 *   - confirmare  : confirmation/reception of goods
 */
class ETransportXmlGenerator
{
    private const NS = 'mfp:anaf:dgti:eTransport:declaratie:v2';

    /**
     * Generates a notification XML for the given delivery note.
     */
    public function generateNotification(DeliveryNote $note): string
    {
        $dom = $this->createDocument();
        $root = $this->createRoot($dom, $note->getCompany()->getCif(), $note->getId());

        if ($note->getEtransportPostIncident() === 'D') {
            $root->setAttribute('declPostAvarie', 'D');
        }

        $notificare = $this->buildNotificare($dom, $note);
        $root->appendChild($notificare);

        $dom->appendChild($root);

        return $dom->saveXML();
    }

    /**
     * Generates a correction XML for the given delivery note.
     * Identical to a notification but includes a <corectie uit="..."/> as the
     * first child of <notificare>.
     */
    public function generateCorrection(DeliveryNote $note, string $uit): string
    {
        $dom = $this->createDocument();
        $root = $this->createRoot($dom, $note->getCompany()->getCif(), $note->getId());

        if ($note->getEtransportPostIncident() === 'D') {
            $root->setAttribute('declPostAvarie', 'D');
        }

        $notificare = $this->buildNotificare($dom, $note);

        // Insert <corectie> as the very first child of <notificare>
        $corectie = $dom->createElement('corectie');
        $corectie->setAttribute('uit', $uit);
        $notificare->insertBefore($corectie, $notificare->firstChild);

        $root->appendChild($notificare);

        $dom->appendChild($root);

        return $dom->saveXML();
    }

    /**
     * Generates a deletion XML for the given UIT.
     */
    public function generateDeletion(string $cif, string $uit): string
    {
        $cif = $this->normalizeCif($cif);

        $dom = $this->createDocument();
        $root = $dom->createElementNS(self::NS, 'eTransport');
        $root->setAttribute('codDeclarant', $cif);

        $stergere = $dom->createElement('stergere');
        $stergere->setAttribute('uit', $uit);
        $root->appendChild($stergere);

        $dom->appendChild($root);

        return $dom->saveXML();
    }

    /**
     * Generates a confirmation XML for the given UIT.
     *
     * @param int         $type    tipConfirmare value (e.g. 10 = received, 20 = refused)
     * @param string|null $remarks Optional observatii text
     */
    public function generateConfirmation(string $cif, string $uit, int $type, ?string $remarks): string
    {
        $cif = $this->normalizeCif($cif);

        $dom = $this->createDocument();
        $root = $dom->createElementNS(self::NS, 'eTransport');
        $root->setAttribute('codDeclarant', $cif);

        $confirmare = $dom->createElement('confirmare');
        $confirmare->setAttribute('uit', $uit);
        $confirmare->setAttribute('tipConfirmare', (string) $type);
        if ($remarks !== null && $remarks !== '') {
            $confirmare->setAttribute('observatii', $remarks);
        }
        $root->appendChild($confirmare);

        $dom->appendChild($root);

        return $dom->saveXML();
    }

    // -------------------------------------------------------------------------
    // Internal builders
    // -------------------------------------------------------------------------

    private function createDocument(): \DOMDocument
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        return $dom;
    }

    /**
     * Creates the <eTransport> root element with the default namespace and
     * codDeclarant / refDeclarant attributes.
     */
    private function createRoot(\DOMDocument $dom, int $cif, mixed $id): \DOMElement
    {
        $root = $dom->createElementNS(self::NS, 'eTransport');
        $root->setAttribute('codDeclarant', (string) $cif);
        $root->setAttribute('refDeclarant', (string) $id);

        return $root;
    }

    /**
     * Builds the complete <notificare> element from the delivery note data.
     * The <corectie> child, when required, is inserted by the caller before
     * appending this element to the root.
     */
    private function buildNotificare(\DOMDocument $dom, DeliveryNote $note): \DOMElement
    {
        $notificare = $dom->createElement('notificare');
        $notificare->setAttribute('codTipOperatiune', (string) ($note->getEtransportOperationType() ?? 30));

        // --- Goods lines ---
        foreach ($note->getLines() as $line) {
            $notificare->appendChild($this->buildBunuriTransportate($dom, $line));
        }

        // --- Commercial partner ---
        $notificare->appendChild($this->buildPartenerComercial($dom, $note));

        // --- Transport data ---
        $notificare->appendChild($this->buildDateTransport($dom, $note));

        // --- Route start ---
        $locStart = $dom->createElement('locStartTraseuRutier');
        $locStart->appendChild($this->buildLocatie($dom, $note, 'start'));
        $notificare->appendChild($locStart);

        // --- Route end ---
        $locFinal = $dom->createElement('locFinalTraseuRutier');
        $locFinal->appendChild($this->buildLocatie($dom, $note, 'end'));
        $notificare->appendChild($locFinal);

        // --- Transport document ---
        $notificare->appendChild($this->buildDocumenteTransport($dom, $note));

        return $notificare;
    }

    private function buildBunuriTransportate(\DOMDocument $dom, DeliveryNoteLine $line): \DOMElement
    {
        $el = $dom->createElement('bunuriTransportate');

        if ($line->getPurposeCode() !== null) {
            $el->setAttribute('codScopOperatiune', (string) $line->getPurposeCode());
        }

        if ($line->getTariffCode() !== null && $line->getTariffCode() !== '') {
            $el->setAttribute('codTarifar', $line->getTariffCode());
        }

        if ($line->getDescription() !== null && $line->getDescription() !== '') {
            $el->setAttribute('denumireMarfa', $line->getDescription());
        }

        $el->setAttribute('cantitate', $this->formatDecimal($line->getQuantity()));

        if ($line->getUnitOfMeasureCode() !== null && $line->getUnitOfMeasureCode() !== '') {
            $el->setAttribute('codUnitateMasura', $line->getUnitOfMeasureCode());
        }

        if ($line->getNetWeight() !== null) {
            $el->setAttribute('greutateNeta', $this->formatDecimal($line->getNetWeight()));
        }

        if ($line->getGrossWeight() !== null) {
            $el->setAttribute('greutateBruta', $this->formatDecimal($line->getGrossWeight()));
        }

        if ($line->getValueWithoutVat() !== null) {
            $el->setAttribute('valoareLeiFaraTva', $this->formatDecimal($line->getValueWithoutVat()));
        }

        return $el;
    }

    private function buildPartenerComercial(\DOMDocument $dom, DeliveryNote $note): \DOMElement
    {
        $el = $dom->createElement('partenerComercial');

        $client = $note->getClient();
        $codTara = $client ? ($client->getCountry() ?: 'RO') : 'RO';
        $el->setAttribute('codTara', $codTara);

        if ($client !== null) {
            if ($client->getCui() !== null && $client->getCui() !== '') {
                $el->setAttribute('cod', $this->normalizeCif($client->getCui()));
            }

            if ($client->getName() !== null && $client->getName() !== '') {
                $el->setAttribute('denumire', $client->getName());
            }
        }

        return $el;
    }

    private function buildDateTransport(\DOMDocument $dom, DeliveryNote $note): \DOMElement
    {
        $el = $dom->createElement('dateTransport');

        if ($note->getEtransportVehicleNumber() !== null && $note->getEtransportVehicleNumber() !== '') {
            $el->setAttribute('nrVehicul', $note->getEtransportVehicleNumber());
        }

        if ($note->getEtransportTrailer1() !== null && $note->getEtransportTrailer1() !== '') {
            $el->setAttribute('nrRemorca1', $note->getEtransportTrailer1());
        }

        if ($note->getEtransportTrailer2() !== null && $note->getEtransportTrailer2() !== '') {
            $el->setAttribute('nrRemorca2', $note->getEtransportTrailer2());
        }

        if ($note->getEtransportTransporterCountry() !== null && $note->getEtransportTransporterCountry() !== '') {
            $el->setAttribute('codTaraOrgTransport', $note->getEtransportTransporterCountry());
        }

        if ($note->getEtransportTransporterCode() !== null && $note->getEtransportTransporterCode() !== '') {
            $el->setAttribute('codOrgTransport', $note->getEtransportTransporterCode());
        }

        if ($note->getEtransportTransporterName() !== null && $note->getEtransportTransporterName() !== '') {
            $el->setAttribute('denumireOrgTransport', $note->getEtransportTransporterName());
        }

        if ($note->getEtransportTransportDate() !== null) {
            $el->setAttribute('dataTransport', $note->getEtransportTransportDate()->format('Y-m-d'));
        }

        return $el;
    }

    /**
     * Builds a <locatie> element for either the start or end of the route.
     *
     * @param string $endpoint Either 'start' or 'end'
     */
    private function buildLocatie(\DOMDocument $dom, DeliveryNote $note, string $endpoint): \DOMElement
    {
        $el = $dom->createElement('locatie');

        if ($endpoint === 'start') {
            $county     = $note->getEtransportStartCounty();
            $locality   = $note->getEtransportStartLocality();
            $street     = $note->getEtransportStartStreet();
            $number     = $note->getEtransportStartNumber();
            $postalCode = $note->getEtransportStartPostalCode();
            $otherInfo  = $note->getEtransportStartOtherInfo();
        } else {
            $county     = $note->getEtransportEndCounty();
            $locality   = $note->getEtransportEndLocality();
            $street     = $note->getEtransportEndStreet();
            $number     = $note->getEtransportEndNumber();
            $postalCode = $note->getEtransportEndPostalCode();
            $otherInfo  = $note->getEtransportEndOtherInfo();
        }

        if ($county !== null) {
            $el->setAttribute('codJudet', (string) $county);
        }

        if ($locality !== null && $locality !== '') {
            $el->setAttribute('denumireLocalitate', $locality);
        }

        if ($street !== null && $street !== '') {
            $el->setAttribute('denumireStrada', $street);
        }

        if ($number !== null && $number !== '') {
            $el->setAttribute('numar', $number);
        }

        if ($postalCode !== null && $postalCode !== '') {
            $el->setAttribute('codPostal', $postalCode);
        }

        if ($otherInfo !== null && $otherInfo !== '') {
            $el->setAttribute('alteInfo', $otherInfo);
        }

        return $el;
    }

    private function buildDocumenteTransport(\DOMDocument $dom, DeliveryNote $note): \DOMElement
    {
        $el = $dom->createElement('documenteTransport');

        // tipDocument 30 = aviz de insotire a marfii (delivery note)
        $el->setAttribute('tipDocument', '30');

        if ($note->getNumber() !== null && $note->getNumber() !== '') {
            $el->setAttribute('numarDocument', $note->getNumber());
        }

        if ($note->getIssueDate() !== null) {
            $el->setAttribute('dataDocument', $note->getIssueDate()->format('Y-m-d'));
        }

        return $el;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Strips the 'RO' prefix from a CIF string if present, and trims whitespace.
     * Company::getCif() returns an int so this is primarily relevant for client
     * CUIs that may arrive as strings with the prefix included.
     */
    private function normalizeCif(string $cif): string
    {
        $cif = trim($cif);
        if (str_starts_with(strtoupper($cif), 'RO')) {
            $cif = ltrim(substr($cif, 2));
        }

        return $cif;
    }

    /**
     * Formats a decimal string by stripping unnecessary trailing zeros while
     * keeping at least two decimal places when the value is non-integer, and
     * returning a plain integer string when there is no fractional part.
     *
     * Examples: "2500.00" → "2500", "25000.09" → "25000.09"
     */
    private function formatDecimal(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim($value, '0');
            $value = rtrim($value, '.');
        }

        return $value;
    }
}
