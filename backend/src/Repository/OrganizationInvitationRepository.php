<?php

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\OrganizationInvitation;
use App\Enum\InvitationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationInvitation>
 */
class OrganizationInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationInvitation::class);
    }

    public function findValidByToken(string $token): ?OrganizationInvitation
    {
        $invitation = $this->findOneBy([
            'token' => $token,
            'status' => InvitationStatus::PENDING,
        ]);

        if ($invitation && $invitation->isExpired()) {
            return null;
        }

        return $invitation;
    }

    /**
     * @return OrganizationInvitation[]
     */
    public function findPendingByOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.organization = :org')
            ->andWhere('i.status = :status')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('org', $organization)
            ->setParameter('status', InvitationStatus::PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPendingByEmailAndOrganization(string $email, Organization $organization): ?OrganizationInvitation
    {
        return $this->createQueryBuilder('i')
            ->where('i.organization = :org')
            ->andWhere('i.email = :email')
            ->andWhere('i.status = :status')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('org', $organization)
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setParameter('status', InvitationStatus::PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
