<?php

namespace App\Service\Borderou\Pdf\Bank;

use App\Service\Borderou\Pdf\PdfStatementTransaction;

/**
 * Accumulates the lines of one BCR statement row and derives the counterparty
 * from the "Platitor:" / "Beneficiar:" segments BCR prints in the explanation.
 */
final class BcrRowBuilder
{
    private array $raw;

    public function __construct(
        private readonly \DateTimeImmutable $date,
        private string $description,
        private string $referenceCell,
        private readonly string $debit,
        private readonly string $credit,
        private readonly string $closing,
        string $rawLine,
    ) {
        $this->raw = [$rawLine];
    }

    public function continueWith(string $descriptionPart, string $referencePart, string $rawLine): void
    {
        if ($descriptionPart !== '') {
            $this->description = trim($this->description . ' ' . $descriptionPart);
        }
        if ($referencePart !== '') {
            $this->referenceCell = trim($this->referenceCell . ' ' . $referencePart);
        }
        $this->raw[] = $rawLine;
    }

    public function build(): PdfStatementTransaction
    {
        $reference = null;
        if (preg_match('/^(\S+)/', $this->referenceCell, $m)) {
            $reference = $m[1];
        }
        $valueDate = null;
        if (preg_match('/\b(\d{2})\.(\d{2})\.(\d{4})\b/', $this->referenceCell, $m)) {
            $valueDate = \DateTimeImmutable::createFromFormat('!d.m.Y', "$m[1].$m[2].$m[3]") ?: null;
        }

        // Counterparty: for incoming money the payer, for outgoing the beneficiary.
        $isCredit = bccomp($this->credit, '0', 2) > 0;
        $label = $isCredit ? 'Platitor' : 'Beneficiar';
        $name = null;
        $iban = null;
        if (preg_match('/' . $label . ':\s*([^;]+);\s*([A-Z]{2}\d{2}[A-Z0-9 ]{10,34}?)(?=;|\s*-|\s*CODFISC|$)/iu', $this->description, $m)) {
            $name = trim($m[1]);
            $iban = preg_replace('/\s+/', '', strtoupper($m[2]));
        } elseif (preg_match('/' . $label . ':\s*([^;]+?)(?:;|-Detalii|$)/iu', $this->description, $m)) {
            $name = trim($m[1]);
        }

        return new PdfStatementTransaction(
            $this->date,
            preg_replace('/\s{2,}/', ' ', $this->description) ?? $this->description,
            $this->debit,
            $this->credit,
            $reference,
            $valueDate,
            $name,
            $iban,
            $this->closing,
            null,
            $this->raw,
        );
    }
}
