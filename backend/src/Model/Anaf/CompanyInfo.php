<?php

namespace App\Model\Anaf;

class CompanyInfo
{
    const DEFAULT_COUNTRY = 'RO';

    private int $cif;
    private string $name;
    private string $address;
    private string $city;
    private string $state;
    private string $country;
    private ?string $sector = null;
    private ?string $postalCode = null;
    private ?string $phone = null;
    private ?string $registrationNumber = null;
    private ?string $caenCode = null;
    private bool $vatPayer = false;
    private ?string $vatCode = null;
    private ?string $registrationStatus = null;
    private ?string $registrationDate = null;
    private bool $eFacturaEnabled = false;
    private ?string $eFacturaDate = null;
    private bool $inactive = false;
    private bool $splitVat = false;
    private bool $vatOnCollection = false;
    private ?string $legalForm = null;
    private ?string $ownershipForm = null;
    private ?string $organizationForm = null;
    private ?string $fiscalAuthority = null;
    private ?string $iban = null;
    private array $rawData = [];

    public function __construct(int $cui, string $name, string $address, string $state, string $city)
    {
        $this->cif = $cui;
        $this->name = $name;
        $this->address = $address;
        $this->state = $state;
        $this->city = $city;
        $this->country = self::DEFAULT_COUNTRY;
    }

    public static function createFromAnaf(array $data): ?self
    {
        if (empty($data['found'])) {
            return null;
        }

        $c = $data['found'][0];
        $g = $c['date_generale'];
        $a = $c['adresa_sediu_social'];
        $tva = $c['inregistrare_scop_Tva'] ?? [];
        $inactiv = $c['stare_inactiv'] ?? [];
        $split = $c['inregistrare_SplitTVA'] ?? [];
        $tvaInc = $c['inregistrare_RTVAI'] ?? [];

        $info = new self(
            (int) $g['cui'],
            $g['denumire'],
            $g['adresa'],
            $a['scod_JudetAuto'],
            $a['sdenumire_Localitate'],
        );

        $info->postalCode = $g['codPostal'] ?: ($a['scod_Postal'] ?: null);
        $info->phone = !empty($g['telefon']) ? $g['telefon'] : null;
        $info->registrationNumber = !empty($g['nrRegCom']) ? $g['nrRegCom'] : null;
        $info->caenCode = !empty($g['cod_CAEN']) ? $g['cod_CAEN'] : null;
        $info->registrationStatus = $g['stare_inregistrare'] ?? null;
        $info->registrationDate = !empty($g['data_inregistrare']) ? $g['data_inregistrare'] : null;
        $info->legalForm = !empty($g['forma_juridica']) ? $g['forma_juridica'] : null;
        $info->ownershipForm = !empty($g['forma_de_proprietate']) ? $g['forma_de_proprietate'] : null;
        $info->organizationForm = !empty($g['forma_organizare']) ? $g['forma_organizare'] : null;
        $info->fiscalAuthority = !empty($g['organFiscalCompetent']) ? $g['organFiscalCompetent'] : null;
        $info->iban = !empty($g['iban']) ? $g['iban'] : null;

        // VAT status
        $info->vatPayer = (bool) ($tva['scpTVA'] ?? false);
        if ($info->vatPayer) {
            $info->vatCode = 'RO' . $g['cui'];
        }

        // e-Factura
        $info->eFacturaEnabled = (bool) ($g['statusRO_e_Factura'] ?? false);
        $info->eFacturaDate = !empty($g['data_inreg_Reg_RO_e_Factura']) ? $g['data_inreg_Reg_RO_e_Factura'] : null;

        // Status flags
        $info->inactive = (bool) ($inactiv['statusInactivi'] ?? false);
        $info->splitVat = (bool) ($split['statusSplitTVA'] ?? false);
        $info->vatOnCollection = (bool) ($tvaInc['statusTvaIncasare'] ?? false);

        $info->rawData = $c;

        return $info;
    }

    // --- Getters ---

    public function getCif(): int
    {
        return $this->cif;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function getSector(): ?string
    {
        preg_match('/SECTOR\s(\d)/', strtoupper($this->address . ' ' . $this->city), $match);

        if (!empty($match)) {
            return 'SECTOR' . $match[1];
        }

        return $this->sector;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getRegistrationNumber(): ?string
    {
        return $this->registrationNumber;
    }

    public function getCaenCode(): ?string
    {
        return $this->caenCode;
    }

    public function isVatPayer(): bool
    {
        return $this->vatPayer;
    }

    public function getVatCode(): ?string
    {
        return $this->vatCode;
    }

    public function getRegistrationStatus(): ?string
    {
        return $this->registrationStatus;
    }

    public function getRegistrationDate(): ?string
    {
        return $this->registrationDate;
    }

    public function isEFacturaEnabled(): bool
    {
        return $this->eFacturaEnabled;
    }

    public function getEFacturaDate(): ?string
    {
        return $this->eFacturaDate;
    }

    public function isInactive(): bool
    {
        return $this->inactive;
    }

    public function isSplitVat(): bool
    {
        return $this->splitVat;
    }

    public function isVatOnCollection(): bool
    {
        return $this->vatOnCollection;
    }

    public function getLegalForm(): ?string
    {
        return $this->legalForm;
    }

    public function getOwnershipForm(): ?string
    {
        return $this->ownershipForm;
    }

    public function getOrganizationForm(): ?string
    {
        return $this->organizationForm;
    }

    public function getFiscalAuthority(): ?string
    {
        return $this->fiscalAuthority;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }
}
