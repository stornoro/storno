<?php

namespace App\Exception;

class AnafRateLimitException extends \RuntimeException
{
    public function __construct(
        public readonly int $retryAfter,
        public readonly string $limitName,
    ) {
        parent::__construct(sprintf(
            'ANAF rate limit "%s" exceeded. Retry after %d seconds.',
            $limitName,
            $retryAfter,
        ));
    }
}
