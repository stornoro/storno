<?php

namespace App\Service;

use App\Entity\EmailEvent;
use App\Entity\EmailLog;
use App\Entity\Receipt;
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

class ReceiptEmailService
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
        Receipt $receipt,
        string $to,
        ?string $subject = null,
        ?string $body = null,
        ?array $cc = null,
        ?array $bcc = null,
        ?User $sentBy = null,
    ): EmailLog {
        $receiptNumber = $receipt->getNumber() ?? 'N/A';
        $companyName = $receipt->getCompany()?->getName() ?? '';

        $subject = $subject ?? sprintf('Bon fiscal %s - %s', $receiptNumber, $companyName);
        $body = $body ?? sprintf(
            "Buna ziua,\n\nVa trimitem atasat bonul fiscal %s.\n\nCu stima,\n%s",
            $receiptNumber,
            $companyName,
        );

        $subject = $this->substituteVariables($subject, $receipt);
        $body = $this->substituteVariables($body, $receipt);

        $fromName = $companyName ?: 'Storno.ro';

        $emailLog = new EmailLog();
        $emailLog->setReceipt($receipt);
        $emailLog->setCompany($receipt->getCompany());
        $emailLog->setToEmail($to);
        $emailLog->setSubject($subject);
        $emailLog->setSentBy($sentBy);
        $emailLog->setFromEmail($this->mailFrom);
        $emailLog->setFromName($fromName);
        $emailLog->setCategory('receipt');

        if ($cc) {
            $emailLog->setCcEmails($cc);
        }
        if ($bcc) {
            $emailLog->setBccEmails($bcc);
        }

        $this->entityManager->persist($emailLog);

        // Check if recipient has unsubscribed from this company's emails
        if ($this->emailUnsubscribeRepository->isUnsubscribed($to, $receipt->getCompany())) {
            throw new \RuntimeException(sprintf('Destinatarul %s s-a dezabonat de la emailurile acestei companii.', $to));
        }

        try {
            $converter = new CommonMarkConverter(['html_input' => 'allow']);
            $bodyHtml = $converter->convert($body)->getContent();

            $unsubscribeUrl = $this->emailUnsubscribeService->generateUrl($to, 'document');

            $html = $this->twig->render('emails/receipt.html.twig', [
                'body' => $bodyHtml,
                'companyName' => $companyName,
                'receiptNumber' => $receiptNumber,
                'issueDate' => $receipt->getIssueDate()?->format('d.m.Y') ?? '-',
                'total' => $receipt->getTotal() ?? '0.00',
                'currency' => $receipt->getCurrency() ?? 'RON',
                'paymentMethod' => $receipt->getPaymentMethod(),
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
                $pdf = $this->documentPdfService->generateReceiptPdf($receipt);
                $email->attach($pdf, sprintf('bon-%s.pdf', $receiptNumber), 'application/pdf');
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

    public function substituteVariables(string $text, Receipt $receipt): string
    {
        $companyName = $receipt->getCompany()?->getName() ?? '';
        $clientName = $receipt->getClient()?->getName() ?? $receipt->getCustomerName() ?? '';

        $replacements = [
            '[[client_name]]' => $clientName,
            '[[receipt_number]]' => $receipt->getNumber() ?? 'N/A',
            '[[total]]' => $receipt->getTotal() ?? '0.00',
            '[[issue_date]]' => $receipt->getIssueDate()?->format('d.m.Y') ?? '-',
            '[[company_name]]' => $companyName,
            '[[currency]]' => $receipt->getCurrency() ?? 'RON',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    public function getDefaultRecipient(Receipt $receipt): ?string
    {
        return $receipt->getClient()?->getEmail();
    }

    public function getDefaultSubject(Receipt $receipt): string
    {
        $receiptNumber = $receipt->getNumber() ?? 'N/A';
        $companyName = $receipt->getCompany()?->getName() ?? '';

        return sprintf('Bon fiscal %s - %s', $receiptNumber, $companyName);
    }

    public function getDefaultBody(Receipt $receipt): string
    {
        $receiptNumber = $receipt->getNumber() ?? 'N/A';
        $companyName = $receipt->getCompany()?->getName() ?? '';

        return sprintf(
            "Buna ziua,\n\nVa trimitem atasat bonul fiscal %s.\n\nCu stima,\n%s",
            $receiptNumber,
            $companyName,
        );
    }
}
