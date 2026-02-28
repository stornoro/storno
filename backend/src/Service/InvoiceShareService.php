<?php

namespace App\Service;

use App\Entity\EmailLog;
use App\Entity\Invoice;
use App\Entity\InvoiceShareToken;
use App\Entity\User;
use App\Enum\ShareTokenStatus;
use App\Repository\InvoiceShareTokenRepository;
use App\Repository\StripeConnectAccountRepository;
use Doctrine\ORM\EntityManagerInterface;

class InvoiceShareService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceShareTokenRepository $shareTokenRepository,
        private readonly StripeConnectAccountRepository $connectAccountRepository,
        private readonly string $frontendUrl,
    ) {}

    public function createShareToken(
        Invoice $invoice,
        ?EmailLog $emailLog = null,
        ?User $createdBy = null,
        int $expiryDays = 30,
    ): InvoiceShareToken {
        $token = new InvoiceShareToken();
        $token->setInvoice($invoice);
        $token->setCompany($invoice->getCompany());
        $token->setEmailLog($emailLog);
        $token->setCreatedBy($createdBy);
        $token->setExpiresAt(new \DateTimeImmutable("+{$expiryDays} days"));

        // Enable payment if the invoice has online payment enabled and company has active Stripe Connect
        if ($invoice->isPlataOnline()) {
            $connectAccount = $this->connectAccountRepository->findByCompany($invoice->getCompany());
            if ($connectAccount && $connectAccount->isChargesEnabled()) {
                $token->setPaymentEnabled(true);
            }
        }

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $token;
    }

    public function getShareUrl(InvoiceShareToken $token): string
    {
        return sprintf('%s/share/%s', rtrim($this->frontendUrl, '/'), $token->getToken());
    }

    public function revokeAllForInvoice(Invoice $invoice): int
    {
        $activeTokens = $this->shareTokenRepository->findActiveByInvoice($invoice);
        $count = 0;

        foreach ($activeTokens as $token) {
            $token->revoke();
            $count++;
        }

        if ($count > 0) {
            $this->entityManager->flush();
        }

        return $count;
    }
}
