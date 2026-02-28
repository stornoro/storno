<?php

namespace App\MessageHandler;

use App\Entity\Company;
use App\Entity\Notification;
use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Message\SendExternalNotificationMessage;
use App\Message\SendPushNotificationMessage;
use App\Repository\UserDeviceRepository;
use App\Service\EmailUnsubscribeService;
use App\Service\Storage\OrganizationStorageResolver;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\TexterInterface;
use Symfony\Component\Notifier\Message\SmsMessage;
use Twig\Environment;

#[AsMessageHandler]
class SendExternalNotificationHandler
{
    private const EMAIL_TEMPLATES = [
        'invoice.validated' => 'emails/notification_invoice_validated.html.twig',
        'invoice.rejected' => 'emails/notification_invoice_rejected.html.twig',
        'invoice.due_soon' => 'emails/notification_invoice_due_soon.html.twig',
        'invoice.due_today' => 'emails/notification_invoice_due_today.html.twig',
        'invoice.overdue' => 'emails/notification_invoice_overdue.html.twig',
        'invoice.issued' => 'emails/notification_invoice_issued.html.twig',
        'invoice.paid' => 'emails/notification_invoice_paid.html.twig',
        'token.expiring_soon' => 'emails/notification_token_expiring.html.twig',
        'token.refresh_failed' => 'emails/notification_token_refresh_failed.html.twig',
        'efactura.new_documents' => 'emails/notification_efactura_new_documents.html.twig',
        'sync.error' => 'emails/notification_sync_error.html.twig',
        'export_ready' => 'emails/notification_export_ready.html.twig',
        'payment.received' => 'emails/notification_payment_received.html.twig',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
        private readonly UserDeviceRepository $userDeviceRepository,
        private readonly Environment $twig,
        private readonly FilesystemOperator $defaultStorage,
        private readonly OrganizationStorageResolver $storageResolver,
        private readonly EmailUnsubscribeService $emailUnsubscribeService,
        private readonly string $mailFrom,
        private readonly string $frontendUrl,
        private readonly ?MailerInterface $mailer = null,
        private readonly ?ChatterInterface $chatter = null,
        private readonly ?TexterInterface $texter = null,
    ) {}

    public function __invoke(SendExternalNotificationMessage $message): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($message->getUserId());

        if (!$user) {
            $this->logger->warning('User not found for external notification.', ['userId' => $message->getUserId()]);
            return;
        }

        $preference = $this->entityManager->getRepository(NotificationPreference::class)->findOneBy([
            'user' => $user,
            'eventType' => $message->getEventType(),
        ]);

        if ($preference?->isEmailEnabled() && $user->getEmail() && $this->mailer) {
            $this->sendEmail($user->getEmail(), $message->getTitle(), $message->getMessage(), $message->getEventType(), $message->getData(), (string) $user->getId());
        }

        if ($preference?->isTelegramEnabled() && $user->getTelegramChatId() && $this->chatter) {
            $text = sprintf("*%s*\n%s", $message->getTitle(), $message->getMessage());
            $this->sendTelegram($user->getTelegramChatId(), $text);
        }

        if ($preference?->isWhatsappEnabled() && $user->getPhone() && $this->texter) {
            $whatsappText = $this->formatWhatsappMessage($message->getEventType(), $message->getTitle(), $message->getMessage(), $message->getData());
            $this->sendWhatsapp($user->getPhone(), $whatsappText);
        }

        if ($preference?->isPushEnabled()) {
            $devices = $this->userDeviceRepository->findBy(['user' => $user]);
            foreach ($devices as $device) {
                $this->messageBus->dispatch(new SendPushNotificationMessage(
                    deviceToken: $device->getToken(),
                    title: $message->getTitle(),
                    body: $message->getMessage(),
                    data: $message->getData(),
                ));
            }
        }
    }

    private function sendEmail(string $to, string $title, string $body, string $eventType, array $data, string $userId): void
    {
        try {
            $unsubscribeUrl = $this->emailUnsubscribeService->generateUrl($to, $eventType, $userId);

            $email = (new Email())
                ->from($this->mailFrom)
                ->to($to)
                ->subject($title);

            $template = self::EMAIL_TEMPLATES[$eventType] ?? null;

            if ($template) {
                $html = $this->twig->render($template, [
                    'title' => $title,
                    'message' => $body,
                    'data' => $data,
                    'frontendUrl' => rtrim($this->frontendUrl, '/'),
                    'unsubscribeUrl' => $unsubscribeUrl,
                ]);
                $email->html($html);
            } else {
                $email->text(sprintf("%s\n\n%s", $title, $body));
            }

            if ($eventType === 'export_ready' && !empty($data['storagePath'])) {
                $this->attachExport($email, $data['storagePath'], $data['filename'] ?? 'export.zip', $data);
            }

            $email->getHeaders()->addTextHeader('X-Storno-Email-Category', 'notification');
            $email->getHeaders()->addTextHeader('List-Unsubscribe', sprintf('<%s>', $unsubscribeUrl));
            $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send email notification.', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function attachExport(Email $email, string $storagePath, string $filename, array $data): void
    {
        try {
            $storage = $this->defaultStorage;
            if (!empty($data['companyId'])) {
                $company = $this->entityManager->getRepository(Company::class)->find($data['companyId']);
                if ($company) {
                    $storage = $this->storageResolver->resolveForCompany($company);
                }
            }
            $content = $storage->read($storagePath);
            $email->attach($content, $filename, 'application/zip');
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to attach export file to email.', [
                'storagePath' => $storagePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendTelegram(string $chatId, string $text): void
    {
        try {
            $chatMessage = new ChatMessage($text);
            $chatMessage->transport('telegram');
            $chatMessage->options(new \Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions([
                'chat_id' => $chatId,
                'parse_mode' => 'Markdown',
            ]));

            $this->chatter->send($chatMessage);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Telegram notification.', [
                'chatId' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatWhatsappMessage(string $eventType, string $title, string $message, array $data): string
    {
        $frontendUrl = rtrim($this->frontendUrl, '/');
        $invoiceNumber = $data['invoiceNumber'] ?? $data['invoice_number'] ?? null;
        $invoiceLink = isset($data['invoiceId'], $data['companyId'])
            ? sprintf('%s/invoices/%s?company=%s', $frontendUrl, $data['invoiceId'], $data['companyId'])
            : null;

        return match ($eventType) {
            'invoice.validated' => implode("\n", array_filter([
                "\u{2705} *Factura validata ANAF*",
                $invoiceNumber ? "\u{1F4C4} Factura: *{$invoiceNumber}*" : null,
                '',
                $message,
                $invoiceLink ? "\n\u{1F517} {$invoiceLink}" : null,
            ])),

            'invoice.rejected' => implode("\n", array_filter([
                "\u{274C} *Factura respinsa ANAF*",
                $invoiceNumber ? "\u{1F4C4} Factura: *{$invoiceNumber}*" : null,
                !empty($data['companyName']) ? "\u{1F3E2} Compania: {$data['companyName']}" : null,
                '',
                $message,
                $invoiceLink ? "\n\u{1F517} {$invoiceLink}" : null,
            ])),

            'invoice.due_soon' => implode("\n", array_filter([
                "\u{26A0}\u{FE0F} *Factura aproape de scadenta*",
                $invoiceNumber ? "\u{1F4C4} Factura: *{$invoiceNumber}*" : null,
                '',
                $message,
                $invoiceLink ? "\n\u{1F517} {$invoiceLink}" : null,
            ])),

            'invoice.due_today' => implode("\n", array_filter([
                "\u{1F514} *Factura scadenta azi*",
                $invoiceNumber ? "\u{1F4C4} Factura: *{$invoiceNumber}*" : null,
                '',
                $message,
                $invoiceLink ? "\n\u{1F517} {$invoiceLink}" : null,
            ])),

            'invoice.overdue' => implode("\n", array_filter([
                "\u{1F6A8} *Factura restanta*",
                $invoiceNumber ? "\u{1F4C4} Factura: *{$invoiceNumber}*" : null,
                '',
                $message,
                $invoiceLink ? "\n\u{1F517} {$invoiceLink}" : null,
            ])),

            'invoice.issued' => implode("\n", array_filter([
                "\u{1F4E4} *Factura emisa*",
                $invoiceNumber ? "\u{1F4C4} Factura: *{$invoiceNumber}*" : null,
                '',
                $message,
                $invoiceLink ? "\n\u{1F517} {$invoiceLink}" : null,
            ])),

            'invoice.paid' => implode("\n", array_filter([
                "\u{1F389} *Factura platita*",
                $invoiceNumber ? "\u{1F4C4} Factura: *{$invoiceNumber}*" : null,
                '',
                $message,
                $invoiceLink ? "\n\u{1F517} {$invoiceLink}" : null,
            ])),

            'token.expiring_soon' => implode("\n", [
                "\u{26A0}\u{FE0F} *Token ANAF expira curand*",
                '',
                $message,
                '',
                'Reautorizeaza token-ul ANAF pentru a continua sa trimiti facturi electronice fara intreruperi.',
                '',
                "\u{1F517} {$frontendUrl}/efactura",
            ]),

            'token.refresh_failed' => implode("\n", [
                "\u{1F512} *Eroare reinnoire token ANAF*",
                '',
                $message,
                '',
                'Re-autorizeaza token-ul ANAF cat mai curand.',
                '',
                "\u{1F517} {$frontendUrl}/efactura",
            ]),

            'efactura.new_documents' => implode("\n", array_filter([
                "\u{1F4E5} *Documente noi e-Factura*",
                !empty($data['count']) ? "\u{1F4CB} Documente noi: *{$data['count']}*" : null,
                '',
                $message,
                isset($data['companyId'])
                    ? "\n\u{1F517} {$frontendUrl}/invoices?company={$data['companyId']}&direction=received"
                    : null,
            ])),

            'sync.completed' => implode("\n", [
                "\u{2705} *Sincronizare finalizata*",
                '',
                $message,
            ]),

            'sync.error' => implode("\n", array_filter([
                "\u{26A0}\u{FE0F} *Eroare sincronizare e-Factura*",
                '',
                $message,
                !empty($data['errors']) ? "\n\u{1F4CB} _Detalii:_\n" . implode("\n", array_map(fn ($e) => "  \u{2022} {$e}", (array) $data['errors'])) : null,
                isset($data['companyId'])
                    ? "\n\u{1F517} {$frontendUrl}/companies/{$data['companyId']}/anaf"
                    : null,
            ])),

            'export_ready' => implode("\n", array_filter([
                "\u{2705} *Export finalizat*",
                !empty($data['filename']) ? "\u{1F4C1} Fisier: _{$data['filename']}_" : null,
                '',
                $message,
                !empty($data['downloadUrl']) ? "\n\u{1F4E5} {$data['downloadUrl']}" : null,
            ])),

            'payment.received' => implode("\n", array_filter([
                "\u{1F4B0} *Plata primita*",
                !empty($data['amount']) ? "\u{1F4B5} Suma: *{$data['amount']} " . ($data['currency'] ?? 'RON') . '*' : null,
                $invoiceNumber ? "\u{1F4C4} Factura: *{$invoiceNumber}*" : null,
                '',
                $message,
                "\n\u{1F517} {$frontendUrl}/collections",
            ])),

            default => sprintf("*%s*\n%s", $title, $message),
        };
    }

    private function sendWhatsapp(string $phone, string $text): void
    {
        try {
            $smsMessage = new SmsMessage('whatsapp:' . $phone, $text);
            $smsMessage->transport('twilio');

            $this->texter->send($smsMessage);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send WhatsApp notification.', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
