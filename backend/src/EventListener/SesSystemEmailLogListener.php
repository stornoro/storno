<?php

namespace App\EventListener;

use App\Entity\EmailEvent;
use App\Entity\EmailLog;
use App\Enum\EmailEventType;
use App\Enum\EmailStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Email;

#[AsEventListener(event: SentMessageEvent::class, priority: -10)]
class SesSystemEmailLogListener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SentMessageEvent $event): void
    {
        $original = $event->getMessage()->getOriginalMessage();
        if (!$original instanceof Email) {
            return;
        }

        // Document email services already create their own EmailLog
        if ($original->getHeaders()->has('X-Storno-Email-Tracked')) {
            return;
        }

        $category = $original->getHeaders()->has('X-Storno-Email-Category')
            ? $original->getHeaders()->get('X-Storno-Email-Category')?->getBodyAsString()
            : null;

        $toAddresses = $original->getTo();
        $toEmail = $toAddresses[0]?->getAddress() ?? 'unknown';

        $ccEmails = array_map(fn ($a) => $a->getAddress(), $original->getCc());
        $bccEmails = array_map(fn ($a) => $a->getAddress(), $original->getBcc());

        $fromAddresses = $original->getFrom();
        $fromEmail = $fromAddresses[0]?->getAddress();
        $fromName = $fromAddresses[0]?->getName();

        $messageId = $event->getMessage()->getMessageId();

        try {
            $emailLog = new EmailLog();
            $emailLog->setToEmail($toEmail);
            $emailLog->setSubject($original->getSubject() ?? '');
            $emailLog->setFromEmail($fromEmail);
            $emailLog->setFromName($fromName ?: null);
            $emailLog->setCategory($category);
            $emailLog->setStatus(EmailStatus::SENT);

            if ($ccEmails) {
                $emailLog->setCcEmails($ccEmails);
            }
            if ($bccEmails) {
                $emailLog->setBccEmails($bccEmails);
            }

            if ($messageId) {
                $emailLog->setSesMessageId(trim($messageId, '<> '));
            }

            $sendEvent = new EmailEvent();
            $sendEvent->setEmailLog($emailLog);
            $sendEvent->setEventType(EmailEventType::SEND);
            $sendEvent->setTimestamp(new \DateTimeImmutable());
            $sendEvent->setRecipients(array_values(array_filter([$toEmail, ...$ccEmails, ...$bccEmails])));
            $sendEvent->setRawData(['source' => 'system', 'category' => $category, 'messageId' => $messageId]);
            $emailLog->addEvent($sendEvent);

            $this->entityManager->persist($emailLog);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create system email log.', [
                'to' => $toEmail,
                'category' => $category,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
