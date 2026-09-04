<?php

namespace App\Service\Spv;

/** ANAF returned something that is not a PDF/ZIP (typically the F5 logout page). */
final class SpvNotADocumentException extends \RuntimeException
{
}
