<?php

namespace App\Enum;

class NotificationChannels
{
    const GENERAL = "general";
    const ERROR = "error";
    const WARNING = "warning";
    const TELEGRAM = "telegram";
    const WHATSAPP = "whatsapp";

    const CHANNELS = [
        self::GENERAL,
        self::ERROR,
        self::WARNING,
        self::TELEGRAM,
        self::WHATSAPP,
    ];

    const NOTIFICATION_FROM_SYSTEM = "system";
}
