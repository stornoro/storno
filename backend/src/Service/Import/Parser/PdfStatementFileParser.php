<?php

namespace App\Service\Import\Parser;

use App\Service\Borderou\Pdf\PdfStatement;
use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use App\Service\Borderou\Pdf\PdfStatementTransaction;
use App\Service\Borderou\Pdf\PdfWordExtractor;

/**
 * FileParserInterface for bank statement PDFs.
 *
 * Runs the bank-specific PDF parsers and exposes the result as flat rows with
 * fixed headers, so the borderou pipeline treats a PDF like any CSV. Statement
 * level data (bank, IBAN, currency, holder, warnings) goes into preview()['metadata'].
 */
class PdfStatementFileParser implements FileParserInterface
{
    public const HEADER_MARKER = '__pdf_statement';
    public const COL_DATE = 'Data';
    public const COL_VALUE_DATE = 'Data valuta';
    public const COL_DESCRIPTION = 'Descriere';
    public const COL_REFERENCE = 'Referinta';
    public const COL_DEBIT = 'Debit';
    public const COL_CREDIT = 'Credit';
    public const COL_CURRENCY = 'Valuta';
    public const COL_COUNTERPARTY = 'Contrapartida';
    public const COL_COUNTERPARTY_IBAN = 'IBAN contrapartida';
    public const COL_BALANCE = 'Sold';
    public const COL_IBAN = 'Cont';

    public const HEADERS = [
        self::HEADER_MARKER,
        self::COL_DATE,
        self::COL_VALUE_DATE,
        self::COL_DESCRIPTION,
        self::COL_REFERENCE,
        self::COL_DEBIT,
        self::COL_CREDIT,
        self::COL_CURRENCY,
        self::COL_COUNTERPARTY,
        self::COL_COUNTERPARTY_IBAN,
        self::COL_BALANCE,
        self::COL_IBAN,
    ];

    /** @var array<string, array{statements: PdfStatement[], mtime: int|false}> */
    private array $cache = [];

    public function __construct(
        private readonly PdfWordExtractor $extractor,
        private readonly PdfStatementDispatcher $dispatcher,
    ) {}

    public function supports(string $fileFormat): bool
    {
        return $fileFormat === 'pdf';
    }

    public function parse(string $filePath): \Generator
    {
        foreach ($this->statements($filePath) as $statement) {
            foreach ($statement->transactions as $tx) {
                yield $this->toRow($statement, $tx);
            }
        }
    }

    public function preview(string $filePath, int $maxRows = 20): array
    {
        $statements = $this->statements($filePath);
        $rows = [];
        foreach ($this->parse($filePath) as $row) {
            $rows[] = $row;
            if (count($rows) >= $maxRows) {
                break;
            }
        }

        return [
            'headers' => self::HEADERS,
            'rows' => $rows,
            'metadata' => $this->metadata($statements),
        ];
    }

    public function countRows(string $filePath): int
    {
        $n = 0;
        foreach ($this->statements($filePath) as $statement) {
            $n += count($statement->transactions);
        }

        return $n;
    }

    /**
     * @return PdfStatement[]
     */
    public function statements(string $filePath): array
    {
        $mtime = @filemtime($filePath);
        if (isset($this->cache[$filePath]) && $this->cache[$filePath]['mtime'] === $mtime) {
            return $this->cache[$filePath]['statements'];
        }

        $pages = $this->extractor->extract($filePath);
        $statements = $this->dispatcher->parse($pages, $filePath);
        $this->cache[$filePath] = ['statements' => $statements, 'mtime' => $mtime];

        return $statements;
    }

    /**
     * @param PdfStatement[] $statements
     * @return array<string, mixed>
     */
    public function metadata(array $statements): array
    {
        $first = $statements[0] ?? null;
        if (!$first) {
            return [];
        }
        $warnings = [];
        $ibans = [];
        $currencies = [];
        foreach ($statements as $s) {
            $warnings = array_merge($warnings, $s->warnings);
            if ($s->iban) {
                $ibans[] = $s->iban;
            }
            $currencies[] = $s->currency;
        }

        return [
            self::HEADER_MARKER => true,
            'bank' => $first->bankKey,
            'bank_label' => $first->bankLabel,
            // Same keys the BT CSV preamble uses, so extractIban() logic stays uniform.
            'Numar cont' => $first->iban,
            'Moneda cont' => $first->currency,
            'ibans' => array_values(array_unique($ibans)),
            'currencies' => array_values(array_unique($currencies)),
            'account_holder' => $first->accountHolder,
            'fiscal_code' => $first->fiscalCode,
            'opening_balance' => $first->openingBalance,
            'closing_balance' => $first->closingBalance,
            'period_start' => $first->periodStart?->format('Y-m-d'),
            'period_end' => $first->periodEnd?->format('Y-m-d'),
            'statements' => count($statements),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function toRow(PdfStatement $statement, PdfStatementTransaction $tx): array
    {
        return [
            self::HEADER_MARKER => $statement->bankKey,
            self::COL_DATE => $tx->date->format('Y-m-d'),
            self::COL_VALUE_DATE => $tx->valueDate?->format('Y-m-d') ?? '',
            self::COL_DESCRIPTION => $tx->description,
            self::COL_REFERENCE => $tx->reference ?? '',
            self::COL_DEBIT => $tx->debit,
            self::COL_CREDIT => $tx->credit,
            self::COL_CURRENCY => $tx->currency ?? $statement->currency,
            self::COL_COUNTERPARTY => $tx->counterpartyName ?? '',
            self::COL_COUNTERPARTY_IBAN => $tx->counterpartyIban ?? '',
            self::COL_BALANCE => $tx->balance ?? '',
            self::COL_IBAN => $statement->iban ?? '',
        ];
    }
}
