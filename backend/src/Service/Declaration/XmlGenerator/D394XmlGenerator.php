<?php

namespace App\Service\Declaration\XmlGenerator;

use App\Entity\TaxDeclaration;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;

/**
 * Generates the D394 XML (namespace mfp:anaf:dgti:d394:declaratie:v5, applied afterwards by
 * DeclarationNamespaceResolver): the <declaratie394> header, then <informatii>, <rezumat1>,
 * <rezumat2>, <serieFacturi> and <op1> in the order the schema fixes.
 *
 * Everything is written from `data` as the populator (or the user) left it, in whole lei;
 * empty optional attributes are left out. `totalPlata_A` — the control sum ANAF checks
 * (Σ nrCui1..4 + Σ rezumat2 bazaL + bazaA + bazaAI) — is recomputed from the data written.
 */
class D394XmlGenerator implements DeclarationXmlGeneratorInterface
{
    private const HEADER = [
        'luna', 'an', 'tip_D394', 'sistemTVA', 'op_efectuate', 'cui', 'caen', 'den', 'adresa', 'telefon', 'fax', 'mail', 'totalPlata_A',
        'cifR', 'denR', 'functie_reprez', 'adresaR', 'telefonR', 'faxR', 'mailR',
        'tip_intocmit', 'den_intocmit', 'cif_intocmit', 'calitate_intocmit', 'functie_intocmit',
        'optiune', 'schimb_optiune', 'prsAfiliat',
    ];

    private const INFORMATII = [
        'nrCui1', 'nrCui2', 'nrCui3', 'nrCui4', 'nr_BF_i1', 'incasari_i1', 'incasari_i2', 'nrFacturi_terti', 'nrFacturi_benef', 'nrFacturi',
        'nrFacturiL_PF', 'nrFacturiLS_PF', 'val_LS_PF',
        'tvaDed24', 'tvaDed21', 'tvaDed11', 'tvaDed20', 'tvaDed19', 'tvaDed9', 'tvaDed5',
        'tvaDedAI24', 'tvaDedAI21', 'tvaDedAI11', 'tvaDedAI20', 'tvaDedAI19', 'tvaDedAI9', 'tvaDedAI5',
        'tvaCol24', 'tvaCol21', 'tvaCol11', 'tvaCol20', 'tvaCol19', 'tvaCol9', 'tvaCol5',
        'incasari_ag', 'costuri_ag', 'marja_ag', 'tva_ag', 'pret_vanzare', 'pret_cumparare', 'marja_antic', 'tva_antic',
        'solicit',
    ];

    /** Written only when `solicit` = 1 (refund requested through the VAT return). */
    private const REFUND_BLOCK = [
        'achizitiiPE', 'achizitiiCR', 'achizitiiCB', 'achizitiiCI', 'achizitiiA',
        'achizitiiB24', 'achizitiiB21', 'achizitiiB11', 'achizitiiB20', 'achizitiiB19', 'achizitiiB9', 'achizitiiB5',
        'achizitiiS24', 'achizitiiS21', 'achizitiiS11', 'achizitiiS20', 'achizitiiS19', 'achizitiiS9', 'achizitiiS5',
        'importB', 'acINecorp', 'livrariBI',
        'BUN24', 'BUN21', 'BUN11', 'BUN20', 'BUN19', 'BUN9', 'BUN5', 'valoareScutit', 'BunTI',
        'Prest24', 'Prest21', 'Prest11', 'Prest20', 'Prest19', 'Prest9', 'Prest5', 'PrestScutit',
        'LIntra', 'PrestIntra', 'Export', 'livINecorp', 'efectuat',
    ];

    private const REZUMAT1 = [
        'tip_partener', 'cota', 'facturiL', 'bazaL', 'tvaL', 'facturiLS', 'bazaLS', 'facturiA', 'bazaA', 'tvaA',
        'facturiAI', 'bazaAI', 'tvaAI', 'facturiAS', 'bazaAS', 'facturiV', 'bazaV', 'facturiC', 'bazaC', 'tvaC',
        'facturiN', 'document_N', 'bazaN',
    ];

    private const REZUMAT2 = [
        'cota', 'bazaFSLcod', 'TVAFSLcod', 'bazaFSL', 'TVAFSL', 'bazaFSA', 'TVAFSA', 'bazaFSAI', 'TVAFSAI', 'bazaBFAI', 'TVABFAI',
        'nrFacturiL', 'bazaL', 'tvaL', 'nrFacturiA', 'bazaA', 'tvaA', 'nrFacturiAI', 'bazaAI', 'tvaAI',
        'baza_incasari_i1', 'tva_incasari_i1', 'baza_incasari_i2', 'tva_incasari_i2', 'bazaL_PF', 'tvaL_PF',
    ];

    private const SERIE_FACTURI = ['tip', 'serieI', 'nrI', 'nrF', 'den', 'cui'];

    private const OP1 = ['tip', 'tip_partener', 'cota', 'cuiP', 'denP', 'taraP', 'locP', 'judP', 'strP', 'nrP', 'blP', 'apP', 'detP', 'tip_document', 'nrFact', 'baza', 'tva'];

    public function supportsType(string $type): bool
    {
        return $type === 'd394';
    }

    public function generate(TaxDeclaration $declaration): string
    {
        $data = $declaration->getData() ?? [];
        $company = $declaration->getCompany();

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('declaratie394');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        $header = array_merge([
            'luna' => $declaration->getMonth(),
            'an' => $declaration->getYear(),
            'tip_D394' => $declaration->getPeriodType() === 'quarterly' ? 'T' : 'L',
            'sistemTVA' => $company->isVatOnCollection() ? 1 : 0,
            'op_efectuate' => !empty($data['partners']) ? 1 : 0,
            'cui' => (string) $company->getCif(),
            'caen' => (string) ($company->getCaenCode() ?? ''),
            'den' => (string) ($company->getName() ?? ''),
            'adresa' => (string) ($company->getAddress() ?? ''),
            'telefon' => (string) ($company->getPhone() ?? ''),
            'mail' => (string) ($company->getEmail() ?? ''),
            'tip_intocmit' => 0,
            'cif_intocmit' => (string) $company->getCif(),
            'optiune' => 0,
            'prsAfiliat' => 0,
        ], $data['header'] ?? []);

        $informatii = $data['informatii'] ?? [];
        $rezumat2 = $data['rezumat2'] ?? [];
        $controlSum = 0;
        foreach (['nrCui1', 'nrCui2', 'nrCui3', 'nrCui4'] as $attr) {
            $controlSum += (int) ($informatii[$attr] ?? 0);
        }
        foreach ($rezumat2 as $r) {
            $controlSum += (int) ($r['bazaL'] ?? 0) + (int) ($r['bazaA'] ?? 0) + (int) ($r['bazaAI'] ?? 0);
        }
        $header['totalPlata_A'] = $controlSum;

        $this->writeAttributes($root, self::HEADER, $header);
        $dom->appendChild($root);

        $info = $dom->createElement('informatii');
        $this->writeAttributes($info, self::INFORMATII, $informatii);
        if ((int) ($informatii['solicit'] ?? 0) === 1) {
            $this->writeAttributes($info, self::REFUND_BLOCK, $informatii, true);
        }
        $root->appendChild($info);

        foreach ($data['rezumat1'] ?? [] as $row) {
            $el = $dom->createElement('rezumat1');
            $this->writeAttributes($el, self::REZUMAT1, $row);
            $root->appendChild($el);
        }
        foreach ($rezumat2 as $row) {
            $el = $dom->createElement('rezumat2');
            $this->writeAttributes($el, self::REZUMAT2, $row);
            $root->appendChild($el);
        }
        foreach ($data['serieFacturi'] ?? [] as $row) {
            $el = $dom->createElement('serieFacturi');
            $this->writeAttributes($el, self::SERIE_FACTURI, $row);
            $root->appendChild($el);
        }
        foreach ($data['partners'] ?? [] as $row) {
            $el = $dom->createElement('op1');
            $this->writeAttributes($el, self::OP1, $row);
            $root->appendChild($el);
        }

        return $dom->saveXML();
    }

    /**
     * Writes the listed attributes that have a value; with $zeroWhenMissing every listed
     * attribute is written (0 when absent), for blocks the validator wants complete.
     *
     * @param string[] $attributes
     * @param array<string, mixed> $values
     */
    private function writeAttributes(\DOMElement $el, array $attributes, array $values, bool $zeroWhenMissing = false): void
    {
        foreach ($attributes as $attr) {
            $value = $values[$attr] ?? null;
            if ($value === null || $value === '' || $value === false) {
                if (!$zeroWhenMissing) {
                    continue;
                }
                $value = 0;
            }
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            if (is_float($value)) {
                $value = (int) round($value);
            }
            $el->setAttribute($attr, (string) $value);
        }
    }
}
