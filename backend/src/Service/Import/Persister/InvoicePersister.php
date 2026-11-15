<?php

namespace App\Service\Import\Persister;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\ImportJob;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Payment;
use App\Entity\Supplier;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\InvoiceDirection;
use App\Repository\ClientRepository;
use App\Repository\InvoiceRepository;
use App\Repository\VatRateRepository;
use App\Service\Import\ImportResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class InvoicePersister implements EntityPersisterInterface
{
    private const BATCH_SIZE = 100;
    private int $batchCount = 0;

    /** @var array<string, Invoice> In-memory dedup cache for multi-line formats */
    private array $pendingCache = [];

    /** @var array<string, string|null> client lookup key → client UUID (pre-loaded) */
    private array $clientCache = [];

    /** @var array<string, true> Known existing invoice numbers for this company (pre-loaded) */
    private array $existingInvoiceNumbers = [];

    /** @var array<string, string> normalized supplier CIF → supplier UUID (pre-loaded) */
    private array $supplierCache = [];

    /** @var array<string, array{company: Company, job: mixed, markAsPaid: bool, paid: ?float, fullyPaid: bool, unpaid?: float}> */
    private array $pendingSettlement = [];

    /** The batch is full: flush before the next new invoice, never between the rows of one. */
    private bool $flushPending = false;

    private bool $initialized = false;
    private ?string $companyId = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ClientRepository $clientRepository,
        private readonly ?VatRateRepository $vatRateRepository = null,
    ) {}

    public function supports(string $importType): bool
    {
        return in_array($importType, ['invoices', 'invoices_issued', 'invoices_received'], true);
    }

    /**
     * Pre-load all client IDs and existing invoice numbers for the company.
     * This replaces thousands of individual DB queries with just 2 queries.
     */
    private function initialize(Company $company): void
    {
        if ($this->initialized && $this->companyId === $company->getId()->toRfc4122()) {
            return;
        }

        $companyId = $company->getId()->toRfc4122();
        $conn = $this->entityManager->getConnection();

        // Name is not unique enough to be an identity key — keep to CUI/email.
        $rows = $conn->fetchAllAssociative(
            'SELECT id, LOWER(email) as email, cui FROM client WHERE company_id = :companyId AND deleted_at IS NULL',
            ['companyId' => $companyId],
        );

        foreach ($rows as $row) {
            $clientId = $row['id'];
            if (!empty($row['email'])) {
                $this->clientCache[$companyId . ':email:' . $row['email']] = $clientId;
            }
            if (!empty($row['cui'])) {
                $this->clientCache[$companyId . ':cui:' . self::normalizeCif($row['cui'])] = $clientId;
            }
        }

        $suppliers = $conn->fetchAllAssociative(
            'SELECT id, cif FROM supplier WHERE company_id = :companyId AND deleted_at IS NULL AND cif IS NOT NULL',
            ['companyId' => $companyId],
        );
        foreach ($suppliers as $row) {
            $this->supplierCache[self::normalizeCif((string) $row['cif'])] = $row['id'];
        }

        // Pre-load all existing invoice numbers for dedup. A received invoice is identified by
        // its number and its supplier: two suppliers may well use the same number.
        $numbers = $conn->fetchAllAssociative(
            'SELECT LOWER(number) AS number, direction, sender_cif FROM invoice WHERE company_id = :companyId AND deleted_at IS NULL',
            ['companyId' => $companyId],
        );
        foreach ($numbers as $row) {
            $this->existingInvoiceNumbers[self::numberKey((string) $row['number'], $row['direction'] ?? null, $row['sender_cif'] ?? null)] = true;
        }

        $this->companyId = $companyId;
        $this->initialized = true;
    }

    public function persist(array $mappedData, Company $company, ImportResult $result): void
    {
        $this->initialize($company);

        $number = $mappedData['number'] ?? null;
        if (empty($number)) {
            return;
        }

        // Shop exports carry every order; only paid / completed ones become invoices unless asked otherwise
        if (array_key_exists('_statusPaid', $mappedData) && $mappedData['_statusPaid'] === false
            && empty($mappedData['_importOptions']['includeAll'])) {
            $result->incrementSkipped();

            return;
        }

        $senderCif   = !empty($mappedData['senderCif'])   ? trim($mappedData['senderCif'])   : null;
        $receiverCif = !empty($mappedData['receiverCif']) ? trim($mappedData['receiverCif']) : null;
        $direction   = $mappedData['direction'] ?? null;

        // Build composite dedup key
        $dedupKey = implode(':', [
            $company->getId()->toRfc4122(),
            mb_strtolower($number),
            $senderCif   ?? '',
            $receiverCif ?? '',
            $direction   ?? '',
        ]);

        // In-memory check: within-batch duplicate → append lines (multi-line formats)
        if (isset($this->pendingCache[$dedupKey])) {
            $existingInvoice = $this->pendingCache[$dedupKey];
            $lines = $mappedData['lines'] ?? [];
            $currentPosition = $existingInvoice->getLines()->count();
            foreach ($lines as $i => $lineData) {
                $line = $this->buildInvoiceLine($lineData, $currentPosition + $i);
                $existingInvoice->addLine($line);
            }

            $this->noteCollection($dedupKey, $mappedData);

            // Accumulate invoice-level totals
            if (isset($mappedData['subtotal']) && $mappedData['subtotal'] !== '') {
                $existingSubtotal = (float) ($existingInvoice->getSubtotal() ?? '0');
                $existingInvoice->setSubtotal(number_format($existingSubtotal + (float) $mappedData['subtotal'], 2, '.', ''));
            }
            if (isset($mappedData['vatTotal']) && $mappedData['vatTotal'] !== '') {
                $existingVat = (float) ($existingInvoice->getVatTotal() ?? '0');
                $existingInvoice->setVatTotal(number_format($existingVat + (float) $mappedData['vatTotal'], 2, '.', ''));
            }
            if (isset($mappedData['total']) && $mappedData['total'] !== '') {
                $existingTotal = (float) ($existingInvoice->getTotal() ?? '0');
                $existingInvoice->setTotal(number_format($existingTotal + (float) $mappedData['total'], 2, '.', ''));
            }

            return;
        }

        // Fast dedup check against pre-loaded invoice numbers (no DB query)
        $lowerNumber = self::numberKey(mb_strtolower($number), $this->resolveDirection($mappedData), $senderCif);
        if (isset($this->existingInvoiceNumbers[$lowerNumber])) {
            $this->pendingCache[$dedupKey] = new Invoice(); // placeholder to skip future multi-line rows
            $result->incrementSkipped();
            return;
        }

        // A file that names both parties says whose invoices it holds: one issued by (or
        // addressed to) another company does not belong in this company's books.
        $ownCif = $this->resolveDirection($mappedData) === 'incoming' ? $receiverCif : $senderCif;
        $companyCif = (string) ($company->getCif() ?? '');
        if ($ownCif !== null && $companyCif !== '' && self::normalizeCif($ownCif) !== self::normalizeCif($companyCif)) {
            $this->pendingCache[$dedupKey] = new Invoice(); // its other rows are skipped quietly
            throw new \RuntimeException(sprintf(
                'Factura %s apartine altei firme (CUI %s), nu firmei selectate (CUI %s).',
                $number,
                $ownCif,
                $companyCif,
            ));
        }

        // A full batch is written here, when a new invoice starts: flushing right after the
        // invoice was created would drop the remaining lines of a multi-row invoice.
        if ($this->flushPending) {
            $this->flush();
            // flush() clears the entity manager: the company and the job are detached now
            $company = $this->entityManager->getReference(Company::class, $company->getId());
            if (isset($mappedData['_importJob'])) {
                $mappedData['_importJob'] = $this->entityManager->getReference(ImportJob::class, $mappedData['_importJob']->getId());
            }
        }

        // Exports that only know the gross amount: split it with the company's default VAT rate
        if (!empty($mappedData['_vatIncludedUnknownRate'])) {
            $mappedData = $this->splitGrossWithCompanyRate($mappedData, $company);
        }

        // Create the invoice
        $invoice = $this->buildInvoice($mappedData, $company);

        if (isset($mappedData['_importJob'])) {
            $invoice->setImportJob($mappedData['_importJob']);
        }

        // Link client entity and populate sender from company
        $this->linkClientAndSender($invoice, $mappedData, $company);

        $this->entityManager->persist($invoice);

        // Create line items
        $lines = $mappedData['lines'] ?? [];
        foreach ($lines as $position => $lineData) {
            $line = $this->buildInvoiceLine($lineData, $position);
            $invoice->addLine($line);
        }

        // Collections are settled when the batch is written, once every row of the invoice has
        // been added to it: only then is the invoice total known.
        $this->pendingSettlement[$dedupKey] = [
            'company' => $company,
            'job' => $mappedData['_importJob'] ?? null,
            'markAsPaid' => !empty($mappedData['_importOptions']['markAsPaid']),
            'paid' => null,
            'fullyPaid' => false,
        ];
        $this->noteCollection($dedupKey, $mappedData);

        // Track for dedup
        $this->pendingCache[$dedupKey] = $invoice;
        $this->existingInvoiceNumbers[$lowerNumber] = true;
        $result->incrementCreated();

        $this->batchCount++;
        if ($this->batchCount >= self::BATCH_SIZE) {
            $this->flushPending = true;
        }
    }

    /**
     * What the row says about the collection of its invoice: an amount (the same on every
     * row of the invoice, so the largest is kept) or a plain "paid".
     */
    private function noteCollection(string $dedupKey, array $data): void
    {
        if (!isset($this->pendingSettlement[$dedupKey])) {
            return;
        }
        if (!empty($data['_paid'])) {
            $this->pendingSettlement[$dedupKey]['fullyPaid'] = true;
        }
        if (isset($data['_unpaidAmount']) && is_numeric($data['_unpaidAmount'])) {
            $this->pendingSettlement[$dedupKey]['unpaid'] = max((float) $data['_unpaidAmount'], (float) ($this->pendingSettlement[$dedupKey]['unpaid'] ?? 0));
        }
        if (isset($data['_paidAmount']) && is_numeric($data['_paidAmount'])) {
            $this->pendingSettlement[$dedupKey]['paid'] = max((float) $data['_paidAmount'], (float) ($this->pendingSettlement[$dedupKey]['paid'] ?? 0));
        }
    }

    /**
     * Create the payments of the invoices in the batch. An export that says how much of each
     * invoice was collected decides per invoice; the "mark as paid" option covers the others.
     */
    private function settleCollections(): void
    {
        foreach ($this->pendingSettlement as $dedupKey => $settlement) {
            $invoice = $this->pendingCache[$dedupKey] ?? null;
            if ($invoice === null || $invoice->getStatus() === DocumentStatus::CANCELLED) {
                continue;
            }
            $total = (float) ($invoice->getTotal() ?? '0');
            if ($total <= 0) {
                continue; // nothing to collect on a zero or a storno invoice
            }
            if ($settlement['paid'] === null && isset($settlement['unpaid'])) {
                $settlement['paid'] = max($total - $settlement['unpaid'], 0.0);
            }
            $knowsCollection = $settlement['fullyPaid'] || $settlement['paid'] !== null;
            $paid = $settlement['fullyPaid'] ? $total : ($settlement['paid'] ?? ($settlement['markAsPaid'] ? $total : 0.0));
            if (!$knowsCollection && !$settlement['markAsPaid']) {
                continue;
            }
            $paid = min($paid, $total);
            if ($paid <= 0.004) {
                continue;
            }

            $amount = number_format($paid, 2, '.', '');
            $this->addPayment($invoice, $settlement['company'], $amount, $settlement['job']);
            $invoice->setAmountPaid($amount);
            if ($paid >= $total - 0.004) {
                $invoice->setStatus(DocumentStatus::PAID);
                $paymentDate = $invoice->getIssueDate();
                $invoice->setPaidAt($paymentDate instanceof \DateTime
                    ? \DateTimeImmutable::createFromMutable($paymentDate)
                    : new \DateTimeImmutable());
            } else {
                $invoice->setStatus(DocumentStatus::PARTIALLY_PAID);
            }
        }
        $this->pendingSettlement = [];
    }

    private function addPayment(Invoice $invoice, Company $company, string $amount, mixed $importJob): void
    {
        $paymentDate = $invoice->getIssueDate() ?? new \DateTime();

        $payment = new Payment();
        $payment->setCompany($company);
        $payment->setInvoice($invoice);
        $payment->setAmount($amount);
        $payment->setCurrency($invoice->getCurrency() ?? 'RON');
        $payment->setPaymentDate($paymentDate);
        $payment->setPaymentMethod($invoice->getPaymentMethod() ?? 'bank_transfer');
        $payment->setReference('Import automat');
        $payment->setIsReconciled(true);
        if ($importJob !== null) {
            $payment->setImportJob($importJob);
        }
        $this->entityManager->persist($payment);
    }

    /** Tax code as an identity key: no spaces, upper case, no RO prefix. */
    private static function normalizeCif(string $cif): string
    {
        $cif = strtoupper(preg_replace('/[\s.\-]+/', '', $cif) ?? $cif);

        return preg_match('/^RO\d+$/', $cif) ? substr($cif, 2) : $cif;
    }

    private static function numberKey(string $lowerNumber, ?string $direction, ?string $senderCif): string
    {
        if ($direction === 'incoming' && $senderCif !== null && trim($senderCif) !== '') {
            return $lowerNumber . '|' . self::normalizeCif($senderCif);
        }

        return $lowerNumber;
    }

    private function resolveDirection(array $data): ?string
    {
        $direction = $data['direction'] ?? null;
        if (empty($direction)) {
            $direction = match ($data['_importType'] ?? null) {
                'invoices_issued' => 'outgoing',
                'invoices_received' => 'incoming',
                default => null,
            };
        }

        return match ($direction) {
            'issued', 'sale', 'sales', 'out' => 'outgoing',
            'received', 'purchase', 'in' => 'incoming',
            default => $direction,
        };
    }

    public function flush(): void
    {
        $this->settleCollections();
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->pendingCache = [];
        $this->batchCount = 0;
        $this->flushPending = false;
    }

    public function reset(): void
    {
        $this->pendingCache = [];
        $this->clientCache = [];
        $this->supplierCache = [];
        $this->pendingSettlement = [];
        $this->flushPending = false;
        $this->existingInvoiceNumbers = [];
        $this->initialized = false;
        $this->companyId = null;
        $this->batchCount = 0;
    }

    /**
     * Link the invoice to a Client entity and auto-populate sender/receiver fields.
     */
    private function linkClientAndSender(Invoice $invoice, array $data, Company $company): void
    {
        $direction = $invoice->getDirection();
        $isIssued = $direction === InvoiceDirection::OUTGOING;

        // Auto-populate sender from company for issued invoices
        if ($isIssued) {
            if (empty($invoice->getSenderName())) {
                $invoice->setSenderName($company->getName());
            }
            if (empty($invoice->getSenderCif())) {
                $cif = $company->getCif();
                if ($cif) {
                    $invoice->setSenderCif((string) $cif);
                }
            }
        }

        // Auto-populate receiver from company for received invoices
        if ($direction === InvoiceDirection::INCOMING) {
            if (empty($invoice->getReceiverName())) {
                $invoice->setReceiverName($company->getName());
            }
            if (empty($invoice->getReceiverCif())) {
                $cif = $company->getCif();
                if ($cif) {
                    $invoice->setReceiverCif((string) $cif);
                }
            }
        }

        // Received invoices belong to a supplier when the nomenclature has one with that tax code
        if ($direction === InvoiceDirection::INCOMING && !empty($data['senderCif'])) {
            $supplierId = $this->supplierCache[self::normalizeCif((string) $data['senderCif'])] ?? null;
            if ($supplierId !== null) {
                $invoice->setSupplier($this->entityManager->getReference(Supplier::class, Uuid::fromString($supplierId)));
            }
        }

        // Match client: for issued invoices match by receiver info, for received by sender info
        $clientName = $isIssued ? ($data['receiverName'] ?? null) : ($data['senderName'] ?? null);
        $clientCif = $isIssued ? ($data['receiverCif'] ?? null) : ($data['senderCif'] ?? null);
        $clientEmail = $data['clientEmail'] ?? null;

        if (empty($clientName) && empty($clientCif) && empty($clientEmail)) {
            return;
        }

        $clientId = $this->findClientId($company, $clientEmail, $clientCif, $clientName);
        if ($clientId) {
            // Use getReference — no DB query, just sets the FK
            $invoice->setClient($this->entityManager->getReference(Client::class, Uuid::fromString($clientId)));

            return;
        }

        // Shop orders: the customer becomes a client (revertible with the import)
        if ($isIssued && !empty($data['_createClient'])) {
            $invoice->setClient($this->createClient($company, $data, $clientName, $clientCif, $clientEmail));
        }
    }

    /**
     * Create the client of an issued invoice from the row's receiver / billing
     * fields. Customers without a name are pooled under "Persoană fizică".
     */
    private function createClient(Company $company, array $data, ?string $name, ?string $cif, ?string $email): Client
    {
        $companyId = $company->getId()->toRfc4122();
        $name = trim((string) $name) !== '' ? trim((string) $name) : 'Persoană fizică';
        $cnp = !empty($data['receiverCnp']) ? preg_replace('/\s+/', '', (string) $data['receiverCnp']) : null;
        $cnp = $cnp !== null && preg_match('/^\d{13}$/', $cnp) ? $cnp : null;

        $nameKey = $companyId . ':name:' . mb_strtolower($name);
        if ($cif === null && $email === null && $cnp === null && isset($this->clientCache[$nameKey])) {
            return $this->entityManager->getReference(Client::class, Uuid::fromString($this->clientCache[$nameKey]));
        }

        $client = new Client();
        $client->setCompany($company);
        $client->setName(mb_substr($name, 0, 255));
        $client->setType($cif ? 'company' : 'individual');
        if ($cif) {
            $cifUpper = strtoupper(trim($cif));
            if (str_starts_with($cifUpper, 'RO')) {
                $client->setVatCode($cifUpper);
                $client->setCui(substr($cifUpper, 2));
                $client->setIsVatPayer(true);
            } else {
                $client->setCui($cifUpper);
            }
        }
        if ($cnp !== null) {
            $client->setCnp($cnp);
        }
        foreach (['clientEmail' => 'setEmail', 'clientPhone' => 'setPhone', 'clientAddress' => 'setAddress', 'clientCity' => 'setCity', 'clientCounty' => 'setCounty', 'clientPostalCode' => 'setPostalCode'] as $field => $setter) {
            if (!empty($data[$field])) {
                $client->$setter(mb_substr(trim((string) $data[$field]), 0, 255));
            }
        }
        if (!empty($data['clientCountry']) && preg_match('/^[A-Za-z]{2}$/', trim((string) $data['clientCountry']))) {
            $client->setCountry(strtoupper(trim((string) $data['clientCountry'])));
        }
        $client->setSource('import:' . ($data['_source'] ?? 'generic'));
        if (isset($data['_importJob'])) {
            $client->setImportJob($data['_importJob']);
        }
        $this->entityManager->persist($client);

        $id = $client->getId()->toRfc4122();
        $this->clientCache[$nameKey] = $id;
        if ($cif) {
            $this->clientCache[$companyId . ':cui:' . self::normalizeCif($cif)] = $id;
        }
        if ($email) {
            $this->clientCache[$companyId . ':email:' . mb_strtolower(trim($email))] = $id;
        }

        return $client;
    }

    /**
     * Gross-only exports (order total with VAT included, rate unknown): use the
     * company's default VAT rate when it is a VAT payer, no VAT otherwise.
     */
    private function splitGrossWithCompanyRate(array $data, Company $company): array
    {
        $gross = (float) ($data['total'] ?? 0);
        $rate = 0.0;
        if ($company->isVatPayer()) {
            $default = $this->vatRateRepository?->findDefaultByCompany($company);
            $rate = $default !== null ? (float) $default->getRate() : 21.0;
        }
        $net = $rate > 0 ? round($gross / (1 + $rate / 100), 2) : $gross;
        $vat = round($gross - $net, 2);

        $data['subtotal'] = number_format($net, 2, '.', '');
        $data['vatTotal'] = number_format($vat, 2, '.', '');
        $data['total'] = number_format($gross, 2, '.', '');
        foreach ($data['lines'] ?? [] as $i => $line) {
            $lineGross = (float) ($line['lineTotal'] ?? $gross);
            $lineNet = $rate > 0 ? round($lineGross / (1 + $rate / 100), 2) : $lineGross;
            $data['lines'][$i]['unitPrice'] = number_format($lineNet / max((float) ($line['quantity'] ?? 1), 0.0001), 2, '.', '');
            $data['lines'][$i]['lineTotal'] = number_format($lineNet, 2, '.', '');
            $data['lines'][$i]['vatAmount'] = number_format($lineGross - $lineNet, 2, '.', '');
            $data['lines'][$i]['vatRate'] = number_format($rate, 2, '.', '');
            $data['lines'][$i]['vatCategoryCode'] = $rate > 0 ? 'S' : ($company->isVatPayer() ? 'E' : 'O');
        }

        return $data;
    }

    /**
     * Find a client ID from the pre-loaded cache. No DB queries.
     */
    private function findClientId(Company $company, ?string $email, ?string $cui, ?string $name): ?string
    {
        $companyId = $company->getId()->toRfc4122();

        if (!empty($email)) {
            $key = $companyId . ':email:' . mb_strtolower(trim($email));
            if (isset($this->clientCache[$key])) {
                return $this->clientCache[$key];
            }
        }

        if (!empty($cui)) {
            $key = $companyId . ':cui:' . self::normalizeCif($cui);
            if (isset($this->clientCache[$key])) {
                return $this->clientCache[$key];
            }
        }

        return null;
    }

    private function buildInvoice(array $data, Company $company): Invoice
    {
        $invoice = new Invoice();
        $invoice->setCompany($company);

        $invoice->setNumber($data['number']);

        $documentType = DocumentType::INVOICE;
        if (!empty($data['documentType'])) {
            try {
                $documentType = DocumentType::from($data['documentType']);
            } catch (\ValueError) {}
        }
        $invoice->setDocumentType($documentType);

        $status = DocumentStatus::SYNCED;
        if (!empty($data['status'])) {
            try {
                $status = DocumentStatus::from($data['status']);
            } catch (\ValueError) {}
        }
        $invoice->setStatus($status);

        $direction = $data['direction'] ?? null;
        if (empty($direction)) {
            $importType = $data['_importType'] ?? null;
            if ($importType === 'invoices_issued') {
                $direction = 'outgoing';
            } elseif ($importType === 'invoices_received') {
                $direction = 'incoming';
            }
        }
        // Mappers describe the direction the way their export does ("issued" /
        // "received"); the entity knows outgoing / incoming.
        $direction = match ($direction) {
            'issued', 'sale', 'sales', 'out' => 'outgoing',
            'received', 'purchase', 'in' => 'incoming',
            default => $direction,
        };
        if (!empty($direction)) {
            try {
                $invoice->setDirection(InvoiceDirection::from($direction));
            } catch (\ValueError) {}
        }

        if (!empty($data['senderCif'])) {
            $invoice->setSenderCif(trim($data['senderCif']));
        }
        if (!empty($data['receiverCif'])) {
            $invoice->setReceiverCif(trim($data['receiverCif']));
        }
        if (!empty($data['senderName'])) {
            $invoice->setSenderName($data['senderName']);
        }
        if (!empty($data['receiverName'])) {
            $invoice->setReceiverName($data['receiverName']);
        }

        if (isset($data['subtotal']) && $data['subtotal'] !== '') {
            $invoice->setSubtotal(number_format((float) $data['subtotal'], 2, '.', ''));
        }
        if (isset($data['vatTotal']) && $data['vatTotal'] !== '') {
            $invoice->setVatTotal(number_format((float) $data['vatTotal'], 2, '.', ''));
        }
        if (isset($data['total']) && $data['total'] !== '') {
            $invoice->setTotal(number_format((float) $data['total'], 2, '.', ''));
        }
        if (isset($data['discount']) && $data['discount'] !== '') {
            $invoice->setDiscount(number_format((float) $data['discount'], 2, '.', ''));
        }

        if (!empty($data['currency'])) {
            $invoice->setCurrency(strtoupper(trim($data['currency'])));
        }

        if (!empty($data['issueDate'])) {
            try {
                $invoice->setIssueDate(new \DateTime($data['issueDate']));
            } catch (\Exception) {}
        }
        if (!empty($data['dueDate'])) {
            try {
                $invoice->setDueDate(new \DateTime($data['dueDate']));
            } catch (\Exception) {}
        }

        if (!empty($data['notes'])) {
            $invoice->setNotes($data['notes']);
        }
        if (!empty($data['paymentTerms'])) {
            $invoice->setPaymentTerms($data['paymentTerms']);
        }
        if (!empty($data['invoiceTypeCode'])) {
            $invoice->setInvoiceTypeCode($data['invoiceTypeCode']);
        }
        if (!empty($data['exchangeRate'])) {
            $invoice->setExchangeRate(number_format((float) $data['exchangeRate'], 4, '.', ''));
        }
        if (!empty($data['orderNumber'])) {
            $invoice->setOrderNumber($data['orderNumber']);
        }
        if (!empty($data['contractNumber'])) {
            $invoice->setContractNumber($data['contractNumber']);
        }
        if (!empty($data['paymentMethod'])) {
            $invoice->setPaymentMethod($data['paymentMethod']);
        }

        if (!empty($data['createdAt'])) {
            try {
                $invoice->setCreatedAt(new \DateTimeImmutable($data['createdAt']));
            } catch (\Exception) {}
        }

        $source = $data['_source'] ?? 'generic';
        $idempotencyKey = 'import:' . $source . ':' . $company->getId()->toRfc4122() . ':' . $data['number'];
        if (!empty($data['senderCif'])) {
            $idempotencyKey .= ':' . $data['senderCif'];
        }
        $invoice->setIdempotencyKey(hash('sha256', $idempotencyKey));

        return $invoice;
    }

    private function buildInvoiceLine(array $lineData, int $position): InvoiceLine
    {
        $line = new InvoiceLine();
        $line->setPosition($position);

        $line->setDescription(!empty($lineData['description']) ? mb_substr($lineData['description'], 0, 500) : '-');

        if (isset($lineData['quantity']) && $lineData['quantity'] !== '') {
            $line->setQuantity(number_format((float) $lineData['quantity'], 4, '.', ''));
        }
        if (!empty($lineData['unitOfMeasure'])) {
            $line->setUnitOfMeasure($lineData['unitOfMeasure']);
        }
        if (isset($lineData['unitPrice']) && $lineData['unitPrice'] !== '') {
            $line->setUnitPrice(number_format((float) $lineData['unitPrice'], 2, '.', ''));
        }
        if (isset($lineData['vatRate']) && $lineData['vatRate'] !== '') {
            $line->setVatRate((string) $lineData['vatRate']);
        }
        if (!empty($lineData['vatCategoryCode'])) {
            $line->setVatCategoryCode($lineData['vatCategoryCode']);
        }
        if (isset($lineData['vatAmount']) && $lineData['vatAmount'] !== '') {
            $line->setVatAmount(number_format((float) $lineData['vatAmount'], 2, '.', ''));
        }
        if (isset($lineData['lineTotal']) && $lineData['lineTotal'] !== '') {
            $line->setLineTotal(number_format((float) $lineData['lineTotal'], 2, '.', ''));
        }
        if (isset($lineData['discount']) && $lineData['discount'] !== '') {
            $line->setDiscount(number_format((float) $lineData['discount'], 2, '.', ''));
        }
        if (isset($lineData['discountPercent']) && $lineData['discountPercent'] !== '') {
            $line->setDiscountPercent(number_format((float) $lineData['discountPercent'], 2, '.', ''));
        }
        if (isset($lineData['vatIncluded'])) {
            $line->setVatIncluded((bool) $lineData['vatIncluded']);
        }
        if (!empty($lineData['productCode'])) {
            $line->setProductCode($lineData['productCode']);
        }

        return $line;
    }
}
