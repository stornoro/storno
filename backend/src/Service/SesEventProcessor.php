<?php

namespace App\Service;

use App\Entity\EmailEvent;
use App\Entity\EmailLog;
use App\Enum\EmailEventType;
use App\Enum\EmailStatus;
use App\Repository\EmailLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class SesEventProcessor
{
    public function __construct(
        private readonly EmailLogRepository $emailLogRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(array $snsMessage): void
    {
        // The SNS Message field contains a JSON string with the SES event
        $sesNotification = json_decode($snsMessage['Message'] ?? '{}', true);
        if (!$sesNotification) {
            $this->logger->warning('SES webhook: empty or invalid Message payload');
            return;
        }

        // Extract the SES message ID
        $messageId = $sesNotification['mail']['messageId'] ?? null;
        if (!$messageId) {
            $this->logger->warning('SES webhook: no messageId found in notification');
            return;
        }

        // Find the EmailLog by SES message ID
        $emailLog = $this->emailLogRepository->findBySesMessageId($messageId);
        if (!$emailLog) {
            $this->logger->info('SES webhook: no EmailLog found for messageId ' . $messageId);
            return;
        }

        // Determine event type from the SES notification type
        $notificationType = $sesNotification['notificationType'] ?? $sesNotification['eventType'] ?? null;
        $eventType = $this->mapEventType($notificationType);
        if (!$eventType) {
            $this->logger->warning('SES webhook: unknown notification type: ' . $notificationType);
            return;
        }

        // Extract event timestamp
        $timestamp = $this->extractTimestamp($sesNotification, $eventType);

        // Idempotency check: skip if we already have this exact event
        foreach ($emailLog->getEvents() as $existing) {
            if ($existing->getEventType() === $eventType
                && $existing->getTimestamp()->format('Y-m-d H:i:s') === $timestamp->format('Y-m-d H:i:s')) {
                $this->logger->info('SES webhook: duplicate event skipped');
                return;
            }
        }

        // Create the EmailEvent
        $event = new EmailEvent();
        $event->setEmailLog($emailLog);
        $event->setEventType($eventType);
        $event->setTimestamp($timestamp);
        $event->setRawData($sesNotification);

        // Extract type-specific data
        $this->enrichEvent($event, $sesNotification, $eventType);

        $emailLog->addEvent($event);

        // Update EmailLog status based on event type
        $this->updateEmailLogStatus($emailLog, $eventType, $sesNotification);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $this->logger->info(sprintf('SES webhook: processed %s event for EmailLog %s', $eventType->value, $emailLog->getId()));
    }

    private function mapEventType(?string $type): ?EmailEventType
    {
        return match ($type) {
            'Send' => EmailEventType::SEND,
            'Delivery' => EmailEventType::DELIVERY,
            'Bounce' => EmailEventType::BOUNCE,
            'Complaint' => EmailEventType::COMPLAINT,
            'Reject' => EmailEventType::REJECT,
            'Open' => EmailEventType::OPEN,
            'Click' => EmailEventType::CLICK,
            default => null,
        };
    }

    private function extractTimestamp(array $notification, EmailEventType $eventType): \DateTimeImmutable
    {
        $key = lcfirst($eventType->value);
        $ts = $notification[$key]['timestamp'] ?? $notification['mail']['timestamp'] ?? null;

        if ($ts) {
            try {
                return new \DateTimeImmutable($ts);
            } catch (\Exception) {
                // fall through
            }
        }

        return new \DateTimeImmutable();
    }

    private function enrichEvent(EmailEvent $event, array $notification, EmailEventType $eventType): void
    {
        $key = lcfirst($eventType->value);
        $detail = $notification[$key] ?? [];

        // Recipients
        $recipients = $notification['mail']['destination'] ?? null;
        if ($recipients) {
            $event->setRecipients($recipients);
        }

        switch ($eventType) {
            case EmailEventType::BOUNCE:
                $event->setBounceType($detail['bounceType'] ?? null);
                $event->setBounceSubType($detail['bounceSubType'] ?? null);
                // Build detail string from bounced recipients
                $bouncedRecipients = $detail['bouncedRecipients'] ?? [];
                if ($bouncedRecipients) {
                    $details = array_map(fn($r) => ($r['emailAddress'] ?? '') . ': ' . ($r['diagnosticCode'] ?? 'unknown'), $bouncedRecipients);
                    $event->setEventDetail(mb_substr(implode('; ', $details), 0, 500));
                }
                break;

            case EmailEventType::COMPLAINT:
                $complainedRecipients = $detail['complainedRecipients'] ?? [];
                if ($complainedRecipients) {
                    $emails = array_map(fn($r) => $r['emailAddress'] ?? '', $complainedRecipients);
                    $event->setEventDetail('Complaint from: ' . implode(', ', $emails));
                }
                break;

            case EmailEventType::OPEN:
                $event->setUserAgent(mb_substr($detail['userAgent'] ?? '', 0, 500) ?: null);
                break;

            case EmailEventType::CLICK:
                $event->setLinkClicked(mb_substr($detail['link'] ?? '', 0, 2000) ?: null);
                $event->setUserAgent(mb_substr($detail['userAgent'] ?? '', 0, 500) ?: null);
                break;

            case EmailEventType::REJECT:
                $event->setEventDetail($detail['reason'] ?? null);
                break;

            default:
                break;
        }
    }

    private function updateEmailLogStatus(EmailLog $emailLog, EmailEventType $eventType, array $notification): void
    {
        match ($eventType) {
            EmailEventType::DELIVERY => $emailLog->setStatus(EmailStatus::DELIVERED),
            EmailEventType::BOUNCE => (function () use ($emailLog, $notification) {
                $emailLog->setStatus(EmailStatus::BOUNCED);
                $bounceDetail = $notification['bounce'] ?? [];
                $bouncedRecipients = $bounceDetail['bouncedRecipients'] ?? [];
                if ($bouncedRecipients) {
                    $msg = ($bouncedRecipients[0]['diagnosticCode'] ?? $bounceDetail['bounceType'] ?? 'Bounce');
                    $emailLog->setErrorMessage(mb_substr($msg, 0, 255));
                }
            })(),
            EmailEventType::COMPLAINT => (function () use ($emailLog) {
                $emailLog->setStatus(EmailStatus::BOUNCED);
                $emailLog->setErrorMessage('Complaint received');
            })(),
            default => null,
        };
    }
}
