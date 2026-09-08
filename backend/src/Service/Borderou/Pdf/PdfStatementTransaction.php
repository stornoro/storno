<?php

namespace App\Service\Borderou\Pdf;

/**
 * One statement line, normalised. Amounts are decimal strings ("1234.56"),
 * never negative: money leaving the account is $debit, money arriving is $credit.
 */
final class PdfStatementTransaction
{
    /**
     * @param string[] $rawLines the text lines the transaction was built from
     */
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly string $description,
        public readonly string $debit = '0.00',
        public readonly string $credit = '0.00',
        public readonly ?string $reference = null,
        public readonly ?\DateTimeImmutable $valueDate = null,
        public readonly ?string $counterpartyName = null,
        public readonly ?string $counterpartyIban = null,
        public readonly ?string $balance = null,
        public readonly ?string $currency = null,
        public readonly array $rawLines = [],
    ) {}

    public function isCredit(): bool
    {
        return bccomp($this->credit, '0', 2) > 0;
    }

    public function isDebit(): bool
    {
        return bccomp($this->debit, '0', 2) > 0;
    }
}
