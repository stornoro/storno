<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\PdfTemplateConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PdfTemplateConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PdfTemplateConfig::class);
    }

    public function findByCompany(Company $company): ?PdfTemplateConfig
    {
        return $this->findOneBy(['company' => $company]);
    }
}
