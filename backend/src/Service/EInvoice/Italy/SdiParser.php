<?php

namespace App\Service\EInvoice\Italy;

/**
 * Parses incoming FatturaPA XML documents received from SDI.
 */
class SdiParser
{
    /**
     * Parse a FatturaPA XML string into a structured array.
     *
     * @return array{
     *     header: array{sender: array, receiver: array, transmission: array},
     *     body: array{general: array, lines: array, vatSummary: array, payment: array}
     * }
     */
    public function parse(string $xml): array
    {
        $dom = new \DOMDocument();
        if (!$dom->loadXML($xml)) {
            throw new \RuntimeException('Failed to parse FatturaPA XML.');
        }

        $root = $dom->documentElement;

        return [
            'header' => $this->parseHeader($root),
            'body' => $this->parseBody($root),
        ];
    }

    private function parseHeader(\DOMElement $root): array
    {
        $header = $this->getFirstChild($root, 'FatturaElettronicaHeader');
        if ($header === null) {
            return ['sender' => [], 'receiver' => [], 'transmission' => []];
        }

        return [
            'transmission' => $this->parseDatiTrasmissione($header),
            'sender' => $this->parseCedentePrestatore($header),
            'receiver' => $this->parseCessionarioCommittente($header),
        ];
    }

    private function parseBody(\DOMElement $root): array
    {
        $body = $this->getFirstChild($root, 'FatturaElettronicaBody');
        if ($body === null) {
            return ['general' => [], 'lines' => [], 'vatSummary' => [], 'payment' => []];
        }

        return [
            'general' => $this->parseDatiGenerali($body),
            'lines' => $this->parseDettaglioLinee($body),
            'vatSummary' => $this->parseDatiRiepilogo($body),
            'payment' => $this->parseDatiPagamento($body),
        ];
    }

    private function parseDatiTrasmissione(\DOMElement $header): array
    {
        $dt = $this->getFirstChild($header, 'DatiTrasmissione');
        if ($dt === null) {
            return [];
        }

        $idTrasmittente = $this->getFirstChild($dt, 'IdTrasmittente');

        return [
            'idPaese' => $this->getVal($idTrasmittente, 'IdPaese'),
            'idCodice' => $this->getVal($idTrasmittente, 'IdCodice'),
            'progressivoInvio' => $this->getVal($dt, 'ProgressivoInvio'),
            'formatoTrasmissione' => $this->getVal($dt, 'FormatoTrasmissione'),
            'codiceDestinatario' => $this->getVal($dt, 'CodiceDestinatario'),
            'pecDestinatario' => $this->getVal($dt, 'PECDestinatario'),
        ];
    }

    private function parseCedentePrestatore(\DOMElement $header): array
    {
        $cp = $this->getFirstChild($header, 'CedentePrestatore');
        if ($cp === null) {
            return [];
        }

        $datiAnagrafici = $this->getFirstChild($cp, 'DatiAnagrafici');
        $sede = $this->getFirstChild($cp, 'Sede');
        $idFiscaleIVA = $datiAnagrafici ? $this->getFirstChild($datiAnagrafici, 'IdFiscaleIVA') : null;
        $anagrafica = $datiAnagrafici ? $this->getFirstChild($datiAnagrafici, 'Anagrafica') : null;

        return [
            'vatCountry' => $this->getVal($idFiscaleIVA, 'IdPaese'),
            'vatId' => $this->getVal($idFiscaleIVA, 'IdCodice'),
            'codiceFiscale' => $this->getVal($datiAnagrafici, 'CodiceFiscale'),
            'name' => $this->getVal($anagrafica, 'Denominazione'),
            'address' => $this->getVal($sede, 'Indirizzo'),
            'postalCode' => $this->getVal($sede, 'CAP'),
            'city' => $this->getVal($sede, 'Comune'),
            'country' => $this->getVal($sede, 'Nazione'),
        ];
    }

    private function parseCessionarioCommittente(\DOMElement $header): array
    {
        $cc = $this->getFirstChild($header, 'CessionarioCommittente');
        if ($cc === null) {
            return [];
        }

        $datiAnagrafici = $this->getFirstChild($cc, 'DatiAnagrafici');
        $sede = $this->getFirstChild($cc, 'Sede');
        $idFiscaleIVA = $datiAnagrafici ? $this->getFirstChild($datiAnagrafici, 'IdFiscaleIVA') : null;
        $anagrafica = $datiAnagrafici ? $this->getFirstChild($datiAnagrafici, 'Anagrafica') : null;

        return [
            'vatCountry' => $this->getVal($idFiscaleIVA, 'IdPaese'),
            'vatId' => $this->getVal($idFiscaleIVA, 'IdCodice'),
            'codiceFiscale' => $this->getVal($datiAnagrafici, 'CodiceFiscale'),
            'name' => $this->getVal($anagrafica, 'Denominazione'),
            'address' => $this->getVal($sede, 'Indirizzo'),
            'postalCode' => $this->getVal($sede, 'CAP'),
            'city' => $this->getVal($sede, 'Comune'),
            'country' => $this->getVal($sede, 'Nazione'),
        ];
    }

    private function parseDatiGenerali(\DOMElement $body): array
    {
        $dg = $this->getFirstChild($body, 'DatiGenerali');
        if ($dg === null) {
            return [];
        }

        $dgd = $this->getFirstChild($dg, 'DatiGeneraliDocumento');

        return [
            'tipoDocumento' => $this->getVal($dgd, 'TipoDocumento'),
            'divisa' => $this->getVal($dgd, 'Divisa'),
            'data' => $this->getVal($dgd, 'Data'),
            'numero' => $this->getVal($dgd, 'Numero'),
            'causale' => $this->getVal($dgd, 'Causale'),
        ];
    }

    private function parseDettaglioLinee(\DOMElement $body): array
    {
        $dbs = $this->getFirstChild($body, 'DatiBeniServizi');
        if ($dbs === null) {
            return [];
        }

        $lines = [];
        foreach ($dbs->getElementsByTagName('DettaglioLinee') as $dettaglio) {
            $lines[] = [
                'numeroLinea' => $this->getVal($dettaglio, 'NumeroLinea'),
                'descrizione' => $this->getVal($dettaglio, 'Descrizione'),
                'quantita' => $this->getVal($dettaglio, 'Quantita'),
                'prezzoUnitario' => $this->getVal($dettaglio, 'PrezzoUnitario'),
                'prezzoTotale' => $this->getVal($dettaglio, 'PrezzoTotale'),
                'aliquotaIVA' => $this->getVal($dettaglio, 'AliquotaIVA'),
                'natura' => $this->getVal($dettaglio, 'Natura'),
            ];
        }

        return $lines;
    }

    private function parseDatiRiepilogo(\DOMElement $body): array
    {
        $dbs = $this->getFirstChild($body, 'DatiBeniServizi');
        if ($dbs === null) {
            return [];
        }

        $summary = [];
        foreach ($dbs->getElementsByTagName('DatiRiepilogo') as $riepilogo) {
            $summary[] = [
                'aliquotaIVA' => $this->getVal($riepilogo, 'AliquotaIVA'),
                'imponibileImporto' => $this->getVal($riepilogo, 'ImponibileImporto'),
                'imposta' => $this->getVal($riepilogo, 'Imposta'),
                'natura' => $this->getVal($riepilogo, 'Natura'),
            ];
        }

        return $summary;
    }

    private function parseDatiPagamento(\DOMElement $body): array
    {
        $dp = $this->getFirstChild($body, 'DatiPagamento');
        if ($dp === null) {
            return [];
        }

        $dettaglio = $this->getFirstChild($dp, 'DettaglioPagamento');

        return [
            'condizioniPagamento' => $this->getVal($dp, 'CondizioniPagamento'),
            'modalitaPagamento' => $this->getVal($dettaglio, 'ModalitaPagamento'),
            'dataScadenzaPagamento' => $this->getVal($dettaglio, 'DataScadenzaPagamento'),
            'importoPagamento' => $this->getVal($dettaglio, 'ImportoPagamento'),
            'iban' => $this->getVal($dettaglio, 'IBAN'),
        ];
    }

    private function getFirstChild(\DOMElement|null $parent, string $tagName): ?\DOMElement
    {
        if ($parent === null) {
            return null;
        }

        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $tagName) {
                return $child;
            }
        }

        return null;
    }

    private function getVal(?\DOMElement $parent, string $tagName): ?string
    {
        $child = $this->getFirstChild($parent, $tagName);
        return $child?->textContent ?: null;
    }
}
