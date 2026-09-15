<?php

namespace App\Service\Import\Persister;

use App\Entity\Company;
use App\Entity\ImportJob;
use App\Entity\Receipt;
use App\Entity\ReceiptLine;
use App\Enum\ReceiptStatus;
use App\Repository\VatRateRepository;
use App\Service\Import\ImportResult;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates one issued Receipt per fiscal receipt of a cash register export and
 * a daily summary (receipts, totals, payment split, the Z report declared by
 * the register) on the import job.
 *
 * VAT: the register reports totals per VAT level. A level that is one of the
 * company's VAT rates (or a rate ANAF used: 0, 5, 9, 11, 19, 21, 24) is taken
 * as the percentage; otherwise it is the register's VAT group index (1 = A,
 * 2 = B, …) resolved through the `vatGroups` option ({"1": "21", "2": "11",
 * "3": "5", "4": "0"} by default, 1 = the company's default rate).
 *
 * Idempotency: the receipt's fiscal identifier (idB — device serial, date,
 * time, Z report and receipt number) keys the receipt; a fiscal id already
 * imported is skipped.
 */
class ReceiptPersister implements EntityPersisterInterface, SummaryProviderInterface
{
    public const IMPORT_TYPE = 'receipts';

    private const BATCH_SIZE = 100;
    private const KNOWN_RATES = [0.0, 5.0, 9.0, 11.0, 19.0, 21.0, 24.0];

    /**
     * The payment types a register reports (`tipP`): 1 card, 2 credit,
     * 3 numerar, 4 bonuri valorice / tichete, 5-9 other means of payment.
     * A register that numbers them differently is corrected with the
     * `paymentTypes` option ({"1": "cash", "3": "card"}).
     */
    private const PAYMENT_TYPES = ['1' => 'card', '2' => 'other', '3' => 'cash', '4' => 'other', '5' => 'other', '6' => 'other', '7' => 'other', '8' => 'other', '9' => 'other'];

    /** @var array<string, true> */
    private array $existingKeys = [];

    /** @var float[] */
    private array $companyRates = [];

    /** @var array<string, array<string, mixed>> date => totals */
    private array $days = [];

    private ?Company $company = null;
    private ?ImportJob $job = null;
    private int $batchCount = 0;
    private int $receiptsCreated = 0;
    private int $receiptsSkipped = 0;
    private int $zReports = 0;
    private ?string $companyId = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VatRateRepository $vatRateRepository,
    ) {}

    public function supports(string $importType): bool
    {
        return $importType === self::IMPORT_TYPE;
    }

    public function persist(array $mappedData, Company $company, ImportResult $result): void
    {
        $this->initialize($company, $mappedData['_importJob'] ?? null);

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($mappedData['issueDate'] ?? ''));
        if ($date === false) {
            throw new \RuntimeException(sprintf('Data "%s" a bonului nu a putut fi interpretată.', $mappedData['issueDate'] ?? ''));
        }
        $dayKey = $date->format('Y-m-d');
        $this->days[$dayKey] ??= ['date' => $dayKey, 'receipts' => 0, 'total' => 0.0, 'vat' => 0.0, 'cash' => 0.0, 'card' => 0.0, 'other' => 0.0, 'zReports' => []];

        if (($mappedData['type'] ?? 'bon') === 'Z') {
            $this->days[$dayKey]['zReports'][] = [
                'number' => (string) ($mappedData['zReportNumber'] ?? ''),
                'receiptsDeclared' => (int) ($mappedData['receiptCount'] ?? 0),
                'total' => number_format((float) ($mappedData['total'] ?? 0), 2, '.', ''),
                'vat' => number_format((float) ($mappedData['vatTotal'] ?? 0), 2, '.', ''),
            ];
            $this->zReports++;
            $result->incrementUpdated();

            return;
        }

        $fiscalId = trim((string) ($mappedData['fiscalId'] ?? ''));
        $serial = trim((string) ($mappedData['deviceSerial'] ?? ''));
        $zNumber = trim((string) ($mappedData['zReportNumber'] ?? ''));
        $number = trim((string) ($mappedData['receiptNumber'] ?? ''));
        if ($fiscalId === '') {
            $fiscalId = implode('-', array_filter([$serial, $dayKey, $zNumber, $number, $mappedData['time'] ?? '']));
        }
        $key = hash('sha256', sprintf('import:cash_register:%s:%s', $this->companyId, $fiscalId));
        if (isset($this->existingKeys[$key])) {
            $this->receiptsSkipped++;
            $result->incrementSkipped();

            return;
        }

        $total = (float) ($mappedData['total'] ?? 0);
        $vatTotal = (float) ($mappedData['vatTotal'] ?? 0);
        $options = $mappedData['_importOptions'] ?? [];

        $receipt = new Receipt();
        $receipt->setCompany($company);
        $receipt->setNumber(mb_substr(implode('-', array_filter([$serial !== '' ? $serial : 'AMEF', $zNumber !== '' ? 'Z' . $zNumber : null, 'B' . ($number !== '' ? $number : substr($fiscalId, -4))])), 0, 255));
        $receipt->setFiscalNumber(mb_substr($fiscalId, 0, 255));
        $receipt->setDeviceSerial($serial !== '' ? mb_substr($serial, 0, 30) : null);
        $receipt->setCashRegisterName(trim((string) ($options['cashRegisterName'] ?? '')) ?: ($serial !== '' ? 'Casă de marcat ' . $serial : 'Casă de marcat'));
        $receipt->setStatus(ReceiptStatus::ISSUED);
        $receipt->setIssueDate(\DateTime::createFromImmutable($date));
        $time = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) ($mappedData['time'] ?? '')) ? $mappedData['time'] : '00:00:00';
        $receipt->setIssuedAt(new \DateTimeImmutable($dayKey . ' ' . $time));
        $receipt->setCurrency(preg_match('/^[A-Z]{3}$/', (string) ($mappedData['currency'] ?? '')) ? $mappedData['currency'] : ($company->getDefaultCurrency() ?: 'RON'));
        $receipt->setNotes('Import din fișierul casei de marcat (A4200)');
        if (!empty($mappedData['customerCif'])) {
            $receipt->setCustomerCif(mb_substr(preg_replace('/\s+/', '', (string) $mappedData['customerCif']), 0, 20));
        }
        if ($this->job !== null) {
            $receipt->setImportJob($this->job);
        }
        $receipt->setIdempotencyKey($key);

        [$cash, $card, $other] = $this->payments((string) ($mappedData['payments'] ?? ''), $total, $options);
        $receipt->setCashPayment(number_format($cash, 2, '.', ''));
        $receipt->setCardPayment(number_format($card, 2, '.', ''));
        $receipt->setOtherPayment(number_format($other, 2, '.', ''));
        $receipt->setPaymentMethod(match (true) {
            $cash > 0 && $card <= 0 && $other <= 0 => 'cash',
            $card > 0 && $cash <= 0 && $other <= 0 => 'card',
            $cash > 0 || $card > 0 => 'mixed',
            default => 'other',
        });

        $lines = $this->productLines((string) ($mappedData['lines'] ?? ''), $options);
        if ($lines === []) {
            $lines = $this->vatLines((string) ($mappedData['vatBreakdown'] ?? ''), $total, $vatTotal, $options);
        }
        $position = 0;
        $subtotal = 0.0;
        $vatSum = 0.0;
        foreach ($lines as $l) {
            $line = new ReceiptLine();
            $line->setPosition($position++);
            $line->setDescription(mb_substr($l['description'], 0, 500));
            $line->setQuantity(number_format($l['quantity'], 4, '.', ''));
            $line->setUnitOfMeasure('buc');
            $line->setUnitPrice(number_format($l['quantity'] > 0 ? $l['net'] / $l['quantity'] : $l['net'], 2, '.', ''));
            $line->setVatRate(number_format($l['rate'], 2, '.', ''));
            $line->setVatCategoryCode($l['rate'] > 0 ? 'S' : ($company->isVatPayer() ? 'E' : 'O'));
            $line->setVatAmount(number_format($l['vat'], 2, '.', ''));
            $line->setLineTotal(number_format($l['net'], 2, '.', ''));
            $receipt->addLine($line);
            $subtotal += $l['net'];
            $vatSum += $l['vat'];
        }
        $receipt->setSubtotal(number_format($subtotal, 2, '.', ''));
        $receipt->setVatTotal(number_format($vatSum, 2, '.', ''));
        $receipt->setTotal(number_format($total > 0 ? $total : $subtotal + $vatSum, 2, '.', ''));

        $this->entityManager->persist($receipt);
        $this->existingKeys[$key] = true;
        $this->receiptsCreated++;
        $result->incrementCreated();

        $day = &$this->days[$dayKey];
        $day['receipts']++;
        $day['total'] += (float) $receipt->getTotal();
        $day['vat'] += $vatSum;
        $day['cash'] += $cash;
        $day['card'] += $card;
        $day['other'] += $other;
        unset($day);

        if (++$this->batchCount >= self::BATCH_SIZE) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->batchCount = 0;
        $this->job = null;
    }

    public function reset(): void
    {
        $this->existingKeys = [];
        $this->companyRates = [];
        $this->days = [];
        $this->company = null;
        $this->job = null;
        $this->companyId = null;
        $this->batchCount = 0;
        $this->receiptsCreated = 0;
        $this->receiptsSkipped = 0;
        $this->zReports = 0;
    }

    public function getSummary(): array
    {
        ksort($this->days);
        $days = [];
        foreach ($this->days as $d) {
            $days[] = [
                'date' => $d['date'],
                'receipts' => $d['receipts'],
                'total' => number_format($d['total'], 2, '.', ''),
                'vat' => number_format($d['vat'], 2, '.', ''),
                'cash' => number_format($d['cash'], 2, '.', ''),
                'card' => number_format($d['card'], 2, '.', ''),
                'other' => number_format($d['other'], 2, '.', ''),
                'zReports' => $d['zReports'],
            ];
        }

        return [
            'receiptsCreated' => $this->receiptsCreated,
            'receiptsSkipped' => $this->receiptsSkipped,
            'zReports' => $this->zReports,
            'from' => $days[0]['date'] ?? null,
            'to' => $days !== [] ? $days[array_key_last($days)]['date'] : null,
            'total' => number_format(array_sum(array_map(fn ($d) => (float) $d['total'], $days)), 2, '.', ''),
            'days' => array_slice($days, 0, 62),
        ];
    }

    // -------------------------------------------------------------------------

    private function initialize(Company $company, ?ImportJob $job): void
    {
        $companyId = $company->getId()->toRfc4122();
        if ($this->companyId === $companyId) {
            $this->company = $company;
            $this->job ??= $job;

            return;
        }

        $this->company = $company;
        $this->companyId = $companyId;
        $this->job = $job;

        $keys = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT idempotency_key FROM receipt WHERE company_id = :c AND idempotency_key IS NOT NULL',
            ['c' => $companyId],
        );
        foreach ($keys as $k) {
            $this->existingKeys[$k] = true;
        }
        foreach ($this->vatRateRepository->findActiveByCompany($company) as $rate) {
            $this->companyRates[] = (float) $rate->getRate();
        }
    }

    /**
     * "3:20.00;1:15.50" → [cash, card, other]; no payments = all cash.
     *
     * @param array<string, mixed> $options
     * @return array{0: float, 1: float, 2: float}
     */
    private function payments(string $cell, float $total, array $options = []): array
    {
        $types = self::PAYMENT_TYPES;
        if (!empty($options['paymentTypes']) && is_array($options['paymentTypes'])) {
            foreach ($options['paymentTypes'] as $code => $method) {
                if (in_array($method, ['cash', 'card', 'other'], true)) {
                    $types[(string) $code] = $method;
                }
            }
        }

        $cash = $card = $other = 0.0;
        foreach (array_filter(explode(';', $cell)) as $part) {
            [$type, $amount] = array_pad(explode(':', $part, 2), 2, '0');
            $amount = (float) $amount;
            $method = $types[trim($type)] ?? (in_array(strtolower(trim($type)), ['cash', 'numerar'], true) ? 'cash' : (in_array(strtolower(trim($type)), ['card'], true) ? 'card' : 'other'));
            match ($method) {
                'cash' => $cash += $amount,
                'card' => $card += $amount,
                default => $other += $amount,
            };
        }
        if ($cash + $card + $other <= 0) {
            $cash = $total;
        }

        return [$cash, $card, $other];
    }

    /**
     * Product lines "den|cant|pret|val|cota;…" from a lenient export.
     *
     * @param array<string, mixed> $options
     * @return array<int, array{description: string, quantity: float, net: float, vat: float, rate: float}>
     */
    private function productLines(string $cell, array $options): array
    {
        $lines = [];
        foreach (array_filter(explode(';', $cell)) as $part) {
            [$name, $qty, $price, $value, $cota] = array_pad(explode('|', $part), 5, '');
            $qty = (float) $qty ?: 1.0;
            $gross = (float) $value ?: (float) $price * $qty;
            $rate = $this->resolveRate($cota, $options, null, null);
            $net = $rate > 0 ? round($gross / (1 + $rate / 100), 2) : $gross;
            $lines[] = ['description' => $name !== '' ? $name : 'Produs', 'quantity' => $qty, 'net' => $net, 'vat' => round($gross - $net, 2), 'rate' => $rate];
        }

        return $lines;
    }

    /**
     * One line per VAT level of the receipt: "cota:tva[:baza];…".
     *
     * @param array<string, mixed> $options
     * @return array<int, array{description: string, quantity: float, net: float, vat: float, rate: float}>
     */
    private function vatLines(string $cell, float $total, float $vatTotal, array $options): array
    {
        $entries = [];
        foreach (array_filter(explode(';', $cell)) as $part) {
            [$cota, $vat, $base] = array_pad(explode(':', $part, 3), 3, '');
            $entries[] = ['cota' => trim($cota), 'vat' => (float) $vat, 'base' => $base !== '' ? (float) $base : null];
        }

        $netTotal = round($total - $vatTotal, 2);
        if ($entries === []) {
            $rate = $netTotal > 0 ? $this->nearestRate($vatTotal / $netTotal * 100) : 0.0;

            return [['description' => $rate > 0 ? sprintf('Vânzări cotă TVA %s%%', $this->fmtRate($rate)) : 'Vânzări', 'quantity' => 1.0, 'net' => $netTotal, 'vat' => $vatTotal, 'rate' => $rate]];
        }

        $single = count($entries) === 1 ? $entries[0] : null;
        $lines = [];
        $assigned = 0.0;
        foreach ($entries as $e) {
            $rate = $this->resolveRate($e['cota'], $options, $single !== null ? $netTotal : null, $single !== null ? $e['vat'] : null);
            $base = $e['base'] ?? ($rate > 0 ? round($e['vat'] / ($rate / 100), 2) : null);
            $lines[] = ['description' => $rate > 0 ? sprintf('Vânzări cotă TVA %s%%', $this->fmtRate($rate)) : 'Vânzări scutite / cotă 0%', 'quantity' => 1.0, 'net' => $base ?? 0.0, 'vat' => $e['vat'], 'rate' => $rate, '_open' => $base === null];
            $assigned += $base ?? 0.0;
        }

        // The zero-rate level has no VAT to derive its base from: it takes what is left of the net total.
        $remainder = round($netTotal - $assigned, 2);
        $openIndex = null;
        foreach ($lines as $i => $l) {
            if ($l['_open']) {
                $openIndex = $i;
                break;
            }
        }
        if ($openIndex !== null) {
            $lines[$openIndex]['net'] = max($remainder, 0.0);
        } elseif (abs($remainder) > 0 && abs($remainder) <= 0.05 * max(count($lines), 1)) {
            // rounding of the register: put the cents on the largest line
            $largest = 0;
            foreach ($lines as $i => $l) {
                if ($l['net'] > $lines[$largest]['net']) {
                    $largest = $i;
                }
            }
            $lines[$largest]['net'] = round($lines[$largest]['net'] + $remainder, 2);
        }
        foreach ($lines as &$l) {
            unset($l['_open']);
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveRate(string $cota, array $options, ?float $singleNet, ?float $singleVat): float
    {
        $cota = trim($cota, " %");
        if ($cota === '') {
            return $this->defaultRate();
        }

        // letter groups A, B, C…
        if (preg_match('/^[A-Za-z]$/', $cota)) {
            $cota = (string) (ord(strtoupper($cota)) - ord('A') + 1);
        }

        $value = (float) str_replace(',', '.', $cota);
        $rates = array_unique(array_merge($this->companyRates, self::KNOWN_RATES));
        $isKnownRate = in_array($value, $rates, true);

        if ($isKnownRate && $singleNet !== null && $singleVat !== null && $singleNet > 0) {
            // A single level on the receipt: the amounts say whether the level is the percentage
            $implied = $singleVat / $singleNet * 100;
            if (abs($implied - $value) <= 1.0) {
                return $value;
            }
            $groups = $this->groups($options);
            if (isset($groups[(string) (int) $value]) && abs($implied - $groups[(string) (int) $value]) <= 1.0) {
                return $groups[(string) (int) $value];
            }

            return $value;
        }

        if ($isKnownRate && $value >= 5) {
            return $value;
        }

        $groups = $this->groups($options);
        if (isset($groups[(string) (int) $value])) {
            return $groups[(string) (int) $value];
        }

        return $isKnownRate ? $value : $this->defaultRate();
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, float>
     */
    private function groups(array $options): array
    {
        $groups = ['1' => $this->defaultRate(), '2' => 11.0, '3' => 5.0, '4' => 0.0];
        if (!empty($options['vatGroups']) && is_array($options['vatGroups'])) {
            foreach ($options['vatGroups'] as $k => $v) {
                if (is_numeric($v)) {
                    $groups[(string) (int) $k] = (float) $v;
                }
            }
        }

        return $groups;
    }

    private function nearestRate(float $computed): float
    {
        $rates = $this->companyRates ?: self::KNOWN_RATES;
        $best = $rates[0];
        foreach ($rates as $r) {
            if (abs($r - $computed) < abs($best - $computed)) {
                $best = $r;
            }
        }

        return abs($best - $computed) <= 1.5 ? $best : round($computed, 2);
    }

    private function defaultRate(): float
    {
        $default = $this->company !== null ? $this->vatRateRepository->findDefaultByCompany($this->company) : null;
        if ($default !== null) {
            return (float) $default->getRate();
        }

        return $this->companyRates === [] ? 21.0 : max($this->companyRates);
    }

    private function fmtRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }
}
