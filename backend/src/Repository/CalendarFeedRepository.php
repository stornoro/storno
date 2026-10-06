<?php

namespace App\Repository;

use App\Entity\CalendarFeed;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarFeed>
 */
class CalendarFeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarFeed::class);
    }

    public function findForMember(User $user, Organization $organization): ?CalendarFeed
    {
        return $this->findOneBy(['user' => $user, 'organization' => $organization]);
    }
}
