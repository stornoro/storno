<?php

namespace App\Message;

final readonly class GenerateBackupMessage
{
    public function __construct(
        public string $backupJobId,
        public string $companyId,
        public string $userId,
        public bool $includeFiles = true,
        public bool $includeSoftDeleted = false,
    ) {
    }
}
