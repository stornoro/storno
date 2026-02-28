<?php

namespace App\Enum;

enum ShareTokenStatus: string
{
    case ACTIVE = 'active';
    case REVOKED = 'revoked';
    case EXPIRED = 'expired';
}
