<?php

namespace App\Service\Client;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\EmailEvent;
use App\Entity\EmailLog;
use App\Entity\User;
use App\Enum\EmailEventType;
use App\Enum\EmailStatus;
use App\EventListener\SesMessageIdListener;
use App\Exception\EmailSendBlockedException;
use App\Repository\EmailTemplateRepository;
use App\Repository\EmailUnsubscribeRepository;
use App\Service\EmailUnsubscribeService;
use App\Service\OrgMailer;
use App\Service\OutboundEmailGuard;
use App\Service\WhiteLabelResolver;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\CommonMarkConverter;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * E-mails a customer statement (situație facturi neachitate) to a client,
 * following the invoice e-mail conventions: platform sender with the company
 * as display name, reply-to the company address, OutboundEmailGuard,
 * unsubscribe list, EmailLog + send event, PDF attachment.
 *
 * An EmailTemplate with category "statement" marked as default overrides the
 * subject/body; the placeholders [[client_name]], [[company_name]], [[as_of]],
 * [[total]], [[currency]], [[invoice_count]], [[invoice_list]] and
 * [[bank_accounts]] are substituted.
 */
class ClientStatementEmailService
{
    public const CATEGORY = 'statement';

    public function __construct(
        private readonly ClientStatementService $statementService,
        private readonly ClientStatementPdfService $pdfService,
        private readonly SesMessageIdListener $sesMessageIdListener,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly EmailUnsubscribeService $emailUnsubscribeService,
        private readonly EmailUnsubscribeRepository $emailUnsubscribeRepository,
        private readonly EmailTemplateRepository $emailTemplateRepository,
        private readonly WhiteLabelResolver $whiteLabelResolver,
        private readonly OrgMailer $orgMailer,
        private readonly OutboundEmailGuard $outboundEmailGuard,
        private readonly string $mailFrom,
    ) {}

    /**
     * @param array|null $statement pre-computed statement (ClientStatementService::statement) or null to compute it
     */
    public function send(
        Client $client,
        \DateTimeImmutable $asOf,
        ?string $to = null,
        ?string $message = null,
        ?User $sentBy = null,
        ?array $statement = null,
    ): EmailLog {
        $company = $client->getCompany();
        if (!$company) {
            throw new \InvalidArgumentException('Client has no company.');
        }

        $to = trim((string) ($to ?: $client->getEmail()));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Clientul nu are o adresă de e-mail validă.');
        }

        $statement ??= $this->statementService->statement($client, $asOf);
        $companyName = $company->getName() ?? '';

        $template = $this->emailTemplateRepository->findDefaultForCompanyAndCategory($company, self::CATEGORY);
        $subject = $template?->getSubject() ?: $this->defaultSubject($company);
        $body = $template?->getBody() ?: $this->defaultBody($statement, $message);
        if ($template && $message) {
            $body .= "\n\n" . $message;
        }

        $subject = $this->substituteVariables($subject, $client, $statement);
        $body = $this->substituteVariables($body, $client, $statement);

        $this->outboundEmailGuard->assertCanSend($company, $sentBy, self::CATEGORY, $to, null, null, $subject, $body);

        $emailLog = new EmailLog();
        $emailLog->setCompany($company);
        $emailLog->setToEmail($to);
        $emailLog->setSubject($subject);
        $emailLog->setSentBy($sentBy);
        $emailLog->setTemplateUsed($template?->getName());
        $emailLog->setFromEmail($this->mailFrom);
        $emailLog->setFromName($companyName ?: 'Storno.ro');
        $emailLog->setCategory(self::CATEGORY);
        $this->entityManager->persist($emailLog);

        if ($this->emailUnsubscribeRepository->isUnsubscribed($to, $company)) {
            throw new \RuntimeException(sprintf('Destinatarul %s s-a dezabonat de la emailurile acestei companii.', $to));
        }

        try {
            $converter = new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
            $bodyHtml = $converter->convert($body)->getContent();
            $unsubscribeUrl = $this->emailUnsubscribeService->generateUrl($to, 'document');

            $html = $this->twig->render('emails/client_statement.html.twig', [
                'body' => $bodyHtml,
                'companyName' => $companyName,
                'statement' => $statement,
                'bands' => ClientStatementPdfService::bandLabels(),
                'unsubscribeUrl' => $unsubscribeUrl,
                'hideBranding' => $company->getOrganization()
                    ? $this->whiteLabelResolver->shouldHideBranding($company->getOrganization())
                    : false,
            ]);

            $email = (new Email())
                ->from(new Address($this->mailFrom, $companyName ?: 'Storno.ro'))
                ->to($to)
                ->subject($subject)
                ->text($body)
                ->html($html);

            $companyEmail = $company->getEmail();
            if ($companyEmail && filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
                $email->replyTo(new Address($companyEmail, $companyName));
            }

            try {
                $pdf = $this->pdfService->generate($client, $statement);
                $email->attach($pdf, $this->attachmentName($client, $asOf), 'application/pdf');
            } catch (\Throwable) {
                // The statement is fully described in the body; a PDF failure is non-fatal.
            }

            $email->getHeaders()->addTextHeader('X-Storno-Email-Tracked', '1');
            $email->getHeaders()->addTextHeader('List-Unsubscribe', sprintf('<%s>', $unsubscribeUrl));
            $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            $this->sesMessageIdListener->reset();
            $usedCustomSender = $this->orgMailer->send($company->getOrganization(), $email, $companyName);

            $messageId = $usedCustomSender ? null : $this->sesMessageIdListener->getLastMessageId();
            if ($messageId) {
                $emailLog->setSesMessageId(trim($messageId, '<> '));
            }

            $emailLog->setStatus(EmailStatus::SENT);

            $sendEvent = new EmailEvent();
            $sendEvent->setEmailLog($emailLog);
            $sendEvent->setEventType(EmailEventType::SEND);
            $sendEvent->setTimestamp(new \DateTimeImmutable());
            $sendEvent->setRecipients([$to]);
            $sendEvent->setRawData(['source' => 'application', 'messageId' => $messageId, 'clientId' => (string) $client->getId(), 'asOf' => $asOf->format('Y-m-d')]);
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

    /**
     * E-mail the statement to every client of the company with a balance of at
     * least $minBalance and an e-mail address. Returns per-client results and
     * counts; with $dryRun nothing is sent.
     *
     * @param Client[] $clientsById optional preloaded clients keyed by id
     */
    public function sendToAllWithBalance(
        Company $company,
        \DateTimeImmutable $asOf,
        string $minBalance = '0.01',
        ?string $message = null,
        ?User $sentBy = null,
        bool $dryRun = false,
        array $clientsById = [],
    ): array {
        $overview = $this->statementService->statementsForCompany($company, $asOf);

        $results = [];
        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $stopped = null;

        foreach ($overview['statements'] as $statement) {
            $entry = [
                'clientId' => $statement['client']['id'],
                'clientName' => $statement['client']['name'],
                'email' => $statement['client']['email'],
                'balance' => $statement['balance'],
                'currency' => $statement['currency'],
                'status' => 'skipped',
                'reason' => null,
            ];

            if (bccomp($statement['balance'], $minBalance, 2) < 0) {
                $entry['reason'] = 'below_min_balance';
                $skipped++;
                $results[] = $entry;
                continue;
            }

            $email = trim((string) $statement['client']['email']);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $entry['reason'] = 'no_email';
                $skipped++;
                $results[] = $entry;
                continue;
            }

            if ($this->emailUnsubscribeRepository->isUnsubscribed($email, $company)) {
                $entry['reason'] = 'unsubscribed';
                $skipped++;
                $results[] = $entry;
                continue;
            }

            if ($stopped !== null) {
                $entry['reason'] = $stopped;
                $skipped++;
                $results[] = $entry;
                continue;
            }

            if ($dryRun) {
                $entry['status'] = 'would_send';
                $sent++;
                $results[] = $entry;
                continue;
            }

            $client = $clientsById[$statement['client']['id']] ?? $this->entityManager->find(Client::class, $statement['client']['id']);
            if (!$client) {
                $entry['reason'] = 'client_not_found';
                $skipped++;
                $results[] = $entry;
                continue;
            }

            try {
                $log = $this->send($client, $asOf, $email, $message, $sentBy, $statement + ['bankAccounts' => $this->bankAccountsPayload($company)]);
                $entry['status'] = 'sent';
                $entry['emailLogId'] = (string) $log->getId();
                $sent++;
            } catch (EmailSendBlockedException $e) {
                $entry['status'] = 'failed';
                $entry['reason'] = $e->errorCode;
                $entry['error'] = $e->getMessage();
                $failed++;
                if (\in_array($e->errorCode, [EmailSendBlockedException::CODE_DAILY_LIMIT, EmailSendBlockedException::CODE_RATE_LIMIT, EmailSendBlockedException::CODE_PLAN_LIMIT], true)) {
                    $stopped = $e->errorCode;
                }
            } catch (\Throwable $e) {
                $entry['status'] = 'failed';
                $entry['reason'] = 'send_failed';
                $entry['error'] = mb_substr($e->getMessage(), 0, 255);
                $failed++;
            }

            $results[] = $entry;
        }

        return [
            'asOf' => $asOf->format('Y-m-d'),
            'dryRun' => $dryRun,
            'minBalance' => $minBalance,
            'clientsWithBalance' => \count($overview['statements']),
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
            'stoppedReason' => $stopped,
            'results' => $results,
        ];
    }

    public function defaultSubject(Company $company): string
    {
        return sprintf('Facturi neachitate %s', $company->getName() ?? '');
    }

    public function defaultBody(array $statement, ?string $message = null): string
    {
        $lines = [];
        $lines[] = 'Bună ziua,';
        $lines[] = '';
        $lines[] = sprintf(
            'Vă informăm că, la data %s, în evidențele noastre figurează următoarele facturi neachitate:',
            (new \DateTimeImmutable($statement['asOf']))->format('d.m.Y'),
        );
        $lines[] = '';
        $lines[] = '[[invoice_list]]';
        $lines[] = '';
        $lines[] = 'Total de achitat: [[total]] [[currency]]';
        $lines[] = '';
        $lines[] = '[[bank_accounts]]';
        if ($message !== null && trim($message) !== '') {
            $lines[] = '';
            $lines[] = trim($message);
        }
        $lines[] = '';
        $lines[] = 'Dacă plata a fost efectuată între timp, vă rugăm să nu luați în considerare acest mesaj.';
        $lines[] = '';
        $lines[] = 'Cu stimă,';
        $lines[] = '[[company_name]]';

        return implode("\n", $lines);
    }

    public function substituteVariables(string $text, Client $client, array $statement): string
    {
        $invoiceList = [];
        foreach ($statement['invoices'] as $inv) {
            $invoiceList[] = sprintf(
                '- Factura %s din %s, scadentă la %s: total %s %s, rest de plată %s %s%s',
                $inv['number'] ?? 'N/A',
                $inv['issueDate'] ? (new \DateTimeImmutable($inv['issueDate']))->format('d.m.Y') : '-',
                $inv['dueDate'] ? (new \DateTimeImmutable($inv['dueDate']))->format('d.m.Y') : '-',
                number_format((float) $inv['total'], 2, ',', '.'),
                $inv['currency'],
                number_format((float) $inv['outstanding'], 2, ',', '.'),
                $inv['currency'],
                $inv['daysOverdue'] > 0 ? sprintf(' (%d zile întârziere)', $inv['daysOverdue']) : '',
            );
        }

        $banks = [];
        foreach ($statement['bankAccounts'] ?? [] as $account) {
            $banks[] = sprintf('%s%s%s', $account['iban'], $account['bankName'] ? ' - ' . $account['bankName'] : '', $account['currency'] ? ' (' . $account['currency'] . ')' : '');
        }
        $bankText = $banks ? "Plata se poate efectua în contul:\n" . implode("\n", $banks) : '';

        $replacements = [
            '[[client_name]]' => $client->getName() ?? '',
            '[[company_name]]' => $client->getCompany()?->getName() ?? '',
            '[[as_of]]' => (new \DateTimeImmutable($statement['asOf']))->format('d.m.Y'),
            '[[total]]' => number_format((float) $statement['balance'], 2, ',', '.'),
            '[[currency]]' => $statement['currency'],
            '[[invoice_count]]' => (string) $statement['totals']['count'],
            '[[invoice_list]]' => implode("\n", $invoiceList),
            '[[bank_accounts]]' => $bankText,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    public function attachmentName(Client $client, \DateTimeImmutable $asOf): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $client->getName())), '-'));

        return sprintf('situatie-facturi-%s-%s.pdf', $slug !== '' ? $slug : 'client', $asOf->format('Y-m-d'));
    }

    private function bankAccountsPayload(Company $company): array
    {
        static $cache = [];
        $key = (string) $company->getId();
        if (!isset($cache[$key])) {
            $cache[$key] = array_map(static fn ($a) => [
                'iban' => $a->getIban(),
                'bankName' => $a->getBankName(),
                'currency' => $a->getCurrency(),
                'isDefault' => $a->isDefault(),
            ], $this->statementService->bankAccounts($company));
        }

        return $cache[$key];
    }
}
