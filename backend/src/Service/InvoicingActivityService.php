<?php

namespace App\Service;

use App\Enum\DocumentStatus;
use App\Enum\InvoiceDirection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Answers a narrow question for platforms that integrate with Storno: does this
 * fiscal code belong to an organization that is actually issuing invoices here?
 *
 * Built for platforms that give their users a benefit for invoicing through
 * Storno. A dormant account earns nothing for anyone, so the answer is counted
 * from invoices actually issued in a recent window, not from the account
 * merely existing.
 *
 * Deliberately minimal: a boolean and a count. No company name, no amounts, no
 * client list. Whoever holds the integration key can already ask about any
 * fiscal code, so every extra field would be a leak with no purpose.
 */
class InvoicingActivityService
{
    /** Widest window a caller may ask for, in days. */
    public const MAX_WINDOW_DAYS = 365;

    public const DEFAULT_WINDOW_DAYS = 30;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return array{active: bool, invoicesLast30d: int, windowDays: int, invoicesInWindow: int}
     */
    public function lookup(string $cui, int $windowDays = self::DEFAULT_WINDOW_DAYS): array
    {
        $windowDays = max(1, min($windowDays, self::MAX_WINDOW_DAYS));
        $fiscalCode = $this->normaliseCui($cui);

        $empty = [
            'active' => false,
            'invoicesLast30d' => 0,
            'windowDays' => $windowDays,
            'invoicesInWindow' => 0,
        ];

        if (null === $fiscalCode) {
            return $empty;
        }

        // A company whose organization is suspended is not "active" for an
        // integration, even if invoices exist in its history.
        $rows = $this->entityManager->createQuery(
            'SELECT c.id
             FROM App\Entity\Company c
             JOIN c.organization o
             WHERE c.cif = :cif AND o.isActive = true'
        )->setParameter('cif', $fiscalCode)->getScalarResult();

        if (!$rows) {
            return $empty;
        }

        $companyIds = array_column($rows, 'id');
        $invoicesInWindow = $this->countIssuedSince($companyIds, $windowDays);

        /*
         * The 30-day figure is what discount tiers are built on, so it is always
         * reported. When a caller asks for another window we compute both, and
         * the two never disagree.
         */
        $invoicesLast30d = self::DEFAULT_WINDOW_DAYS === $windowDays
            ? $invoicesInWindow
            : $this->countIssuedSince($companyIds, self::DEFAULT_WINDOW_DAYS);

        return [
            'active' => true,
            'invoicesLast30d' => $invoicesLast30d,
            'windowDays' => $windowDays,
            'invoicesInWindow' => $invoicesInWindow,
        ];
    }

    /**
     * Invoices the company issued in the last `$days` days.
     *
     * Drafts do not count: they are not invoices yet. Cancelled and rejected
     * ones do not count either, otherwise issuing and voiding would earn a
     * discount. Everything else that left the door counts, whatever it has
     * been through since — sent to the SPV, paid in part, overdue.
     * Incoming invoices belong to the supplier, not to this company.
     *
     * @param list<mixed> $companyIds
     */
    private function countIssuedSince(array $companyIds, int $days): int
    {
        $countable = array_map(
            static fn (DocumentStatus $status): string => $status->value,
            [
                DocumentStatus::ISSUED,
                DocumentStatus::SENT_TO_PROVIDER,
                DocumentStatus::SYNCED,
                DocumentStatus::VALIDATED,
                DocumentStatus::PAID,
                DocumentStatus::PARTIALLY_PAID,
                DocumentStatus::OVERDUE,
            ],
        );

        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(i.id)
             FROM App\Entity\Invoice i
             WHERE i.company IN (:companies)
               AND i.issueDate >= :since
               AND i.status IN (:statuses)
               AND (i.direction IS NULL OR i.direction = :outgoing)'
        )
            ->setParameter('companies', $companyIds)
            ->setParameter('since', new \DateTimeImmutable(sprintf('-%d days', $days)))
            ->setParameter('statuses', $countable)
            ->setParameter('outgoing', InvoiceDirection::OUTGOING->value)
            ->getSingleScalarResult();
    }

    /**
     * Fiscal codes arrive written in every possible way: "RO 12345678",
     * "ro12345678", with spaces or dots. Internally they are plain integers.
     */
    private function normaliseCui(string $cui): ?int
    {
        $digits = preg_replace('/\D+/', '', $cui) ?? '';

        if ('' === $digits || strlen($digits) > 10) {
            return null;
        }

        return (int) $digits;
    }
}
