<?php

namespace App\EventListener;

use App\Entity\Traits\AuditableTrait;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
class AuditableListener
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$this->usesAuditableTrait($entity)) {
            return;
        }

        $now = new \DateTimeImmutable();
        $user = $this->getUser();

        if ($entity->getCreatedAt() === null) {
            $entity->setCreatedAt($now);
        }
        $entity->setUpdatedAt($now);

        if ($user !== null && $entity->getCreatedBy() === null) {
            $entity->setCreatedBy($user);
        }
        if ($user !== null) {
            $entity->setUpdatedBy($user);
        }
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$this->usesAuditableTrait($entity)) {
            return;
        }

        $entity->setUpdatedAt(new \DateTimeImmutable());

        $user = $this->getUser();
        if ($user !== null) {
            $entity->setUpdatedBy($user);
        }
    }

    private function usesAuditableTrait(object $entity): bool
    {
        return $this->usesTraitRecursive($entity::class, AuditableTrait::class);
    }

    private function usesTraitRecursive(string $class, string $trait): bool
    {
        do {
            if (in_array($trait, class_uses($class) ?: [], true)) {
                return true;
            }
        } while ($class = get_parent_class($class));

        return false;
    }

    private function getUser(): ?User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        // Ensure the User entity is managed by the EntityManager.
        // Security may return a deserialized User not tracked by the UnitOfWork.
        if (!$this->entityManager->contains($user)) {
            return $this->entityManager->getReference(User::class, $user->getId());
        }

        return $user;
    }
}
