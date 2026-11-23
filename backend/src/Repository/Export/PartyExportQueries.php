<?php

namespace App\Repository\Export;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Product;
use App\Entity\Supplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Batched reads of a company's clients, suppliers and products for the CSV and SAGA exports.
 *
 * The rows come in the same order as the repositories' findAllByCompany() (name ascending),
 * with the id as tie-breaker so the keyset is stable. Each batch is a keyset query on
 * (name, id), which the (company_id, name, deleted_at) indexes serve without a sort. A batch
 * is detached once the caller has moved past it, so memory stays at about one batch. Only
 * the exported entities are detached: the request's Company and User stay managed.
 */
class PartyExportQueries
{
    public const BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return \Generator<int, Client>
     */
    public function iterateClients(Company $company): \Generator
    {
        return $this->iterateByName(Client::class, $company, true);
    }

    /**
     * @return \Generator<int, Supplier>
     */
    public function iterateSuppliers(Company $company): \Generator
    {
        return $this->iterateByName(Supplier::class, $company, true);
    }

    /**
     * ProductRepository::findAllByCompany() has no deletedAt condition of its own (the soft
     * delete filter applies), so none is added here either.
     *
     * @return \Generator<int, Product>
     */
    public function iterateProducts(Company $company): \Generator
    {
        return $this->iterateByName(Product::class, $company, false);
    }

    /**
     * @param class-string<Client|Supplier|Product> $class
     */
    private function iterateByName(string $class, Company $company, bool $skipDeleted): \Generator
    {
        $after = null;

        do {
            $qb = $this->entityManager->createQueryBuilder()
                ->select('e')
                ->from($class, 'e')
                ->where('e.company = :company')
                ->setParameter('company', $company)
                ->orderBy('e.name', 'ASC')
                ->addOrderBy('e.id', 'ASC')
                ->setMaxResults(self::BATCH_SIZE);

            if ($skipDeleted) {
                $qb->andWhere('e.deletedAt IS NULL');
            }

            if ($after !== null) {
                // Written as a range on name plus a tie-break so the index range scan applies.
                $qb->andWhere('e.name >= :afterName AND (e.name > :afterName OR e.id > :afterId)')
                    ->setParameter('afterName', $after['name'])
                    ->setParameter('afterId', $after['id'], UuidType::NAME);
            }

            $batch = $qb->getQuery()->getResult();
            if ($batch === []) {
                return;
            }

            foreach ($batch as $entity) {
                yield $entity;
            }

            $last = $batch[array_key_last($batch)];
            $after = ['name' => $last->getName(), 'id' => $last->getId()];

            (new BatchDetacher($this->entityManager))->detach($batch);
            $count = \count($batch);
            unset($batch, $last);
        } while ($count === self::BATCH_SIZE);
    }
}
