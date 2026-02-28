<?php

namespace App\Enum;

enum EmailEventType: string
{
    case SEND = 'send';
    case DELIVERY = 'delivery';
    case BOUNCE = 'bounce';
    case COMPLAINT = 'complaint';
    case REJECT = 'reject';
    case OPEN = 'open';
    case CLICK = 'click';

    public function label(): string
    {
        return match ($this) {
            self::SEND => 'Trimis',
            self::DELIVERY => 'Livrat',
            self::BOUNCE => 'Respins',
            self::COMPLAINT => 'Reclamatie',
            self::REJECT => 'Respins',
            self::OPEN => 'Deschis',
            self::CLICK => 'Click',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::SEND => 'send',
            self::DELIVERY => 'check-circle',
            self::BOUNCE => 'alert-triangle',
            self::COMPLAINT => 'flag',
            self::REJECT => 'x-circle',
            self::OPEN => 'eye',
            self::CLICK => 'mouse-pointer-click',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SEND => 'blue',
            self::DELIVERY => 'green',
            self::BOUNCE => 'orange',
            self::COMPLAINT => 'red',
            self::REJECT => 'red',
            self::OPEN => 'info',
            self::CLICK => 'info',
        };
    }
}
