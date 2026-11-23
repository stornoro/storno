<?php

namespace App\Repository\Export;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Proxy;

/**
 * Detaches a finished export batch from the entity manager so the identity map does not grow
 * with the export.
 *
 * Besides the entities themselves it detaches the uninitialized proxies their to-one
 * associations point to. Hydrating a payment's invoice registers a proxy for the invoice's
 * client, one per distinct client: over 400k payments that alone ran out of 256 MB.
 * Initialized associations (the request's Company and User among them) are left alone.
 */
final class BatchDetacher
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param iterable<object> $entities
     */
    public function detach(iterable $entities): void
    {
        foreach ($entities as $entity) {
            if (!$this->entityManager->contains($entity)) {
                continue;
            }

            $metadata = $this->entityManager->getClassMetadata($entity::class);
            foreach ($metadata->getAssociationMappings() as $field => $mapping) {
                if (!$metadata->isSingleValuedAssociation($field)) {
                    continue;
                }
                $target = $metadata->getFieldValue($entity, $field);
                if ($target instanceof Proxy && !$target->__isInitialized() && $this->entityManager->contains($target)) {
                    $this->entityManager->detach($target);
                }
            }

            $this->entityManager->detach($entity);
        }
    }
}
