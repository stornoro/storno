<?php

namespace App\Service\Borderou\Pdf;

class PdfStatementNotRecognizedException extends \RuntimeException
{
    public function __construct(string $message = 'Formatul extrasului PDF nu a fost recunoscut. Verificati ca fisierul este un extras de cont emis de banca, nu o scanare.')
    {
        parent::__construct($message);
    }
}
