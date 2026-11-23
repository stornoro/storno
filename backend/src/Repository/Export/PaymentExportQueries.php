<?php

namespace App\Repository\Export;

use App\Entity\Company;
use App\Entity\Payment;
use App\Enum\InvoiceDirection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Payments for the SAGA receipts / payments exports: same rows and order as
 * PaymentRepository::findByCompanyAndDirectionFiltered() (payment date descending, id as
 * tie-break), optionally narrowed to one currency, loaded in batches with their invoice.
 *
 * The payment table has no (company_id, payment_date) index, so a keyset on the payment date
 * would rescan all of the company's payments for every batch (about 2 s per batch at 400k
 * payments). Instead prepare() runs the filtered query once and keeps only the ordered ids,
 * packed into one string at 36 bytes per payment, plus the first and last payment date for
 * the file name. iterate() then loads 500 payments at a time by primary key, with the invoice
 * fetch-joined so getInvoice() does not cost one query per payment. Each batch is detached
 * once the caller has moved past it, with the client proxies of its invoices.
 */
class PaymentExportQueries
{
    public const BATCH_SIZE = 500;
    private const ID_LENGTH = 36;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param string|null $currency upper-case currency code; a payment without one counts as RON
     *
     * @return array{ids: string, count: int, minDate: ?\DateTimeInterface, maxDate: ?\DateTimeInterface}
     */
    public function prepare(Company $company, InvoiceDirection $direction, ?string $dateFrom, ?string $dateTo, ?string $currency): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('p.id AS id', 'p.paymentDate AS paymentDate')
            ->from(Payment::class, 'p')
            ->join('p.invoice', 'i')
            ->where('p.company = :company')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('direction', $direction)
            ->orderBy('p.paymentDate', 'DESC')
            ->addOrderBy('p.id', 'DESC');

        if ($dateFrom) {
            $qb->andWhere('p.paymentDate >= :dateFrom')->setParameter('dateFrom', $dateFrom);
        }
        if ($dateTo) {
            $qb->andWhere('p.paymentDate <= :dateTo')->setParameter('dateTo', $dateTo);
        }
        if ($currency !== null && $currency !== '') {
            // Same rule as the old in-memory filter: strtoupper($currency ?: 'RON') === $requested.
            $qb->andWhere("UPPER(COALESCE(NULLIF(p.currency, ''), 'RON')) = :currency")
                ->setParameter('currency', $currency);
        }

        $ids = '';
        $minDate = null;
        $maxDate = null;
        foreach ($qb->getQuery()->toIterable([], AbstractQuery::HYDRATE_SCALAR) as $row) {
            // Scalar hydration may hand back the raw column values; ids are compared in the
            // lower-case form Uuid::toRfc4122() gives.
            $id = strtolower((string) $row['id']);
            if (\strlen($id) !== self::ID_LENGTH) {
                throw new \UnexpectedValueException('Unexpected payment id format.');
            }
            $ids .= $id;
            $date = $row['paymentDate'];
            if (\is_string($date) && $date !== '') {
                $date = new \DateTimeImmutable($date);
            }
            if ($date instanceof \DateTimeInterface) {
                $maxDate ??= $date;
                $minDate = $date;
            }
        }

        return [
            'ids' => $ids,
            'count' => intdiv(\strlen($ids), self::ID_LENGTH),
            'minDate' => $minDate,
            'maxDate' => $maxDate,
        ];
    }

    /**
     * @param string $packedIds the `ids` of prepare()
     *
     * @return \Generator<int, Payment>
     */
    public function iterate(string $packedIds): \Generator
    {
        $chunkBytes = self::BATCH_SIZE * self::ID_LENGTH;
        $length = \strlen($packedIds);

        for ($offset = 0; $offset < $length; $offset += $chunkBytes) {
            $ids = str_split(substr($packedIds, $offset, $chunkBytes), self::ID_LENGTH);

            $loaded = $this->entityManager->createQueryBuilder()
                ->select('p', 'i')
                ->from(Payment::class, 'p')
                ->join('p.invoice', 'i')
                ->where('p.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->getResult();

            $byId = [];
            foreach ($loaded as $payment) {
                $byId[$payment->getId()->toRfc4122()] = $payment;
            }

            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    yield $byId[$id];
                }
            }

            $invoices = [];
            foreach ($byId as $payment) {
                if ($payment->getInvoice() !== null) {
                    $invoices[spl_object_id($payment->getInvoice())] = $payment->getInvoice();
                }
            }
            $detacher = new BatchDetacher($this->entityManager);
            $detacher->detach($byId);
            $detacher->detach($invoices);
            unset($loaded, $byId, $ids, $invoices);
        }
    }
}
