<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched after an admin updates an app_version_override row when the
 * caller asked for notifications to fan out to affected users. The
 * handler queries telemetry for everyone currently on the platform with
 * a stale version, creates an in-app notification (which fans out to
 * Centrifugo + push for free) tier-appropriately, and respects quiet
 * hours for the recommended tier only.
 */
final class BroadcastVersionGateMessage
{
    public function __construct(
        public readonly string $platform,
        public readonly string $triggeredByUserId,
    ) {
    }
}
