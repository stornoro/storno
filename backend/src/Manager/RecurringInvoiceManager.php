<?php

namespace App\Manager;

use App\Entity\Company;
use App\Entity\RecurringInvoice;
use App\Entity\RecurringInvoiceLine;
use App\Enum\DocumentType;
use App\Manager\Trait\DocumentCalculationTrait;
use App\Repository\ClientRepository;
use App\Repository\DocumentSeriesRepository;
use App\Repository\ProductRepository;
use App\Repository\RecurringInvoiceRepository;
use App\Service\ExchangeRateService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class RecurringInvoiceManager
{
    use DocumentCalculationTrait;
    public function __construct(
        private readonly RecurringInvoiceRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientRepository $clientRepository,
        private readonly DocumentSeriesRepository $documentSeriesRepository,
        private readonly ProductRepository $productRepository,
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

    public function find(string $uuid): ?RecurringInvoice
    {
        $ri = $this->repository->findWithDetails($uuid);
        if ($ri) {
            $this->enrichWithEstimatedTotals([$ri]);
        }
        return $ri;
    }

    public function listByCompany(Company $company, array $filters = [], int $page = 1, int $limit = 20): array
    {
        $result = $this->repository->findByCompanyPaginated($company, $filters, $page, $limit);
        $this->enrichWithEstimatedTotals($result['data'] ?? []);
        return $result;
    }

    public function enrichWithEstimatedTotals(array $recurringInvoices): void
    {
        // Collect all reference currencies needed
        $currencies = [];
        foreach ($recurringInvoices as $ri) {
            if ($ri instanceof RecurringInvoice) {
                foreach ($ri->getLines() as $line) {
                    $ref = $line->getReferenceCurrency();
                    if ($ref) {
                        $currencies[$ref] = true;
                    }
                }
            }
        }

        // Resolve rates (currency => float)
        $rates = [];
        foreach (array_keys($currencies) as $currency) {
            $rate = $this->exchangeRateService->getRate($currency);
            if ($rate !== null) {
                $rates[$currency] = $rate;
            }
        }

        foreach ($recurringInvoices as $ri) {
            if ($ri instanceof RecurringInvoice) {
                if ($ri->hasReferenceCurrencyLines() && !empty($rates)) {
                    $amounts = $ri->computeEstimatedAmounts($rates);
                    $ri->setEstimatedAmounts($amounts['subtotal'], $amounts['vatTotal'], $amounts['total']);
                } else {
                    $ri->setEstimatedAmounts($ri->getSubtotal(), $ri->getVatTotal(), $ri->getTotal());
                }
            }
        }
    }

    public function create(Company $company, array $data): RecurringInvoice
    {
        $ri = new RecurringInvoice();
        $ri->setCompany($company);
        $ri->setCreatedAt(new \DateTimeImmutable());

        $this->applyData($ri, $data, $company);

        $this->setLines($ri, $data['lines'] ?? []);

        $this->entityManager->persist($ri);
        $this->entityManager->flush();

        return $ri;
    }

    public function update(RecurringInvoice $ri, array $data): RecurringInvoice
    {
        $company = $ri->getCompany();
        $ri->setUpdatedAt(new \DateTimeImmutable());

        $this->applyData($ri, $data, $company);

        if (isset($data['lines'])) {
            $ri->clearLines();
            $this->entityManager->flush();
            $this->setLines($ri, $data['lines']);
        }

        $this->entityManager->flush();

        return $ri;
    }

    public function delete(RecurringInvoice $ri): void
    {
        $ri->setDeletedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function toggle(RecurringInvoice $ri): RecurringInvoice
    {
        $ri->setIsActive(!$ri->isActive());
        $ri->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $ri;
    }

    private function applyData(RecurringInvoice $ri, array $data, ?Company $company): void
    {
        if (isset($data['reference'])) {
            $ri->setReference($data['reference']);
        }
        if (isset($data['documentType'])) {
            $ri->setDocumentType(DocumentType::from($data['documentType']));
        }
        if (isset($data['currency'])) {
            $ri->setCurrency($data['currency']);
        }
        if (array_key_exists('invoiceTypeCode', $data)) {
            $ri->setInvoiceTypeCode($data['invoiceTypeCode'] ?: null);
        }
        if (array_key_exists('isActive', $data)) {
            $ri->setIsActive((bool) $data['isActive']);
        }

        // Schedule
        if (isset($data['frequency'])) {
            $ri->setFrequency($data['frequency']);
        }
        if (isset($data['frequencyDay'])) {
            $day = (int) $data['frequencyDay'];
            // Cap to 28 for month-based frequencies to avoid month-end ambiguity
            if (in_array($ri->getFrequency(), ['once', 'monthly', 'bimonthly', 'quarterly', 'semi_annually', 'yearly'], true) && $day > 28) {
                $day = 28;
            }
            $ri->setFrequencyDay($day);
        }
        if (array_key_exists('frequencyMonth', $data)) {
            $ri->setFrequencyMonth($data['frequencyMonth'] !== null ? (int) $data['frequencyMonth'] : null);
        }
        if (isset($data['nextIssuanceDate'])) {
            $ri->setNextIssuanceDate(new \DateTime($data['nextIssuanceDate']));
        }
        if (array_key_exists('stopDate', $data)) {
            $ri->setStopDate($data['stopDate'] ? new \DateTime($data['stopDate']) : null);
        }

        // Due date config
        if (array_key_exists('dueDateType', $data)) {
            $ri->setDueDateType($data['dueDateType']);
        }
        if (array_key_exists('dueDateDays', $data)) {
            $ri->setDueDateDays($data['dueDateDays'] !== null ? (int) $data['dueDateDays'] : null);
        }
        if (array_key_exists('dueDateFixedDay', $data)) {
            $ri->setDueDateFixedDay($data['dueDateFixedDay'] !== null ? (int) $data['dueDateFixedDay'] : null);
        }

        // Client
        if (array_key_exists('clientId', $data)) {
            if (!empty($data['clientId'])) {
                $client = $this->clientRepository->find(Uuid::fromString($data['clientId']));
                if ($client) {
                    $ri->setClient($client);
                }
            } else {
                $ri->setClient(null);
            }
        }

        // Document series
        if (array_key_exists('documentSeriesId', $data)) {
            if (!empty($data['documentSeriesId'])) {
                $series = $this->documentSeriesRepository->find(Uuid::fromString($data['documentSeriesId']));
                if ($series && $company && $series->getCompany()?->getId()->equals($company->getId())) {
                    $ri->setDocumentSeries($series);
                }
            } else {
                $ri->setDocumentSeries(null);
            }
        }

        // Notes
        if (array_key_exists('notes', $data)) {
            $ri->setNotes($data['notes']);
        }
        if (array_key_exists('paymentTerms', $data)) {
            $ri->setPaymentTerms($data['paymentTerms']);
        }

        // Auto-email
        if (array_key_exists('autoEmailEnabled', $data)) {
            $ri->setAutoEmailEnabled((bool) $data['autoEmailEnabled']);
        }
        if (array_key_exists('autoEmailTime', $data)) {
            $ri->setAutoEmailTime($data['autoEmailTime'] ?: null);
        }
        if (array_key_exists('autoEmailDayOffset', $data)) {
            $ri->setAutoEmailDayOffset((int) $data['autoEmailDayOffset']);
        }

        // Penalties
        if (array_key_exists('penaltyEnabled', $data)) {
            $ri->setPenaltyEnabled((bool) $data['penaltyEnabled']);
        }
        if (array_key_exists('penaltyPercentPerDay', $data)) {
            $ri->setPenaltyPercentPerDay($data['penaltyPercentPerDay'] !== null ? (string) $data['penaltyPercentPerDay'] : null);
        }
        if (array_key_exists('penaltyGraceDays', $data)) {
            $ri->setPenaltyGraceDays($data['penaltyGraceDays'] !== null ? (int) $data['penaltyGraceDays'] : null);
        }
    }

    private function setLines(RecurringInvoice $ri, array $linesData): void
    {
        foreach ($linesData as $i => $lineData) {
            $line = new RecurringInvoiceLine();
            $this->populateLineFields($line, $lineData, $i + 1);

            // Recurring-specific fields
            if (isset($lineData['priceRule'])) {
                $line->setPriceRule($lineData['priceRule']);
            }
            if (array_key_exists('referenceCurrency', $lineData)) {
                $line->setReferenceCurrency($lineData['referenceCurrency'] ?: null);
            }
            if (array_key_exists('markupPercent', $lineData)) {
                $line->setMarkupPercent($lineData['markupPercent'] !== null ? (string) $lineData['markupPercent'] : null);
            }

            // Product link
            if (!empty($lineData['productId'])) {
                $product = $this->productRepository->find(Uuid::fromString($lineData['productId']));
                if ($product) {
                    $line->setProduct($product);
                }
            }

            $ri->addLine($line);
        }
    }
}
