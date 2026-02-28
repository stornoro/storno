<?php

namespace App\Service\EInvoice;

interface EInvoiceConnectionTesterInterface
{
    /**
     * @return array{success: bool, error: ?string}
     */
    public function test(array $config): array;
}
