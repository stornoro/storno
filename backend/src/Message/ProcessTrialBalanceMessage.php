<?php

namespace App\Message;

final readonly class ProcessTrialBalanceMessage
{
    public function __construct(
        public string $trialBalanceId,
    ) {
    }
}
