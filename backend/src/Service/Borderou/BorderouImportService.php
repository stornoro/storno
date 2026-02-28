<?php

namespace App\Service\Borderou;

use App\Entity\BankAccount;
use App\Entity\BorderouTransaction;
use App\Entity\Company;
use App\Entity\ImportJob;
use App\Entity\Payment;
use App\Repository\BorderouTransactionRepository;
use App\Repository\ImportJobRepository;
use App\Service\Borderou\Parser\BorderouParserInterface;
use App\Service\Import\Parser\FileParserInterface;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;

class BorderouImportService
{
    /** @var BorderouParserInterface[] */
    private array $bordereauParsers = [];

    /** @var FileParserInterface[] */
    private array $fileParsers = [];

    /**
     * @param iterable<BorderouParserInterface> $parsers
     * @param iterable<FileParserInterface> $fileParsers
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BorderouMatchingService $matchingService,
        private readonly BorderouTransactionRepository $txRepo,
        private readonly ImportJobRepository $importJobRepo,
        private readonly PaymentService $paymentService,
        iterable $parsers,
        iterable $fileParsers,
    ) {
        foreach ($parsers as $parser) {
            $this->bordereauParsers[] = $parser;
        }
        foreach ($fileParsers as $fp) {
            $this->fileParsers[] = $fp;
        }
    }

    /**
     * @return array{importJobId: string, summary: array, transactions: BorderouTransaction[]}
     */
    public function import(
        Company $company,
        string $filePath,
        string $fileFormat,
        string $originalFilename,
        string $sourceType,
        string $provider,
        string $currency = 'RON',
        ?string $bordereauNumber = null,
        ?BankAccount $bankAccount = null,
    ): array {
        // 1. Create ImportJob
        $job = new ImportJob();
        $job->setCompany($company);
        $job->setImportType($sourceType);
        $job->setSource($provider);
        $job->setFileFormat($fileFormat);
        $job->setOriginalFilename($originalFilename);
        $job->setStatus('processing');
        $job->setImportOptions([
            'currency' => $currency,
            'bordereauNumber' => $bordereauNumber,
        ]);

        $this->em->persist($job);
        $this->em->flush();

        // 2. Find file parser
        $fileParser = $this->findFileParser($fileFormat);
        if (!$fileParser) {
            $job->setStatus('failed');
            $job->setErrors(['No parser found for format: ' . $fileFormat]);
            $this->em->flush();
            throw new \RuntimeException('No file parser supports format: ' . $fileFormat);
        }

        // 3. Read headers and rows
        $preview = $fileParser->preview($filePath, 1);
        $headers = $preview['headers'];
        $rows = $fileParser->parse($filePath);

        // 4. Find borderou parser
        $bordParser = $this->findBordereauParser($provider, $sourceType, $headers);
        if (!$bordParser) {
            $job->setStatus('failed');
            $job->setErrors(['No borderou parser found for provider: ' . $provider]);
            $this->em->flush();
            throw new \RuntimeException('No borderou parser found for provider: ' . $provider);
        }

        // 4b. Validate IBAN matches the selected bank account
        if ($bankAccount && $sourceType === 'bank_statement') {
            $fileIban = $this->extractIbanFromFile($bordParser, $filePath, $fileFormat, $headers);
            if ($fileIban && strtoupper($fileIban) !== strtoupper($bankAccount->getIban())) {
                $job->setStatus('failed');
                $job->setErrors([sprintf(
                    'IBAN mismatch: file contains %s but selected bank account is %s',
                    $fileIban,
                    $bankAccount->getIban(),
                )]);
                $this->em->flush();
                throw new \RuntimeException(sprintf(
                    'Extrasul contine IBAN-ul %s, dar contul selectat are IBAN-ul %s. Selectati contul corect.',
                    $fileIban,
                    $bankAccount->getIban(),
                ));
            }
        }

        // 5. Parse rows into standardized format
        // Re-read rows since the generator may have been consumed during IBAN extraction
        $rows = $fileParser->parse($filePath);
        $parsedRows = $bordParser->parseRows($headers, $rows);

        // 6. Create BorderouTransaction entities (with duplicate detection)
        $transactions = [];
        $duplicatesSkipped = 0;
        foreach ($parsedRows as $parsed) {
            $txDate = new \DateTime($parsed['date']);

            // Check for duplicates before creating
            $duplicate = $this->txRepo->findDuplicate(
                $company,
                $parsed['bankReference'] ?? null,
                $txDate,
                $parsed['amount'],
                $parsed['explanation'] ?? null,
            );
            if ($duplicate) {
                $duplicatesSkipped++;
                continue;
            }

            $tx = new BorderouTransaction();
            $tx->setCompany($company);
            $tx->setImportJob($job);
            $tx->setTransactionDate($txDate);
            $tx->setClientName($parsed['clientName']);
            $tx->setClientCif($parsed['clientCif']);
            $tx->setExplanation($parsed['explanation']);
            $tx->setAmount($parsed['amount']);
            $tx->setCurrency($parsed['currency'] ?: $currency);
            $tx->setAwbNumber($parsed['awbNumber']);
            $tx->setBankReference($parsed['bankReference']);
            $tx->setDocumentType($parsed['documentType']);
            $tx->setDocumentNumber($parsed['documentNumber'] ?? $bordereauNumber);
            $tx->setSourceType($sourceType);
            $tx->setSourceProvider($provider);
            $tx->setRawData($parsed['rawData']);

            // 7. Run matching
            $match = $this->matchingService->matchTransaction($tx, $company);
            $tx->setMatchConfidence($match['confidence']);
            $tx->setMatchedInvoice($match['invoice']);
            $tx->setMatchedProformaInvoice($match['proformaInvoice']);
            $tx->setMatchedClient($match['client']);

            $this->em->persist($tx);
            $transactions[] = $tx;
        }

        // 8. Update job
        $job->setTotalRows(count($transactions));
        $job->setStatus('completed');
        $job->setProcessedAt(new \DateTimeImmutable());
        $this->em->flush();

        // 9. Build summary
        $summary = $this->txRepo->countByImportJobGroupedByStatus($job);
        $summary['duplicatesSkipped'] = $duplicatesSkipped;

        return [
            'importJobId' => $job->getId()->toRfc4122(),
            'summary' => $summary,
            'transactions' => $transactions,
        ];
    }

    /**
     * Bulk save transactions as Payment records.
     *
     * @param string[] $transactionIds
     * @return array{saved: int, errors: array}
     */
    public function saveTransactions(array $transactionIds, Company $company): array
    {
        $transactions = $this->txRepo->findByIds($transactionIds);
        $saved = 0;
        $errors = [];

        foreach ($transactions as $tx) {
            // If EntityManager was closed by a previous error, stop processing
            if (!$this->em->isOpen()) {
                $errors[] = [
                    'transactionId' => $tx->getId()->toRfc4122(),
                    'error' => 'EntityManager closed by previous error, skipping remaining transactions.',
                ];
                continue;
            }

            if ($tx->getCompany()->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
                continue;
            }

            if ($tx->getStatus() !== 'unsaved') {
                $errors[] = [
                    'transactionId' => $tx->getId()->toRfc4122(),
                    'error' => 'Transaction already processed (status: ' . $tx->getStatus() . ')',
                ];
                continue;
            }

            // Skip unmatched transactions — must have an invoice or proforma
            if (!$tx->getMatchedInvoice() && !$tx->getMatchedProformaInvoice()) {
                $errors[] = [
                    'transactionId' => $tx->getId()->toRfc4122(),
                    'error' => 'Transaction has no matched invoice or proforma.',
                ];
                continue;
            }

            try {
                $payment = $this->createPaymentFromTransaction($tx, $company);
                $tx->setStatus('saved');
                $tx->setCreatedPayment($payment);
                // Flush after each transaction to isolate failures
                $this->em->flush();
                $saved++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'transactionId' => $tx->getId()->toRfc4122(),
                    'error' => $e->getMessage(),
                ];
                // If EM is still open, mark as error and flush
                if ($this->em->isOpen()) {
                    $tx->setStatus('error');
                    try {
                        $this->em->flush();
                    } catch (\Throwable) {
                        // EM likely closed, will be caught by isOpen check above
                    }
                }
            }
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    private function createPaymentFromTransaction(BorderouTransaction $tx, Company $company): Payment
    {
        $invoice = $tx->getMatchedInvoice();
        $proforma = $tx->getMatchedProformaInvoice();

        $paymentMethod = match ($tx->getDocumentType()) {
            'ramburs' => 'cash',
            'transfer' => 'bank_transfer',
            'card' => 'card',
            default => 'bank_transfer',
        };

        if ($invoice) {
            $balance = $invoice->getBalance();

            // If invoice is already fully paid, create standalone payment
            if (bccomp($balance, '0', 2) <= 0) {
                return $this->createStandalonePayment($tx, $company, $paymentMethod, 'Factura ' . $invoice->getNumber() . ' deja platita');
            }

            // Cap payment amount to invoice balance to prevent DomainException
            $amount = bccomp($tx->getAmount(), $balance, 2) > 0 ? $balance : $tx->getAmount();

            $data = [
                'amount' => $amount,
                'currency' => $tx->getCurrency(),
                'paymentMethod' => $paymentMethod,
                'paymentDate' => $tx->getTransactionDate()->format('Y-m-d'),
                'reference' => $tx->getExplanation(),
                'notes' => 'Import borderou: ' . ($tx->getSourceProvider() ?? 'generic'),
            ];

            return $this->paymentService->recordPayment($invoice, $data);
        }

        if ($proforma) {
            // Proforma matched — create standalone payment referencing the proforma
            return $this->createStandalonePayment($tx, $company, $paymentMethod, 'Proforma ' . $proforma->getNumber());
        }

        // Standalone payment (no invoice or proforma linked)
        return $this->createStandalonePayment($tx, $company, $paymentMethod);
    }

    private function createStandalonePayment(BorderouTransaction $tx, Company $company, string $paymentMethod, ?string $extraNote = null): Payment
    {
        $payment = new Payment();
        $payment->setCompany($company);
        $payment->setAmount($tx->getAmount());
        $payment->setCurrency($tx->getCurrency());
        $payment->setPaymentMethod($paymentMethod);
        $payment->setPaymentDate($tx->getTransactionDate());
        $payment->setReference($tx->getExplanation());

        $notes = 'Import borderou: ' . ($tx->getSourceProvider() ?? 'generic');
        if ($extraNote) {
            $notes .= ' | ' . $extraNote;
        }
        $payment->setNotes($notes);

        $this->em->persist($payment);

        return $payment;
    }

    /**
     * Extract IBAN from the file using parser-specific logic or generic header scan.
     */
    private function extractIbanFromFile(
        BorderouParserInterface $bordParser,
        string $filePath,
        string $fileFormat,
        array $headers,
    ): ?string {
        // Use parser-specific IBAN extraction if available
        if (method_exists($bordParser, 'extractIban')) {
            $fileParser = $this->findFileParser($fileFormat);
            if ($fileParser) {
                $rows = $fileParser->parse($filePath);
                $iban = $bordParser->extractIban($rows);
                if ($iban) {
                    return $iban;
                }
            }
        }

        // Generic fallback: check if any header contains an IBAN-like value
        // Some bank CSVs have the IBAN as a header or in the first data row
        $normalised = array_map(fn (string $h) => mb_strtolower(trim($h)), $headers);
        $ibanCandidates = ['cont', 'iban', 'contul'];

        foreach ($normalised as $idx => $h) {
            foreach ($ibanCandidates as $candidate) {
                if (str_contains($h, $candidate)) {
                    // This header might contain IBAN data — read first row
                    $fileParser = $this->findFileParser($fileFormat);
                    if ($fileParser) {
                        $preview = $fileParser->preview($filePath, 1);
                        if (!empty($preview['rows'])) {
                            $firstRow = $preview['rows'][0];
                            $value = $firstRow[$headers[$idx]] ?? '';
                            if (preg_match('/^RO\d{2}[A-Z]{4}\d{16}$/i', trim($value))) {
                                return strtoupper(trim($value));
                            }
                        }
                    }
                    break;
                }
            }
        }

        return null;
    }

    private function findFileParser(string $fileFormat): ?FileParserInterface
    {
        foreach ($this->fileParsers as $parser) {
            if ($parser->supports($fileFormat)) {
                return $parser;
            }
        }

        return null;
    }

    private function findBordereauParser(string $provider, string $sourceType, array $headers): ?BorderouParserInterface
    {
        // First try exact provider match
        foreach ($this->bordereauParsers as $parser) {
            if ($parser->getProvider() === $provider && $parser->getSourceType() === $sourceType) {
                return $parser;
            }
        }

        // Fall back to best auto-detect
        $best = null;
        $bestConf = 0.0;

        foreach ($this->bordereauParsers as $parser) {
            if ($parser->getSourceType() !== $sourceType) {
                continue;
            }
            $conf = $parser->detectConfidence($headers);
            if ($conf > $bestConf) {
                $bestConf = $conf;
                $best = $parser;
            }
        }

        return $best;
    }

    /**
     * Get available providers grouped by source type.
     */
    public function getProviders(): array
    {
        return [
            'borderou' => [
                ['key' => 'fan_courier', 'label' => 'FAN Courier', 'formats' => ['csv', 'xlsx']],
                ['key' => 'gls', 'label' => 'GLS Romania', 'formats' => ['csv', 'xlsx']],
                ['key' => 'sameday', 'label' => 'Sameday', 'formats' => ['csv', 'xlsx']],
                ['key' => 'dpd', 'label' => 'DPD', 'formats' => ['csv', 'xlsx']],
                ['key' => 'cargus', 'label' => 'Urgent Cargus', 'formats' => ['csv', 'xlsx']],
                ['key' => 'generic', 'label' => 'Altul', 'formats' => ['csv', 'xlsx']],
            ],
            'bank_statement' => [
                ['key' => 'alpha_bank', 'label' => 'Alpha Bank Romania', 'formats' => ['csv', 'xlsx']],
                ['key' => 'bcr', 'label' => 'Banca Comerciala Romana', 'formats' => ['csv', 'xlsx']],
                ['key' => 'bt', 'label' => 'Banca Transilvania', 'formats' => ['csv', 'xlsx']],
                ['key' => 'brd', 'label' => 'BRD - Groupe Societe Generale', 'formats' => ['csv', 'xlsx']],
                ['key' => 'cec', 'label' => 'CEC Bank', 'formats' => ['csv', 'xlsx']],
                ['key' => 'first_bank', 'label' => 'First Bank Romania', 'formats' => ['csv', 'xlsx']],
                ['key' => 'garanti', 'label' => 'Garantibank International NV', 'formats' => ['csv', 'xlsx']],
                ['key' => 'ing', 'label' => 'ING Bank NV', 'formats' => ['csv', 'xlsx']],
                ['key' => 'libra', 'label' => 'Libra Bank', 'formats' => ['csv', 'xlsx']],
                ['key' => 'otp', 'label' => 'OTP Bank Romania', 'formats' => ['csv', 'xlsx']],
                ['key' => 'raiffeisen', 'label' => 'Raiffeisen Bank', 'formats' => ['csv', 'xlsx']],
                ['key' => 'revolut', 'label' => 'Revolut', 'formats' => ['csv', 'xlsx']],
                ['key' => 'unicredit', 'label' => 'UniCredit Bank SA', 'formats' => ['csv', 'xlsx']],
                ['key' => 'generic_bank', 'label' => 'Alta banca', 'formats' => ['csv', 'xlsx']],
            ],
        ];
    }
}
