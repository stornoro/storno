<?php

namespace App\DTO\Sync;

class ParsedParty
{
    public function __construct(
        public readonly ?string $cif = null,
        public readonly ?string $name = null,
        public readonly ?string $vatCode = null,
        public readonly ?string $registrationNumber = null,
        public readonly ?string $address = null,
        public readonly ?string $city = null,
        public readonly ?string $county = null,
        public readonly string $country = 'RO',
        public readonly ?string $postalCode = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?string $bankAccount = null,
        public readonly ?string $bankName = null,
    ) {}

    public function isVatPayer(): bool
    {
        return $this->vatCode !== null && $this->vatCode !== '';
    }
}
