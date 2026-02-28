<?php

namespace App\Service\EInvoice\Poland;

use App\Entity\Invoice;
use App\Enum\DocumentType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generates KSeF FA(2) XML for Polish e-invoicing.
 *
 * Schema: http://crd.gov.pl/wzor/2023/06/29/12648/
 * Root: <Faktura> with namespace http://crd.gov.pl/wzor/2023/06/29/12648/
 *
 * @see https://www.podatki.gov.pl/ksef/
 */
class KsefXmlGenerator
{
    private const NAMESPACE_URI = 'http://crd.gov.pl/wzor/2023/06/29/12648/';

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
            throw new \InvalidArgumentException('Proforma invoices cannot be submitted to KSeF.');
        }

        $isCreditNote = $invoice->getDocumentType() === DocumentType::CREDIT_NOTE;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NAMESPACE_URI, 'Faktura');
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:xsi',
            'http://www.w3.org/2001/XMLSchema-instance'
        );
        $dom->appendChild($root);

        // === Naglowek (Header) ===
        $naglowek = $dom->createElement('Naglowek');
        $root->appendChild($naglowek);

        $kodFormularza = $dom->createElement('KodFormularza', 'FA');
        $kodFormularza->setAttribute('kodSystemowy', 'FA (2)');
        $kodFormularza->setAttribute('wersjaSchemy', '1-0E');
        $naglowek->appendChild($kodFormularza);
        $this->addEl($dom, $naglowek, 'WariantFormularza', '2');
        $this->addEl($dom, $naglowek, 'DataWytworzeniaFa', (new \DateTimeImmutable())->format('Y-m-d\TH:i:s'));
        $this->addEl($dom, $naglowek, 'SystemInfo', 'Storno');

        // === Podmiot1 (Seller) ===
        $podmiot1 = $dom->createElement('Podmiot1');
        $root->appendChild($podmiot1);
        $this->addSellerData($dom, $podmiot1, $company);

        // === Podmiot2 (Buyer) ===
        $podmiot2 = $dom->createElement('Podmiot2');
        $root->appendChild($podmiot2);
        $this->addBuyerData($dom, $podmiot2, $client);

        // === Fa (Invoice data) ===
        $fa = $dom->createElement('Fa');
        $root->appendChild($fa);

        // KodWaluty must come first in Fa
        $this->addEl($dom, $fa, 'KodWaluty', $invoice->getCurrency());

        $this->addEl($dom, $fa, 'P_1', $invoice->getIssueDate()?->format('Y-m-d') ?? ''); // Issue date
        $this->addEl($dom, $fa, 'P_2', $invoice->getNumber() ?? '');  // Invoice number

        if ($invoice->getDueDate() !== null) {
            $this->addEl($dom, $fa, 'TerminPlatnosci', $invoice->getDueDate()->format('Y-m-d'));
        }

        // === Invoice lines — each FaWiersz is a direct child of Fa ===
        $lineNum = 1;
        foreach ($invoice->getLines() as $line) {
            $wiersz = $dom->createElement('FaWiersz');

            $this->addEl($dom, $wiersz, 'NrWierszaFa', (string) $lineNum);
            $this->addEl($dom, $wiersz, 'P_7', $line->getDescription() ?? ''); // Description
            $this->addEl($dom, $wiersz, 'P_8A', $this->mapUnit($line->getUnitOfMeasure())); // Unit
            $this->addEl($dom, $wiersz, 'P_8B', $this->formatDec($line->getQuantity())); // Quantity
            $this->addEl($dom, $wiersz, 'P_9A', $this->formatDec($line->getUnitPrice())); // Unit price net
            $this->addEl($dom, $wiersz, 'P_11', $this->formatDec($line->getLineTotal())); // Line total net

            // VAT rate field depends on the rate
            $vatRate = (float) $line->getVatRate();
            if ($vatRate > 0) {
                $this->addEl($dom, $wiersz, 'P_12', $this->formatVatRate($vatRate));
            } else {
                $this->addEl($dom, $wiersz, 'P_12', 'zw'); // zwolniony (exempt)
            }

            $fa->appendChild($wiersz);
            $lineNum++;
        }

        // === Totals grouped by VAT rate ===
        // P_13_x / P_14_x where x corresponds to rate bracket:
        // 1 = 23%, 2 = 8%, 3 = 5%, 4 = 0%, 5 = exempt (zw), 6 = exempt from invoice (np)
        $vatGroups = [];
        foreach ($invoice->getLines() as $line) {
            $rate = (float) $line->getVatRate();
            $bracket = $this->getVatBracket($rate, $line->getVatCategoryCode());
            if (!isset($vatGroups[$bracket])) {
                $vatGroups[$bracket] = ['net' => '0.00', 'vat' => '0.00'];
            }
            $vatGroups[$bracket]['net'] = bcadd($vatGroups[$bracket]['net'], $line->getLineTotal(), 2);
            $vatGroups[$bracket]['vat'] = bcadd($vatGroups[$bracket]['vat'], $line->getVatAmount(), 2);
        }

        foreach ($vatGroups as $bracket => $amounts) {
            $this->addEl($dom, $fa, 'P_13_' . $bracket, $this->formatDec($amounts['net']));
            $this->addEl($dom, $fa, 'P_14_' . $bracket, $this->formatDec($amounts['vat']));
        }

        // P_15 — Grand total (brutto)
        $this->addEl($dom, $fa, 'P_15', $this->formatDec($invoice->getTotal()));

        // Invoice type: VAT (regular), KOR (correction/credit note)
        $rodzajFaktury = $isCreditNote ? 'KOR' : 'VAT';
        $this->addEl($dom, $fa, 'RodzajFaktury', $rodzajFaktury);

        // Reference to original invoice for credit notes
        if ($isCreditNote && $invoice->getParentDocument() !== null) {
            $parent = $invoice->getParentDocument();
            $this->addEl($dom, $fa, 'NrFaKorygowanej', $parent->getNumber() ?? '');
            $this->addEl($dom, $fa, 'DataWystFaKorygowanej', $parent->getIssueDate()?->format('Y-m-d') ?? '');
            $this->addEl($dom, $fa, 'PrzyczynaKorekty', $invoice->getNotes() ?? 'Correction');
        }

        // Payment method
        $this->addEl($dom, $fa, 'RodzajPlatnosci', $this->mapPaymentMethod($invoice->getPaymentMethod()));

        // Bank account
        if ($company?->getBankAccount()) {
            $this->addEl($dom, $fa, 'NrRachunku', str_replace(' ', '', $company->getBankAccount()));
        }

        // === Adnotacje (mandatory boolean annotations) ===
        $adnotacje = $dom->createElement('Adnotacje');

        // P_16 — Metoda kasowa (cash accounting): 1 = yes, 2 = no
        $this->addEl($dom, $adnotacje, 'P_16', '2');
        // P_17 — Samofakturowanie (self-billing): 1 = yes, 2 = no
        $this->addEl($dom, $adnotacje, 'P_17', '2');
        // P_18 — Odwrotne obciążenie (reverse charge): 1 = yes, 2 = no
        $this->addEl($dom, $adnotacje, 'P_18', '2');
        // P_18A — Mechanizm podzielonej płatności (split payment): 1 = yes, 2 = no
        $this->addEl($dom, $adnotacje, 'P_18A', '2');

        // Zwolnienie — VAT exemption annotations
        $zwolnienie = $dom->createElement('Zwolnienie');
        // P_19N — Brak zwolnienia (no exemption applies): 1 = yes
        $this->addEl($dom, $zwolnienie, 'P_19N', '1');
        $adnotacje->appendChild($zwolnienie);

        // NoweSrodkiTransportu — new means of transport: empty with P_22N=1 (not applicable)
        $nst = $dom->createElement('NoweSrodkiTransportu');
        $this->addEl($dom, $nst, 'P_22N', '1');
        $adnotacje->appendChild($nst);

        // P_23 — Procedura marży (margin scheme): 1 = yes, 2 = no
        $this->addEl($dom, $adnotacje, 'P_23', '2');

        $fa->appendChild($adnotacje);

        // Notes
        if ($invoice->getNotes() && !$isCreditNote) {
            $this->addEl($dom, $fa, 'Uwagi', mb_substr($invoice->getNotes(), 0, 256));
        }

        return $dom->saveXML();
    }

    private function addSellerData(\DOMDocument $dom, \DOMElement $parent, $company): void
    {
        $daneIdentyfikacyjne = $dom->createElement('DaneIdentyfikacyjne');
        $parent->appendChild($daneIdentyfikacyjne);

        // NIP — Polish tax ID (10 digits)
        $this->addEl($dom, $daneIdentyfikacyjne, 'NIP', (string) ($company?->getCif() ?? ''));
        $this->addEl($dom, $daneIdentyfikacyjne, 'Nazwa', $company?->getName() ?? '');

        $adres = $dom->createElement('Adres');
        $parent->appendChild($adres);

        $this->addEl($dom, $adres, 'KodKraju', $company?->getCountry() ?? 'PL');
        $this->addEl($dom, $adres, 'AdresL1', $company?->getAddress() ?? '');
        $this->addEl($dom, $adres, 'AdresL2', $company?->getCity() ?? '');
    }

    private function addBuyerData(\DOMDocument $dom, \DOMElement $parent, $client): void
    {
        $daneIdentyfikacyjne = $dom->createElement('DaneIdentyfikacyjne');
        $parent->appendChild($daneIdentyfikacyjne);

        if ($client !== null) {
            if ($client->getCui()) {
                $this->addEl($dom, $daneIdentyfikacyjne, 'NIP', $client->getCui());
            }
            $this->addEl($dom, $daneIdentyfikacyjne, 'Nazwa', $client->getName() ?? '');

            $adres = $dom->createElement('Adres');
            $parent->appendChild($adres);

            $this->addEl($dom, $adres, 'KodKraju', $client->getCountry() ?? 'PL');
            $this->addEl($dom, $adres, 'AdresL1', $client->getAddress() ?? '');
            $this->addEl($dom, $adres, 'AdresL2', $client->getCity() ?? '');
        }
    }

    /**
     * Map VAT rate to Polish bracket number for P_13_x/P_14_x.
     *
     * 1 = 23%, 2 = 8%, 3 = 5%, 4 = 0%, 5 = exempt (zw), 6 = not applicable (np)
     */
    private function getVatBracket(float $rate, string $categoryCode): string
    {
        if ($rate >= 23) {
            return '1';
        }
        if ($rate >= 8) {
            return '2';
        }
        if ($rate >= 5) {
            return '3';
        }
        if ($rate > 0) {
            return '4'; // 0% taxable
        }

        // Zero rate — check if exempt or not subject
        return match ($categoryCode) {
            'E' => '5',   // zwolniony (exempt)
            'O' => '6',   // nie podlega (not subject)
            default => '5',
        };
    }

    private function addEl(\DOMDocument $dom, \DOMElement $parent, string $name, string $value): void
    {
        $element = $dom->createElement($name, htmlspecialchars($value, ENT_XML1, 'UTF-8'));
        $parent->appendChild($element);
    }

    private function formatDec(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function formatVatRate(float $rate): string
    {
        // Polish VAT rates: 23%, 8%, 5%, 0%
        // KSeF expects integer percentages
        return (string) (int) $rate;
    }

    private function mapUnit(string $unit): string
    {
        return match (mb_strtolower($unit)) {
            'buc', 'bucata', 'bucati', 'szt', 'sztuka', 'piece', 'pcs' => 'szt.',
            'kg', 'kilogram' => 'kg',
            'l', 'litru', 'litri', 'litr', 'liter' => 'l',
            'm', 'metru', 'metri', 'metr', 'meter' => 'm',
            'ora', 'ore', 'h', 'godz', 'godzina', 'hour' => 'godz.',
            'zi', 'zile', 'dzien', 'day' => 'dzien',
            'luna', 'luni', 'mies', 'month' => 'mies.',
            'set', 'kpl', 'komplet' => 'kpl.',
            default => 'szt.',
        };
    }

    private function mapPaymentMethod(?string $method): string
    {
        return match ($method) {
            'cash' => '1',           // gotówka
            'bank_transfer' => '2',  // przelew
            'card' => '3',           // karta
            'cheque' => '4',         // czek
            default => '2',          // przelew (bank transfer default)
        };
    }
}
