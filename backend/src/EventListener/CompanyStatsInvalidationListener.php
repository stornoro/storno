<?php

namespace App\EventListener;

use App\Entity\Company;
use App\Service\Cache\CompanyStatsCache;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Moves a company's stats-cache version when any of its records is written through the ORM,
 * so cached dashboard figures and list totals never outlive the data they were computed from.
 *
 * The companies are collected in onFlush (the only place the change sets are visible) and
 * invalidated in postFlush, after the rows are written, so a concurrent reader cannot cache
 * the old figures under the new version.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
class CompanyStatsInvalidationListener
{
    /** @var array<string, true> */
    private array $pending = [];

    public function __construct(
        private readonly CompanyStatsCache $statsCache,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        $entities = [
            ...$uow->getScheduledEntityInsertions(),
            ...$uow->getScheduledEntityUpdates(),
            ...$uow->getScheduledEntityDeletions(),
        ];

        foreach ($entities as $entity) {
            try {
                $company = $this->companyOf($entity);
            } catch (\Throwable) {
                continue;
            }
            if ($company !== null && $company->getId() !== null) {
                $this->pending[$company->getId()->toRfc4122()] = true;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $companyIds = array_keys($this->pending);
        $this->pending = [];

        foreach ($companyIds as $companyId) {
            $this->statsCache->invalidate($companyId);
        }
    }

    private function companyOf(object $entity): ?Company
    {
        if ($entity instanceof Company) {
            return $entity;
        }
        if (method_exists($entity, 'getCompany')) {
            $company = $entity->getCompany();

            return $company instanceof Company ? $company : null;
        }
        // Lines carry no company of their own; they belong to the document's company.
        if (method_exists($entity, 'getInvoice')) {
            $invoice = $entity->getInvoice();
            if (is_object($invoice) && method_exists($invoice, 'getCompany')) {
                $company = $invoice->getCompany();

                return $company instanceof Company ? $company : null;
            }
        }

        return null;
    }
}
