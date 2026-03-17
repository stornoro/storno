<?php

namespace App\Enum;

enum DeclarationType: string
{
    // Auto-populated from invoice data
    case D394 = 'd394';
    case D300 = 'd300';
    case D390 = 'd390';
    case D392 = 'd392';
    case D393 = 'd393';

    // Manual entry
    case D100 = 'd100';
    case D101 = 'd101';
    case D106 = 'd106';
    case D112 = 'd112';
    case D120 = 'd120';
    case D130 = 'd130';
    case D180 = 'd180';
    case D205 = 'd205';
    case D208 = 'd208';
    case D212 = 'd212';
    case D301 = 'd301';
    case D311 = 'd311';

    public function label(): string
    {
        return match ($this) {
            self::D394 => 'D394 - Declaratie informativa livr/achiz',
            self::D300 => 'D300 - Decont TVA',
            self::D390 => 'D390 - Declaratie recapitulativa VIES',
            self::D392 => 'D392 - Declaratie informativa operatiuni intracomunitare',
            self::D393 => 'D393 - Declaratie informativa VIES servicii',
            self::D100 => 'D100 - Obligatii plata buget de stat',
            self::D101 => 'D101 - Impozit pe profit',
            self::D106 => 'D106 - Declaratie informativa dividende actionari',
            self::D112 => 'D112 - Declaratie unica (CAS/CASS/impozit salarii)',
            self::D120 => 'D120 - Decont accize',
            self::D130 => 'D130 - Decont impozit titei productie interna',
            self::D180 => 'D180 - Nota de certificare (consultant fiscal)',
            self::D205 => 'D205 - Declaratie informativa retineri la sursa',
            self::D208 => 'D208 - Declaratie informativa transferuri imobiliare',
            self::D212 => 'D212 - Declaratie unica PF',
            self::D301 => 'D301 - Decont special TVA',
            self::D311 => 'D311 - Declaratie TVA colectat (cod anulat)',
        };
    }

    public function periodType(): string
    {
        return match ($this) {
            self::D394, self::D300, self::D390, self::D392, self::D393 => 'monthly',
            self::D100, self::D112, self::D130, self::D180, self::D301 => 'monthly',
            self::D101, self::D106, self::D120, self::D311 => 'quarterly',
            self::D205, self::D208, self::D212 => 'annual',
        };
    }

    public function canAutoPopulate(): bool
    {
        return match ($this) {
            self::D394, self::D300, self::D390, self::D392, self::D393 => true,
            default => false,
        };
    }
}
