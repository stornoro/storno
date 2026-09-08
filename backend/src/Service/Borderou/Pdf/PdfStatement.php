<?php

namespace App\Service\Borderou\Pdf;

/**
 * A parsed bank statement (one account, one currency).
 */
final class PdfStatement
{
    /**
     * @param PdfStatementTransaction[] $transactions
     * @param string[] $warnings
     */
    public function __construct(
        public readonly string $bankKey,
        public readonly string $bankLabel,
        public readonly ?string $iban,
        public readonly string $currency,
        public readonly array $transactions,
        public readonly ?string $accountHolder = null,
        public readonly ?string $fiscalCode = null,
        public readonly ?string $openingBalance = null,
        public readonly ?string $closingBalance = null,
        public readonly ?\DateTimeImmutable $periodStart = null,
        public readonly ?\DateTimeImmutable $periodEnd = null,
        public readonly array $warnings = [],
    ) {}

    public function withWarnings(array $warnings): self
    {
        return new self(
            $this->bankKey,
            $this->bankLabel,
            $this->iban,
            $this->currency,
            $this->transactions,
            $this->accountHolder,
            $this->fiscalCode,
            $this->openingBalance,
            $this->closingBalance,
            $this->periodStart,
            $this->periodEnd,
            array_values(array_unique(array_merge($this->warnings, $warnings))),
        );
    }
}
