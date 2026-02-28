<?php

namespace App\Service;

use App\Entity\DeliveryNote;
use App\Entity\EmailEvent;
use App\Entity\EmailLog;
use App\Entity\User;
use App\Enum\EmailEventType;
use App\Enum\EmailStatus;
use App\EventListener\SesMessageIdListener;
use App\Repository\EmailUnsubscribeRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\CommonMarkConverter;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class DeliveryNoteEmailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly SesMessageIdListener $sesMessageIdListener,
        private readonly DocumentPdfService $documentPdfService,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly EmailUnsubscribeService $emailUnsubscribeService,
        private readonly EmailUnsubscribeRepository $emailUnsubscribeRepository,
        private readonly string $mailFrom,
    ) {}

    public function send(
        DeliveryNote $deliveryNote,
        string $to,
        ?string $subject = null,
        ?string $body = null,
        ?array $cc = null,
        ?array $bcc = null,
        ?User $sentBy = null,
    ): EmailLog {
        $noteNumber = $deliveryNote->getNumber() ?? 'N/A';
        $companyName = $deliveryNote->getCompany()?->getName() ?? '';

        $subject = $subject ?? sprintf('Aviz de insotire %s - %s', $noteNumber, $companyName);
        $body = $body ?? sprintf(
            "Buna ziua,\n\nVa trimitem atasat avizul de insotire %s.\n\nCu stima,\n%s",
            $noteNumber,
            $companyName,
        );

        $subject = $this->substituteVariables($subject, $deliveryNote);
        $body = $this->substituteVariables($body, $deliveryNote);

        $fromName = $companyName ?: 'Storno.ro';

        $emailLog = new EmailLog();
        $emailLog->setDeliveryNote($deliveryNote);
        $emailLog->setCompany($deliveryNote->getCompany());
        $emailLog->setToEmail($to);
        $emailLog->setSubject($subject);
        $emailLog->setSentBy($sentBy);
        $emailLog->setFromEmail($this->mailFrom);
        $emailLog->setFromName($fromName);
        $emailLog->setCategory('delivery_note');

        if ($cc) {
            $emailLog->setCcEmails($cc);
        }
        if ($bcc) {
            $emailLog->setBccEmails($bcc);
        }

        $this->entityManager->persist($emailLog);

        // Check if recipient has unsubscribed from this company's emails
        if ($this->emailUnsubscribeRepository->isUnsubscribed($to, $deliveryNote->getCompany())) {
            throw new \RuntimeException(sprintf('Destinatarul %s s-a dezabonat de la emailurile acestei companii.', $to));
        }

        try {
            $converter = new CommonMarkConverter(['html_input' => 'allow']);
            $bodyHtml = $converter->convert($body)->getContent();

            $unsubscribeUrl = $this->emailUnsubscribeService->generateUrl($to, 'document');

            $html = $this->twig->render('emails/delivery_note.html.twig', [
                'body' => $bodyHtml,
                'companyName' => $companyName,
                'noteNumber' => $noteNumber,
                'issueDate' => $deliveryNote->getIssueDate()?->format('d.m.Y') ?? '-',
                'total' => $deliveryNote->getTotal() ?? '0.00',
                'currency' => $deliveryNote->getCurrency() ?? 'RON',
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);

            $email = (new Email())
                ->from(new Address($this->mailFrom, $companyName ?: 'Storno.ro'))
                ->to($to)
                ->subject($subject)
                ->text($body)
                ->html($html);

            if ($cc) {
                foreach ($cc as $ccAddress) {
                    if (filter_var($ccAddress, FILTER_VALIDATE_EMAIL)) {
                        $email->addCc($ccAddress);
                    }
                }
            }
            if ($bcc) {
                foreach ($bcc as $bccAddress) {
                    if (filter_var($bccAddress, FILTER_VALIDATE_EMAIL)) {
                        $email->addBcc($bccAddress);
                    }
                }
            }

            // Attach PDF
            try {
                $pdf = $this->documentPdfService->generateDeliveryNotePdf($deliveryNote);
                $email->attach($pdf, sprintf('aviz-%s.pdf', $noteNumber), 'application/pdf');
            } catch (\Throwable) {
                // PDF attachment failure is non-fatal
            }

            $email->getHeaders()->addTextHeader('X-Storno-Email-Tracked', '1');
            $email->getHeaders()->addTextHeader('List-Unsubscribe', sprintf('<%s>', $unsubscribeUrl));
            $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            $this->sesMessageIdListener->reset();
            $this->mailer->send($email);

            $messageId = $this->sesMessageIdListener->getLastMessageId();
            if ($messageId) {
                $emailLog->setSesMessageId(trim($messageId, '<> '));
            }

            $emailLog->setStatus(EmailStatus::SENT);

            $sendEvent = new EmailEvent();
            $sendEvent->setEmailLog($emailLog);
            $sendEvent->setEventType(EmailEventType::SEND);
            $sendEvent->setTimestamp(new \DateTimeImmutable());
            $sendEvent->setRecipients(array_values(array_filter([$to, ...($cc ?? []), ...($bcc ?? [])])));
            $sendEvent->setRawData(['source' => 'application', 'messageId' => $messageId]);
            $emailLog->addEvent($sendEvent);
        } catch (\Throwable $e) {
            $emailLog->setStatus(EmailStatus::FAILED);
            $emailLog->setErrorMessage(mb_substr($e->getMessage(), 0, 255));
        }

        $this->entityManager->persist($emailLog);
        $this->entityManager->flush();

        if ($emailLog->getStatus() === EmailStatus::FAILED) {
            throw new \RuntimeException('Failed to send email: ' . $emailLog->getErrorMessage());
        }

        return $emailLog;
    }

    public function substituteVariables(string $text, DeliveryNote $deliveryNote): string
    {
        $companyName = $deliveryNote->getCompany()?->getName() ?? '';
        $clientName = $deliveryNote->getClient()?->getName() ?? '';

        $replacements = [
            '[[client_name]]' => $clientName,
            '[[delivery_note_number]]' => $deliveryNote->getNumber() ?? 'N/A',
            '[[total]]' => $deliveryNote->getTotal() ?? '0.00',
            '[[issue_date]]' => $deliveryNote->getIssueDate()?->format('d.m.Y') ?? '-',
            '[[company_name]]' => $companyName,
            '[[currency]]' => $deliveryNote->getCurrency() ?? 'RON',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    public function getDefaultRecipient(DeliveryNote $deliveryNote): ?string
    {
        return $deliveryNote->getClient()?->getEmail();
    }

    public function getDefaultSubject(DeliveryNote $deliveryNote): string
    {
        $noteNumber = $deliveryNote->getNumber() ?? 'N/A';
        $companyName = $deliveryNote->getCompany()?->getName() ?? '';

        return sprintf('Aviz de insotire %s - %s', $noteNumber, $companyName);
    }

    public function getDefaultBody(DeliveryNote $deliveryNote): string
    {
        $noteNumber = $deliveryNote->getNumber() ?? 'N/A';
        $companyName = $deliveryNote->getCompany()?->getName() ?? '';

        return sprintf(
            "Buna ziua,\n\nVa trimitem atasat avizul de insotire %s.\n\nCu stima,\n%s",
            $noteNumber,
            $companyName,
        );
    }
}
