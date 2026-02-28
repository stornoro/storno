<?php

namespace App\Service;

use App\Entity\EmailEvent;
use App\Entity\EmailLog;
use App\Entity\EmailTemplate;
use App\Entity\Invoice;
use App\Entity\User;
use App\Enum\EmailEventType;
use App\Enum\EmailStatus;
use App\EventListener\SesMessageIdListener;
use App\Repository\EmailUnsubscribeRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\CommonMarkConverter;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class InvoiceEmailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly SesMessageIdListener $sesMessageIdListener,
        private readonly PdfGeneratorService $pdfGenerator,
        private readonly DocumentPdfService $documentPdfService,
        private readonly InvoiceXmlResolver $xmlResolver,
        private readonly FilesystemOperator $defaultStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly InvoiceShareService $shareService,
        private readonly EmailUnsubscribeService $emailUnsubscribeService,
        private readonly EmailUnsubscribeRepository $emailUnsubscribeRepository,
        private readonly string $mailFrom,
        private readonly string $frontendUrl,
    ) {}

    public function send(
        Invoice $invoice,
        string $to,
        ?string $subject = null,
        ?string $body = null,
        ?array $cc = null,
        ?array $bcc = null,
        ?EmailTemplate $template = null,
        ?User $sentBy = null,
    ): EmailLog {
        $invoiceNumber = $invoice->getNumber() ?? 'N/A';
        $companyName = $invoice->getCompany()?->getName() ?? '';

        // Use template values if no explicit subject/body provided
        if ($template) {
            if (!$subject) {
                $subject = $template->getSubject();
            }
            if (!$body) {
                $body = $template->getBody();
            }
        }

        $subject = $subject ?? sprintf('Factura %s - %s', $invoiceNumber, $companyName);
        $body = $body ?? sprintf(
            "Buna ziua,\n\nVa trimitem atasat factura %s.\n\nCu stima,\n%s",
            $invoiceNumber,
            $companyName,
        );

        // Always substitute template variables (handles both raw template text and user-edited text)
        $subject = $this->substituteVariables($subject, $invoice);
        $body = $this->substituteVariables($body, $invoice);

        // Build email log entry
        $fromName = $companyName ?: 'Storno.ro';
        $emailLog = new EmailLog();
        $emailLog->setInvoice($invoice);
        $emailLog->setCompany($invoice->getCompany());
        $emailLog->setToEmail($to);
        $emailLog->setSubject($subject);
        $emailLog->setSentBy($sentBy);
        $emailLog->setTemplateUsed($template?->getName());
        $emailLog->setFromEmail($this->mailFrom);
        $emailLog->setFromName($fromName);
        $emailLog->setCategory('invoice');

        if ($cc) {
            $emailLog->setCcEmails($cc);
        }
        if ($bcc) {
            $emailLog->setBccEmails($bcc);
        }

        // Persist EmailLog early so it can be referenced by the share token
        $this->entityManager->persist($emailLog);

        // Check if recipient has unsubscribed from this company's emails
        if ($this->emailUnsubscribeRepository->isUnsubscribed($to, $invoice->getCompany())) {
            throw new \RuntimeException(sprintf('Destinatarul %s s-a dezabonat de la emailurile acestei companii.', $to));
        }

        try {
            // Convert markdown body to HTML
            $converter = new CommonMarkConverter(['html_input' => 'allow']);
            $bodyHtml = $converter->convert($body)->getContent();

            // Create share token for public invoice viewing
            $shareToken = $this->shareService->createShareToken($invoice, $emailLog, $sentBy);
            $invoiceUrl = $this->shareService->getShareUrl($shareToken);

            $unsubscribeUrl = $this->emailUnsubscribeService->generateUrl($to, 'document');

            // Render HTML email
            $html = $this->twig->render('emails/invoice.html.twig', [
                'body' => $bodyHtml,
                'companyName' => $companyName,
                'invoiceNumber' => $invoiceNumber,
                'issueDate' => $invoice->getIssueDate()?->format('d.m.Y') ?? '-',
                'dueDate' => $invoice->getDueDate()?->format('d.m.Y'),
                'total' => $invoice->getTotal() ?? '0.00',
                'currency' => $invoice->getCurrency() ?? 'RON',
                'invoiceUrl' => $invoiceUrl,
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
            $pdf = $this->getPdfContent($invoice);
            if ($pdf) {
                $email->attach($pdf, sprintf('factura-%s.pdf', $invoiceNumber), 'application/pdf');
            }

            // Attach XML
            $xml = $this->getXmlContent($invoice);
            if ($xml) {
                $email->attach($xml, sprintf('factura-%s.xml', $invoiceNumber), 'application/xml');
            }

            $email->getHeaders()->addTextHeader('X-Storno-Email-Tracked', '1');
            $email->getHeaders()->addTextHeader('List-Unsubscribe', sprintf('<%s>', $unsubscribeUrl));
            $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            $this->sesMessageIdListener->reset();
            $this->mailer->send($email);

            // Capture SES Message-ID via the event listener
            $messageId = $this->sesMessageIdListener->getLastMessageId();
            if ($messageId) {
                $emailLog->setSesMessageId(trim($messageId, '<> '));
            }

            $emailLog->setStatus(EmailStatus::SENT);

            // Create initial Send event
            $sendEvent = new EmailEvent();
            $sendEvent->setEmailLog($emailLog);
            $sendEvent->setEventType(EmailEventType::SEND);
            $sendEvent->setTimestamp(new \DateTimeImmutable());
            $sendEvent->setRecipients(array_filter([$to, ...($cc ?? []), ...($bcc ?? [])]));
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

    public function substituteVariables(string $text, Invoice $invoice): string
    {
        $companyName = $invoice->getCompany()?->getName() ?? '';
        $clientName = $invoice->getClient()?->getName() ?? $invoice->getReceiverName() ?? '';

        $replacements = [
            '[[client_name]]' => $clientName,
            '[[invoice_number]]' => $invoice->getNumber() ?? 'N/A',
            '[[total]]' => $invoice->getTotal() ?? '0.00',
            '[[due_date]]' => $invoice->getDueDate()?->format('d.m.Y') ?? '-',
            '[[issue_date]]' => $invoice->getIssueDate()?->format('d.m.Y') ?? '-',
            '[[company_name]]' => $companyName,
            '[[balance]]' => $invoice->getBalance() ?? '0.00',
            '[[currency]]' => $invoice->getCurrency() ?? 'RON',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    public function getDefaultRecipient(Invoice $invoice): ?string
    {
        return $invoice->getClient()?->getEmail()
            ?? $invoice->getSupplier()?->getEmail();
    }

    private function getPdfContent(Invoice $invoice): ?string
    {
        // Outgoing invoices: generate using company's selected design template
        if ($this->documentPdfService->isOutgoingInvoice($invoice)) {
            try {
                return $this->documentPdfService->generateInvoicePdf($invoice);
            } catch (\Throwable) {
                return null;
            }
        }

        // Incoming invoices: serve cached PDF or generate from XML via Java service
        $pdfPath = $invoice->getPdfPath();
        if ($pdfPath && $this->defaultStorage->fileExists($pdfPath)) {
            return $this->defaultStorage->read($pdfPath);
        }

        $xml = $this->getXmlContent($invoice);
        if ($xml) {
            try {
                return $this->pdfGenerator->generatePdf($xml);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function getXmlContent(Invoice $invoice): ?string
    {
        return $this->xmlResolver->resolve($invoice);
    }
}
