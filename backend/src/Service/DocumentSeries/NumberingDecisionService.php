<?php

namespace App\Service\DocumentSeries;

use App\Entity\Company;
use App\Entity\DocumentSeries;
use App\Repository\DocumentSeriesRepository;

/**
 * Builds the yearly internal numbering decision (decizia de numerotare): the
 * document every company must hold that names the person responsible for
 * allocating document numbers and lists, per document type, the series and
 * the number range allocated for the year.
 */
class NumberingDecisionService
{
    public const DEFAULT_RANGE_SIZE = 9999;
    public const MAX_RANGE_SIZE = 9999999;

    /** @var array<string, string> series type => Romanian document label */
    public const TYPE_LABELS = [
        'invoice' => 'Factură',
        'credit_note' => 'Factură storno (notă de credit)',
        'proforma' => 'Factură proformă',
        'delivery_note' => 'Aviz de însoțire a mărfii',
        'receipt' => 'Chitanță',
        'voucher' => 'Bon fiscal',
    ];

    /** Display order of the document types in the decision table. */
    private const TYPE_ORDER = ['invoice', 'credit_note', 'proforma', 'delivery_note', 'receipt', 'voucher'];

    public function __construct(
        private readonly DocumentSeriesRepository $documentSeriesRepository,
    ) {}

    /**
     * @param array{
     *     decisionNumber?: int|string|null,
     *     decisionDate?: string|null,
     *     responsible?: string|null,
     *     rangeSize?: int|string|null,
     *     rangeSizes?: array<string, int>|null
     * } $options rangeSizes is keyed by series prefix and overrides rangeSize per series
     *
     * @throws \InvalidArgumentException on an invalid year, date, number or range size
     */
    public function build(Company $company, int $year, array $options = []): array
    {
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('year must be between 2000 and 2100.');
        }

        $decisionNumber = self::intOption($options['decisionNumber'] ?? null, 1, 1, 999999, 'decisionNumber');
        $rangeSize = self::intOption($options['rangeSize'] ?? null, self::DEFAULT_RANGE_SIZE, 1, self::MAX_RANGE_SIZE, 'rangeSize');
        $decisionDate = self::dateOption($options['decisionDate'] ?? null, $year);

        $representative = trim((string) $company->getRepresentative());
        $responsible = trim((string) ($options['responsible'] ?? ''));
        if ($responsible === '') {
            $responsible = $representative;
        }
        if (mb_strlen($responsible) > 200) {
            throw new \InvalidArgumentException('responsible must be at most 200 characters.');
        }

        $rangeSizes = [];
        foreach ((array) ($options['rangeSizes'] ?? []) as $prefix => $size) {
            $rangeSizes[(string) $prefix] = self::intOption($size, $rangeSize, 1, self::MAX_RANGE_SIZE, 'rangeSizes.' . $prefix);
        }

        $rows = [];
        foreach ($this->documentSeriesRepository->findByCompany($company) as $series) {
            $issued = $this->documentSeriesRepository->findIssuedNumbersForYear($series, $year);
            if (!$series->isActive() && $issued === []) {
                continue;
            }
            $rows[] = $this->row($series, $issued, $rangeSizes[$series->getPrefix()] ?? $rangeSize);
        }

        usort($rows, static function (array $a, array $b): int {
            $ta = array_search($a['type'], self::TYPE_ORDER, true);
            $tb = array_search($b['type'], self::TYPE_ORDER, true);
            $ta = $ta === false ? PHP_INT_MAX : $ta;
            $tb = $tb === false ? PHP_INT_MAX : $tb;

            return [$ta, $b['isDefault'], $a['prefix']] <=> [$tb, $a['isDefault'], $b['prefix']];
        });

        return [
            'year' => $year,
            'decisionNumber' => $decisionNumber,
            'decisionDate' => $decisionDate->format('Y-m-d'),
            'legalBasis' => self::legalBasis($year),
            'company' => [
                'id' => $company->getId()?->toRfc4122(),
                'name' => $company->getName(),
                'cif' => $company->getCif() > 0 ? (string) $company->getCif() : null,
                'vatCode' => $company->getVatCode(),
                'registrationNumber' => $company->getRegistrationNumber(),
                'address' => self::address($company),
                'representative' => $representative !== '' ? $representative : null,
                'representativeRole' => $company->getRepresentativeRole() ?: 'Administrator',
            ],
            'responsible' => $responsible !== '' ? $responsible : null,
            'rangeSize' => $rangeSize,
            'rows' => $rows,
            'warnings' => $this->warnings($company, $rows, $responsible),
        ];
    }

    /**
     * @param int[] $issued sequence numbers already issued in the year
     */
    private function row(DocumentSeries $series, array $issued, int $rangeSize): array
    {
        if ($issued === []) {
            $first = $series->getCurrentNumber() + 1;
            $last = $first + $rangeSize - 1;
        } else {
            $first = min($issued);
            $last = max($first + $rangeSize - 1, max($issued));
        }

        return [
            'seriesId' => $series->getId()?->toRfc4122(),
            'type' => $series->getType(),
            'typeLabel' => self::TYPE_LABELS[$series->getType()] ?? ucfirst(str_replace('_', ' ', $series->getType())),
            'prefix' => $series->getPrefix(),
            'firstNumber' => $first,
            'lastNumber' => $last,
            'firstFormatted' => $series->formatNumber($first),
            'lastFormatted' => $series->formatNumber($last),
            'formatExample' => $series->formatNumber($first),
            'issuedCount' => count($issued),
            'isDefault' => $series->isDefault(),
            'active' => $series->isActive(),
        ];
    }

    /**
     * @return array{code: string, title: string, text: string}
     */
    public static function legalBasis(int $year): array
    {
        if ($year >= 2016) {
            return [
                'code' => 'OMFP 2634/2015',
                'title' => 'Ordinul ministrului finanțelor publice nr. 2634/2015 privind documentele financiar-contabile',
                'text' => 'În temeiul Legii contabilității nr. 82/1991, republicată, cu modificările și completările ulterioare, '
                    . 'al Ordinului ministrului finanțelor publice nr. 2634/2015 privind documentele financiar-contabile '
                    . '(Anexa nr. 1 – Norme generale de întocmire și utilizare a documentelor financiar-contabile) '
                    . 'și al art. 319 din Legea nr. 227/2015 privind Codul fiscal, cu modificările și completările ulterioare,',
            ];
        }

        return [
            'code' => 'OMEF 2226/2006',
            'title' => 'Ordinul ministrului economiei și finanțelor nr. 2226/2006 privind utilizarea unor formulare financiar-contabile de către persoanele prevăzute la art. 1 din Legea contabilității nr. 82/1991',
            'text' => 'În temeiul Legii contabilității nr. 82/1991, republicată, cu modificările și completările ulterioare, '
                . 'și al Ordinului ministrului economiei și finanțelor nr. 2226/2006 privind utilizarea unor formulare financiar-contabile '
                . 'de către persoanele prevăzute la art. 1 din Legea contabilității nr. 82/1991,',
        ];
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    private function warnings(Company $company, array $rows, string $responsible): array
    {
        $warnings = [];
        if ($rows === []) {
            $warnings[] = ['code' => 'NO_SERIES', 'message' => 'Compania nu are nicio serie de documente activă.'];
        }
        if ($responsible === '') {
            $warnings[] = ['code' => 'NO_RESPONSIBLE', 'message' => 'Nu este setat reprezentantul companiei; completați persoana responsabilă.'];
        }
        if (!$company->getRegistrationNumber()) {
            $warnings[] = ['code' => 'NO_REGISTRATION_NUMBER', 'message' => 'Numărul de înregistrare la Registrul Comerțului lipsește din datele companiei.'];
        }

        return $warnings;
    }

    private static function address(Company $company): ?string
    {
        $parts = array_filter([
            trim((string) $company->getAddress()),
            trim((string) $company->getCity()),
            trim((string) $company->getState()),
        ], static fn (string $p) => $p !== '');
        $country = trim((string) $company->getCountry());
        if ($country !== '' && strtoupper($country) !== 'RO' && strtolower($country) !== 'românia' && strtolower($country) !== 'romania') {
            $parts[] = $country;
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    private static function intOption(mixed $value, int $default, int $min, int $max, string $name): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (\is_int($value)) {
            $int = $value;
        } elseif (\is_string($value) && preg_match('/^\d+$/', trim($value))) {
            $int = (int) trim($value);
        } else {
            throw new \InvalidArgumentException(sprintf('%s must be an integer.', $name));
        }
        if ($int < $min || $int > $max) {
            throw new \InvalidArgumentException(sprintf('%s must be between %d and %d.', $name, $min, $max));
        }

        return $int;
    }

    private static function dateOption(mixed $value, int $year): \DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return new \DateTimeImmutable(sprintf('%04d-01-01', $year));
        }
        if (!\is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException('decisionDate must be a date formatted YYYY-MM-DD.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('decisionDate is not a valid date.');
        }

        return $date;
    }
}
