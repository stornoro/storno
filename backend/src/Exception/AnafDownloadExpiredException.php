<?php

declare(strict_types=1);

namespace App\Exception;

/** ANAF keeps a message's file for 60 days; after that the download returns a plain-text refusal instead of the zip. */
class AnafDownloadExpiredException extends \RuntimeException
{
    public function __construct(public readonly string $messageId, string $anafText)
    {
        parent::__construct(sprintf('ANAF message %s can no longer be downloaded (60-day window passed): %s', $messageId, $anafText));
    }
}
