<?php

namespace App\Service;

use App\Entity\Organization;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends an Email through the organization's own SMTP sender when one is
 * configured (Business white-label), otherwise through the platform mailer.
 */
class OrgMailer
{
    public function __construct(
        private readonly MailerInterface $defaultMailer,
        private readonly MailerConfigResolver $resolver,
        private readonly string $mailFrom,
    ) {}

    /**
     * @return bool true when the org's custom sender was used (no SES tracking applies)
     */
    public function send(?Organization $org, Email $email, ?string $fallbackFromName = null): bool
    {
        $config = $org ? $this->resolver->resolve($org) : null;

        if ($config) {
            $email->from(new Address($config['fromAddress'], $config['fromName'] ?: ($fallbackFromName ?? '')));
            $transport = $this->buildTransport(
                $config['host'],
                $config['port'],
                $config['encryption'],
                $config['username'],
                $config['password'],
            );
            (new SymfonyMailer($transport))->send($email);

            return true;
        }

        if (\count($email->getFrom()) === 0) {
            $email->from(new Address($this->mailFrom, $fallbackFromName ?: 'Storno.ro'));
        }
        $this->defaultMailer->send($email);

        return false;
    }

    public function buildTransport(
        string $host,
        int $port,
        string $encryption,
        ?string $username,
        ?string $password,
    ): EsmtpTransport {
        // ssl → implicit TLS (port 465); tls → STARTTLS (auto); none → plaintext
        $tls = match ($encryption) {
            'ssl' => true,
            'none' => false,
            default => null,
        };

        $transport = new EsmtpTransport($host, $port, $tls);
        if ($username !== null && $username !== '') {
            $transport->setUsername($username);
            $transport->setPassword((string) $password);
        }

        return $transport;
    }
}
