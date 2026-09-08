<?php

namespace App\Service\Borderou\Pdf;

class PdfPasswordRequiredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Extrasul PDF este protejat cu parola.');
    }
}
