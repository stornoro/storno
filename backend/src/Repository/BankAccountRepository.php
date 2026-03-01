<?php

namespace App\Repository;

use App\Entity\BankAccount;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BankAccount>
 */
class BankAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankAccount::class);
    }

    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('ba')
            ->where('ba.company = :company')
            ->setParameter('company', $company)
            ->orderBy('ba.isDefault', 'DESC')
            ->addOrderBy('ba.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findForInvoice(Company $company): array
    {
        return $this->createQueryBuilder('ba')
            ->where('ba.company = :company')
            ->andWhere('ba.showOnInvoice = true')
            ->setParameter('company', $company)
            ->orderBy('ba.isDefault', 'DESC')
            ->addOrderBy('ba.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByIban(Company $company, string $iban): ?BankAccount
    {
        $normalizedIban = strtoupper(preg_replace('/\s+/', '', $iban));

        return $this->createQueryBuilder('ba')
            ->where('ba.company = :company')
            ->andWhere('ba.iban = :iban')
            ->setParameter('company', $company)
            ->setParameter('iban', $normalizedIban)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
