<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\BroadcastVersionGateMessage;
use App\Service\NotificationService;
use App\Service\VersionGateService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fans out version-gate notifications to every user currently on the
 * given platform whose last reported app_version is below the effective
 * latest (recommended tier) or min (blocking tier). Source of truth for
 * "currently on the platform" is the telemetry feed — users who haven't
 * pinged us recently won't be notified, but they'll see the gate the
 * next time they open the app anyway.
 */
#[AsMessageHandler]
class BroadcastVersionGateHandler
{
    /** Only target users seen in telemetry within this many days. */
    private const ACTIVITY_WINDOW_DAYS = 30;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationService $notificationService,
        private readonly VersionGateService $versionGate,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(BroadcastVersionGateMessage $message): void
    {
        $platform = $message->platform;
        $config = $this->versionGate->effectiveConfigFor($platform);
        if ($config === null) {
            $this->logger->warning('Version gate broadcast skipped — unknown platform.', [
                'platform' => $platform,
            ]);
            return;
        }

        $min = (string) ($config['min'] ?? '0.0.0');
        $latest = (string) ($config['latest'] ?? '0.0.0');
        $message_map = is_array($config['message'] ?? null) ? $config['message'] : [];

        $rows = $this->fetchActiveUsers($platform);
        if ($rows === []) {
            $this->logger->info('Version gate broadcast — no active users on platform.', [
                'platform' => $platform,
            ]);
            return;
        }

        $userRepo = $this->entityManager->getRepository(User::class);
        $sent = ['blocking' => 0, 'recommended' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            $userId = $row['user_id'];
            $version = (string) $row['app_version'];

            $tier = $this->resolveTier($version, $min, $latest);
            if ($tier === VersionGateService::TIER_OK || $tier === VersionGateService::TIER_UNKNOWN) {
                $sent['skipped']++;
                continue;
            }

            $user = $userRepo->find($userId);
            if ($user === null || !$user->isActive()) {
                $sent['skipped']++;
                continue;
            }

            $this->sendForUser($user, $platform, $tier, $version, $latest, $message_map);
            $sent[$tier]++;
        }

        $this->logger->info('Version gate broadcast complete.', [
            'platform' => $platform,
            'triggeredBy' => $message->triggeredByUserId,
            'counts' => $sent,
        ]);
    }

    /**
     * @return array<int, array{user_id: string, app_version: string}>
     */
    private function fetchActiveUsers(string $platform): array
    {
        // Pull the most recent app_version per user on this platform within
        // the activity window. The subquery uses the (user_id, created_at)
        // index defined in the TelemetryEvent entity.
        $sql = <<<SQL
            SELECT t.user_id, t.app_version
            FROM telemetry_event t
            INNER JOIN (
                SELECT user_id, MAX(created_at) AS most_recent
                FROM telemetry_event
                WHERE platform = :platform
                  AND created_at >= :since
                  AND app_version IS NOT NULL
                GROUP BY user_id
            ) latest ON latest.user_id = t.user_id AND latest.most_recent = t.created_at
            WHERE t.platform = :platform
        SQL;

        $since = (new \DateTimeImmutable())
            ->sub(new \DateInterval('P' . self::ACTIVITY_WINDOW_DAYS . 'D'))
            ->format('Y-m-d H:i:s');

        return $this->connection->fetchAllAssociative($sql, [
            'platform' => $platform,
            'since' => $since,
        ]);
    }

    private function resolveTier(string $clientVersion, string $min, string $latest): string
    {
        if (version_compare($clientVersion, $min, '<')) {
            return VersionGateService::TIER_BLOCKING;
        }
        if (version_compare($clientVersion, $latest, '<')) {
            return VersionGateService::TIER_RECOMMENDED;
        }
        return VersionGateService::TIER_OK;
    }

    /**
     * @param array<string, string> $serverMessage
     */
    private function sendForUser(
        User $user,
        string $platform,
        string $tier,
        string $current,
        string $latest,
        array $serverMessage,
    ): void {
        $locale = $user->getLocale() ?: 'en';

        $title = $this->translator->trans(
            $tier === VersionGateService::TIER_BLOCKING
                ? 'notifications.version_gate.blocking_title'
                : 'notifications.version_gate.recommended_title',
            [],
            null,
            $locale,
        );

        $messageText = $serverMessage[$locale]
            ?? $serverMessage[explode('-', $locale)[0]]
            ?? $serverMessage['en']
            ?? $this->translator->trans(
                $tier === VersionGateService::TIER_BLOCKING
                    ? 'notifications.version_gate.blocking_body'
                    : 'notifications.version_gate.recommended_body',
                ['%current%' => $current, '%latest%' => $latest],
                null,
                $locale,
            );

        $this->notificationService->createNotification(
            $user,
            'version.update_' . $tier,
            $title,
            $messageText,
            [
                'platform' => $platform,
                'tier' => $tier,
                'current' => $current,
                'latest' => $latest,
            ],
        );
    }
}
