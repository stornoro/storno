<?php

declare(strict_types=1);

namespace App\Service\Declaration\Populator;

use App\Entity\BankAccount;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Payment;
use App\Enum\DocumentType;
use App\Enum\InvoiceDirection;
use App\Enum\InvoiceTypeCode;
use App\Repository\BankAccountRepository;
use App\Repository\CashMovementRepository;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Service\Declaration\D394\D394Rules;
use App\Service\Declaration\DeclarationDataPopulatorInterface;
use App\Service\Declaration\Saft\SaftAccountMapping;
use App\Service\Declaration\Saft\SaftPartnerId;
use App\Service\Declaration\Saft\SaftTaxCodes;

/**
 * Populates D406 (SAF-T, the standard audit file) from the company's documents of the period.
 *
 * The file has four parts; Storno derives all of them from invoices and payments:
 *
 * - Header: the company (registration number, address, contact, VAT registration, bank
 *   account), the reporting period (`L` monthly / `T` quarterly, following the VAT period)
 *   and the software that produced the file;
 * - MasterFiles: the accounts used (fixed mapping, see SaftAccountMapping), the customers
 *   and suppliers of the period identified the way ANAF requires (SaftPartnerId), the VAT
 *   tax codes used (SaftTaxCodes), the units of measure and the products invoiced;
 * - GeneralLedgerEntries: one journal per document kind (sales, purchases, bank, cash) with
 *   one transaction per invoice / payment and the ledger lines of the fixed mapping;
 * - SourceDocuments: every sales invoice, purchase invoice and payment with its lines.
 *
 * Amounts are in RON, two decimals; foreign-currency documents keep their currency amount
 * and the invoice's exchange rate. What Storno does not keep (opening balances, journal
 * entries without a document, stock, fixed assets) is reported in `warnings` so an
 * accountant knows what to add; the file still validates.
 *
 * `data` stays a flat, displayable structure: counts and totals per section, the account
 * summary, the mapping table and the full detail the XML generator writes.
 */
class D406Populator implements DeclarationDataPopulatorInterface
{
    public const SOFTWARE_COMPANY = 'Storno.ro';
    public const SOFTWARE_ID = 'Storno';
    public const AUDIT_FILE_VERSION = '2.4.9';

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly BankAccountRepository $bankAccountRepository,
        private readonly ?CashMovementRepository $cashMovementRepository = null,
        private readonly ?string $softwareVersion = null,
    ) {
    }

    public function supportsType(string $type): bool
    {
        return $type === 'd406';
    }

    public function populate(Company $company, int $year, int $month, string $periodType): array
    {
        [$from, $to] = D300Populator::periodBounds($year, $month, $periodType);
        $registeredForVat = $company->isVatPayer();
        $vatOnCollection = $company->isVatOnCollection();

        $warnings = [];
        $issues = [];
        $counts = ['excludedInvoices' => 0, 'excludedPayments' => 0, 'reverseChargeLines' => 0, 'unsupportedRates' => []];

        /** @var array<string, array<string, mixed>> $customers keyed by SAF-T id */
        $customers = [];
        /** @var array<string, array<string, mixed>> $suppliers */
        $suppliers = [];
        /** @var array<string, array<string, mixed>> $accounts */
        $accounts = [];
        /** @var array<string, array<string, mixed>> $taxCodes keyed by type|code */
        $taxCodes = [];
        /** @var array<string, string> $uoms code → description */
        $uoms = [];
        /** @var array<string, array<string, mixed>> $products */
        $products = [];
        $journals = [];
        foreach (SaftAccountMapping::JOURNALS as $key => $journal) {
            $journals[$key] = $journal + ['transactions' => []];
        }
        $salesInvoices = [];
        $purchaseInvoices = [];
        $payments = [];

        $invoices = $this->invoiceRepository->findForVatReturn($company, $from, $to);
        $n = 0;
        foreach ($invoices as $invoice) {
            $direction = $invoice->getDirection();
            if ($direction === null) {
                continue;
            }
            $isSale = $direction === InvoiceDirection::OUTGOING;
            $partner = $this->partner($invoice, $isSale);
            if ($partner['id'] === null) {
                $issues[$partner['issue']] = ($issues[$partner['issue']] ?? 0) + 1;
                $counts['excludedInvoices']++;
                continue;
            }
            $n++;
            $document = $this->invoiceDocument($invoice, $isSale, $partner, $registeredForVat, $vatOnCollection, $from, $to, $n, $counts, $taxCodes, $uoms, $products);
            if ($isSale) {
                $salesInvoices[] = $document;
                $this->addPartner($customers, $partner, SaftAccountMapping::CUSTOMERS);
                $customers[$partner['id']]['invoiced'] = bcadd($customers[$partner['id']]['invoiced'], $document['grossTotal'], 2);
                $customers[$partner['id']]['invoiceCount']++;
            } else {
                $purchaseInvoices[] = $document;
                $this->addPartner($suppliers, $partner, SaftAccountMapping::SUPPLIERS);
                $suppliers[$partner['id']]['invoiced'] = bcadd($suppliers[$partner['id']]['invoiced'], $document['grossTotal'], 2);
                $suppliers[$partner['id']]['invoiceCount']++;
            }
            $journals[$isSale ? 'sales' : 'purchases']['transactions'][] = $this->invoiceTransaction($document, $isSale, $registeredForVat, $accounts);
        }

        foreach ([InvoiceDirection::OUTGOING, InvoiceDirection::INCOMING] as $direction) {
            $isSale = $direction === InvoiceDirection::OUTGOING;
            foreach ($this->paymentRepository->findByCompanyAndDirectionFiltered($company, $direction, $from->format('Y-m-d'), $to->format('Y-m-d')) as $payment) {
                $invoice = $payment->getInvoice();
                if ($invoice === null) {
                    continue;
                }
                $partner = $this->partner($invoice, $isSale);
                if ($partner['id'] === null) {
                    $issues[$partner['issue']] = ($issues[$partner['issue']] ?? 0) + 1;
                    $counts['excludedPayments']++;
                    continue;
                }
                $n++;
                $document = $this->paymentDocument($payment, $invoice, $isSale, $partner, $from, $to, $n, $warnings);
                $payments[] = $document;
                if ($isSale) {
                    $this->addPartner($customers, $partner, SaftAccountMapping::CUSTOMERS);
                    $customers[$partner['id']]['settled'] = bcadd($customers[$partner['id']]['settled'], $document['amount'], 2);
                    $customers[$partner['id']]['paymentCount']++;
                } else {
                    $this->addPartner($suppliers, $partner, SaftAccountMapping::SUPPLIERS);
                    $suppliers[$partner['id']]['settled'] = bcadd($suppliers[$partner['id']]['settled'], $document['amount'], 2);
                    $suppliers[$partner['id']]['paymentCount']++;
                }
                $journals[$document['cash'] ? 'cash' : 'bank']['transactions'][] = $this->paymentTransaction($document, $isSale, $accounts);
            }
        }

        // The "no tax" pair is what every ledger / payment line without VAT carries
        $noneKey = SaftTaxCodes::TAX_TYPE_NONE . '|' . SaftTaxCodes::TAX_CODE_NONE;
        if (!isset($taxCodes[$noneKey])) {
            $taxCodes[$noneKey] = ['taxType' => SaftTaxCodes::TAX_TYPE_NONE, 'code' => SaftTaxCodes::TAX_CODE_NONE, 'description' => SaftTaxCodes::description(SaftTaxCodes::TAX_CODE_NONE), 'rate' => 0];
        }

        $header = $this->header($company, $from, $to, $periodType, $registeredForVat, $vatOnCollection, $warnings);

        $journalRows = [];
        $ledgerDebit = '0.00';
        $ledgerCredit = '0.00';
        $transactionCount = 0;
        $lineCount = 0;
        foreach ($journals as $key => $journal) {
            $debit = '0.00';
            $credit = '0.00';
            $lines = 0;
            foreach ($journal['transactions'] as $transaction) {
                foreach ($transaction['lines'] as $line) {
                    $lines++;
                    if ($line['side'] === 'D') {
                        $debit = bcadd($debit, $line['amount'], 2);
                    } else {
                        $credit = bcadd($credit, $line['amount'], 2);
                    }
                }
            }
            $journalRows[] = ['key' => $key, 'id' => $journal['id'], 'description' => $journal['description'], 'type' => $journal['type'], 'transactionCount' => count($journal['transactions']), 'lineCount' => $lines, 'debit' => $debit, 'credit' => $credit, 'transactions' => $journal['transactions']];
            $ledgerDebit = bcadd($ledgerDebit, $debit, 2);
            $ledgerCredit = bcadd($ledgerCredit, $credit, 2);
            $transactionCount += count($journal['transactions']);
            $lineCount += $lines;
        }

        $accountRows = $this->finishAccounts($accounts);
        $customerRows = $this->finishPartners($customers, true);
        $supplierRows = $this->finishPartners($suppliers, false);

        $sectionTotals = static function (array $documents): array {
            $debit = '0.00';
            $credit = '0.00';
            foreach ($documents as $document) {
                foreach ($document['lines'] as $line) {
                    if ($line['side'] === 'D') {
                        $debit = bcadd($debit, $line['amount'], 2);
                    } else {
                        $credit = bcadd($credit, $line['amount'], 2);
                    }
                }
            }

            return ['count' => count($documents), 'debit' => $debit, 'credit' => $credit];
        };
        $sumInvoices = static function (array $documents): array {
            $net = '0.00';
            $vat = '0.00';
            $gross = '0.00';
            foreach ($documents as $document) {
                $net = bcadd($net, $document['netTotal'], 2);
                $gross = bcadd($gross, $document['grossTotal'], 2);
                $vat = bcadd($vat, bcsub($document['grossTotal'], $document['netTotal'], 2), 2);
            }

            return ['net' => $net, 'vat' => $vat, 'gross' => $gross];
        };
        $received = '0.00';
        $paid = '0.00';
        foreach ($payments as $payment) {
            if ($payment['direction'] === 'in') {
                $received = bcadd($received, $payment['amount'], 2);
            } else {
                $paid = bcadd($paid, $payment['amount'], 2);
            }
        }

        // Warnings: what the validator refuses without, then what an accountant must add by hand
        if (($issues['NO_ID'] ?? 0) > 0) {
            $warnings[] = ['code' => 'PARTNER_WITHOUT_ID', 'message' => sprintf('%d documente au un partener fara CUI / CNP / cod de TVA si au fost lasate in afara fisierului; SAF-T identifica fiecare partener prin codul fiscal.', $issues['NO_ID'])];
        }
        $invalid = ($issues['INVALID_CUI'] ?? 0) + ($issues['INVALID_CNP'] ?? 0);
        if ($invalid > 0) {
            $warnings[] = ['code' => 'PARTNER_INVALID_ID', 'message' => sprintf('%d documente au un CUI / CNP de partener cu cifra de control gresita si au fost lasate in afara fisierului; corecteaza partenerul.', $invalid)];
        }
        if ($counts['reverseChargeLines'] > 0) {
            $warnings[] = ['code' => 'REVERSE_CHARGE_VAT_NOT_BOOKED', 'message' => sprintf('%d linii cu taxare inversa (achizitii intracomunitare, import, art. 331) sunt raportate cu TVA 0, ca pe factura; nota contabila 4426 = 4427 pentru taxa autolichidata nu este generata de Storno.', $counts['reverseChargeLines'])];
        }
        if ($counts['unsupportedRates'] !== []) {
            $warnings[] = ['code' => 'UNSUPPORTED_RATE', 'message' => sprintf('Linii cu cote de TVA pe care nomenclatorul SAF-T nu le are (%s %%) au fost raportate cu codul de taxa 000000; verifica-le.', implode(', ', array_keys($counts['unsupportedRates'])))];
        }
        if ($salesInvoices === [] && $purchaseInvoices === [] && $payments === []) {
            $warnings[] = ['code' => 'NO_OPERATIONS', 'message' => 'Nicio factura si nicio incasare / plata in perioada; fisierul contine doar antetul si nomenclatoarele.'];
        } else {
            $warnings[] = ['code' => 'NO_OPENING_BALANCES', 'message' => sprintf('Soldurile initiale ale conturilor, clientilor si furnizorilor la %s sunt raportate 0, iar soldurile finale sunt doar rulajele perioadei: Storno nu tine balanta de verificare. Completeaza-le din contabilitate inainte de depunere.', $from->format('d.m.Y'))];
            $warnings[] = ['code' => 'LEDGER_FROM_DOCUMENTS_ONLY', 'message' => 'Registrul jurnal contine numai notele derivate din facturi si incasari / plati (4111, 401, 707 / 7015, 604 / 628, 4426, 4427, 5121, 5311). Notele fara document (salarii, amortizari, inchiderea TVA 4423 / 4424, comisioane bancare, regularizari), stocurile si imobilizarile trebuie adaugate de contabil.'];
        }
        if ($this->cashMovementRepository !== null && $this->cashMovementRepository->findInRange($company, 'RON', $from, $to) !== []) {
            $warnings[] = ['code' => 'CASH_MOVEMENTS_NOT_INCLUDED', 'message' => 'Registrul de casa are miscari fara partener (depuneri / ridicari de numerar) in perioada; SAF-T cere un partener pe fiecare nota, asa ca ele nu sunt incluse (581 = 5311 / 5121 se adauga de contabil).'];
        }

        return [
            'form' => 'D406',
            'period' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'type' => $periodType === 'quarterly' ? 'T' : 'L',
                'startMonth' => (int) $from->format('n'),
                'startYear' => (int) $from->format('Y'),
                'endMonth' => (int) $to->format('n'),
                'endYear' => (int) $to->format('Y'),
            ],
            'header' => $header,
            'counts' => [
                'accounts' => count($accountRows),
                'customers' => count($customerRows),
                'suppliers' => count($supplierRows),
                'taxCodes' => count($taxCodes),
                'uoms' => count($uoms),
                'products' => count($products),
                'journals' => count(array_filter($journalRows, static fn (array $j) => $j['transactionCount'] > 0)),
                'transactions' => $transactionCount,
                'transactionLines' => $lineCount,
                'salesInvoices' => count($salesInvoices),
                'purchaseInvoices' => count($purchaseInvoices),
                'payments' => count($payments),
                'excludedInvoices' => $counts['excludedInvoices'],
                'excludedPayments' => $counts['excludedPayments'],
            ],
            'totals' => [
                'sales' => $sumInvoices($salesInvoices),
                'purchases' => $sumInvoices($purchaseInvoices),
                'payments' => ['received' => $received, 'paid' => $paid],
                'ledger' => ['debit' => $ledgerDebit, 'credit' => $ledgerCredit],
                'sections' => [
                    'ledger' => ['count' => $transactionCount, 'debit' => $ledgerDebit, 'credit' => $ledgerCredit],
                    'salesInvoices' => $sectionTotals($salesInvoices),
                    'purchaseInvoices' => $sectionTotals($purchaseInvoices),
                    'payments' => $sectionTotals($payments),
                ],
            ],
            'accounts' => $accountRows,
            'customers' => $customerRows,
            'suppliers' => $supplierRows,
            'taxCodes' => array_values($taxCodes),
            'uoms' => $this->uomRows($uoms),
            'products' => array_values($products),
            'journals' => $journalRows,
            'salesInvoices' => $salesInvoices,
            'purchaseInvoices' => $purchaseInvoices,
            'payments' => $payments,
            'mapping' => SaftAccountMapping::rows(),
            'warnings' => $warnings,
        ];
    }

    /** @param list<array{code: string, message: string}> $warnings */
    private function header(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to, string $periodType, bool $registeredForVat, bool $vatOnCollection, array &$warnings): array
    {
        $cif = preg_replace('/\D/', '', (string) $company->getCif()) ?? '';
        $registration = ($registeredForVat ? 'RO' : '') . $cif;

        $city = trim((string) $company->getCity());
        if ($city === '') {
            $warnings[] = ['code' => 'MISSING_ADDRESS', 'message' => 'Localitatea companiei lipseste; antetul SAF-T cere adresa (Companie → Setari → Adresa).'];
            $city = '-';
        }
        $state = strtoupper(trim((string) $company->getState()));
        $region = isset(D394Rules::COUNTY_CODES[$state]) ? 'RO-' . $state : null;

        $contact = null;
        $representative = trim((string) $company->getRepresentative());
        $phone = substr(preg_replace('/[^0-9+]/', '', (string) $company->getPhone()) ?? '', 0, 18);
        if ($representative === '') {
            $warnings[] = ['code' => 'MISSING_REPRESENTATIVE', 'message' => 'Reprezentantul companiei lipseste; antetul SAF-T cere persoana de contact (nume si prenume).'];
        }
        if ($phone === '') {
            $warnings[] = ['code' => 'MISSING_PHONE', 'message' => 'Telefonul companiei lipseste; antetul SAF-T il cere in datele persoanei de contact.'];
        }
        if ($representative !== '') {
            $parts = preg_split('/\s+/', $representative) ?: [$representative];
            $lastName = array_shift($parts);
            $firstName = $parts !== [] ? implode(' ', $parts) : $lastName;
            $contact = [
                'firstName' => mb_substr((string) $firstName, 0, 35),
                'lastName' => mb_substr((string) $lastName, 0, 70),
                'phone' => $phone,
                'email' => mb_substr(trim((string) $company->getEmail()), 0, 70),
            ];
        }

        $bankAccounts = [];
        foreach ($this->bankAccountRepository->findByCompany($company) as $account) {
            if (!$account instanceof BankAccount || $account->isCash()) {
                continue;
            }
            $iban = strtoupper(preg_replace('/\s+/', '', (string) $account->getIban()) ?? '');
            if ($iban === '') {
                continue;
            }
            $row = ['iban' => mb_substr($iban, 0, 35), 'name' => mb_substr(trim((string) $account->getBankName()), 0, 70)];
            if ($account->isDefault()) {
                array_unshift($bankAccounts, $row);
            } else {
                $bankAccounts[] = $row;
            }
        }
        if ($bankAccounts === []) {
            $warnings[] = ['code' => 'MISSING_BANK_ACCOUNT', 'message' => 'Compania nu are niciun cont bancar cu IBAN; antetul SAF-T cere cel putin unul (Setari → Conturi bancare).'];
        }

        return [
            'auditFileVersion' => self::AUDIT_FILE_VERSION,
            'dateCreated' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'softwareCompanyName' => self::SOFTWARE_COMPANY,
            'softwareId' => self::SOFTWARE_ID,
            'softwareVersion' => substr($this->softwareVersion ?? self::readVersion(), 0, 18),
            'registrationNumber' => $registration,
            'name' => mb_substr(trim((string) $company->getName()), 0, 256),
            'street' => mb_substr(trim((string) $company->getAddress()), 0, 70),
            'city' => mb_substr($city, 0, 35),
            'region' => $region,
            'country' => strtoupper(trim((string) $company->getCountry())) ?: 'RO',
            'contact' => $contact,
            'taxRegistration' => [
                'number' => $registration,
                'type' => $registeredForVat ? ($vatOnCollection ? '100040' : '100010') : '100020',
                'taxNumber' => $cif,
            ],
            'bankAccounts' => $bankAccounts,
            'currency' => 'RON',
            'headerComment' => $periodType === 'quarterly' ? 'T' : 'L',
            'taxAccountingBasis' => 'A',
            'selection' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'periodStart' => (int) $from->format('n'),
                'periodStartYear' => (int) $from->format('Y'),
                'periodEnd' => (int) $to->format('n'),
                'periodEndYear' => (int) $to->format('Y'),
            ],
        ];
    }

    public static function readVersion(): string
    {
        $file = __DIR__ . '/../../../../VERSION.txt';
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $version !== '' ? $version : '0.0.0';
    }

    /**
     * The partner of an invoice in SAF-T terms.
     *
     * @return array{id: ?string, issue: ?string, name: string, city: string, country: string}
     */
    private function partner(Invoice $invoice, bool $isSale): array
    {
        if ($isSale) {
            $client = $invoice->getClient();
            $snapshot = $invoice->getBuyerSnapshot() ?? [];
            $country = strtoupper((string) ($client?->getCountry() ?: ($snapshot['country'] ?? 'RO'))) ?: 'RO';
            $name = (string) ($invoice->getReceiverName() ?: $client?->getName() ?: ($snapshot['name'] ?? ''));
            $cui = (string) ($invoice->getReceiverCif() ?: $client?->getCui() ?: ($snapshot['cui'] ?? ''));
            $cnp = (string) ($client?->getCnp() ?: ($snapshot['cnp'] ?? ''));
            $vatCode = (string) ($client?->getVatCode() ?: ($snapshot['vatCode'] ?? ''));
            $individual = ($client?->getType() ?? ($snapshot['type'] ?? 'company')) === 'individual';
            $city = (string) ($client?->getCity() ?: ($snapshot['city'] ?? ''));
            $code = $client?->getClientCode() ?: ($client?->getId() !== null ? str_replace('-', '', (string) $client->getId()) : null);
        } else {
            $supplier = $invoice->getSupplier();
            $country = strtoupper((string) ($supplier?->getCountry() ?: 'RO')) ?: 'RO';
            $name = (string) ($invoice->getSenderName() ?: $supplier?->getName() ?: '');
            $cui = (string) ($invoice->getSenderCif() ?: $supplier?->getCif() ?: '');
            $cnp = '';
            $vatCode = (string) ($supplier?->getVatCode() ?: '');
            $individual = false;
            $city = (string) ($supplier?->getCity() ?: '');
            $code = $supplier?->getId() !== null ? str_replace('-', '', (string) $supplier->getId()) : null;
        }
        $built = SaftPartnerId::build($country, $cui, $cnp, $vatCode, $individual, $code);

        return [
            'id' => $built['id'],
            'issue' => $built['issue'],
            'name' => mb_substr(trim($name), 0, 256),
            'city' => mb_substr(trim($city) !== '' ? trim($city) : '-', 0, 35),
            'country' => $country,
        ];
    }

    /** @param array<string, array<string, mixed>> $partners @param array{id: ?string, name: string, city: string, country: string} $partner */
    private function addPartner(array &$partners, array $partner, string $accountId): void
    {
        $id = (string) $partner['id'];
        if (!isset($partners[$id])) {
            $partners[$id] = [
                'id' => $id,
                'registrationNumber' => $id,
                'name' => $partner['name'],
                'city' => $partner['city'],
                'country' => $partner['country'],
                'accountId' => $accountId,
                'invoiced' => '0.00',
                'settled' => '0.00',
                'invoiceCount' => 0,
                'paymentCount' => 0,
            ];
        }
    }

    /**
     * A sales / purchase invoice as the SourceDocuments section wants it, with the ledger
     * amounts (RON) every line carries.
     *
     * @param array{id: ?string, name: string, city: string, country: string} $partner
     * @param array<string, mixed> $counts
     * @param array<string, array<string, mixed>> $taxCodes
     * @param array<string, string> $uoms
     * @param array<string, array<string, mixed>> $products
     */
    private function invoiceDocument(Invoice $invoice, bool $isSale, array $partner, bool $registeredForVat, bool $vatOnCollection, \DateTimeImmutable $from, \DateTimeImmutable $to, int $n, array &$counts, array &$taxCodes, array &$uoms, array &$products): array
    {
        $currency = strtoupper((string) ($invoice->getCurrency() ?: 'RON'));
        $rate = $this->exchangeRate($invoice);
        $issueDate = $invoice->getIssueDate() ? \DateTimeImmutable::createFromInterface($invoice->getIssueDate()) : $from;
        $postingDate = $isSale ? $issueDate : ($invoice->getCreatedAt() ? \DateTimeImmutable::createFromInterface($invoice->getCreatedAt()) : $issueDate);
        $postingDate = $this->clamp($postingDate, $from, $to);
        $taxPoint = $invoice->getTaxPointDate() ? \DateTimeImmutable::createFromInterface($invoice->getTaxPointDate()) : $issueDate;
        $number = trim((string) $invoice->getNumber()) ?: ('FARA-NR-' . $n);
        $shortId = substr(str_replace('-', '', (string) $invoice->getId()), 0, 8);
        $transactionId = mb_substr(($isSale ? 'FV-' : 'FP-') . $number . '-' . $shortId, 0, 70);
        $partnerCountry = $partner['country'];
        $foreignPartner = $partnerCountry !== 'RO';
        $partnerInEu = $foreignPartner && D394Rules::isEu($partnerCountry);
        $typeCode = (string) $invoice->getInvoiceTypeCode();

        $lines = [];
        $taxTotals = [];
        $net = '0.00';
        $vat = '0.00';
        $position = 0;
        foreach ($invoice->getLines() as $line) {
            $position++;
            $lineNet = $this->money($line->getLineTotal());
            $lineVat = $this->money($line->getVatAmount());
            $lineRate = (int) round((float) ($line->getVatRate() ?? '0'));
            $category = strtoupper((string) $line->getVatCategoryCode());
            $product = $line->getProduct();
            $isService = $product?->isService() ?? false;

            [$taxType, $taxCode, $taxRate, $reverse] = $isSale
                ? $this->saleTax($lineRate, $category, $typeCode, $registeredForVat, $vatOnCollection, $isService, $partnerInEu, $foreignPartner, $issueDate)
                : $this->purchaseTax($lineRate, $category, $typeCode, $registeredForVat, $invoice->isTvaLaIncasare(), $isService, $foreignPartner, $partnerInEu, $issueDate);
            if ($reverse) {
                $counts['reverseChargeLines']++;
            }
            if ($taxCode === null) {
                $counts['unsupportedRates'][(string) $lineRate] = true;
                $taxType = SaftTaxCodes::TAX_TYPE_NONE;
                $taxCode = SaftTaxCodes::TAX_CODE_NONE;
                $taxRate = 0;
            }
            $key = $taxType . '|' . $taxCode;
            if (!isset($taxCodes[$key])) {
                $taxCodes[$key] = ['taxType' => $taxType, 'code' => $taxCode, 'description' => SaftTaxCodes::description($taxCode), 'rate' => $taxRate];
            }

            // a company not registered for VAT books the purchase gross: the VAT is part of the expense
            $bookedNet = (!$isSale && !$registeredForVat) ? bcadd($lineNet, $lineVat, 2) : $lineNet;
            $bookedVat = (!$isSale && !$registeredForVat) ? '0.00' : $lineVat;
            $netRon = $this->toRon($bookedNet, $rate);
            $vatRon = $this->toRon($bookedVat, $rate);

            $uom = SaftTaxCodes::unitCode($line->getUnitOfMeasure());
            $uoms[$uom] = SaftTaxCodes::unitDescription($uom);
            $productCode = null;
            if ($product !== null && $product->getId() !== null) {
                $productCode = SaftPartnerId::sanitize($product->getCode()) ?: ('P' . str_replace('-', '', (string) $product->getId()));
                $productCode = mb_substr($productCode, 0, 70);
                if (!isset($products[$productCode])) {
                    $productUom = SaftTaxCodes::unitCode($product->getUnitOfMeasure() ?: $line->getUnitOfMeasure());
                    $uoms[$productUom] = SaftTaxCodes::unitDescription($productUom);
                    $nc = preg_replace('/\D/', '', (string) $product->getNcCode()) ?? '';
                    $products[$productCode] = [
                        'code' => $productCode,
                        'goodsServicesId' => $isService ? '02' : '01',
                        'description' => mb_substr(trim((string) $product->getName()) ?: $productCode, 0, 256),
                        'commodityCode' => strlen($nc) === 8 ? $nc : '0',
                        'uom' => $productUom,
                    ];
                }
            }

            $quantity = $this->quantity($line->getQuantity());
            $unitPrice = $this->toRon($this->money($line->getUnitPrice()), $rate);
            $side = $isSale ? 'C' : 'D';
            if (bccomp($bookedNet, '0', 2) < 0) {
                $side = $side === 'C' ? 'D' : 'C';
            }
            $lines[] = [
                'lineNumber' => (string) $position,
                'accountId' => $isSale ? SaftAccountMapping::revenueAccount($isService) : SaftAccountMapping::expenseAccount($isService),
                'productCode' => $productCode,
                'description' => mb_substr(trim((string) $line->getDescription()) ?: 'Linie ' . $position, 0, 256),
                'quantity' => $quantity,
                'uom' => $uom,
                'unitPrice' => $this->abs($unitPrice),
                'taxPointDate' => $taxPoint->format('Y-m-d'),
                'side' => $side,
                'amount' => $this->abs($netRon),
                'currency' => $currency,
                'currencyAmount' => $this->abs($bookedNet),
                'exchangeRate' => $currency === 'RON' ? null : $rate,
                'taxType' => $taxType,
                'taxCode' => $taxCode,
                'taxPercentage' => $taxRate,
                'taxBase' => $netRon,
                'taxAmount' => $vatRon,
                'taxAmountCurrency' => $bookedVat,
                'isService' => $isService,
                'signedNet' => $netRon,
                'signedVat' => $vatRon,
            ];
            $net = bcadd($net, $netRon, 2);
            $vat = bcadd($vat, $vatRon, 2);
            if (!isset($taxTotals[$key])) {
                $taxTotals[$key] = ['taxType' => $taxType, 'taxCode' => $taxCode, 'taxPercentage' => $taxRate, 'taxBase' => '0.00', 'taxAmount' => '0.00', 'taxAmountCurrency' => '0.00'];
            }
            $taxTotals[$key]['taxBase'] = bcadd($taxTotals[$key]['taxBase'], $netRon, 2);
            $taxTotals[$key]['taxAmount'] = bcadd($taxTotals[$key]['taxAmount'], $vatRon, 2);
            $taxTotals[$key]['taxAmountCurrency'] = bcadd($taxTotals[$key]['taxAmountCurrency'], $bookedVat, 2);
        }

        $isCreditNote = $invoice->getDocumentType() === DocumentType::CREDIT_NOTE || bccomp(bcadd($net, $vat, 2), '0', 2) < 0;

        return [
            'invoiceNo' => mb_substr($number, 0, 70),
            'partnerId' => $partner['id'],
            'partnerName' => $partner['name'],
            'billing' => ['city' => $partner['city'], 'country' => $partnerCountry],
            'accountId' => $isSale ? SaftAccountMapping::CUSTOMERS : SaftAccountMapping::SUPPLIERS,
            'period' => (int) $postingDate->format('n'),
            'periodYear' => (int) $postingDate->format('Y'),
            'date' => $issueDate->format('Y-m-d'),
            'postingDate' => $postingDate->format('Y-m-d'),
            'type' => $isCreditNote ? '381' : '380',
            'selfBilling' => $typeCode === InvoiceTypeCode::SELF_BILLING->value ? '389' : '0',
            'transactionId' => $transactionId,
            'currency' => $currency,
            'exchangeRate' => $currency === 'RON' ? null : $rate,
            'lines' => $lines,
            'taxTotals' => array_values($taxTotals),
            'netTotal' => $net,
            'grossTotal' => bcadd($net, $vat, 2),
            'vatTotal' => $vat,
        ];
    }

    /**
     * Tax code of a sales line: [taxType, taxCode|null, rate, reverseCharge].
     *
     * @return array{0: string, 1: ?string, 2: int, 3: bool}
     */
    private function saleTax(int $rate, string $category, string $typeCode, bool $registeredForVat, bool $vatOnCollection, bool $isService, bool $partnerInEu, bool $foreignPartner, \DateTimeImmutable $date): array
    {
        $vatType = SaftTaxCodes::TAX_TYPE_VAT;
        if (!$registeredForVat) {
            return [$vatType, SaftTaxCodes::sale(SaftTaxCodes::SALE_EXEMPT_WITHOUT_DEDUCTION, 0), 0, false];
        }
        if ($typeCode === InvoiceTypeCode::SPECIAL_REGIME_ART_314_315->value) {
            return [$vatType, SaftTaxCodes::sale(SaftTaxCodes::SALE_SPECIAL_REGIME_OSS, 0), 0, false];
        }
        if ($category === 'AE' || $typeCode === InvoiceTypeCode::REVERSE_CHARGE->value) {
            return [$vatType, SaftTaxCodes::sale(SaftTaxCodes::SALE_REVERSE_CHARGE, 0), 0, false];
        }
        if ($category === 'K') {
            return [$vatType, SaftTaxCodes::sale($isService ? SaftTaxCodes::SALE_INTRA_COMMUNITY_SERVICES : SaftTaxCodes::SALE_INTRA_COMMUNITY_GOODS, 0), 0, false];
        }
        if ($typeCode === InvoiceTypeCode::SERVICES_ART_278->value) {
            return [$vatType, SaftTaxCodes::sale($partnerInEu ? SaftTaxCodes::SALE_INTRA_COMMUNITY_SERVICES : SaftTaxCodes::SALE_SERVICES_OUTSIDE_EU, 0), 0, false];
        }
        if ($category === 'G') {
            return [$vatType, SaftTaxCodes::sale(SaftTaxCodes::SALE_EXPORT, 0), 0, false];
        }
        if ($category === 'O' || in_array($typeCode, [InvoiceTypeCode::NON_TAXABLE->value, InvoiceTypeCode::NON_TRANSFER->value], true)) {
            return [$vatType, SaftTaxCodes::sale(SaftTaxCodes::SALE_OUTSIDE_SCOPE, 0), 0, false];
        }
        if ($rate > 0) {
            $code = SaftTaxCodes::sale($vatOnCollection ? SaftTaxCodes::SALE_VAT_ON_COLLECTION : SaftTaxCodes::SALE_STANDARD, $rate);

            return [$vatType, $code, $rate, false];
        }
        $withDeduction = $category === 'Z' || in_array($typeCode, [InvoiceTypeCode::EXEMPT_WITH_DEDUCTION->value, InvoiceTypeCode::EXEMPT_ART_294_AB->value, InvoiceTypeCode::EXEMPT_ART_294_CD->value], true);
        if ($withDeduction && $foreignPartner && !$isService) {
            return [$vatType, SaftTaxCodes::sale($partnerInEu ? SaftTaxCodes::SALE_INTRA_COMMUNITY_GOODS : SaftTaxCodes::SALE_EXPORT, 0), 0, false];
        }

        return [$vatType, SaftTaxCodes::sale($withDeduction ? SaftTaxCodes::SALE_EXEMPT_WITH_DEDUCTION : SaftTaxCodes::SALE_EXEMPT_WITHOUT_DEDUCTION, 0), 0, false];
    }

    /**
     * Tax code of a purchase line: [taxType, taxCode|null, rate, reverseCharge].
     *
     * @return array{0: string, 1: ?string, 2: int, 3: bool}
     */
    private function purchaseTax(int $rate, string $category, string $typeCode, bool $registeredForVat, bool $supplierVatOnCollection, bool $isService, bool $foreignPartner, bool $partnerInEu, \DateTimeImmutable $date): array
    {
        $vatType = SaftTaxCodes::TAX_TYPE_VAT;
        $standardRate = $date >= new \DateTimeImmutable('2025-08-01') ? 21 : 19;
        if ($foreignPartner) {
            // the supplier charges no VAT: the buyer self-assesses it at the Romanian rate of the good / service
            $selfRate = $rate > 0 ? $rate : $standardRate;
            $kind = $isService ? SaftTaxCodes::PURCHASE_INTRA_COMMUNITY_SERVICES : ($partnerInEu ? SaftTaxCodes::PURCHASE_INTRA_COMMUNITY_GOODS : SaftTaxCodes::PURCHASE_IMPORT);

            return [$vatType, SaftTaxCodes::purchase($kind, $selfRate, $registeredForVat), $selfRate, $registeredForVat];
        }
        if ($category === 'AE' || $typeCode === InvoiceTypeCode::REVERSE_CHARGE->value) {
            $selfRate = $rate > 0 ? $rate : $standardRate;

            return [$vatType, SaftTaxCodes::purchase(SaftTaxCodes::PURCHASE_REVERSE_CHARGE, $selfRate, $registeredForVat), $selfRate, $registeredForVat];
        }
        if ($rate > 0) {
            $kind = ($registeredForVat && $supplierVatOnCollection) ? SaftTaxCodes::PURCHASE_VAT_ON_COLLECTION : SaftTaxCodes::PURCHASE_STANDARD;

            return [$vatType, SaftTaxCodes::purchase($kind, $rate, $registeredForVat), $rate, false];
        }

        return [$vatType, SaftTaxCodes::purchase(SaftTaxCodes::PURCHASE_EXEMPT, 0, $registeredForVat), 0, false];
    }

    /**
     * The ledger transaction of an invoice (fixed mapping), accumulating the account movements.
     *
     * @param array<string, mixed> $document
     * @param array<string, array<string, mixed>> $accounts
     */
    private function invoiceTransaction(array $document, bool $isSale, bool $registeredForVat, array &$accounts): array
    {
        $partnerAccount = $document['accountId'];
        $customerId = $isSale ? $document['partnerId'] : '0';
        $supplierId = $isSale ? '0' : $document['partnerId'];
        $sourceId = mb_substr($document['invoiceNo'], 0, 35);
        $none = [SaftTaxCodes::TAX_TYPE_NONE, SaftTaxCodes::TAX_CODE_NONE];
        $lines = [];
        $record = 0;

        // the partner line: gross, customers on the debit side, suppliers on the credit side
        $lines[] = $this->ledgerLine(++$record, $partnerAccount, $sourceId, $customerId, $supplierId, ($isSale ? 'Factura ' : 'Factura primita ') . $document['invoiceNo'] . ' ' . $document['partnerName'], $isSale ? 'D' : 'C', $document['grossTotal'], $document['currency'], $document['exchangeRate'], $none[0], $none[1], null, null, '0.00', $accounts);

        // revenue / expense per line
        foreach ($document['lines'] as $line) {
            $lines[] = $this->ledgerLine(++$record, $line['accountId'], $sourceId, $customerId, $supplierId, $line['description'], $isSale ? 'C' : 'D', $line['signedNet'], $document['currency'], $document['exchangeRate'], $line['taxType'], $line['taxCode'], $line['taxPercentage'], $line['taxBase'], $line['taxAmount'], $accounts);
        }

        // VAT per tax code (a company not registered for VAT keeps it in the expense)
        if ($isSale || $registeredForVat) {
            foreach ($document['taxTotals'] as $tax) {
                if (bccomp($tax['taxAmount'], '0', 2) === 0) {
                    continue;
                }
                $lines[] = $this->ledgerLine(++$record, $isSale ? SaftAccountMapping::VAT_COLLECTED : SaftAccountMapping::VAT_DEDUCTIBLE, $sourceId, $customerId, $supplierId, 'TVA ' . $tax['taxPercentage'] . '% ' . $document['invoiceNo'], $isSale ? 'C' : 'D', $tax['taxAmount'], $document['currency'], $document['exchangeRate'], $tax['taxType'], $tax['taxCode'], $tax['taxPercentage'], $tax['taxBase'], $tax['taxAmount'], $accounts);
            }
        }

        return [
            'id' => $document['transactionId'],
            'period' => $document['period'],
            'periodYear' => $document['periodYear'],
            'date' => $document['date'],
            'postingDate' => $document['postingDate'],
            'description' => mb_substr(($isSale ? 'Factura emisa ' : 'Factura primita ') . $document['invoiceNo'] . ' - ' . $document['partnerName'], 0, 256),
            'customerId' => $customerId,
            'supplierId' => $supplierId,
            'lines' => $lines,
        ];
    }

    /**
     * A payment as the Payments section wants it.
     *
     * @param array{id: ?string, name: string, city: string, country: string} $partner
     * @param list<array{code: string, message: string}> $warnings
     */
    private function paymentDocument(Payment $payment, Invoice $invoice, bool $isSale, array $partner, \DateTimeImmutable $from, \DateTimeImmutable $to, int $n, array &$warnings): array
    {
        $method = strtolower((string) $payment->getPaymentMethod());
        $cash = $method === 'cash';
        $currency = strtoupper((string) ($payment->getCurrency() ?: $invoice->getCurrency() ?: 'RON'));
        $rate = $currency === 'RON' ? '1.0000' : $this->exchangeRate($invoice);
        $amountCurrency = $this->money($payment->getAmount());
        $amountRon = $this->toRon($amountCurrency, $rate);
        $date = $payment->getPaymentDate() ? \DateTimeImmutable::createFromInterface($payment->getPaymentDate()) : $from;
        $date = $this->clamp($date, $from, $to);
        $invoiceNo = trim((string) $invoice->getNumber());
        $shortId = substr(str_replace('-', '', (string) $payment->getId()), 0, 8);
        $reference = SaftPartnerId::sanitize($payment->getReference());
        $refNo = mb_substr(($isSale ? 'INC-' : 'PL-') . ($reference !== '' ? $reference . '-' : '') . $shortId, 0, 35);
        $side = $isSale ? 'C' : 'D'; // the partner account: a receipt credits the customer, a payment debits the supplier
        if (bccomp($amountRon, '0', 2) < 0) {
            $side = $side === 'C' ? 'D' : 'C';
        }

        return [
            'refNo' => $refNo,
            'direction' => $isSale ? 'in' : 'out',
            'cash' => $cash,
            'method' => $cash ? '01' : '03',
            'mechanism' => $cash ? '10' : ($method === 'card' ? '48' : '42'),
            'period' => (int) $date->format('n'),
            'periodYear' => (int) $date->format('Y'),
            'date' => $date->format('Y-m-d'),
            'transactionId' => mb_substr(($isSale ? 'INC-' : 'PL-') . $shortId, 0, 70),
            'description' => mb_substr(($isSale ? 'Incasare factura ' : 'Plata factura ') . $invoiceNo . ' - ' . $partner['name'], 0, 256),
            'partnerId' => $partner['id'],
            'partnerName' => $partner['name'],
            'invoiceNo' => mb_substr($invoiceNo, 0, 35),
            'currency' => $currency,
            'exchangeRate' => $currency === 'RON' ? null : $rate,
            'amount' => $amountRon,
            'lines' => [[
                'lineNumber' => '1',
                'sourceDocumentId' => mb_substr($invoiceNo, 0, 35),
                'accountId' => $isSale ? SaftAccountMapping::CUSTOMERS : SaftAccountMapping::SUPPLIERS,
                'customerId' => $isSale ? $partner['id'] : '0',
                'supplierId' => $isSale ? '0' : $partner['id'],
                'description' => mb_substr(($isSale ? 'Incasare ' : 'Plata ') . $invoiceNo, 0, 256),
                'side' => $side,
                'amount' => $this->abs($amountRon),
                'currency' => $currency,
                'currencyAmount' => $this->abs($amountCurrency),
                'exchangeRate' => $currency === 'RON' ? null : $rate,
                'taxType' => SaftTaxCodes::TAX_TYPE_NONE,
                'taxCode' => SaftTaxCodes::TAX_CODE_NONE,
            ]],
            'grossTotal' => $amountRon,
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, array<string, mixed>> $accounts
     */
    private function paymentTransaction(array $document, bool $isSale, array &$accounts): array
    {
        $customerId = $isSale ? $document['partnerId'] : '0';
        $supplierId = $isSale ? '0' : $document['partnerId'];
        $treasury = SaftAccountMapping::treasuryAccount($document['cash']);
        $partnerAccount = $isSale ? SaftAccountMapping::CUSTOMERS : SaftAccountMapping::SUPPLIERS;
        $none = [SaftTaxCodes::TAX_TYPE_NONE, SaftTaxCodes::TAX_CODE_NONE];
        $lines = [
            $this->ledgerLine(1, $isSale ? $treasury : $partnerAccount, $document['invoiceNo'], $customerId, $supplierId, $document['description'], 'D', $document['amount'], $document['currency'], $document['exchangeRate'], $none[0], $none[1], null, null, '0.00', $accounts),
            $this->ledgerLine(2, $isSale ? $partnerAccount : $treasury, $document['invoiceNo'], $customerId, $supplierId, $document['description'], 'C', $document['amount'], $document['currency'], $document['exchangeRate'], $none[0], $none[1], null, null, '0.00', $accounts),
        ];

        return [
            'id' => $document['transactionId'],
            'period' => $document['period'],
            'periodYear' => $document['periodYear'],
            'date' => $document['date'],
            'postingDate' => $document['date'],
            'description' => $document['description'],
            'customerId' => $customerId,
            'supplierId' => $supplierId,
            'lines' => $lines,
        ];
    }

    /**
     * One ledger line; a negative amount goes on the opposite side so DebitAmount / CreditAmount stay ≥ 0.
     *
     * @param array<string, array<string, mixed>> $accounts
     */
    private function ledgerLine(int $record, string $accountId, string $sourceId, string $customerId, string $supplierId, string $description, string $side, string $amountRon, string $currency, ?string $exchangeRate, string $taxType, string $taxCode, ?int $taxPercentage, ?string $taxBase, string $taxAmount, array &$accounts): array
    {
        if (bccomp($amountRon, '0', 2) < 0) {
            $side = $side === 'D' ? 'C' : 'D';
        }
        $amount = $this->abs($amountRon);
        $currencyAmount = $currency === 'RON' || $exchangeRate === null || bccomp($exchangeRate, '0', 4) <= 0 ? $amount : bcdiv($amount, $exchangeRate, 2);

        if (!isset($accounts[$accountId])) {
            $accounts[$accountId] = ['id' => $accountId, 'debit' => '0.00', 'credit' => '0.00'];
        }
        $accounts[$accountId][$side === 'D' ? 'debit' : 'credit'] = bcadd($accounts[$accountId][$side === 'D' ? 'debit' : 'credit'], $amount, 2);

        return [
            'recordId' => (string) $record,
            'accountId' => $accountId,
            'sourceDocumentId' => mb_substr($sourceId, 0, 35),
            'customerId' => $customerId,
            'supplierId' => $supplierId,
            'description' => mb_substr($description, 0, 256),
            'side' => $side,
            'amount' => $amount,
            'currency' => $currency,
            'currencyAmount' => $currencyAmount,
            'exchangeRate' => $currency === 'RON' ? null : $exchangeRate,
            'taxType' => $taxType,
            'taxCode' => $taxCode,
            'taxPercentage' => $taxPercentage,
            'taxBase' => $taxBase,
            'taxAmount' => $this->abs($taxAmount),
        ];
    }

    /** @param array<string, array<string, mixed>> $accounts */
    private function finishAccounts(array $accounts): array
    {
        $rows = [];
        foreach ($accounts as $movement) {
            $id = (string) $movement['id']; // numeric account ids become integer array keys
            $meta = SaftAccountMapping::ACCOUNTS[$id] ?? ['description' => 'Cont ' . $id, 'type' => 'Bifunctional'];
            $balance = bcsub($movement['debit'], $movement['credit'], 2);
            $debitSide = $meta['type'] !== 'Pasiv';
            $rows[] = [
                'id' => $id,
                'description' => $meta['description'],
                'type' => $meta['type'],
                'openingDebit' => $debitSide ? '0.00' : null,
                'openingCredit' => $debitSide ? null : '0.00',
                'debit' => $movement['debit'],
                'credit' => $movement['credit'],
                'closingDebit' => bccomp($balance, '0', 2) >= 0 ? $balance : null,
                'closingCredit' => bccomp($balance, '0', 2) < 0 ? $this->abs($balance) : null,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $rows;
    }

    /** @param array<string, array<string, mixed>> $partners */
    private function finishPartners(array $partners, bool $customers): array
    {
        $rows = [];
        foreach ($partners as $partner) {
            $balance = bcsub($partner['invoiced'], $partner['settled'], 2); // what the partner still owes / is owed
            if ($customers) {
                $partner['openingDebit'] = '0.00';
                $partner['openingCredit'] = null;
                $partner['closingDebit'] = bccomp($balance, '0', 2) >= 0 ? $balance : null;
                $partner['closingCredit'] = bccomp($balance, '0', 2) < 0 ? $this->abs($balance) : null;
            } else {
                $partner['openingDebit'] = null;
                $partner['openingCredit'] = '0.00';
                $partner['closingDebit'] = bccomp($balance, '0', 2) < 0 ? $this->abs($balance) : null;
                $partner['closingCredit'] = bccomp($balance, '0', 2) >= 0 ? $balance : null;
            }
            $rows[] = $partner;
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /** @param array<string, string> $uoms */
    private function uomRows(array $uoms): array
    {
        ksort($uoms);
        $rows = [];
        foreach ($uoms as $code => $description) {
            $rows[] = ['code' => (string) $code, 'description' => $description];
        }

        return $rows;
    }

    private function exchangeRate(Invoice $invoice): string
    {
        if (strtoupper((string) $invoice->getCurrency()) === 'RON' || !$invoice->getCurrency()) {
            return '1.0000';
        }
        $rate = (string) ($invoice->getExchangeRate() ?? '');
        if ($rate === '' || bccomp($rate, '0', 6) <= 0) {
            return '1.0000';
        }

        return bcadd($rate, '0', 4);
    }

    /** Converts to RON at the document's rate, rounded half away from zero (bcmul truncates). */
    private function toRon(string $amount, string $rate): string
    {
        if (bccomp($rate, '1.0000', 4) === 0) {
            return $amount;
        }
        $exact = bcmul($amount, $rate, 6);
        $half = bccomp($exact, '0', 6) < 0 ? '-0.005' : '0.005';

        return bcadd($exact, $half, 2);
    }

    private function money(string|float|int|null $amount): string
    {
        $amount = trim((string) ($amount ?? '0'));
        if ($amount === '' || !is_numeric($amount)) {
            return '0.00';
        }

        return bcadd(number_format((float) $amount, 6, '.', ''), '0', 2);
    }

    private function quantity(string|float|int|null $quantity): string
    {
        $quantity = trim((string) ($quantity ?? '1'));
        if ($quantity === '' || !is_numeric($quantity)) {
            return '1';
        }
        $formatted = rtrim(rtrim(number_format((float) $quantity, 6, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    private function abs(string $amount): string
    {
        return bccomp($amount, '0', 2) < 0 ? bcmul($amount, '-1', 2) : $amount;
    }

    private function clamp(\DateTimeImmutable $date, \DateTimeImmutable $from, \DateTimeImmutable $to): \DateTimeImmutable
    {
        if ($date < $from) {
            return $from;
        }
        if ($date > $to) {
            return $to->setTime(0, 0);
        }

        return $date;
    }
}
