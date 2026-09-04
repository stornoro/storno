<?php

declare(strict_types=1);

namespace App\Service\Declaration\Forms;

final class FormBuildResult
{
    /** @param list<array{level: 'error'|'warning'|'info', code: string, field: string, message: string}> $issues */
    public function __construct(
        public readonly string $xml,
        public readonly array $issues,
    ) {
    }

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue['level'] === 'error') {
                return true;
            }
        }

        return false;
    }
}
