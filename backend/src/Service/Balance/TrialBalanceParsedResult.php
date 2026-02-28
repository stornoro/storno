<?php

namespace App\Service\Balance;

final class TrialBalanceParsedResult
{
    public ?int $year = null;
    public ?int $month = null;
    public ?string $sourceSoftware = null;
    public ?string $companyCui = null;

    /** @var array<array{accountCode: string, accountName: string, initialDebit: string, initialCredit: string, previousDebit: string, previousCredit: string, currentDebit: string, currentCredit: string, totalDebit: string, totalCredit: string, finalDebit: string, finalCredit: string}> */
    public array $rows = [];
}
