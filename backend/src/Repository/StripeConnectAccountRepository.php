<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\StripeConnectAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StripeConnectAccount>
 */
class StripeConnectAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StripeConnectAccount::class);
    }

    public function findByCompany(Company $company): ?StripeConnectAccount
    {
        return $this->findOneBy(['company' => $company]);
    }

    public function findByStripeAccountId(string $stripeAccountId): ?StripeConnectAccount
    {
        return $this->findOneBy(['stripeAccountId' => $stripeAccountId]);
    }
}
