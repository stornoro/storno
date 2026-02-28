<?php

namespace App\Repository;

use App\Entity\TrialBalanceRow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrialBalanceRow>
 */
class TrialBalanceRowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrialBalanceRow::class);
    }
}
