<?php

namespace App\Message;

final readonly class RestoreBackupMessage
{
    public function __construct(
        public string $backupJobId,
        public string $companyId,
        public string $userId,
        public bool $purgeExisting = false,
    ) {
    }
}
