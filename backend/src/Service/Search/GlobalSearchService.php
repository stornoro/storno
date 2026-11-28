<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\Product;
use App\Entity\ProformaInvoice;
use App\Entity\Supplier;
use App\Enum\InvoiceDirection;
use App\Repository\InvoiceRepository;
use App\Util\SearchTerms;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One search box over the records people look for most: invoices, proformas, clients, suppliers
 * and products of one company. Each group returns its first few matches in the same card shape
 * as RelatedService (title, subtitle, status, date, href), so a client renders them alike.
 *
 * Matching follows SearchTerms: every typed word must appear in one of the group's columns.
 */
final class GlobalSearchService
{
    public const TYPES = ['invoices', 'proformas', 'clients', 'suppliers', 'products'];
    public const DEFAULT_LIMIT = 5;
    public const MAX_LIMIT = 20;
    /** A single character matches most of the company's records and helps nobody. */
    public const MIN_LENGTH = 2;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param list<string> $types subset of TYPES the caller is allowed to read
     *
     * @return array<string, list<array<string, mixed>>> groups keyed by type, in TYPES order
     */
    public function search(Company $company, string $query, array $types, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $groups = [];
        foreach (self::TYPES as $type) {
            if (!in_array($type, $types, true)) {
                continue;
            }
            $groups[$type] = match ($type) {
                'invoices' => $this->invoices($company, $query, $limit),
                'proformas' => $this->proformas($company, $query, $limit),
                'clients' => $this->clients($company, $query, $limit),
                'suppliers' => $this->suppliers($company, $query, $limit),
                'products' => $this->products($company, $query, $limit),
            };
        }

        return $groups;
    }

    /** @return list<array<string, mixed>> */
    private function invoices(Company $company, string $query, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('i.id', 'i.number', 'i.direction', 'i.status', 'i.issueDate', 'i.total', 'i.currency', 'i.senderName', 'i.receiverName', 'c.name AS clientName')
            ->from(Invoice::class, 'i')
            ->leftJoin('i.client', 'c')
            ->where('i.company = :company')
            ->setParameter('company', $company)
            ->orderBy('i.issueDate', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit);
        SearchTerms::applyTo($qb, $query, InvoiceRepository::searchColumns('i'));

        return array_map(function (array $row): array {
            $id = (string) $row['id'];
            $incoming = $this->enumValue($row['direction']) === InvoiceDirection::INCOMING->value;
            $party = $incoming ? $row['senderName'] : ($row['clientName'] ?? $row['receiverName']);

            return [
                'type' => 'invoice',
                'id' => $id,
                'title' => $row['number'] ?: 'draft',
                'subtitle' => $this->moneyAndParty($row['total'], $row['currency'], $party),
                'status' => $this->enumValue($row['status']),
                'date' => $row['issueDate']?->format('Y-m-d'),
                'href' => '/invoices/' . $id,
                'direction' => $this->enumValue($row['direction']),
            ];
        }, $qb->getQuery()->getArrayResult());
    }

    /** @return list<array<string, mixed>> */
    private function proformas(Company $company, string $query, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p.id', 'p.number', 'p.status', 'p.issueDate', 'p.total', 'p.currency', 'c.name AS clientName')
            ->from(ProformaInvoice::class, 'p')
            ->leftJoin('p.client', 'c')
            ->where('p.company = :company')
            ->setParameter('company', $company)
            ->orderBy('p.issueDate', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit);
        SearchTerms::applyTo($qb, $query, ['p.number', 'c.name', 'c.cui', 'c.cnp', 'c.vatCode']);

        return array_map(function (array $row): array {
            $id = (string) $row['id'];

            return [
                'type' => 'proforma',
                'id' => $id,
                'title' => $row['number'] ?: 'draft',
                'subtitle' => $this->moneyAndParty($row['total'], $row['currency'], $row['clientName']),
                'status' => $this->enumValue($row['status']),
                'date' => $row['issueDate']?->format('Y-m-d'),
                'href' => '/proforma-invoices/' . $id,
            ];
        }, $qb->getQuery()->getArrayResult());
    }

    /** @return list<array<string, mixed>> */
    private function clients(Company $company, string $query, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c.id', 'c.name', 'c.cui', 'c.cnp', 'c.city', 'c.email')
            ->from(Client::class, 'c')
            ->where('c.company = :company')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setMaxResults($limit);
        SearchTerms::applyTo($qb, $query, ['c.name', 'c.cui', 'c.cnp', 'c.vatCode', 'c.email', 'c.idNumber', 'c.registrationNumber', 'c.clientCode', 'c.contactPerson']);

        return array_map(function (array $row): array {
            $id = (string) $row['id'];

            return [
                'type' => 'client',
                'id' => $id,
                'title' => $row['name'],
                'subtitle' => $this->joined([$row['cui'] ?: $row['cnp'], $row['city']]),
                'status' => null,
                'date' => null,
                'href' => '/clients/' . $id,
            ];
        }, $qb->getQuery()->getArrayResult());
    }

    /** @return list<array<string, mixed>> */
    private function suppliers(Company $company, string $query, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('s.id', 's.name', 's.cif', 's.city')
            ->from(Supplier::class, 's')
            ->where('s.company = :company')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('s.name', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setMaxResults($limit);
        SearchTerms::applyTo($qb, $query, ['s.name', 's.cif', 's.vatCode', 's.email', 's.registrationNumber']);

        return array_map(function (array $row): array {
            $id = (string) $row['id'];

            return [
                'type' => 'supplier',
                'id' => $id,
                'title' => $row['name'],
                'subtitle' => $this->joined([$row['cif'], $row['city']]),
                'status' => null,
                'date' => null,
                'href' => '/suppliers/' . $id,
            ];
        }, $qb->getQuery()->getArrayResult());
    }

    /** @return list<array<string, mixed>> */
    private function products(Company $company, string $query, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p.id', 'p.name', 'p.code')
            ->from(Product::class, 'p')
            ->where('p.company = :company')
            ->setParameter('company', $company)
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults($limit);
        SearchTerms::applyTo($qb, $query, ['p.name', 'p.code']);

        return array_map(function (array $row): array {
            $id = (string) $row['id'];

            return [
                'type' => 'product',
                'id' => $id,
                'title' => $row['name'],
                'subtitle' => $row['code'] ?: null,
                'status' => null,
                'date' => null,
                'href' => '/products/' . $id,
            ];
        }, $qb->getQuery()->getArrayResult());
    }

    private function enumValue(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : ($value === null ? null : (string) $value);
    }

    private function moneyAndParty(mixed $total, ?string $currency, ?string $party): string
    {
        return $this->joined([trim(number_format((float) $total, 2, '.', ' ') . ' ' . $currency), $party]);
    }

    /** @param list<?string> $parts */
    private function joined(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''));

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
