<?php

namespace App\Service\Partner;

use App\Entity\Client;
use App\Entity\Invoice;
use App\Service\Client\ClientStatementService;

/**
 * The partner rules a company sets on its clients: `blocked` clients cannot be
 * invoiced, `warning` clients are flagged when picked, and a credit limit
 * produces a warning (never a refusal) when the client's outstanding balance
 * plus the invoice would exceed it.
 */
class PartnerRulesService
{
    public function __construct(
        private readonly ClientStatementService $statementService,
    ) {}

    public static function blockedMessage(Client $client): string
    {
        return sprintf('Clientul "%s" este blocat si nu poate fi facturat. Schimbati statusul clientului (Clienti → Editare) pentru a emite factura.', $client->getName());
    }

    /**
     * Warning payload when issuing this invoice would take the client over its
     * credit limit, null otherwise. Amounts are in the company's default
     * currency; an invoice in another currency is converted with its own
     * exchange rate, and skipped when it has none.
     *
     * @return array{code: string, message: string, creditLimit: string, outstanding: string, invoiceTotal: string, projected: string, currency: string}|null
     */
    public function creditLimitWarning(Invoice $invoice): ?array
    {
        $client = $invoice->getClient();
        $limit = $client?->getCreditLimit();
        if ($client === null || $limit === null || bccomp($limit, '0', 2) <= 0) {
            return null;
        }
        if ($invoice->getParentDocument() !== null || bccomp((string) $invoice->getTotal(), '0', 2) <= 0) {
            return null; // storno / credit notes reduce the balance
        }

        $statement = $this->statementService->statement($client, new \DateTimeImmutable('today'));
        $currency = $statement['currency'] ?? 'RON';
        $outstanding = $statement['balance'] ?? '0.00';

        $total = (string) $invoice->getTotal();
        if (($invoice->getCurrency() ?? $currency) !== $currency) {
            $rate = $invoice->getExchangeRate();
            if ($rate === null || bccomp((string) $rate, '0', 6) <= 0) {
                return null;
            }
            $total = bcmul($total, (string) $rate, 2);
        }

        $projected = bcadd($outstanding, $total, 2);
        if (bccomp($projected, $limit, 2) <= 0) {
            return null;
        }

        return [
            'code' => 'credit_limit_exceeded',
            'message' => sprintf(
                'Soldul clientului "%s" (%s %s) plus aceasta factura (%s %s) depaseste limita de credit de %s %s.',
                $client->getName(), $outstanding, $currency, $total, $currency, $limit, $currency,
            ),
            'creditLimit' => $limit,
            'outstanding' => $outstanding,
            'invoiceTotal' => $total,
            'projected' => $projected,
            'currency' => $currency,
        ];
    }
}
